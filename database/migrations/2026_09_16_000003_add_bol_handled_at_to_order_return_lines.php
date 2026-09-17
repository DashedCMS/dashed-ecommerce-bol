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
            if (! Schema::hasColumn('dashed__order_return_lines', 'bol_handled_at')) {
                $table->dateTime('bol_handled_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dashed__order_return_lines')) {
            return;
        }

        Schema::table('dashed__order_return_lines', function (Blueprint $table) {
            if (Schema::hasColumn('dashed__order_return_lines', 'bol_handled_at')) {
                $table->dropColumn('bol_handled_at');
            }
        });
    }
};
