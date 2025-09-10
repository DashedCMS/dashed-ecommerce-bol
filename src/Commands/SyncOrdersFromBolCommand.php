<?php

namespace Dashed\DashedEcommerceBol\Commands;

use Illuminate\Console\Command;
use Dashed\DashedEcommerceBol\Classes\Bol;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceBol\Models\BolOrder;

class SyncOrdersFromBolCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bol:sync-orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync orders with Bol';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (Bol::isConnected()) {
            Bol::syncOrders();

            foreach(BolOrder::whereNull('order_id')->get() as $bolOrder) {
                $this->info("Order {$bolOrder->bol_id} wordt gesynct.");
                Bol::syncOrder($bolOrder);
            }
        }
    }
}
