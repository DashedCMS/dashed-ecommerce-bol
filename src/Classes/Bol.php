<?php

namespace Dashed\DashedEcommerceBol\Classes;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Dashed\DashedCore\Classes\Sites;
use Illuminate\Support\Facades\Http;
use Dashed\DashedCore\Classes\Locales;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Classes\OssVat;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceCore\Models\OrderLog;
use Illuminate\Http\Client\ConnectionException;
use Dashed\DashedCore\Notifications\AdminNotifier;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
use Dashed\DashedEcommerceCore\Models\OrderProduct;
use Dashed\DashedEcommerceBol\Mail\AdminBolShipmentAbandonedMail;

class Bol
{
    public const APIURL = 'https://api.bol.com';
    public const LOGINURL = 'https://login.bol.com';

    // Pauze tussen opeenvolgende order-requests, zodat we de Bol-API niet in
    // één burst overspoelen (minder kans op rate-limits / 504 gateway timeouts).
    public const REQUEST_THROTTLE_MICROSECONDS = 300000;

    /**
     * Meldt een gelukte of mislukte synchronisatie aan het scherm Koppelingen
     * van dashed-core. Een oudere dashed-core kent dat logboek niet.
     */
    private static function reportSync(bool $ok, ?string $message, ?string $siteId): void
    {
        if (class_exists(\Dashed\DashedCore\Integrations\IntegrationEvents::class)) {
            \Dashed\DashedCore\Integrations\IntegrationEvents::record('bol', $ok, $message, $siteId);
        }
    }

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
                    self::reportSync(false, 'Bestellingen ophalen: ' . $e->getMessage(), $siteId);
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

            self::reportSync(true, count($bolOrders) . ' bestellingen opgehaald', $siteId);

            return $bolOrders;
        }

        // Zonder ingestelde koppeling is er niets misgegaan, alleen niets te doen.
        if (Customsetting::get('bol_client_id', $siteId)) {
            self::reportSync(false, 'Geen toegangstoken: ' . (Customsetting::get('bol_connection_error', $siteId) ?: 'onbekende fout'), $siteId);
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
            if (Customsetting::get('bol_orders_priority', $siteId, 0)) {
                $order->is_priority = true;
            }
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
     * @return 'SUCCESS'|'SHIPPED_ALREADY'|'CANCELLED'|'ERROR'
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
        if (str_contains($errorMessage, 'cancelled already')) {
            return 'CANCELLED';
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
        ?string $trackAndTrace
    ): array {
        $transport = ['transporterCode' => $transporterCode];
        if ($trackAndTrace !== null && $trackAndTrace !== '') {
            $transport['trackAndTrace'] = $trackAndTrace;
        }

        $httpResponse = Http::withToken($accessToken)
            ->withHeaders([
                'Accept' => 'application/vnd.retailer.v10+json',
                'Content-Type' => 'application/vnd.retailer.v10+json',
            ])
            ->retry(3)
            ->post(self::APIURL . '/retailer/shipments', [
                'orderItems' => $orderItems,
                'shipmentReference' => $shipmentReference,
                'transport' => $transport,
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

    /**
     * Herkansen van een mislukte melding: oplopende tussenpozen in minuten
     * (daarna elke dag), en na dit aantal pogingen opgeven met een melding
     * aan de beheerders. Vier bestellingen bij Lovora kregen elk ruim
     * tienduizend identieke foutregels omdat de kwartierronde eindeloos
     * doorging op regels die Bol al geannuleerd had.
     */
    public const SHIPMENT_MAX_ATTEMPTS = 12;

    public const SHIPMENT_BACKOFF_MINUTES = [15, 30, 60, 120, 240, 480, 960, 1440];

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
     * die nog niet gemeld is, niet is opgegeven en op Verzonden of Afgehandeld
     * staat. Zonder track & trace gaat de melding als briefpost, zie
     * BolTransporter.
     */
    public static function shouldSyncShipment(Order $order): bool
    {
        return (bool) $order->bol_order_id
            && ! $order->bol_shipment_synced
            && ! $order->bol_shipment_abandoned_at
            && in_array($order->fulfillment_status, self::SHIPMENT_SYNC_STATUSES, true);
    }

    /**
     * Kwartierronde: het vangnet voor een T&T die later binnenkomt dan de
     * status, en de herkansing van mislukte meldingen zodra hun tussenpoos
     * voorbij is.
     */
    public static function syncShipments()
    {
        $orders = Order::where('bol_shipment_synced', 0)
            ->whereNotNull('bol_order_id')
            ->whereNull('bol_shipment_abandoned_at')
            ->whereIn('fulfillment_status', self::SHIPMENT_SYNC_STATUSES)
            ->where(function ($query) {
                $query->whereNull('bol_shipment_next_attempt_at')
                    ->orWhere('bol_shipment_next_attempt_at', '<=', now());
            })
            ->get();

        foreach ($orders as $order) {
            self::syncShipment($order);
        }
    }

    /** Teller en opgave terugzetten, voor een handmatige nieuwe poging. */
    public static function resetShipmentRetries(Order $order): void
    {
        $order->bol_shipment_attempts = 0;
        $order->bol_shipment_next_attempt_at = null;
        $order->bol_shipment_abandoned_at = null;
        $order->save();
    }

    /**
     * @param  bool  $retryAfterCancelled  Na het wegstempelen van regels die Bol al
     *                                     annuleerde één keer opnieuw proberen met de
     *                                     rest; de recursieve aanroep zet dit op false
     *                                     zodat het nooit rondloopt.
     */
    public static function syncShipment(Order $order, bool $retryAfterCancelled = true)
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

        // Altijd de nieuwste T&T: bij een nagestuurd pakket is dat de code die
        // telt. Zonder T&T (briefpost, of een status met de hand gezet) gaat de
        // melding tóch, met alleen een transporteur: Bol staat dat toe, en een
        // bestelling die bij Bol op "niet verzonden" blijft staan kost een strike.
        $trackAndTrace = $order->trackAndTraces()->orderByDesc('id')->first();
        if ($trackAndTrace) {
            $transporterCode = self::TRANSPORTERS[strtolower((string) $trackAndTrace->delivery_company)] ?? 'OTHER';
            $trackAndTraceCode = (string) $trackAndTrace->code;
            $logSuffix = ' (track & trace ' . $trackAndTrace->code . ' via ' . $trackAndTrace->delivery_company . ')';
        } else {
            $transporterCode = BolTransporter::withoutTrackAndTrace($order->site_id);
            $trackAndTraceCode = null;
            $logSuffix = ' (zonder track & trace, als ' . $transporterCode . ')';
        }

        $bolProducts = self::bolOrderProducts($order);
        if ($bolProducts->isEmpty()) {
            self::logShipmentSkip($order, 'geen orderregels met een Bol-ID');

            return;
        }

        // Per regel een stempel zodra Bol de verzending heeft: een groep gaat
        // hooguit één keer, en een herkansing stuurt alleen wat nog open staat.
        $syncableProducts = $bolProducts->whereNull('bol_shipment_synced_at');
        if ($syncableProducts->isEmpty()) {
            self::markShipmentSynced($order);

            return;
        }

        // Mode: split if every open product already has a cached group index, else initial.
        $allGrouped = $syncableProducts->every(fn ($p) => $p->bol_shipment_group_index !== null);

        try {
            if (! $allGrouped) {
                $response = self::sendShipmentGroup(
                    $accessToken,
                    self::shipmentItems($syncableProducts),
                    $order->invoice_id,
                    $transporterCode,
                    $trackAndTraceCode,
                );

                $classification = self::classifyShipmentResponse($response);

                if ($classification === 'SUCCESS') {
                    self::stampSynced($syncableProducts);
                    self::markShipmentSynced($order, $response);
                    OrderLog::createLog($order->id, note: 'Verzending gesynchroniseerd met Bol.com voor order ID ' . $order->bol_order_id . $logSuffix);

                    return;
                }

                if ($classification === 'SHIPPED_ALREADY') {
                    self::stampSynced($syncableProducts);
                    self::markShipmentSynced($order);
                    OrderLog::createLog($order->id, note: 'Order was al verzonden op Bol.com, gemarkeerd als synced: ' . $order->bol_order_id);

                    return;
                }

                if ($classification === 'CANCELLED') {
                    self::stampCancelled($order, $syncableProducts, (string) ($response['errorMessage'] ?? ''));
                    if ($retryAfterCancelled) {
                        self::syncShipment($order->fresh(), false);
                    } else {
                        self::registerShipmentFailure($order, (string) ($response['errorMessage'] ?? 'Onbekende fout'));
                    }

                    return;
                }

                // ERROR - attempt to parse split signature.
                $splitGroups = self::parseBolSplitError((string) ($response['errorMessage'] ?? ''));
                if ($splitGroups === null) {
                    self::registerShipmentFailure($order, (string) ($response['errorMessage'] ?? 'Onbekende fout'));

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
            $cancelledSeen = false;
            $lastResponse = [];

            foreach ($groups as $groupIndex => $products) {
                $response = self::sendShipmentGroup(
                    $accessToken,
                    self::shipmentItems($products),
                    $order->invoice_id,
                    $transporterCode,
                    $trackAndTraceCode,
                );

                $classification = self::classifyShipmentResponse($response);

                if ($classification === 'SUCCESS') {
                    self::stampSynced($products);
                    $lastResponse = $response;
                    OrderLog::createLog($order->id, note: 'Verzending (groep ' . $groupIndex . ') gesynchroniseerd met Bol.com voor order ID ' . $order->bol_order_id . $logSuffix);
                } elseif ($classification === 'SHIPPED_ALREADY') {
                    self::stampSynced($products);
                    OrderLog::createLog($order->id, note: 'Groep ' . $groupIndex . ' was al verzonden op Bol.com, overgeslagen voor order ID ' . $order->bol_order_id);
                } elseif ($classification === 'CANCELLED') {
                    self::stampCancelled($order, $products, (string) ($response['errorMessage'] ?? ''));
                    $cancelledSeen = true;
                } else {
                    $errors[] = 'groep ' . $groupIndex . ': ' . ($response['errorMessage'] ?? 'Onbekende fout');
                }
            }

            if ($errors !== []) {
                self::registerShipmentFailure($order, implode('; ', $errors));

                return;
            }

            if (self::bolOrderProducts($order)->whereNull('bol_shipment_synced_at')->isEmpty()) {
                self::markShipmentSynced($order, $lastResponse);

                return;
            }

            // Binnen een groep bleef iets open na een annulering: de rest nog één keer.
            if ($cancelledSeen && $retryAfterCancelled) {
                self::syncShipment($order->fresh(), false);
            }
        } catch (\Illuminate\Http\Client\RequestException $e) {
            self::registerShipmentFailure($order, $e->getMessage());
        }
    }

    /** @return array<int, array{orderItemId: string, quantity: int}> */
    private static function shipmentItems(\Illuminate\Support\Collection $products): array
    {
        return $products->map(fn ($p) => [
            'orderItemId' => $p->bol_id,
            'quantity' => $p->quantity,
        ])->values()->all();
    }

    /** Alles gemeld: vlag om, foutstaat en herkansingsteller leeg. */
    private static function markShipmentSynced(Order $order, array $response = []): void
    {
        self::reportSync(true, 'Verzending gemeld voor order ' . $order->bol_order_id, $order->site_id);

        $order->bol_shipment_synced = 1;
        $order->bol_shipment_error = null;
        $order->bol_shipment_attempts = 0;
        $order->bol_shipment_next_attempt_at = null;
        $order->bol_shipment_abandoned_at = null;
        if (isset($response['processStatusId'])) {
            $order->bol_shipment_process_id = $response['processStatusId'];
        }
        if (isset($response['entityId'])) {
            $order->bol_shipment_entity_id = $response['entityId'];
        }
        $order->save();
    }

    /**
     * Een mislukte poging vastleggen: de fout komt één keer in de tijdlijn
     * (alleen als hij anders is dan de vorige), de volgende poging schuift op
     * met oplopende tussenpoos, en na het maximum wordt opgegeven met één
     * logregel en een mail aan de beheerders.
     */
    private static function registerShipmentFailure(Order $order, string $message): void
    {
        self::reportSync(false, 'Verzending order ' . $order->bol_order_id . ': ' . $message, $order->site_id);

        $attempts = (int) $order->bol_shipment_attempts + 1;
        $changed = $order->bol_shipment_error !== $message;

        $order->bol_shipment_synced = 0;
        $order->bol_shipment_error = $message;
        $order->bol_shipment_attempts = $attempts;

        if ($attempts >= self::SHIPMENT_MAX_ATTEMPTS) {
            $order->bol_shipment_next_attempt_at = null;
            $order->bol_shipment_abandoned_at = now();
            $order->save();

            OrderLog::createLog($order->id, 'order.bol-shipment-abandoned', 'Melden van de verzending aan Bol.com opgegeven na ' . $attempts . ' pogingen voor order ID ' . $order->bol_order_id . ': ' . $message . '. Opnieuw proberen met bol:sync-shipments-to-bol --order=' . $order->id);

            $recipients = BolAdminRecipients::for($order->site_id);
            if ($recipients !== []) {
                rescue(fn () => AdminNotifier::send(new AdminBolShipmentAbandonedMail($order, $message, $attempts), $recipients), null, true);
            }

            return;
        }

        $minutes = self::SHIPMENT_BACKOFF_MINUTES[min($attempts, count(self::SHIPMENT_BACKOFF_MINUTES)) - 1];
        $order->bol_shipment_next_attempt_at = now()->addMinutes($minutes);
        $order->save();

        if ($changed) {
            OrderLog::createLog($order->id, note: 'Fout bij synchroniseren van verzending met Bol.com voor order ID ' . $order->bol_order_id . ': ' . $message);
        }
    }

    /**
     * Regels die Bol al geannuleerd heeft tellen als afgehandeld: ze kunnen
     * nooit meer als verzonden gemeld worden, en zonder stempel bood de
     * kwartierronde ze eindeloos opnieuw aan. Noemt Bol regelnummers, dan
     * alleen die; anders alle regels van dit verzoek.
     */
    private static function stampCancelled(Order $order, \Illuminate\Support\Collection $products, string $message): void
    {
        $ids = self::parseCancelledItemIds($message);
        $affected = $ids === [] ? $products : $products->filter(fn ($p) => in_array((string) $p->bol_id, $ids, true));
        if ($affected->isEmpty()) {
            $affected = $products;
        }

        self::stampSynced($affected);

        OrderLog::createLog($order->id, note: 'Regel(s) ' . $affected->pluck('bol_id')->implode(', ') . ' zijn al geannuleerd bij Bol.com en worden niet als verzonden gemeld voor order ID ' . $order->bol_order_id);
    }

    /** @return array<int, string> */
    private static function parseCancelledItemIds(string $message): array
    {
        $after = (string) strstr($message, 'cancelled already');
        preg_match_all('/\d+/', $after, $matches);

        return array_values(array_unique($matches[0]));
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
