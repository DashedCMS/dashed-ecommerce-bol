<?php

namespace Dashed\DashedEcommerceBol\Commands;

use Illuminate\Console\Command;
use Dashed\DashedEcommerceBol\Classes\Bol;
use Dashed\DashedEcommerceCore\Models\Order;

/**
 * Kwartierronde: meldt verzonden Bol-bestellingen die nog niet gemeld zijn,
 * met inachtneming van de herkansingstussenpozen. Met --order wordt één
 * bestelling (id of Bol-ordernummer) met de hand opnieuw geprobeerd, ook als
 * de automatische herkansing is opgegeven.
 */
class SyncShipmentsToBol extends Command
{
    protected $signature = 'bol:sync-shipments-to-bol {--order= : Order-id of Bol-ordernummer om met de hand opnieuw te melden}';

    protected $description = 'Sync shipments to Bol';

    public function handle(): int
    {
        if ($this->option('order')) {
            return $this->syncOne((string) $this->option('order'));
        }

        if (Bol::isConnected()) {
            Bol::syncShipments();
        }

        return self::SUCCESS;
    }

    protected function syncOne(string $ref): int
    {
        $order = Order::where('id', $ref)->orWhere('bol_order_id', $ref)->first();
        if (! $order || ! $order->bol_order_id) {
            $this->error("Geen Bol-bestelling gevonden voor '{$ref}'.");

            return self::FAILURE;
        }

        if (! in_array($order->fulfillment_status, Bol::SHIPMENT_SYNC_STATUSES, true)) {
            $this->error('Bestelling ' . $order->invoice_id . ' staat op ' . $order->fulfillment_status . '; alleen een verzonden of afgehandelde bestelling wordt aan Bol gemeld.');

            return self::FAILURE;
        }

        Bol::resetShipmentRetries($order);
        Bol::syncShipment($order->fresh());
        $order->refresh();

        if ($order->bol_shipment_synced) {
            $this->info('Verzending van ' . $order->invoice_id . ' gemeld aan Bol.');

            return self::SUCCESS;
        }

        $this->error('Niet gelukt: ' . ($order->bol_shipment_error ?: 'onbekende fout'));

        return self::FAILURE;
    }
}
