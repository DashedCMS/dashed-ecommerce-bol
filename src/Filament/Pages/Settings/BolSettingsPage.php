<?php

namespace Dashed\DashedEcommerceBol\Filament\Pages\Settings;

use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Dashed\DashedCore\Classes\Sites;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Dashed\DashedEcommerceBol\Classes\Bol;
use Dashed\DashedCore\Models\Customsetting;
use Filament\Infolists\Components\TextEntry;
use Dashed\DashedCore\Traits\HasSettingsPermission;
use Dashed\DashedEcommerceCore\Models\ProductGroup;
use Dashed\DashedEcommerceBol\Classes\BolTransporter;
use Dashed\DashedEcommerceBol\Classes\BolDeliveryCode;
use Dashed\DashedEcommerceBol\Classes\BolTitleGenerator;
use Dashed\DashedEcommerceBol\Jobs\GenerateBolTitlesJob;

class BolSettingsPage extends Page
{
    use \Dashed\DashedCore\Filament\Concerns\TranslatesPageLabels;
    use HasSettingsPermission;

    /**
     * Porties van vijftig: GenerateBolTitlesJob doet één AI-aanroep per groep
     * en heeft een timeout van een half uur, dus een hele catalogus in één
     * job zou daar op een grote winkel tegenaan lopen.
     */
    public const BOL_TITLE_CHUNK = 50;

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $title = 'Bol';

    protected string $view = 'dashed-core::settings.pages.default-settings';
    public array $data = [];

    public function mount(): void
    {
        $formData = [];
        $sites = Sites::getSites();
        foreach ($sites as $site) {
            $formData["bol_client_id_{$site['id']}"] = Customsetting::get('bol_client_id', $site['id']);
            $formData["bol_client_secret_{$site['id']}"] = Customsetting::get('bol_client_secret', $site['id']);
            $formData["bol_connected_{$site['id']}"] = Customsetting::get('bol_connected', $site['id'], 0) ? true : false;
            $formData["bol_title_brand_{$site['id']}"] = Customsetting::get('bol_title_brand', $site['id']);
            $formData["bol_title_instructions_{$site['id']}"] = Customsetting::get('bol_title_instructions', $site['id']);
            $formData["bol_delivery_code_pre_order_{$site['id']}"] = BolDeliveryCode::preOrderCode($site['id']);
            $formData["bol_delivery_code_default_{$site['id']}"] = BolDeliveryCode::defaultCode($site['id']);
            $formData["bol_transporter_without_tnt_{$site['id']}"] = BolTransporter::withoutTrackAndTrace($site['id']);
        }

        $this->form->fill($formData);
    }

    public function form(Schema $schema): Schema
    {
        $sites = Sites::getSites();
        $tabGroups = [];

        $tabs = [];
        foreach ($sites as $site) {
            $newSchema = [
                TextEntry::make('label')
                    ->state("Bol voor {$site['name']}")
                    ->state('Activeer Bol.')
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ]),
                TextEntry::make('label')
                    ->state("Bol is " . (! Customsetting::get('bol_connected', $site['id'], 0) ? 'niet' : '') . ' geconnect')
                    ->state(Customsetting::get('bol_connection_error', $site['id'], ''))
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ]),
                TextInput::make("bol_client_id_{$site['id']}")
                    ->label(__('Bol client ID'))
                    ->maxLength(255),
                TextInput::make("bol_client_secret_{$site['id']}")
                    ->label(__('Bol client secret'))
                    ->maxLength(255),
                TextInput::make("bol_title_brand_{$site['id']}")
                    ->label(__('Merk voor Bol-titels'))
                    ->helperText(__('Wordt gebruikt als een productgroep geen kenmerk of filter Merk heeft.'))
                    ->maxLength(100),
                Textarea::make("bol_title_instructions_{$site['id']}")
                    ->label(__('Extra aanwijzingen voor Bol-titels'))
                    ->helperText(__('Gaat mee naar de AI bij het genereren van een Bol-titel, bijvoorbeeld: zeg altijd sierkussen in plaats van kussen.'))
                    ->rows(3)
                    ->maxLength(1000)
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ]),
                Select::make("bol_delivery_code_pre_order_{$site['id']}")
                    ->label(__('Levertijd op Bol voor pre-order producten'))
                    ->helperText(__('Voor producten zonder directe voorraad die wel besteld kunnen worden (print-on-demand). Gaat als veld bol_delivery_code mee in de productfeed; koppel dat veld in Channable aan de levertijd van het Bol-aanbod.'))
                    ->options(BolDeliveryCode::options())
                    ->default(BolDeliveryCode::DEFAULT_PRE_ORDER)
                    ->required(),
                Select::make("bol_delivery_code_default_{$site['id']}")
                    ->label(__('Levertijd op Bol voor overige producten'))
                    ->helperText(__('Leeg laten om de levertijd te houden die nu in Channable of op Bol staat.'))
                    ->options(BolDeliveryCode::options())
                    ->nullable(),
                Select::make("bol_transporter_without_tnt_{$site['id']}")
                    ->label(__('Transporteur voor zendingen zonder track & trace'))
                    ->helperText(__('Gaat een Bol-bestelling op Verzonden zonder track & trace (briefpost), dan wordt de verzending met deze transporteur aan Bol gemeld.'))
                    ->options(BolTransporter::options())
                    ->default(BolTransporter::DEFAULT_WITHOUT_TNT)
                    ->required(),
            ];

            $tabs[] = Tab::make($site['id'])
                ->label(ucfirst($site['name']))
                ->schema($newSchema)
                ->columns([
                    'default' => 1,
                    'lg' => 2,
                ]);
        }
        $tabGroups[] = Tabs::make('Sites')
            ->tabs($tabs);

        return $schema->schema($tabGroups)
            ->statePath('data');
    }

    public function submit()
    {
        $sites = Sites::getSites();

        foreach ($sites as $site) {
            Customsetting::set('bol_client_id', $this->form->getState()["bol_client_id_{$site['id']}"], $site['id']);
            Customsetting::set('bol_client_secret', $this->form->getState()["bol_client_secret_{$site['id']}"], $site['id']);
            Customsetting::set('bol_connected', Bol::isConnected($site['id']), $site['id']);
            Customsetting::set('bol_title_brand', $this->form->getState()["bol_title_brand_{$site['id']}"] ?? null, $site['id']);
            Customsetting::set('bol_title_instructions', $this->form->getState()["bol_title_instructions_{$site['id']}"] ?? null, $site['id']);
            Customsetting::set(BolDeliveryCode::SETTING_PRE_ORDER, $this->form->getState()["bol_delivery_code_pre_order_{$site['id']}"] ?? BolDeliveryCode::DEFAULT_PRE_ORDER, $site['id']);
            Customsetting::set(BolDeliveryCode::SETTING_DEFAULT, $this->form->getState()["bol_delivery_code_default_{$site['id']}"] ?: null, $site['id']);
            Customsetting::set(BolTransporter::SETTING_WITHOUT_TNT, $this->form->getState()["bol_transporter_without_tnt_{$site['id']}"] ?? BolTransporter::DEFAULT_WITHOUT_TNT, $site['id']);
        }

        Notification::make()
            ->title(__('De Bol instellingen zijn opgeslagen'))
            ->success()
            ->send();

        return redirect(BolSettingsPage::getUrl());
    }

    protected function getActions(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateMissingBolTitles')
                ->label(__('Ontbrekende Bol-titels genereren'))
                ->icon('heroicon-o-sparkles')
                ->visible(fn () => BolTitleGenerator::available())
                ->requiresConfirmation()
                ->modalHeading(__('Ontbrekende Bol-titels genereren'))
                ->modalDescription(fn () => __('De AI schrijft op de achtergrond een Bol-titelsjabloon voor :aantal productgroepen die er nog geen hebben. Groepen met een sjabloon blijven ongemoeid. Je krijgt per :porties groepen een melding.', [
                    'aantal' => count(self::groupIdsWithoutBolTitle()),
                    'porties' => self::BOL_TITLE_CHUNK,
                ]))
                ->modalSubmitActionLabel(__('Genereren'))
                ->action(function (): void {
                    $ids = self::groupIdsWithoutBolTitle();

                    if ($ids === []) {
                        Notification::make()
                            ->title(__('Alle productgroepen hebben al een Bol-titel'))
                            ->success()
                            ->send();

                        return;
                    }

                    foreach (array_chunk($ids, self::BOL_TITLE_CHUNK) as $chunk) {
                        GenerateBolTitlesJob::dispatch($chunk, false, auth()->user());
                    }

                    Notification::make()
                        ->title(__('Bol-titels worden op de achtergrond gegenereerd'))
                        ->body(__(':aantal productgroepen, je krijgt per :porties groepen een melding.', [
                            'aantal' => count($ids),
                            'porties' => self::BOL_TITLE_CHUNK,
                        ]))
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Productgroepen die de job niet zou overslaan: met naam, met producten
     * en nog zonder sjabloon. Dezelfde toets als de job zelf, zodat het getal
     * in de bevestiging klopt met wat er gebeurt.
     *
     * @return list<int>
     */
    public static function groupIdsWithoutBolTitle(): array
    {
        return ProductGroup::query()
            ->orderBy('id')
            ->get()
            ->filter(fn (ProductGroup $group) => GenerateBolTitlesJob::skipReason($group, false) === null)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
