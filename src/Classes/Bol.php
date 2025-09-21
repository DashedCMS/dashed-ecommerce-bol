<?php

namespace Dashed\DashedEcommerceBol\Classes;

use Dashed\DashedCore\Classes\Sites;
use Illuminate\Support\Facades\Http;
use Dashed\DashedCore\Classes\Locales;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceCore\Models\OrderLog;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
use Dashed\DashedEcommerceCore\Models\OrderProduct;

class Bol
{
    public const APIURL = 'https://api.bol.com';
    public const LOGINURL = 'https://login.bol.com';

    public static function isConnected(?string $siteId = null): bool
    {
        if (!$siteId) {
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
        if (!$siteId) {
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

                    return;
                }
            }
        } catch (\Exception $e) {
            Customsetting::set('bol_connected', 0, $siteId);
            Customsetting::set('bol_connection_error', $e->getMessage(), $siteId);
            Customsetting::set('bol_access_token', null, $siteId);

            return;
        }

        Customsetting::set('bol_connected', 0, $siteId);
        Customsetting::set('bol_connection_error', 'error', $siteId);
        Customsetting::set('bol_access_token', null, $siteId);
    }

    public static function syncOrders($siteId = null)
    {
        if (!$siteId) {
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
            }

            foreach ($bolOrders as $bolOrder) {
                self::syncOrder($bolOrder);
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
            $response = Http::withToken($accessToken)
                ->accept('application/vnd.retailer.v10+json')
                ->retry(3)
                ->get(self::APIURL . '/retailer/orders/' . $bolOrder['orderId'])
                ->json();

            $order = Order::where('bol_order_id', $bolOrder['orderId'])->first();
            if (!$order) {
                $order = new Order();
                $order->bol_order_id = $bolOrder['orderId'];
                $order->bol_order_commission = collect($response['orderItems'])->sum(fn($item) => $item['commission'] * $item['quantity']);
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
            $order->total = collect($response['orderItems'])->sum(fn($item) => $item['unitPrice'] * $item['quantity']);
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

            foreach ($response['orderItems'] as $orderItem) {
                $orderProduct = OrderProduct::where('bol_id', $orderItem['orderItemId'])->where('order_id', $order->id)->first();
                if (!$orderProduct) {
                    $orderProduct = new OrderProduct();
                    $orderProduct->bol_id = $orderItem['orderItemId'];
                }

                $product = Product::where('ean', $orderItem['product']['ean'])->first();
                if ($product) {
                    $orderProduct->product_id = $product->id;
                    $orderProduct->sku = $product->sku;
                } else {
                    $orderProduct->product_id = null;
                }

                $orderProduct->name = $orderItem['product']['title'];
                $orderProduct->quantity = $orderItem['quantity'];
                $orderProduct->price = $orderItem['unitPrice'] * $orderItem['quantity'];
                $orderProduct->discount = 0;
                $orderProduct->order_id = $order->id;
                $orderProduct->btw = $orderProduct->price / 121 * 21;
                $orderProduct->vat_rate = 21;
                $orderProduct->save();
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

    public static function syncShipments()
    {
        foreach (Order::where('bol_shipment_synced', 0)->whereNotNull('bol_order_id')->get() as $order) {
            self::syncShipment($order);
        }
    }

    public static function syncShipment(Order $order)
    {
        self::refreshToken($order->site_id);

        $accessToken = Customsetting::get('bol_access_token', $order->site_id);
        if ($accessToken && $order->trackAndTraces->count()) {
            $trackAndTrace = $order->trackAndTraces->last();

            $orderProducts = [];
            foreach ($order->orderProducts as $orderProduct) {
                if ($orderProduct->bol_id) {
                    $orderProducts[] = [
                        'orderItemId' => $orderProduct->bol_id,
                        'quantity' => $orderProduct->quantity,
                    ];
                }
            }

            $transporters = [
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

            $deliveryKey = strtolower($trackAndTrace->delivery_company);
            $transporterCode = $transporters[$deliveryKey] ?? 'OTHER';

            try {
                $response = Http::withToken($accessToken)
                    ->withHeaders([
                        'Accept' => 'application/vnd.retailer.v10+json',
                        'Content-Type' => 'application/vnd.retailer.v10+json',
                    ])
                    ->retry(3)
                    ->post(self::APIURL . '/retailer/shipments', [
                        'orderItems' => $orderProducts,
                        'shipmentReference' => $order->invoice_id,
//                        'shippingLabelId' => $trackAndTrace->order_id . '-' . $trackAndTrace->id,
                        'transport' => [
                            'transporterCode' => $transporterCode,
                            'trackAndTrace' => $trackAndTrace->code,
                        ],
                    ])
                    ->json();

                $linkToPing = $response['links'][0]['href'] ?? false;
                while ($response['status'] == 'PENDING') {
                    sleep(2);
                    $response = Http::withToken($accessToken)
                        ->withHeaders([
                            'Accept' => 'application/vnd.retailer.v10+json',
                            'Content-Type' => 'application/vnd.retailer.v10+json',
                        ])
//                        ->retry(3)
                        ->get($linkToPing)
//                        ->get(self::APIURL . '/retailer/process-status/' . $response['processStatusId'])
                        ->json();
                }

                //                dump($response);
                //                sleep(5);
                //                $response = Http::withToken($accessToken)
                //                    ->withHeaders([
                //                        'Accept' => 'application/vnd.retailer.v10+json',
                //                        'Content-Type' => 'application/vnd.retailer.v10+json',
                //                    ])
                //                    ->retry(3)
                //                        ->get($response['links'][0]['href'])
                //                    ->json();
                //                dd($response);

                if ($response['status'] == 'SUCCESS') {
                    $order->bol_shipment_synced = 1;
                    $order->bol_shipment_process_id = $response['processStatusId'];
                    $order->bol_shipment_entity_id = $response['entityId'] ?? '';
                    $order->bol_shipment_error = null;
                    $order->save();

                    OrderLog::createLog($order->id, note: 'Verzending gesynchroniseerd met Bol.com voor order ID ' . $order->bol_order_id);
                } else {
                    $order->bol_shipment_error = $response['errorMessage'] ?? 'Onbekende fout';
                    $order->save();

                    OrderLog::createLog($order->id, note: 'Fout bij synchroniseren van verzending met Bol.com voor order ID ' . $order->bol_order_id . ': ' . $order->bol_shipment_error);

                    if (str($order->bol_shipment_error)->contains('shipped already')) {
                        $order->bol_shipment_synced = 1;
                        $order->bol_shipment_error = null;
                        $order->save();
                    }
                }
            } catch (\Illuminate\Http\Client\RequestException $e) {
                $order->bol_shipment_error = $e->getMessage();
                $order->save();

                OrderLog::createLog($order->id, note: 'Fout bij synchroniseren van verzending met Bol.com voor order ID ' . $order->bol_order_id . ': ' . $order->bol_shipment_error);
            }

        }
    }

    public static function updateShipment(Order $order)
    {
        self::refreshToken($order->site_id);

        $accessToken = Customsetting::get('bol_access_token', $order->site_id);
        if ($accessToken && $order->trackAndTraces->count()) {
            $trackAndTrace = $order->trackAndTraces->last();

            $transporters = [
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

            $deliveryKey = strtolower($trackAndTrace->delivery_company);
            $transporterCode = $transporters[$deliveryKey] ?? 'OTHER';

            dump($trackAndTrace->code);

            try {
                //                $response = Http::withToken($accessToken)
                //                    ->withHeaders([
                //                        'Accept' => 'application/vnd.retailer.v10+json',
                //                        'Content-Type' => 'application/vnd.retailer.v10+json',
                //                    ])
                //                    ->retry(3)
                //                    ->put(self::APIURL . '/retailer/transports/' . $order->bol_shipment_process_id, [
                //                        'transporterCode' => $transporterCode,
                //                        'trackAndTrace' => $trackAndTrace->code
                //                    ])
                //                ->json();
                //                dump($response);
                //

                while ($response['status'] == 'PENDING') {
                    sleep(2);
                    $response = Http::withToken($accessToken)
                        ->withHeaders([
                            'Accept' => 'application/vnd.retailer.v10+json',
                            'Content-Type' => 'application/vnd.retailer.v10+json',
                        ])
                        ->retry(3)
                        ->get(self::APIURL . '/retailer/process-status/' . $order->bol_shipment_process_id)
                        ->json();
                    dump($response);
                }

                //                if($response['links'][0]['href'] ?? false){
                //                    dump($response['links'][0]['href']);
                $response = Http::withToken($accessToken)
                    ->withHeaders([
                        'Accept' => 'application/vnd.retailer.v10+json',
                        'Content-Type' => 'application/vnd.retailer.v10+json',
                    ])
                    ->retry(3)
                    ->get('https://api.bol.com/shared/process-status/144982631737')
//                        ->get($response['links'][0]['href'])
                    ->json();
                dd($response, 'test');
                //                }
                dd($response);

                if ($response['status'] == 'SUCCESS') {
                    $order->bol_shipment_synced = 1;
                    $order->bol_shipment_process_id = $response['processStatusId'];
                    $order->bol_shipment_entity_id = $response['entityId'];
                    $order->save();

                    OrderLog::createLog($order->id, note: 'Verzending gesynchroniseerd met Bol.com voor order ID ' . $order->bol_order_id);
                } else {
                    $order->bol_shipment_error = $response['errorMessage'] ?? 'Onbekende fout';
                    $order->save();

                    OrderLog::createLog($order->id, note: 'Fout bij synchroniseren van verzending met Bol.com voor order ID ' . $order->bol_order_id . ': ' . $order->bol_shipment_error);
                }
            } catch (\Illuminate\Http\Client\RequestException $e) {
                dd($e);
                $order->bol_shipment_error = $e->getMessage();
                $order->save();

                OrderLog::createLog($order->id, note: 'Fout bij synchroniseren van verzending met Bol.com voor order ID ' . $order->bol_order_id . ': ' . $order->bol_shipment_error);
            }

        }
    }
}
