<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddBolOrderInformationToOrders extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('dashed__order_bol', function (Blueprint $table) {
            $table->id();

            $table->string('bol_id')
                ->nullable();
            $table->foreignId('order_id')
                ->nullable()
                ->constrained('dashed__orders')
                ->cascadeOnDelete();
            $table->decimal('commission')
                ->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            //
        });
    }
}
