<?php

namespace Dashed\DashedEcommerceBol\Classes;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Dashed\DashedCore\Classes\Sites;
use Illuminate\Support\Facades\Http;
use Dashed\DashedCore\Classes\Locales;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceCore\Classes\OssVat;
use Dashed\DashedEcommerceCore\Models\OrderLog;
use Illuminate\Http\Client\ConnectionException;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
use Dashed\DashedEcommerceCore\Models\OrderProduct;

class Bol
{
    public const APIURL = 'https://api.bol.com';
    public const LOGINURL = 'https://login.bol.com';

    // Pauze tussen opeenvolgende order-requests, zodat we de Bol-API niet in
    // één burst overspoelen (minder kans op rate-limits / 504 gateway timeouts).
    public const REQUEST_THROTTLE_MICROSECONDS = 300000;

    public static function isConnected(?string $siteId = null): bool
    {
        if (! $siteId) {
            $siteId = Sites::getActive();
        }

        try {
            $id = Customsetting::get('bol_client_id', $siteId);
            $secret = Customsetting::get('bol_client_secret', $siteId);
            if ($id && $secret) {
                $response = Http::asForm()
                    ->withBasicAuth($id, $secret)
                    ->post(self::LOGINURL . '/token', [
                        'grant_type' => 'client_credentials',
                    ])
                    ->json();
                if ($response['access_token'] ?? false) {
                    Customsetting::set('bol_connected', 1, $siteId);
                    Customsetting::set('bol_connection_error', null, $siteId);
                    Customsetting::set('bol_access_token', $response['access_token'], $siteId);

                    return true;
                }
            }
        } catch (\Exception $e) {
            Customsetting::set('bol_connected', 0, $siteId);
            Customsetting::set('bol_connection_error', $e->getMessage(), $siteId);
            Customsetting::set('bol_access_token', null, $siteId);

            return false;
        }

        Customsetting::set('bol_connected', 0, $siteId);
        Customsetting::set('bol_connection_error', 'error', $siteId);
        Customsetting::set('bol_access_token', null, $siteId);

        return false;
    }

    public static function refreshToken(?string $siteId = null): void
    {
        if (! $siteId) {
            $siteId = Sites::getActive();
        }

        // Hergebruik een nog geldig token i.p.v. bij elke aanroep een nieuwe op te
        // halen. Bol-tokens leven ~5 min; zonder deze cache vuurt syncShipments()
        // anders één tokenrequest per order af (de grootste vertrager van die sync).
        $existingToken = Customsetting::get('bol_access_token', $siteId);
        $expiresAt = Customsetting::get('bol_access_token_expires_at', $siteId);
        if ($existingToken && $expiresAt && now()->timestamp < (int) $expiresAt) {
            return;
        }

        try {
            $id = Customsetting::get('bol_client_id', $siteId);
            $secret = Customsetting::get('bol_client_secret', $siteId);
            if ($id && $secret) {
                $response = Http::asForm()
                    ->withBasicAuth($id, $secret)
                    ->post(self::LOGINURL . '/token', [
                        'grant_type' => 'client_credentials',
                    ])
                    ->json();
                if ($response['access_token'] ?? false) {
                    Customsetting::set('bol_connected', 1, $siteId);
                    Customsetting::set('bol_connection_error', null, $siteId);
                    Customsetting::set('bol_access_token', $response['access_token'], $siteId);
                    // Ververs iets voor de echte expiry zodat we geen token gebruiken
                    // dat midden in een request verloopt.
                    $ttl = (int) ($response['expires_in'] ?? 299);
                    Customsetting::set('bol_access_token_expires_at', now()->addSeconds(max(30, $ttl - 30))->timestamp, $siteId);

                    return;
                }
            }
        } catch (\Exception $e) {
            Customsetting::set('bol_connected', 0, $siteId);
            Customsetting::set('bol_connection_error', $e->getMessage(), $siteId);
            Customsetting::set('bol_access_token', null, $siteId);
            Customsetting::set('bol_access_token_expires_at', null, $siteId);

            return;
        }

        Customsetting::set('bol_connected', 0, $siteId);
        Customsetting::set('bol_connection_error', 'error', $siteId);
        Customsetting::set('bol_access_token', null, $siteId);
        Customsetting::set('bol_access_token_expires_at', null, $siteId);
    }

    public static function syncOrders($siteId = null)
    {
        if (! $siteId) {
            $siteId = Sites::getActive();
        }

        self::refreshToken($siteId);

        $accessToken = Customsetting::get('bol_access_token', $siteId);
        if ($accessToken) {
            $bolOrders = [];
            $bolOrdersResultCount = 50;
            $page = 1;
            while ($bolOrdersResultCount == 50) {
                try {
                    $response = Http::withToken($accessToken)
                        ->accept('application/vnd.retailer.v10+json')
                        ->retry(3)
                        ->get(self::APIURL . '/retailer/orders', [
                            'page' => $page,
                            'status' => 'ALL',
//                        'fulfilment-method' => 'ALL',
//                        'latest-change-date' => now()->subMonths(3)->toDateString(),
                        ])
                        ->json();
                } catch (\Exception $e) {
                    $bolOrdersResultCount = 0;
                }

                if (isset($response['orders'])) {
                    $bolOrders = array_merge($bolOrders, $response['orders']);
                    $bolOrdersResultCount = count($response['orders']);
                    $page++;
                }

                // Pacing tussen pagina-opvragingen.
                usleep(self::REQUEST_THROTTLE_MICROSECONDS);
            }

            foreach ($bolOrders as $bolOrder) {
                self::syncOrder($bolOrder);

                // Pacing tussen losse order-opvragingen.
                usleep(self::REQUEST_THROTTLE_MICROSECONDS);
            }

            return $bolOrders;
        }

        return [];
    }

    public static function syncOrder($bolOrder): void
    {
        $siteId = Sites::getActive();

        self::refreshToken($siteId);

        $accessToken = Customsetting::get('bol_access_token', $siteId);
        if ($accessToken) {
            try {
                $httpResponse = Http::withToken($accessToken)
                    ->accept('application/vnd.retailer.v10+json')
                    ->retry(3, 1000, throw: false)
                    ->get(self::APIURL . '/retailer/orders/' . $bolOrder['orderId']);
            } catch (ConnectionException $exception) {
                // Verbinding met Bol mislukt (time-out): order overslaan en bij de
                // volgende sync opnieuw proberen, zonder dit als fout te rapporteren.
                Log::warning('Bol.com order ' . $bolOrder['orderId'] . ' tijdelijk overgeslagen (verbindingsfout).');

                return;
            }

            // Tijdelijke serverfout bij Bol (bijv. 504 Gateway Timeout): overslaan en
            // bij de volgende sync opnieuw proberen. Bewust geen report()/error, zodat
            // dit niet als applicatiefout binnenkomt.
            if ($httpResponse->serverError()) {
                Log::warning('Bol.com order ' . $bolOrder['orderId'] . ' tijdelijk overgeslagen (HTTP ' . $httpResponse->status() . ').');

                return;
            }

            // Andere mislukte respons (bijv. 4xx): wél als fout loggen, maar de sync
            // van de overige orders niet blokkeren.
            if ($httpResponse->failed()) {
                report(new \Exception('Bol order sync HTTP ' . $httpResponse->status() . ' voor order ' . $bolOrder['orderId']));
                Log::error('Fout bij synchroniseren van order met Bol.com voor order ID ' . $bolOrder['orderId'] . ': HTTP ' . $httpResponse->status());

                return;
            }

            $response = $httpResponse->json();

            $order = Order::where('bol_order_id', $bolOrder['orderId'])->first();
            if (! $order) {
                $order = new Order();
                $order->bol_order_id = $bolOrder['orderId'];
                $order->bol_order_commission = collect($response['orderItems'])->sum(fn ($item) => $item['commission']);
            } else {
                return;
            }

            $order->first_name = $response['shipmentDetails']['firstName'];
            $order->last_name = $response['shipmentDetails']['surname'];
            $order->email = $response['shipmentDetails']['email'];
            $order->street = $response['shipmentDetails']['streetName'];
            $order->house_nr = $response['shipmentDetails']['houseNumber'] . (isset($response['shipmentDetails']['houseNumberExtension']) && $response['shipmentDetails']['houseNumberExtension'] ? ' ' . $response['shipmentDetails']['houseNumberExtension'] : '');
            $order->zip_code = $response['shipmentDetails']['zipCode'];
            $order->city = $response['shipmentDetails']['city'];
            $order->country = $response['shipmentDetails']['countryCode'];
            $order->company_name = $response['shipmentDetails']['company'] ?? '';
            $order->invoice_first_name = $response['billingDetails']['firstName'];
            $order->invoice_last_name = $response['billingDetails']['surname'];
            $order->invoice_street = $response['billingDetails']['streetName'];
            $order->invoice_house_nr = $response['billingDetails']['houseNumber'] . (isset($response['billingDetails']['houseNumberExtension']) && $response['billingDetails']['houseNumberExtension'] ? ' ' . $response['billingDetails']['houseNumberExtension'] : '');
            $order->invoice_zip_code = $response['billingDetails']['zipCode'];
            $order->invoice_city = $response['billingDetails']['city'];
            $order->invoice_country = $response['billingDetails']['countryCode'];
            $order->total = collect($response['orderItems'])->sum(fn ($item) => $item['unitPrice'] * $item['quantity']);
            $order->btw = $order->total / 121 * 21;
            $order->subtotal = $order->total;
            $order->discount = 0;
            $order->vat_percentages = [
                21 => $order->btw,
            ];
            $order->invoice_send_to_customer = 1;
            $order->order_origin = 'Bol';
            $order->site_id = $siteId;
            $order->gender = $response['shipmentDetails']['salutation'] ?? '';
            $order->locale = Locales::getFirstLocale()['id'];
            $order->invoice_id = 'PROFORMA';
            $order->save();

            $orderContainsPreOrders = false;

            foreach ($response['orderItems'] as $orderItem) {
                $orderProduct = OrderProduct::where('bol_id', $orderItem['orderItemId'])->where('order_id', $order->id)->first();
                if (! $orderProduct) {
                    $orderProduct = new OrderProduct();
                    $orderProduct->bol_id = $orderItem['orderItemId'];
                }

                $product = Product::where('ean', $orderItem['product']['ean'])->first();
                if ($product) {
                    $orderProduct->product_id = $product->id;
                    $orderProduct->sku = $product->sku;

                    if ($product->isPreorderable() && $product->stock < $orderItem['quantity']) {
                        $orderProduct->is_pre_order = true;
                        $orderProduct->pre_order_restocked_date = $product->expected_in_stock_date;
                        $orderContainsPreOrders = true;
                    }
                } else {
                    $orderProduct->product_id = null;
                }

                $orderProduct->name = $product->name ?? $orderItem['product']['title'];
                $orderProduct->quantity = $orderItem['quantity'];
                $orderProduct->price = $orderItem['unitPrice'] * $orderItem['quantity'];
                $orderProduct->discount = 0;
                $orderProduct->order_id = $order->id;
                $orderProduct->btw = $orderProduct->price / 121 * 21;
                $orderProduct->vat_rate = 21;
                $orderProduct->save();

                if ($product) {
                    foreach ($product->bundleProducts as $bundleProduct) {
                        $orderProduct = new OrderProduct();
                        $orderProduct->quantity = $orderItem['quantity'];
                        $orderProduct->product_id = $bundleProduct->id;
                        $orderProduct->order_id = $order->id;
                        $orderProduct->name = $bundleProduct->name;
                        $orderProduct->sku = $bundleProduct->sku;
                        $orderProduct->price = 0;
                        $orderProduct->discount = 0;

                        if ($bundleProduct->isPreorderable() && $bundleProduct->stock < $orderItem['quantity']) {
                            $orderProduct->is_pre_order = true;
                            $orderProduct->pre_order_restocked_date = $bundleProduct->expected_in_stock_date;
                            $orderContainsPreOrders = true;
                        }

                        $orderProduct->save();
                    }
                }
            }

            // De order-btw hierboven is met het eigen tarief gerekend; de regels
            // volgen bij een verzending naar een ander EU-land het landtarief
            // (OSS, dashed-ecommerce-core >= v4.149.0).
            if (class_exists(OssVat::class)) {
                OssVat::recalculateOrderVat($order);
            }

            rescue(fn () => self::logMixedOrderNotice($order, $response['orderItems'] ?? []));

            if ($orderContainsPreOrders) {
                $order->contains_pre_orders = true;
                $order->save();
            }

            $orderPayment = new OrderPayment();
            $orderPayment->amount = $order->total;
            $orderPayment->order_id = $order->id;
            $orderPayment->psp = 'Bol';
            $orderPayment->payment_method = 'Via Bol';
            $orderPayment->status = 'paid';
            $orderPayment->save();

            OrderLog::createLog($order->id, note: 'Order aangemaakt via Bol.com met ID ' . $order->bol_order_id);

            $order->changeStatus('paid');
        }
    }

    /**
     * Parse a Bol.com "items not in the same order" error into groups of order item IDs.
     *
     * Expected format (whitespace may vary):
     *   "order item id(s) 111, 222, 333. And with order item id(s) 444, 555."
     *
     * @return array<int, array<int, string>>|null  Groups of orderItemId strings, or null if the
     *                                               message does not look like a split-order error.
     */
    private static function parseBolSplitError(string $message): ?array
    {
        if (! str_contains($message, 'order item id(s)')) {
            return null;
        }

        $groups = [];
        if (! preg_match_all('/order item id\(s\)\s*([0-9,\s]+?)(?:\.|$)/i', $message, $matches)) {
            return null;
        }

        foreach ($matches[1] as $groupText) {
            preg_match_all('/\d+/', $groupText, $idMatches);
            if (! empty($idMatches[0])) {
                $groups[] = $idMatches[0];
            }
        }

        return count($groups) >= 2 ? $groups : null;
    }

    /**
     * Classify a Bol shipment POST response.
     *
     * @return 'SUCCESS'|'SHIPPED_ALREADY'|'ERROR'
     */
    private static function classifyShipmentResponse(array $response): string
    {
        if (($response['status'] ?? null) === 'SUCCESS') {
            return 'SUCCESS';
        }

        $errorMessage = (string) ($response['errorMessage'] ?? '');
        if (str_contains($errorMessage, 'shipped already')) {
            return 'SHIPPED_ALREADY';
        }

        return 'ERROR';
    }

    /**
     * Send one Bol shipment POST for a specific set of order items and poll until done.
     *
     * @param  array<int, array{orderItemId: string, quantity: int}>  $orderItems
     * @return array{status: string, errorMessage?: string, processStatusId?: string, entityId?: string, links?: array}
     */
    private static function sendShipmentGroup(
        string $accessToken,
        array $orderItems,
        string $shipmentReference,
        string $transporterCode,
        string $trackAndTrace
    ): array {
        $httpResponse = Http::withToken($accessToken)
            ->withHeaders([
                'Accept' => 'application/vnd.retailer.v10+json',
                'Content-Type' => 'application/vnd.retailer.v10+json',
            ])
            ->retry(3)
            ->post(self::APIURL . '/retailer/shipments', [
                'orderItems' => $orderItems,
                'shipmentReference' => $shipmentReference,
                'transport' => [
                    'transporterCode' => $transporterCode,
                    'trackAndTrace' => $trackAndTrace,
                ],
            ]);

        $response = $httpResponse->json();

        // Bol can answer a 2xx with an empty or non-JSON body, in which case
        // ->json() is null. Degrade to a structured error so callers can record
        // it instead of crashing on the array return type.
        if (! is_array($response)) {
            return [
                'status' => 'ERROR',
                'errorMessage' => 'Bol gaf een lege of ongeldige respons (HTTP ' . $httpResponse->status() . ')',
            ];
        }

        $linkToPing = $response['links'][0]['href'] ?? null;
        while (($response['status'] ?? null) === 'PENDING' && $linkToPing) {
            sleep(2);
            $polled = Http::withToken($accessToken)
                ->withHeaders([
                    'Accept' => 'application/vnd.retailer.v10+json',
                    'Content-Type' => 'application/vnd.retailer.v10+json',
                ])
                ->get($linkToPing)
                ->json();

            // Stop polling on an empty/non-JSON poll response; keep the last
            // known (PENDING) state, which classifies downstream as an error.
            if (! is_array($polled)) {
                break;
            }

            $response = $polled;
        }

        return $response;
    }

    /** Fulfilmentstatussen waarbij de verzending naar Bol mag. */
    public const SHIPMENT_SYNC_STATUSES = ['shipped', 'handled'];

    private const TRANSPORTERS = [
        'postnl' => 'TNT',
        'postnl_brief' => 'TNT_BRIEF',
        'dhl' => 'DHL',
        'dhlforyou' => 'DHLFORYOU',
        'dpd_nl' => 'DPD-NL',
        'dpd_be' => 'DPD-BE',
        'bpost' => 'BPOST_BE',
        'gls' => 'GLS',
        'ups' => 'UPS',
    ];

    /**
     * Mag de verzending van deze bestelling naar Bol? Alleen een Bol-bestelling
     * die nog niet gemeld is en op Verzonden of Afgehandeld staat. Of er een
     * track & trace is, beoordeelt syncShipment() zelf, met een logregel.
     */
    public static function shouldSyncShipment(Order $order): bool
    {
        return (bool) $order->bol_order_id
            && ! $order->bol_shipment_synced
            && in_array($order->fulfillment_status, self::SHIPMENT_SYNC_STATUSES, true);
    }

    /** Kwartierronde: het vangnet voor een T&T die later binnenkomt dan de status. */
    public static function syncShipments()
    {
        $orders = Order::where('bol_shipment_synced', 0)
            ->whereNotNull('bol_order_id')
            ->whereIn('fulfillment_status', self::SHIPMENT_SYNC_STATUSES)
            ->get();

        foreach ($orders as $order) {
            self::syncShipment($order);
        }
    }

    public static function syncShipment(Order $order)
    {
        // Pas melden als het pakket echt weg is. Een label of track & trace
        // alleen is geen verzending: Bol zag de T&T dagen voordat het pakket
        // de deur uit ging en rekende die dagen als te laat geleverd.
        if (! in_array($order->fulfillment_status, self::SHIPMENT_SYNC_STATUSES, true)) {
            return;
        }

        self::refreshToken($order->site_id);

        $accessToken = Customsetting::get('bol_access_token', $order->site_id);
        if (! $accessToken) {
            self::logShipmentSkip($order, 'geen geldig Bol-accesstoken');

            return;
        }

        // Altijd de nieuwste T&T: bij een nagestuurd pakket is dat de code die telt.
        $trackAndTrace = $order->trackAndTraces()->orderByDesc('id')->first();
        if (! $trackAndTrace) {
            self::logShipmentSkip($order, 'bestelling staat op verzonden maar heeft nog geen track & trace');

            return;
        }

        $transporterCode = self::TRANSPORTERS[strtolower((string) $trackAndTrace->delivery_company)] ?? 'OTHER';
        $logSuffix = ' (track & trace ' . $trackAndTrace->code . ' via ' . $trackAndTrace->delivery_company . ')';

        $bolProducts = self::bolOrderProducts($order);
        if ($bolProducts->isEmpty()) {
            self::logShipmentSkip($order, 'geen orderregels met een Bol-ID');

            return;
        }

        // Per regel een stempel zodra Bol de verzending heeft: een groep gaat
        // hooguit één keer, en een herkansing stuurt alleen wat nog open staat.
        $syncableProducts = $bolProducts->whereNull('bol_shipment_synced_at');
        if ($syncableProducts->isEmpty()) {
            $order->bol_shipment_synced = 1;
            $order->bol_shipment_error = null;
            $order->save();

            return;
        }

        // Mode: split if every open product already has a cached group index, else initial.
        $allGrouped = $syncableProducts->every(fn ($p) => $p->bol_shipment_group_index !== null);

        try {
            if (! $allGrouped) {
                $initialItems = $syncableProducts->map(fn ($p) => [
                    'orderItemId' => $p->bol_id,
                    'quantity' => $p->quantity,
                ])->values()->all();

                $response = self::sendShipmentGroup(
                    $accessToken,
                    $initialItems,
                    $order->invoice_id,
                    $transporterCode,
                    $trackAndTrace->code,
                );

                $classification = self::classifyShipmentResponse($response);

                if ($classification === 'SUCCESS') {
                    self::stampSynced($syncableProducts);
                    $order->bol_shipment_synced = 1;
                    $order->bol_shipment_process_id = $response['processStatusId'] ?? null;
                    $order->bol_shipment_entity_id = $response['entityId'] ?? '';
                    $order->bol_shipment_error = null;
                    $order->save();
                    OrderLog::createLog($order->id, note: 'Verzending gesynchroniseerd met Bol.com voor order ID ' . $order->bol_order_id . $logSuffix);

                    return;
                }

                if ($classification === 'SHIPPED_ALREADY') {
                    self::stampSynced($syncableProducts);
                    $order->bol_shipment_synced = 1;
                    $order->bol_shipment_error = null;
                    $order->save();
                    OrderLog::createLog($order->id, note: 'Order was al verzonden op Bol.com, gemarkeerd als synced: ' . $order->bol_order_id);

                    return;
                }

                // ERROR - attempt to parse split signature.
                $splitGroups = self::parseBolSplitError((string) ($response['errorMessage'] ?? ''));
                if ($splitGroups === null) {
                    $order->bol_shipment_error = $response['errorMessage'] ?? 'Onbekende fout';
                    $order->save();
                    OrderLog::createLog($order->id, note: 'Fout bij synchroniseren van verzending met Bol.com voor order ID ' . $order->bol_order_id . ': ' . $order->bol_shipment_error);

                    return;
                }

                // Persist group indices on each OrderProduct.
                $bolIdToIndex = [];
                foreach ($splitGroups as $index => $bolIds) {
                    foreach ($bolIds as $bolId) {
                        $bolIdToIndex[(string) $bolId] = $index;
                    }
                }
                foreach ($syncableProducts as $product) {
                    $key = (string) $product->bol_id;
                    if (array_key_exists($key, $bolIdToIndex)) {
                        $product->bol_shipment_group_index = $bolIdToIndex[$key];
                        $product->save();
                    }
                }

                $syncableProducts = self::bolOrderProducts($order)->whereNull('bol_shipment_synced_at');
            }

            // Split mode: send one shipment per open group.
            $groups = $syncableProducts
                ->filter(fn ($p) => $p->bol_shipment_group_index !== null)
                ->groupBy('bol_shipment_group_index');

            $errors = [];
            $lastProcessId = null;
            $lastEntityId = null;

            foreach ($groups as $groupIndex => $products) {
                $items = $products->map(fn ($p) => [
                    'orderItemId' => $p->bol_id,
                    'quantity' => $p->quantity,
                ])->values()->all();

                $response = self::sendShipmentGroup(
                    $accessToken,
                    $items,
                    $order->invoice_id,
                    $transporterCode,
                    $trackAndTrace->code,
                );

                $classification = self::classifyShipmentResponse($response);

                if ($classification === 'SUCCESS') {
                    self::stampSynced($products);
                    $lastProcessId = $response['processStatusId'] ?? $lastProcessId;
                    $lastEntityId = $response['entityId'] ?? $lastEntityId;
                    OrderLog::createLog($order->id, note: 'Verzending (groep ' . $groupIndex . ') gesynchroniseerd met Bol.com voor order ID ' . $order->bol_order_id . $logSuffix);
                } elseif ($classification === 'SHIPPED_ALREADY') {
                    self::stampSynced($products);
                    OrderLog::createLog($order->id, note: 'Groep ' . $groupIndex . ' was al verzonden op Bol.com, overgeslagen voor order ID ' . $order->bol_order_id);
                } else {
                    $msg = 'groep ' . $groupIndex . ': ' . ($response['errorMessage'] ?? 'Onbekende fout');
                    $errors[] = $msg;
                    OrderLog::createLog($order->id, note: 'Fout bij synchroniseren van verzending (groep ' . $groupIndex . ') met Bol.com voor order ID ' . $order->bol_order_id . ': ' . ($response['errorMessage'] ?? 'Onbekende fout'));
                }
            }

            if (empty($errors)) {
                $order->bol_shipment_synced = 1;
                $order->bol_shipment_error = null;
                if ($lastProcessId !== null) {
                    $order->bol_shipment_process_id = $lastProcessId;
                }
                if ($lastEntityId !== null) {
                    $order->bol_shipment_entity_id = $lastEntityId;
                }
                $order->save();
            } else {
                $order->bol_shipment_synced = 0;
                $order->bol_shipment_error = implode('; ', $errors);
                $order->save();
            }
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $order->bol_shipment_error = $e->getMessage();
            $order->save();
            OrderLog::createLog($order->id, note: 'Fout bij synchroniseren van verzending met Bol.com voor order ID ' . $order->bol_order_id . ': ' . $order->bol_shipment_error);
        }
    }

    /** Vers uit de database, want de stempels en groepsindices van zojuist moeten meetellen. */
    private static function bolOrderProducts(Order $order): \Illuminate\Support\Collection
    {
        return $order->orderProducts()->get()->filter(fn ($p) => (bool) $p->bol_id)->values();
    }

    private static function stampSynced(\Illuminate\Support\Collection $products): void
    {
        foreach ($products as $product) {
            $product->bol_shipment_synced_at = now();
            $product->save();
        }
    }

    /**
     * Record why an order was not synced to Bol: store the reason on the order and
     * write an OrderLog. Only logs when the reason changed since the previous
     * attempt, so the 15-minute sync does not flood the log with duplicates.
     */
    private static function logShipmentSkip(Order $order, string $reason): void
    {
        if ($order->bol_shipment_error === $reason) {
            return;
        }

        $order->bol_shipment_error = $reason;
        $order->save();

        OrderLog::createLog($order->id, note: 'Niet naar Bol.com gesynchroniseerd voor order ID ' . $order->bol_order_id . ': ' . $reason);
    }

    /**
     * Een Bol-order met voorraad- én pre-orderregels: waarschuw in de tijdlijn
     * met per regel de leverdatum die Bol de klant beloofde, zodat de
     * voorraadregels niet stilzwijgend meewachten op de pre-order.
     *
     * @param  array<int, array<string, mixed>>  $orderItems  De orderItems uit het Bol-antwoord.
     */
    public static function logMixedOrderNotice(Order $order, array $orderItems): void
    {
        $lines = self::bolOrderProducts($order);

        $hasPreOrder = $lines->contains(fn ($line) => (bool) $line->is_pre_order);
        $hasStock = $lines->contains(fn ($line) => ! $line->is_pre_order);
        if (! $hasPreOrder || ! $hasStock) {
            return;
        }

        $promised = collect($orderItems)->mapWithKeys(fn ($item) => [
            (string) ($item['orderItemId'] ?? '') => $item['fulfilment']['latestDeliveryDate'] ?? null,
        ]);

        $format = fn (?string $date) => $date ? Carbon::parse($date)->format('d-m-Y') : 'onbekend';

        $parts = $lines->map(function ($line) use ($promised, $format) {
            $text = $line->name . ' (' . $line->quantity . 'x): ';
            $deadline = $format($promised[(string) $line->bol_id] ?? null);

            if ($line->is_pre_order) {
                $text .= 'pre-order';
                if ($line->pre_order_restocked_date) {
                    $text .= ', verwacht ' . $format((string) $line->pre_order_restocked_date);
                }

                return $text . ', Bol belooft uiterlijk ' . $deadline;
            }

            return $text . 'voorraad, uiterlijk ' . $deadline;
        });

        OrderLog::createLog(
            $order->id,
            'order.bol.mixed-order',
            'Let op: gemengde Bol-order met voorraad- en pre-orderregels. Verstuur de voorraadregels op tijd en laat ze niet wachten op de pre-order. Per regel: ' . $parts->implode('; ') . '.',
        );
    }
}
