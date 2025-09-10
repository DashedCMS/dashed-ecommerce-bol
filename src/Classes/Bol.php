<?php

namespace Dashed\DashedEcommerceBol\Classes;

use Dashed\DashedCore\Classes\Locales;
use Exception;
use Dashed\DashedCore\Classes\Sites;
use Illuminate\Support\Facades\Http;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceBol\Models\BolOrder;
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

                if (isset($response['orders'])) {
                    $bolOrders = array_merge($bolOrders, $response['orders']);
                    $bolOrdersResultCount = count($response['orders']);
                    $page++;
                }
            }

            foreach ($bolOrders as $bolOrder) {
                $bolOrderConnection = BolOrder::where('bol_id', $bolOrder['orderId'])->first();
                if (!$bolOrderConnection) {
                    $bolOrderConnection = new BolOrder();
                    $bolOrderConnection->bol_id = $bolOrder['orderId'];
                    $bolOrderConnection->save();
                }
            }
            return $bolOrders;
        }

        return [];
    }

    public static function syncOrder(BolOrder $bolOrder): void
    {
        $siteId = Sites::getActive();

        self::refreshToken($siteId);

        $accessToken = Customsetting::get('bol_access_token', $siteId);
        if ($accessToken) {
            $response = Http::withToken($accessToken)
                ->accept('application/vnd.retailer.v10+json')
                ->retry(3)
                ->get(self::APIURL . '/retailer/orders/' . $bolOrder->bol_id)
                ->json();

            $bolOrder->commission = collect($response['orderItems'])->sum(fn($item) => $item['commission'] * $item['quantity']);
            $bolOrder->save();

            $order = new Order();
            $order->first_name = $response['shipmentDetails']['firstName'];
            $order->last_name = $response['shipmentDetails']['surname'];
            $order->email = $response['shipmentDetails']['email'];
            $order->street = $response['shipmentDetails']['streetName'];
            $order->house_nr = $response['shipmentDetails']['houseNumber'] . (isset($response['shipmentDetails']['houseNumberExtension']) && $response['shipmentDetails']['houseNumberExtension'] ? ' ' . $response['shipmentDetails']['houseNumberExtension'] : '');
            $order->zip_code = $response['shipmentDetails']['zipCode'];
            $order->city = $response['shipmentDetails']['city'];
            $order->country = $response['shipmentDetails']['countryCode'];
            $order->company_name = $response['shipmentDetails']['company'];
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
            $order->order_origin = 'Bol.com';
            $order->site_id = $siteId;
            $order->gender = $response['shipmentDetails']['salutation'];
            $order->locale = Locales::getFirstLocale()['id'];
            $order->invoice_id = 'PROFORMA';
            $order->save();

            $bolOrder->order_id = $order->id;
            $bolOrder->save();

            foreach($response['orderItems'] as $orderItem){
                $orderProduct = OrderProduct::where('bol_id', $orderItem['orderItemId'])->where('order_id', $order->id)->first();
                if(!$orderProduct){
                    $orderProduct = new OrderProduct();
                    $orderProduct->bol_id = $orderItem['orderItemId'];
                }

                $product = Product::where('ean', $orderItem['product']['ean'])->first();
                if($product){
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

            $order->changeStatus('paid');
        }
    }

    //    public static function getAllOrders($siteId = null)
    //    {
    //        if (! $siteId) {
    //            $siteId = Sites::getActive();
    //        }
    //
    //        $orderDatas = self::getOrders();
    //        foreach ($orderDatas as $orderData) {
    //            $bolOrder = BolOrder::where('bol_id', $orderData['id'])->first();
    //            if ($bolOrder && ! $bolOrder->order) {
    //                $bolOrder->delete();
    //                $bolOrder = null;
    //            }
    //
    //            if (! $bolOrder) {
    //                self::saveNewOrder($orderData, $siteId);
    //            }
    //        }
    //
    ////        $bolOrders = Order::whereNotNull('bol_order_connection_id')->get();
    ////        foreach ($bolOrders as $bolOrder) {
    ////            $orderStillExistsInBol = false;
    ////
    ////            foreach ($orderDatas as $orderData) {
    ////                if ($bolOrder->bolOrderConnection->channel_id == $orderData['channel_id']) {
    ////                    $orderStillExistsInBol = true;
    ////                }
    ////                if ($orderData['channel_id'] == 1230442136) {
    ////                    dd($orderData);
    ////                }
    ////            }
    ////
    ////            if (!$orderStillExistsInBol) {
    ////                dd('kut', $bolOrder);
    ////            }
    ////        }
    //    }

    public
    static function saveNewOrder($orderData, $siteId = null)
    {
        if (!$siteId) {
            $siteId = Sites::getActive();
        }

        $order = new Order();
        $order->first_name = $orderData['data']['shipping']['first_name'];
        $order->last_name = $orderData['data']['shipping']['last_name'];
        $order->initials = $order->first_name ? strtoupper($order->first_name[0]) . '.' : '';
        $order->gender = $orderData['data']['customer']['gender'] ? $orderData['data']['customer']['gender'][0] : '';
        $order->email = $orderData['data']['customer']['email'];
        $order->phone_number = $orderData['data']['customer']['phone'];
        $order->street = $orderData['data']['shipping']['street'];
        $order->house_nr = $orderData['data']['shipping']['house_number'] . ($orderData['data']['shipping']['house_number_ext'] ? ' ' . $orderData['data']['shipping']['house_number_ext'] : '');
        $order->zip_code = $orderData['data']['shipping']['zip_code'];
        $order->city = $orderData['data']['shipping']['city'];
        $order->country = $orderData['data']['shipping']['country_code'];
        $order->company_name = $orderData['data']['shipping']['company'];
        $order->invoice_first_name = $orderData['data']['billing']['first_name'];
        $order->invoice_last_name = $orderData['data']['billing']['last_name'];
        $order->invoice_street = $orderData['data']['billing']['street'];
        $order->invoice_house_nr = $orderData['data']['billing']['house_number'] . ($orderData['data']['billing']['house_number_ext'] ? ' ' . $orderData['data']['billing']['house_number_ext'] : '');
        $order->invoice_zip_code = $orderData['data']['billing']['zip_code'];
        $order->invoice_city = $orderData['data']['billing']['city'];
        $order->invoice_country = $orderData['data']['billing']['country_code'];
        $order->invoice_id = strtoupper($orderData['channel_name'] . '-' . $orderData['channel_id']);
        $order->order_origin = $orderData['channel_name'];

        $order->total = $orderData['data']['price']['total'];
        $order->subtotal = $orderData['data']['price']['subtotal'];
        $order->discount = $orderData['data']['price']['discount'];
        $order->fulfillment_status = $orderData['status_shipped'] != 'shipped' ? 'unhandled' : $orderData['status_shipped'];
        $order->save();

        $bolOrder = new BolOrder();
        $bolOrder->order_id = $order->id;
        $bolOrder->bol_id = $orderData['id'];
        $bolOrder->project_id = $orderData['project_id'];
        $bolOrder->platform_id = $orderData['platform_id'];
        $bolOrder->platform_name = $orderData['platform_name'];
        $bolOrder->channel_id = $orderData['channel_id'];
        $bolOrder->channel_name = $orderData['channel_name'];
        $bolOrder->status_paid = $orderData['status_paid'];
        $bolOrder->status_shipped = $orderData['status_shipped'];
        $bolOrder->tracking_code = $orderData['tracking_code'];
        $bolOrder->tracking_original = $orderData['tracking_original'];
        $bolOrder->transporter = $orderData['transporter'];
        $bolOrder->transporter_original = $orderData['transporter_original'];
        $bolOrder->status_paid = $orderData['id'];
        $bolOrder->status_shipped = $orderData['id'];
        $bolOrder->commission = $orderData['data']['price']['commission'];
        $bolOrder->save();

        foreach ($orderData['data']['products'] as $product) {
            $thisProduct = Product::publicShowable()->where('ean', $product['ean'])->first();
            $orderProduct = new OrderProduct();
            $orderProduct->quantity = $product['quantity'];
            $orderProduct->product_id = $thisProduct->id ?? null;
            $orderProduct->order_id = $order->id;
            $orderProduct->name = $thisProduct->name ?? $product['title'];
            $orderProduct->price = $product['price'] * $orderProduct->quantity;
            $orderProduct->discount = $product['discount'];
            $orderProduct->sku = $thisProduct->sku ?? '';
            $orderProduct->save();
        }

        if ($orderData['data']['price']['transaction_fee']) {
            $orderProduct = new OrderProduct();
            $orderProduct->quantity = 1;
            $orderProduct->product_id = null;
            $orderProduct->order_id = $order->id;
            $orderProduct->name = $orderData['data']['price']['payment_method'];
            $orderProduct->price = $orderData['data']['price']['transaction_fee'];
            $orderProduct->discount = 0;
            $orderProduct->product_extras = json_encode([]);
            $orderProduct->sku = 'payment_costs';
            $orderProduct->save();
        }

        if ($orderData['data']['price']['shipping']) {
            $orderProduct = new OrderProduct();
            $orderProduct->quantity = 1;
            $orderProduct->product_id = null;
            $orderProduct->order_id = $order->id;
            $orderProduct->name = 'Verzending';
            $orderProduct->price = $orderData['data']['price']['shipping'];
            $orderProduct->discount = 0;
            $orderProduct->product_extras = json_encode([]);
            $orderProduct->sku = 'shipping_costs';
            $orderProduct->save();
        }

        $orderPayment = new OrderPayment();
        $orderPayment->amount = $order->total;
        $orderPayment->order_id = $order->id;
        $orderPayment->psp = $orderData['data']['price']['payment_method'];
        $orderPayment->payment_method = $orderData['data']['price']['payment_method'];
        $orderPayment->status = 'paid';
        $orderPayment->save();

        $orderLog = new OrderLog();
        $orderLog->order_id = $order->id;
        $orderLog->tag = 'order.created.by.bol';
        $orderLog->save();

        $orderLog = new OrderLog();
        $orderLog->order_id = $order->id;
        $orderLog->note = $orderData['data']['extra']['memo'];
        $orderLog->tag = 'order.note.created';
        $orderLog->save();

        if ($orderData['status_paid'] == 'paid') {
            $order->changeStatus('paid');
        }
    }

    public
    static function syncStock()
    {
        $bolApiKey = Customsetting::get('bol_api_key');
        $bolCompanyId = Customsetting::get('bol_company_id');
        $bolProjectId = Customsetting::get('bol_project_id');
        if ($bolApiKey && $bolCompanyId && $bolProjectId) {
            Product::publicShowable()->chunk(50, function ($products) {
                $bolApiKey = Customsetting::get('bol_api_key');
                $bolCompanyId = Customsetting::get('bol_company_id');
                $bolProjectId = Customsetting::get('bol_project_id');

                $bolProducts = [];
                foreach ($products as $product) {
                    $bolProducts[] = [
                        'id' => $product->id,
                        'title' => $product->name,
                        'price' => (float)$product->currentPrice,
                        'stock' => $product->directSellableStock() < 0 ? 0 : $product->directSellableStock(),
                    ];
                }

                try {
                    $response = Http::withToken($bolApiKey)
                        ->retry(5, 5000)
                        ->post(self::APIURL . '/companies/' . $bolCompanyId . '/projects/' . $bolProjectId . '/offers', $bolProducts)
                        ->json();
                } catch (Exception $exception) {
                    $response = null;
                }
            });
        }
    }
}
