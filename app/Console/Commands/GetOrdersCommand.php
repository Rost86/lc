<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\LightspeedController;

class GetOrdersCommand extends Command
{
    protected $signature = 'lightspeed:get-orders';
    protected $description = 'Get orders from Lightspeed';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $controller = new LightspeedController();
        $controller->getOrders();
    }
}
