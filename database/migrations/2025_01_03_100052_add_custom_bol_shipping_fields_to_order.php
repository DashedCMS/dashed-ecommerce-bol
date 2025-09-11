<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('dashed__orders', function (Blueprint $table) {
            $table->boolean('bol_shipment_synced')->default(false);
            $table->string('bol_shipment_process_id')->nullable();
            $table->string('bol_shipment_entity_id')->nullable();
            $table->string('bol_shipment_error')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order', function (Blueprint $table) {
            //
        });
    }
};
