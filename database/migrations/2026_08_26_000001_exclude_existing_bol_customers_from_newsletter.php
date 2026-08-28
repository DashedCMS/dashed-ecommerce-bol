<?php

use Illuminate\Support\Facades\Schema;
use Dashed\DashedEcommerceCore\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Dashed\DashedNewsletter\Models\NewsletterSuppression;
use Dashed\DashedEcommerceBol\Newsletter\ExcludeBolCustomers;

return new class () extends Migration {
    /**
     * Sluit de Bol-klanten uit die er al waren.
     *
     * De luisteraar op OrderCreatedEvent vangt alleen nieuwe bestellingen op.
     * Wat er al stond moet er ook af, en dat mag niet afhangen van iemand die
     * eraan denkt het commando te draaien: op een klantinstallatie denkt
     * niemand daaraan. Een migratie draait per installatie precies een keer en
     * loopt mee met elke deploy, en dat is precies wat hier nodig is.
     *
     * Het commando dashed:exclude-bol-newsletter-contacts blijft bestaan om
     * het later nog eens over te doen, bijvoorbeeld na een import.
     *
     * Twee guards, want dit pakket kan draaien zonder nieuwsbriefmodule en op
     * een verse installatie waar de tabellen nog niet bestaan.
     */
    public function up(): void
    {
        if (! app()->bound('newsletter')) {
            return;
        }

        if (! Schema::hasTable('dashed__orders') || ! Schema::hasTable('dashed__newsletter_suppressions')) {
            return;
        }

        // Per portie en niet alles in een keer: op een webshop met tienduizend
        // Bol-bestellingen is de volledige lijst adressen in het geheugen
        // trekken tijdens een deploy geen goed idee.
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

    /**
     * Alleen de blokkades terugdraaien die deze regel zelf zette. Contacten
     * die op cleaned gezet zijn blijven dat: welke van hen daarvoor actief
     * was, is achteraf niet meer vast te stellen, en iemand ten onrechte weer
     * op een lijst zetten is erger dan hem eraf laten.
     */
    public function down(): void
    {
        if (! Schema::hasTable('dashed__newsletter_suppressions')) {
            return;
        }

        NewsletterSuppression::where('reason', NewsletterSuppression::REASON_MARKETPLACE)
            ->where('source', 'bol')
            ->delete();
    }
};
