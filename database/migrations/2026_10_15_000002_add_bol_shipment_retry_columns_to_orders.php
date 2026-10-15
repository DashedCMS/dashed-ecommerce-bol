<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Herkansen van een mislukte verzendmelding aan Bol met oplopende
     * tussenpozen en een plafond, in plaats van elk kwartier opnieuw.
     */
    public function up(): void
    {
        Schema::table('dashed__orders', function (Blueprint $table) {
            if (! Schema::hasColumn('dashed__orders', 'bol_shipment_attempts')) {
                $table->unsignedSmallInteger('bol_shipment_attempts')->default(0)->after('bol_shipment_error');
            }
            if (! Schema::hasColumn('dashed__orders', 'bol_shipment_next_attempt_at')) {
                $table->timestamp('bol_shipment_next_attempt_at')->nullable()->after('bol_shipment_attempts');
            }
            if (! Schema::hasColumn('dashed__orders', 'bol_shipment_abandoned_at')) {
                $table->timestamp('bol_shipment_abandoned_at')->nullable()->after('bol_shipment_next_attempt_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dashed__orders', function (Blueprint $table) {
            $table->dropColumn(['bol_shipment_attempts', 'bol_shipment_next_attempt_at', 'bol_shipment_abandoned_at']);
        });
    }
};
