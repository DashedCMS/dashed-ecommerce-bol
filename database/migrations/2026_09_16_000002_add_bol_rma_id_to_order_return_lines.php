<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('dashed__order_return_lines')) {
            return;
        }

        Schema::table('dashed__order_return_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('dashed__order_return_lines', 'bol_rma_id')) {
                $table->string('bol_rma_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dashed__order_return_lines')) {
            return;
        }

        Schema::table('dashed__order_return_lines', function (Blueprint $table) {
            if (Schema::hasColumn('dashed__order_return_lines', 'bol_rma_id')) {
                $table->dropColumn('bol_rma_id');
            }
        });
    }
};
