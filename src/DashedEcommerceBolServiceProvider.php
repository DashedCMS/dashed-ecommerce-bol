<?php

namespace Dashed\DashedEcommerceBol;

use Spatie\LaravelPackageTools\Package;
use Illuminate\Console\Scheduling\Schedule;
use Dashed\DashedTranslations\Models\Translation;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Dashed\DashedEcommerceBol\Commands\RefreshBolToken;
use Dashed\DashedEcommerceBol\Commands\SyncShipmentsToBol;
use Dashed\DashedEcommerceBol\Commands\SyncOrdersFromBolCommand;
use Dashed\DashedEcommerceBol\Filament\Pages\Settings\BolSettingsPage;

class DashedEcommerceBolServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-ecommerce-bol';

    public function bootingPackage()
    {
        \Dashed\DashedEcommerceCore\Classes\OrderOrigins::register('Bol', 'Bol.com', true);

        $this->app->booted(function () {
            $schedule = app(Schedule::class);
            $schedule->command(RefreshBolToken::class)
                ->hourly();
            $schedule->command(SyncOrdersFromBolCommand::class)
                ->everyMinute()
                ->withoutOverlapping();
            $schedule->command(SyncShipmentsToBol::class)
                ->everyFifteenMinutes()
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

        cms()->registerSettingsDocs(
            page: \Dashed\DashedEcommerceBol\Filament\Pages\Settings\BolSettingsPage::class,
            title: 'Bol instellingen',
            intro: 'Koppel de webshop met je bol partner account. Met de juiste sleutels kan de webshop bestellingen ophalen, voorraad bijwerken en producten beheren via bol. Per site stel je hier de inloggegevens van bol in.',
            sections: [
                [
                    'heading' => 'Wat kun je hier instellen?',
                    'body' => <<<MARKDOWN
Op deze pagina vul je twee gegevens in waarmee de webshop met bol mag praten:

1. De client ID van je bol partner account.
2. De bijbehorende client secret.

Beide gegevens haal je op bij bol zelf via het developer portaal. Zonder deze sleutels werkt de bol koppeling niet.
MARKDOWN,
                ],
                [
                    'heading' => 'Hoe zet je dit op?',
                    'body' => <<<MARKDOWN
1. Ga naar [developer.bol.com](https://developer.bol.com) en log in met je bol partner account.
2. Maak (of open) een set API credentials voor de webshop.
3. Kopieer de client ID en de client secret.
4. Plak deze in de velden hieronder.
5. Sla de instellingen op.
6. Controleer in het bol overzicht of bestellingen en voorraad netjes worden uitgewisseld.
MARKDOWN,
                ],
            ],
            fields: [
                'Client ID' => 'De client ID uit je bol partner account. Dit is een soort gebruikersnaam waarmee de webshop zich bij bol meldt.',
                'Client secret' => 'De geheime sleutel die hoort bij de client ID. Deel deze waarde nooit met anderen en bewaar hem veilig.',
            ],
            tips: [
                'Maak in het developer portaal van bol een aparte set credentials per webshop. Zo kun je per site precies zien wat er gebeurt en eventueel een sleutel intrekken zonder andere shops te raken.',
                'Klopt er iets niet in de koppeling? Controleer dan eerst of je de client secret zonder spaties hebt geplakt. Een onzichtbare spatie is een veelvoorkomende oorzaak van foutmeldingen.',
                'Bewaar de client secret in een wachtwoordkluis. Bol toont de secret meestal maar een keer bij aanmaken, daarna kun je hem niet meer terugzien.',
            ],
        );
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
                RefreshBolToken::class,
                SyncShipmentsToBol::class,
            ]);

        cms()->builder('plugins', [
            new DashedEcommerceBolPlugin(),
        ]);
    }
}
