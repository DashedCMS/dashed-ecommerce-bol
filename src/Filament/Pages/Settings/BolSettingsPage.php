<?php

namespace Dashed\DashedEcommerceBol\Filament\Pages\Settings;

use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Dashed\DashedCore\Classes\Sites;
use Filament\Schemas\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Dashed\DashedEcommerceBol\Classes\Bol;
use Dashed\DashedCore\Models\Customsetting;
use Filament\Infolists\Components\TextEntry;

class BolSettingsPage extends Page
{
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
                    ->label('Bol client ID')
                    ->maxLength(255),
                TextInput::make("bol_client_secret_{$site['id']}")
                    ->label('Bol client secret')
                    ->maxLength(255),
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
        }

        Notification::make()
            ->title('De Bol instellingen zijn opgeslagen')
            ->success()
            ->send();

        return redirect(BolSettingsPage::getUrl());
    }

    protected function getActions(): array
    {
        return [];
    }
}
