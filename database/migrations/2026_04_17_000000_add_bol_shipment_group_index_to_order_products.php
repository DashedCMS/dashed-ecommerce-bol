<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('dashed__order_products', function (Blueprint $table) {
            $table->unsignedSmallInteger('bol_shipment_group_index')->nullable()->after('bol_id');
        });
    }

    public function down(): void
    {
        Schema::table('dashed__order_products', function (Blueprint $table) {
            $table->dropColumn('bol_shipment_group_index');
        });
    }
};
