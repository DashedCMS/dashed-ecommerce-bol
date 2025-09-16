<?php

namespace Dashed\DashedEcommerceBol\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Classes\CurrencyHelper;

class BolOrderStats extends StatsOverviewWidget
{
    protected function getCards(): array
    {
        return [
            StatsOverviewWidget\Stat::make('Aantal bestellingen vanuit Bol', Order::where('order_origin', 'Bol')->count()),
            StatsOverviewWidget\Stat::make('Omzet vanuit Bol', CurrencyHelper::formatPrice(Order::where('order_origin', 'Bol')->sum('total'))),
            StatsOverviewWidget\Stat::make('Totale commissie aan Bol', CurrencyHelper::formatPrice(Order::where('order_origin', 'Bol')->sum('bol_order_commission'))),
            StatsOverviewWidget\Stat::make('Aantal bestellingen vanuit Bol', Order::where('created_at', '>=', now()->startOfMonth())->where('order_origin', 'Bol')->count())
                ->description('Deze maand'),
            StatsOverviewWidget\Stat::make('Omzet vanuit Bol', CurrencyHelper::formatPrice(Order::where('created_at', '>=', now()->startOfMonth())->where('order_origin', 'Bol')->sum('total')))
                ->description('Deze maand'),
            StatsOverviewWidget\Stat::make('Totale commissie aan Bol', CurrencyHelper::formatPrice(Order::where('created_at', '>=', now()->startOfMonth())->where('order_origin', 'Bol')->sum('bol_order_commission')))
                ->description('Deze maand'),
        ];
    }
}
