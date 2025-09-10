<?php

namespace Dashed\DashedEcommerceBol\Filament\Pages\Settings;

use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Forms\Components\Tabs;
use Dashed\DashedCore\Classes\Sites;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Tabs\Tab;
use Illuminate\Support\Facades\Artisan;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Dashed\DashedEcommerceBol\Classes\Bol;
use Filament\Forms\Components\Placeholder;
use Dashed\DashedCore\Models\Customsetting;

class BolSettingsPage extends Page
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $title = 'Bol';

    protected static string $view = 'dashed-core::settings.pages.default-settings';
    public array $data = [];

    public function mount(): void
    {
        Artisan::call('bol:sync-orders');

        $formData = [];
        $sites = Sites::getSites();
        foreach ($sites as $site) {
            $formData["bol_client_id_{$site['id']}"] = Customsetting::get('bol_client_id', $site['id']);
            $formData["bol_client_secret_{$site['id']}"] = Customsetting::get('bol_client_secret', $site['id']);
            $formData["bol_connected_{$site['id']}"] = Customsetting::get('bol_connected', $site['id'], 0) ? true : false;
        }

        $this->form->fill($formData);
    }

    protected function getFormSchema(): array
    {
        $sites = Sites::getSites();
        $tabGroups = [];

        $tabs = [];
        foreach ($sites as $site) {
            $schema = [
                Placeholder::make('label')
                    ->label("Bol voor {$site['name']}")
                    ->content('Activeer Bol.')
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ]),
                Placeholder::make('label')
                    ->label("Bol is " . (!Customsetting::get('bol_connected', $site['id'], 0) ? 'niet' : '') . ' geconnect')
                    ->content(Customsetting::get('bol_connection_error', $site['id'], ''))
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
                ->schema($schema)
                ->columns([
                    'default' => 1,
                    'lg' => 2,
                ]);
        }
        $tabGroups[] = Tabs::make('Sites')
            ->tabs($tabs);

        return $tabGroups;
    }

    public function getFormStatePath(): ?string
    {
        return 'data';
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
        return [
            Action::make('refreshJsonFeed')
                ->label('Refresh JSON feed')
                ->action(function () {
                    Artisan::call('bol:create-json-feeds');

                    Notification::make()
                        ->title('De JSON feed is vernieuwd')
                        ->success()
                        ->send();
                })
                ->icon('heroicon-o-arrow-path')
                ->color('primary'),
        ];
    }
}
