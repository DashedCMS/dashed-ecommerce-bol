<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;
use Dashed\DashedEcommerceBol\Newsletter\ExcludeBolCustomers;

return new class () extends Migration {
    /**
     * Haalt de Bol-adressen weg die door de eerdere rondes zijn blijven staan.
     *
     * Die rondes keken alleen naar order_origin = Bol en spaarden elk contact
     * met bron "bestelling". Een kassa- of vervangende order met het
     * doorstuuradres van Bol (@verkopen.bol.com) kwam zo op de lijst en bleef
     * erop. Deze ronde kijkt naar het adres zelf.
     */
    public function up(): void
    {
        if (! app()->bound('newsletter')) {
            return;
        }

        if (! Schema::hasTable('dashed__orders') || ! Schema::hasTable('dashed__newsletter_suppressions')) {
            return;
        }

        Log::warning('Bol-adressen van de nieuwsbrieflijsten gehaald.', ExcludeBolCustomers::cleanUp());
    }

    public function down(): void
    {
        // Niets terug te draaien: zie de migratie van 2026-08-26 voor het waarom.
    }
};
