<?php

namespace Dashed\DashedEcommerceBol;

use Spatie\LaravelPackageTools\Package;
use Illuminate\Console\Scheduling\Schedule;
use Dashed\DashedTranslations\Models\Translation;
use Dashed\DashedCore\Support\MeasuresServiceProvider;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Dashed\DashedEcommerceBol\Commands\RefreshBolToken;
use Dashed\DashedEcommerceBol\Commands\SyncShipmentsToBol;
use Dashed\DashedEcommerceBol\Commands\SyncOrdersFromBolCommand;
use Dashed\DashedEcommerceBol\Filament\Pages\Settings\BolSettingsPage;

class DashedEcommerceBolServiceProvider extends PackageServiceProvider
{
    use MeasuresServiceProvider;
    public static string $name = 'dashed-ecommerce-bol';

    public function bootingPackage()
    {
        $this->logProviderMemory('bootingPackage:start');
        $this->app->booted(function () {
            $schedule = app(Schedule::class);
            $schedule->command(RefreshBolToken::class)
                ->hourly();
            $schedule->command(SyncOrdersFromBolCommand::class)
                ->everyMinute()
                ->withoutOverlapping();
            $schedule->command(SyncShipmentsToBol::class)
                ->everyMinute()
                ->withoutOverlapping();
        });

        ecommerce()->builder('customOrderFields', [
            'bolOrderId' => [
                'label' => Translation::get('bol-order-number', 'bol-order-fields', 'Bol bestelnummer'),
                'hideFromCheckout' => true,
                'showOnInvoice' => true,
            ],
            'bolOrderCommission' => [
                'label' => Translation::get('bol-commission', 'bol-order-fields', 'Bol commissie'),
                'hideFromCheckout' => true,
                'showOnInvoice' => false,
            ],
        ]);
        $this->logProviderMemory('bootingPackage:end');
    }

    public function configurePackage(Package $package): void
    {
        $this->logProviderMemory('configurePackage:start');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        cms()->registerSettingsPage(BolSettingsPage::class, 'Bol', 'archive-box', 'Koppel Bol');

        $package
            ->name('dashed-ecommerce-bol')
//            ->hasViews()
//            ->hasRoutes([
//                'bolRoutes',
//            ])
            ->hasCommands([
                SyncOrdersFromBolCommand::class,
                RefreshBolToken::class,
                SyncShipmentsToBol::class,
            ]);

        cms()->builder('plugins', [
            new DashedEcommerceBolPlugin(),
        ]);
        $this->logProviderMemory('configurePackage:end');
    }
}
