<?php

namespace Dashed\DashedEcommerceBol\Commands;

use Illuminate\Console\Command;
use Dashed\DashedEcommerceBol\Classes\Bol;
use Dashed\DashedEcommerceCore\Models\Order;

class SyncDeliveryDatesCommand extends Command
{
    protected $signature = 'bol:sync-delivery-dates {--days=60} {--dry-run}';

    protected $description = 'Fetch the Bol delivery deadline for recent open Bol orders';

    public function handle(): int
    {
        if (! Bol::hasCredentials()) {
            $this->warn('Geen Bol-gegevens ingesteld.');

            return self::SUCCESS;
        }

        $orders = Order::query()
            ->whereNotNull('bol_order_id')
            ->where('bol_order_id', '!=', '')
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->whereIn('status', ['paid', 'partially_paid'])
            ->where(function ($q) {
                $q->whereNull('fulfillment_status')
                    ->orWhereNotIn('fulfillment_status', Order::DEADLINE_DONE_FULFILLMENT_STATUSES);
            })
            ->whereNull('deliver_by_date')
            ->where(function ($q) {
                $q->whereNull('deadline_source')->orWhere('deadline_source', '!=', 'handmatig');
            })
            ->get();

        if ($this->option('dry-run')) {
            $this->info($orders->count() . ' orders zouden worden bijgewerkt.');

            return self::SUCCESS;
        }

        $updated = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                Bol::syncDeliveryDate($order) ? $updated++ : $failed++;
            } catch (\Throwable $exception) {
                report($exception);
                $failed++;
            }

            usleep(Bol::REQUEST_THROTTLE_MICROSECONDS);
        }

        $this->info("Bijgewerkt: {$updated}, mislukt: {$failed}");

        return self::SUCCESS;
    }
}
