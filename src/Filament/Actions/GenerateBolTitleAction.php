<?php

namespace Dashed\DashedEcommerceBol\Filament\Actions;

use Throwable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Dashed\DashedEcommerceCore\Models\ProductGroup;
use Dashed\DashedEcommerceBol\Classes\BolTitleRules;
use Dashed\DashedEcommerceBol\Classes\BolTitleWriter;
use Dashed\DashedEcommerceBol\Classes\BolTitleContext;
use Dashed\DashedEcommerceBol\Classes\BolTitleGenerator;
use Dashed\DashedEcommerceCore\Classes\BolTitleTemplate;

/**
 * Knop op een productgroep: laat de AI een Bol-titelsjabloon schrijven en
 * toont per taal het sjabloon, de meldingen en vier voorbeelden, die
 * meebewegen als de beheerder het sjabloon aanpast. Pas "Overnemen" schrijft.
 */
class GenerateBolTitleAction
{
    public const PREVIEW_COUNT = 4;

    public static function make(): Action
    {
        return Action::make('generateBolTitle')
            ->label(__('Bol-titel genereren'))
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->visible(fn () => BolTitleGenerator::available())
            ->modalHeading(__('Bol-titel genereren'))
            ->modalDescription(__('De AI stelt een sjabloon voor met plaatshouders die per variant uit de filters worden ingevuld. Pas het gerust aan; de voorbeelden bewegen mee.'))
            ->modalWidth('3xl')
            ->modalSubmitActionLabel(__('Overnemen'))
            ->fillForm(function (ProductGroup $record, Action $action): array {
                try {
                    $proposal = (new BolTitleGenerator())->generate($record, BolTitleGenerator::localesFor($record));
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()
                        ->title(__('Genereren is mislukt'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    // Geen lege modal openen: zonder voorstel valt er niets
                    // over te nemen, en "Overnemen" zou dan alleen een tweede
                    // melding over de eerste heen zetten.
                    $action->cancel();
                }

                return ['templates' => $proposal->templates];
            })
            ->schema(fn (ProductGroup $record) => collect(BolTitleGenerator::localesFor($record))
                ->map(fn (string $locale) => Section::make(strtoupper($locale))
                    ->schema([
                        Textarea::make("templates.{$locale}")
                            ->label(__('Sjabloon'))
                            ->rows(2)
                            ->live(debounce: 750),
                        TextEntry::make("problems_{$locale}")
                            ->label(__('Let op'))
                            ->state(fn (Get $get) => self::problems($record, $locale, (string) $get("templates.{$locale}")))
                            ->listWithLineBreaks()
                            ->color('danger')
                            // Een lege lijst zou alleen een losse kop "Let op" tonen.
                            ->visible(fn (Get $get) => self::problems($record, $locale, (string) $get("templates.{$locale}")) !== []),
                        TextEntry::make("previews_{$locale}")
                            ->label(__('Voorbeelden'))
                            ->state(fn (Get $get) => BolTitleTemplate::renderForSets(
                                (string) $get("templates.{$locale}"),
                                array_slice(BolTitleContext::for($record, $locale)['sets'], 0, self::PREVIEW_COUNT),
                            ))
                            ->listWithLineBreaks()
                            ->visible(fn (Get $get) => filled($get("templates.{$locale}"))),
                    ]))
                ->all())
            ->action(function (array $data, ProductGroup $record, mixed $livewire): void {
                $templates = $data['templates'] ?? [];
                $written = BolTitleWriter::write($record, $templates);

                if ($written === 0) {
                    Notification::make()
                        ->title(__('Er is geen sjabloon opgeslagen'))
                        ->warning()
                        ->send();

                    return;
                }

                self::syncEditPageState($livewire, $record, $templates);

                Notification::make()
                    ->title(__('Bol-titel opgeslagen'))
                    ->body(__('De feed wordt op de achtergrond bijgewerkt.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Zet het net geschreven sjabloon ook in de formulierstaat van de
     * bewerkpagina. HasCustomBlocksTab schrijft bij "Opslaan" de hele
     * blokkenstaat van de actieve taal terug met setTranslation(), dus zonder
     * dit zet de eerstvolgende opslag het oude sjabloon (of niets) terug. Geen
     * redirect: dan gaan andere, nog niet opgeslagen wijzigingen verloren.
     *
     * Andere talen houdt de pagina niet in de formulierstaat vast: bij het
     * wisselen van taal leest HasCustomBlocksTab de blokken opnieuw van de
     * relatie customBlocks, en die moet dus vers zijn. otherLocaleData bevat
     * alleen de vertaalbare kolommen van de groep zelf; staat daar toch een
     * customBlocks-sleutel in, dan werken we die voor de zekerheid ook bij.
     *
     * @param  array<string, string|null>  $templates
     */
    protected static function syncEditPageState(mixed $livewire, ProductGroup $record, array $templates): void
    {
        if (! $livewire instanceof EditRecord) {
            return;
        }

        $pageRecord = $livewire->getRecord();
        if (! $pageRecord instanceof ProductGroup || $pageRecord->getKey() !== $record->getKey()) {
            return;
        }

        $pageRecord->unsetRelation('customBlocks');

        $templates = array_filter(
            array_map(fn ($template) => trim((string) $template), $templates),
            fn (string $template) => $template !== ''
        );

        $activeLocale = method_exists($livewire, 'getActiveSchemaLocale')
            ? ($livewire->getActiveSchemaLocale() ?? app()->getLocale())
            : app()->getLocale();

        if (isset($templates[$activeLocale]) && is_array($livewire->data['customBlocks'] ?? null)) {
            $livewire->data['customBlocks'][BolTitleWriter::KEY] = $templates[$activeLocale];
        }

        if (property_exists($livewire, 'otherLocaleData') && is_array($livewire->otherLocaleData)) {
            foreach ($livewire->otherLocaleData as $locale => $localeData) {
                if (isset($templates[$locale]) && is_array($localeData['customBlocks'] ?? null)) {
                    $livewire->otherLocaleData[$locale]['customBlocks'][BolTitleWriter::KEY] = $templates[$locale];
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    protected static function problems(ProductGroup $record, string $locale, string $template): array
    {
        if (trim($template) === '') {
            return [];
        }

        $context = BolTitleContext::for($record, $locale);

        return BolTitleRules::problems($template, $context['variables'], $context['sets']);
    }
}
