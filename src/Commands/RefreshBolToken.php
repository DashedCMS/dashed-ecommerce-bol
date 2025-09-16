<?php

namespace Dashed\DashedEcommerceBol\Commands;

use Illuminate\Console\Command;
use Dashed\DashedEcommerceBol\Classes\Bol;

class RefreshBolToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bol:refresh-token';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh Bol access token';

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
            Bol::refreshToken();
        }
    }
}
