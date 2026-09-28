<?php

namespace Dashed\DashedEcommerceBol\Filament\Actions;

use Filament\Actions\BulkAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Dashed\DashedEcommerceBol\Classes\BolTitleGenerator;
use Dashed\DashedEcommerceBol\Jobs\GenerateBolTitlesJob;

/**
 * Bulkactie op de productgroepenlijst: zet één GenerateBolTitlesJob klaar
 * voor de geselecteerde groepen. Genereren zelf gebeurt op de achtergrond,
 * niet in het verzoek.
 */
class GenerateBolTitlesBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('generateBolTitles')
            ->label(__('Bol-titels genereren'))
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->visible(fn () => BolTitleGenerator::available())
            ->modalHeading(__('Bol-titels genereren'))
            ->modalDescription(__('De AI schrijft op de achtergrond een Bol-titelsjabloon voor elke geselecteerde productgroep. Een voorstel dat niet door de controle komt wordt niet opgeslagen. Je krijgt een melding als het klaar is.'))
            ->modalSubmitActionLabel(__('Genereren'))
            ->schema([
                Toggle::make('overwrite')
                    ->label(__('Ook groepen met een bestaand sjabloon overschrijven'))
                    ->default(false),
            ])
            ->action(function (Collection $records, array $data): void {
                GenerateBolTitlesJob::dispatch(
                    $records->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                    (bool) ($data['overwrite'] ?? false),
                    auth()->user(),
                );

                Notification::make()
                    ->title(__('Bol-titels worden op de achtergrond gegenereerd'))
                    ->body(__('Je krijgt een melding als het klaar is.'))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
