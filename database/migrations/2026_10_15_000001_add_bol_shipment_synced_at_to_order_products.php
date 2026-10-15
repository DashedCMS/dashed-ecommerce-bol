<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Stempel per orderregel wanneer die naar Bol is gemeld als verzonden, zodat
     * een verzendgroep hooguit één keer gaat en een herkansing alleen de open
     * groepen stuurt.
     */
    public function up(): void
    {
        Schema::table('dashed__order_products', function (Blueprint $table) {
            if (! Schema::hasColumn('dashed__order_products', 'bol_shipment_synced_at')) {
                $table->timestamp('bol_shipment_synced_at')->nullable()->after('bol_shipment_group_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dashed__order_products', function (Blueprint $table) {
            $table->dropColumn('bol_shipment_synced_at');
        });
    }
};
