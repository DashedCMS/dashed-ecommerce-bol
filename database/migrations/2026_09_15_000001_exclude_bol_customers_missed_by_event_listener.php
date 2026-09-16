<?php

use Illuminate\Support\Facades\Schema;
use Dashed\DashedEcommerceCore\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Dashed\DashedEcommerceBol\Newsletter\ExcludeBolCustomers;

return new class () extends Migration {
    /**
     * Haalt de Bol-klanten in die sinds v4.4.0 gemist zijn.
     *
     * De luisteraar hing aan OrderCreatedEvent, en dat event vuurt alleen
     * vanuit de checkout; de Bol-sync slaat op met een kale save(). Elke
     * Bol-bestelling sinds de vorige migratie is daardoor nooit langs de
     * blokkadelijst gekomen. Dezelfde ronde als toen, opnieuw, en net als
     * toen niet afhankelijk van iemand die aan het commando denkt.
     * ExcludeBolCustomers::forEmail() is idempotent, dus de klanten van de
     * eerste ronde kosten alleen een query.
     */
    public function up(): void
    {
        if (! app()->bound('newsletter')) {
            return;
        }

        if (! Schema::hasTable('dashed__orders') || ! Schema::hasTable('dashed__newsletter_suppressions')) {
            return;
        }

        Order::query()
            ->where('order_origin', 'Bol')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->select(['id', 'email', 'site_id'])
            ->chunkById(500, function ($orders): void {
                foreach ($orders as $order) {
                    ExcludeBolCustomers::forEmail(
                        (string) ($order->site_id ?? ''),
                        (string) ($order->email ?? ''),
                    );
                }
            });
    }

    public function down(): void
    {
        // Niets terug te draaien: zie de migratie van 2026-08-26 voor het waarom.
    }
};
