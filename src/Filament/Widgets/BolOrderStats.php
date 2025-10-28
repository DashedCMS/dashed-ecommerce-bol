<?php

namespace Dashed\DashedEcommerceBol\Filament\Widgets;

use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Classes\CurrencyHelper;
use Dashed\DashedCore\Filament\Pages\Dashboard\Dashboard;

class BolOrderStats extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    public ?array $filters = [];

    protected $listeners = [
        'setPageFiltersData',
    ];

    public function mount(): void
    {
        $this->filters = Dashboard::getStartData();
    }

    protected function getHeading(): ?string
    {
        return 'Statistieken vanuit Bol';
    }

    public function setPageFiltersData($data)
    {
        $this->filters = $data;
    }

    protected function getCards(): array
    {
        $startDate = $this->filters['startDate'] ? Carbon::parse($this->filters['startDate']) : now()->subMonth();
        $endDate = $this->filters['endDate'] ? Carbon::parse($this->filters['endDate']) : now();
        $steps = $this->filters['steps'] ?? 'per_day';

        if ($this->filters['steps'] == 'per_day') {
            $startFormat = 'startOfDay';
            $endFormat = 'endOfDay';
            $addFormat = 'addDay';
        } elseif ($this->filters['steps'] == 'per_week') {
            $startFormat = 'startOfWeek';
            $endFormat = 'endOfWeek';
            $addFormat = 'addWeek';
        } elseif ($this->filters['steps'] == 'per_month') {
            $startFormat = 'startOfMonth';
            $endFormat = 'endOfMonth';
            $addFormat = 'addMonth';
        }

        return [
            StatsOverviewWidget\Stat::make('Aantal bestellingen vanuit Bol', Order::where('created_at', '>=', $startDate->$startFormat())->where('created_at', '<=', $endDate->$endFormat())->where('order_origin', 'Bol')->count()),
            StatsOverviewWidget\Stat::make('Omzet vanuit Bol', CurrencyHelper::formatPrice(Order::where('created_at', '>=', $startDate->$startFormat())->where('created_at', '<=', $endDate->$endFormat())->where('order_origin', 'Bol')->sum('total'))),
            StatsOverviewWidget\Stat::make('Totale commissie aan Bol', CurrencyHelper::formatPrice(Order::where('created_at', '>=', $startDate->$startFormat())->where('created_at', '<=', $endDate->$endFormat())->where('order_origin', 'Bol')->sum('bol_order_commission'))),
//            StatsOverviewWidget\Stat::make('Aantal bestellingen vanuit Bol', Order::where('created_at', '>=', now()->startOfMonth())->where('order_origin', 'Bol')->count())
//                ->description('Deze maand'),
//            StatsOverviewWidget\Stat::make('Omzet vanuit Bol', CurrencyHelper::formatPrice(Order::where('created_at', '>=', now()->startOfMonth())->where('order_origin', 'Bol')->sum('total')))
//                ->description('Deze maand'),
//            StatsOverviewWidget\Stat::make('Totale commissie aan Bol', CurrencyHelper::formatPrice(Order::where('created_at', '>=', now()->startOfMonth())->where('order_origin', 'Bol')->sum('bol_order_commission')))
//                ->description('Deze maand'),
        ];
    }
}
