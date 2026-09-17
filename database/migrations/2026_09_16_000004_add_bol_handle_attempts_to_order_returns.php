<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('dashed__order_returns')) {
            return;
        }

        Schema::table('dashed__order_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('dashed__order_returns', 'bol_handle_attempts')) {
                $table->unsignedInteger('bol_handle_attempts')->default(0);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dashed__order_returns')) {
            return;
        }

        Schema::table('dashed__order_returns', function (Blueprint $table) {
            if (Schema::hasColumn('dashed__order_returns', 'bol_handle_attempts')) {
                $table->dropColumn('bol_handle_attempts');
            }
        });
    }
};
