<?php

namespace Dashed\DashedEcommerceBol;

use Filament\Panel;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Dashed\DashedEcommerceCore\Models\Order;
use Filament\Schemas\Components\Utilities\Get;
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

        if (\Illuminate\Support\Facades\Schema::hasTable('dashed__orders') && Order::where('order_origin', 'Bol')->count()) {
            $widgets[] = BolOrderStats::class;
        }

        $panel
            ->widgets($widgets)
            ->pages([
                BolSettingsPage::class,
            ]);
    }

    public static function builderBlocks(): void
    {
        cms()
            ->builder('productBlocks', [
                TextInput::make('bol-product-title')
                    ->label('Bol product titel')
                    ->debounce()
                    ->helperText(function (Get $get, $record) {
                        $bolTitle = $get('bol-product-title');
                        if (! $bolTitle || ! $record || ! $record->model || ! $record->model->products || ! $record->model->products->count()) {
                            return 'Mogelijke variablen: :name:, :categorie naam:';
                        } else {
                            $product = $record->model->products->first();

                            foreach ($product->productFilters as $productFilter) {
                                $bolTitle = str($bolTitle)->replace(':' . str($productFilter->name)->lower() . ':', $productFilter->productFilterOptions->where('id', $productFilter->pivot->product_filter_option_id)->first()?->name ?? '');
                            }

                            return 'Mogelijke variablen: :name:, :categorie naam:. Voorbeeld: ' . $bolTitle;
                        }

                    }),
            ]);
    }

    public function boot(Panel $panel): void
    {
        cms()->builder('builderBlockClasses', [
            self::class => 'builderBlocks',
        ]);

        ecommerce()
            ->builder('productPriceFields', [
                'bol_price' => [
                    'label' => 'Bol prijs',
                    'helperText' => 'Voorbeeld: 10.25',
                ],
                'bol_old_price' => [
                    'label' => 'Vorige bol prijs (hogere prijs)',
                    'helperText' => 'Voorbeeld: 14.25',
                ],
            ]);
    }
}
