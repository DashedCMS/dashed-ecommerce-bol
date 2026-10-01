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

        $formats = Dashboard::getFormatsByStep($steps);
        $startFormat = $formats['startFormat'];
        $endFormat = $formats['endFormat'];
        $addFormat = $formats['addFormat'];

        $bolOrders = fn () => Order::where('created_at', '>=', $startDate->$startFormat())
            ->where('created_at', '<=', $endDate->$endFormat())
            ->where('order_origin', 'Bol');

        // Een creditorder (retour) draagt de commissie van de oorspronkelijke order als positief bedrag,
        // terwijl bol die commissie bij een retour terugstort: tel hem daarom negatief, anders telt elke
        // retour dubbel. Creditorders zijn ook geen nieuwe bestelling.
        $commissie = (float) $bolOrders()
            ->selectRaw('SUM(CASE WHEN credit_for_order_id IS NULL THEN bol_order_commission ELSE -ABS(bol_order_commission) END) AS netto')
            ->value('netto');

        return [
            StatsOverviewWidget\Stat::make('Aantal bestellingen vanuit Bol', $bolOrders()->whereNull('credit_for_order_id')->count()),
            StatsOverviewWidget\Stat::make('Omzet vanuit Bol', CurrencyHelper::formatPrice($bolOrders()->sum('total'))),
            StatsOverviewWidget\Stat::make('Totale commissie aan Bol', CurrencyHelper::formatPrice($commissie))
                ->description(__('Na retouren, incl. btw')),
//            StatsOverviewWidget\Stat::make('Aantal bestellingen vanuit Bol', Order::where('created_at', '>=', now()->startOfMonth())->where('order_origin', 'Bol')->count())
//                ->description('Deze maand'),
//            StatsOverviewWidget\Stat::make('Omzet vanuit Bol', CurrencyHelper::formatPrice(Order::where('created_at', '>=', now()->startOfMonth())->where('order_origin', 'Bol')->sum('total')))
//                ->description('Deze maand'),
//            StatsOverviewWidget\Stat::make('Totale commissie aan Bol', CurrencyHelper::formatPrice(Order::where('created_at', '>=', now()->startOfMonth())->where('order_origin', 'Bol')->sum('bol_order_commission')))
//                ->description('Deze maand'),
        ];
    }
}
