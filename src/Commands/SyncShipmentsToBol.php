<?php

namespace Dashed\DashedEcommerceBol\Commands;

use Illuminate\Console\Command;
use Dashed\DashedEcommerceBol\Classes\Bol;

class SyncShipmentsToBol extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bol:sync-shipments-to-bol';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync shipments to Bol';

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
            Bol::syncShipments();
        }
    }
}
