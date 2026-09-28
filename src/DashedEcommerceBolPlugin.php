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
                    ->label(__('Bol product titel'))
                    ->debounce()
                    ->helperText(function (Get $get, $record) {
                        $template = (string) $get('bol-product-title');
                        $group = $record?->model instanceof \Dashed\DashedEcommerceCore\Models\ProductGroup ? $record->model : null;
                        if (! $group) {
                            return __('Gebruik plaatshouders met de filternaam in kleine letters, bijvoorbeeld :kleur:. Op de productgroep kun je het sjabloon laten genereren.');
                        }

                        // Gecachete context: deze helpertekst draait bij elke render
                        // van het formulier, en de sets kosten per product queries.
                        $context = \Dashed\DashedEcommerceBol\Classes\BolTitleContext::for($group, app()->getLocale());
                        $names = collect($context['variables'])->keys()->map(fn ($key) => ":{$key}:")->implode(', ');
                        $example = $template !== ''
                            ? (\Dashed\DashedEcommerceCore\Classes\BolTitleTemplate::renderForSets($template, array_slice($context['sets'], 0, 1))[0] ?? '')
                            : '';

                        return trim(__('Mogelijke plaatshouders: :namen.', ['namen' => $names ?: '-']) . ($example !== '' ? ' ' . __('Voorbeeld: :voorbeeld', ['voorbeeld' => $example]) : ''));
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
