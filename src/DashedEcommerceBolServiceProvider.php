<?php

namespace Dashed\DashedEcommerceBol;

use Spatie\LaravelPackageTools\Package;
use Illuminate\Console\Scheduling\Schedule;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceBol\Models\BolOrder;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Dashed\DashedEcommerceBol\Commands\SyncOrdersFromBolCommand;
use Dashed\DashedEcommerceBol\Filament\Pages\Settings\BolSettingsPage;

class DashedEcommerceBolServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-ecommerce-bol';

    public function bootingPackage()
    {
        $this->app->booted(function () {
            $schedule = app(Schedule::class);
            $schedule->command(SyncOrdersFromBolCommand::class)
                ->everyMinute()
                ->withoutOverlapping();
        });

        Order::addDynamicRelation('bolOrder', function (Order $model) {
            return $model->hasOne(BolOrder::class);
        });
    }

    public function configurePackage(Package $package): void
    {
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
            ]);

        cms()->builder('plugins', [
            new DashedEcommerceBolPlugin(),
        ]);
    }
}
