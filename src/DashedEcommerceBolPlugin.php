<?php

namespace Dashed\DashedEcommerceBol;

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
        $panel
            ->widgets([
                BolOrderStats::class,
            ])
            ->pages([
                BolSettingsPage::class,
            ]);
    }

    public function boot(Panel $panel): void
    {

    }
}
