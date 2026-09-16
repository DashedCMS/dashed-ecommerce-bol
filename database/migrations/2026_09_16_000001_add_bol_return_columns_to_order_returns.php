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
            if (! Schema::hasColumn('dashed__order_returns', 'bol_return_id')) {
                $table->string('bol_return_id')->nullable()->index();
            }
            if (! Schema::hasColumn('dashed__order_returns', 'bol_handling_result')) {
                $table->string('bol_handling_result')->nullable();
            }
            if (! Schema::hasColumn('dashed__order_returns', 'bol_handled_at')) {
                $table->dateTime('bol_handled_at')->nullable();
            }
            if (! Schema::hasColumn('dashed__order_returns', 'bol_handle_error')) {
                $table->text('bol_handle_error')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dashed__order_returns')) {
            return;
        }

        Schema::table('dashed__order_returns', function (Blueprint $table) {
            foreach (['bol_return_id', 'bol_handling_result', 'bol_handled_at', 'bol_handle_error'] as $column) {
                if (Schema::hasColumn('dashed__order_returns', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
