<?php

namespace Dashed\DashedEcommerceBol\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Dashed\DashedEcommerceBol\Models\BolOrder;
use Filament\Widgets\StatsOverviewWidget\Card;

class BolOrderStats extends StatsOverviewWidget
{
    protected function getCards(): array
    {
        return [
            Card::make('Aantal bestellingen vanuit Bol', BolOrder::count()),
        ];
    }
}
