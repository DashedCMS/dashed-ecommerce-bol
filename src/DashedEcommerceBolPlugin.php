<?php

namespace Dashed\DashedEcommerceBol;

use Dashed\DashedEcommerceBol\Filament\Widgets\BolOrderStats2;
use Dashed\DashedEcommerceCore\Models\Order;
use Filament\Panel;
use Filament\Contracts\Plugin;
use Dashed\DashedEcommerceBol\Filament\Widgets\BolOrderStats;
use Dashed\DashedEcommerceBol\Filament\Pages\Settings\BolSettingsPage;

class DashedEcommerceBolPlugin implements Plugin
{
    public function getId(): string
    {
        return 'dashed-ecommerce-bol';
    }

    public function register(Panel $panel): void
    {
        $widgets = [];

        if(Order::where('order_origin', 'Bol')->count()){
            $widgets[] = BolOrderStats::class;
        }

        $panel
            ->widgets($widgets)
            ->pages([
                BolSettingsPage::class,
            ]);
    }

    public function boot(Panel $panel): void
    {

    }
}
