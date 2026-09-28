<?php

namespace Dashed\DashedEcommerceBol\Classes;

use Illuminate\Support\Str;
use Dashed\DashedAi\Facades\Ai;
use Dashed\DashedCore\Classes\Locales;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\ProductGroup;
use Dashed\DashedEcommerceBol\Exceptions\BolTitleGenerationFailed;

/**
 * Laat de AI per taal een Bol-titelsjabloon voor een productgroep schrijven.
 * Het sjabloon gaat door BolTitleRules; bij een fout krijgt de AI één
 * herkansing met de meldingen erbij, en wat daarna nog fout is komt als
 * waarschuwing in het voorstel. Er wordt hier niets opgeslagen.
 */
class BolTitleGenerator
{
    public const BRAND_NAMES = ['merk', 'brand'];

    public static function available(): bool
    {
        return class_exists(Ai::class) && Ai::hasProvider();
    }

    /**
     * @return list<string>
     */
    public static function localesFor(ProductGroup $group): array
    {
        return collect(Locales::getLocales())
            ->pluck('id')
            ->filter(fn ($locale) => filled($group->getTranslation('name', $locale, false)))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $locales
     */
    public function generate(ProductGroup $group, array $locales): BolTitleProposal
    {
        $templates = [];
        $warnings = [];

        foreach ($locales as $locale) {
            $context = BolTitleContext::for($group, $locale, fresh: true);
            $prompt = BolTitleContext::inLocale($locale, fn () => self::prompt($group, $locale, $context));

            $template = $this->ask($prompt);
            $problems = BolTitleRules::problems($template, $context['variables'], $context['sets']);

            if ($problems !== []) {
                $template = $this->ask($prompt
                    . "\n\nJe vorige voorstel was: \"{$template}\". Dat is afgekeurd om deze redenen:\n- "
                    . implode("\n- ", $problems)
                    . "\nLever een verbeterd sjabloon.");
                $problems = BolTitleRules::problems($template, $context['variables'], $context['sets']);
            }

            $templates[$locale] = $template;
            $warnings[$locale] = $problems;
        }

        return new BolTitleProposal($templates, $warnings);
    }

    /**
     * @param  array{variables: array, sets: list<array>}  $context
     */
    public static function prompt(ProductGroup $group, string $locale, array $context): string
    {
        $name = (string) $group->getTranslation('name', $locale, false);
        $description = Str::of(cms()->convertToHtml($group->getTranslation('description', $locale, false) ?: ''))
            ->stripTags()
            ->squish()
            ->limit(600);
        $categories = $group->productCategories()->get()->pluck('name')->filter()->implode(', ');

        $variableLines = [];
        foreach ($context['variables'] as $key => $variable) {
            $kind = match ($variable['kind']) {
                'filter' => 'verschilt per variant, MOET in het sjabloon',
                'group_filter' => 'eigenschap van de groep',
                'group_characteristic' => 'vast kenmerk van de groep',
                default => 'kenmerk dat per product kan verschillen',
            };
            $options = $variable['options'] !== [] ? ' (bijvoorbeeld: ' . implode(', ', array_slice($variable['options'], 0, 8)) . ')' : '';
            $variableLines[] = "- :{$key}: = {$variable['name']}, {$kind}{$options}";
        }

        $brandKey = collect(self::BRAND_NAMES)->first(fn ($key) => isset($context['variables'][$key]));
        $brand = (string) Customsetting::get('bol_title_brand');
        $brandLine = match (true) {
            $brandKey !== null => "Het merk staat in de plaatshouder :{$brandKey}:; zet die vooraan.",
            $brand !== '' => "Het merk is \"{$brand}\"; zet het letterlijk vooraan.",
            default => 'Er is geen merk bekend; begin met de serie of productnaam.',
        };

        $instructions = trim((string) Customsetting::get('bol_title_instructions'));
        $max = BolTitleRules::MAX_LENGTH;
        $banned = implode(', ', BolTitleRules::BANNED_WORDS);

        return implode("\n", array_filter([
            "Schrijf een titelsjabloon voor Bol.com voor een productgroep, in de taal met code \"{$locale}\".",
            'Het sjabloon bevat plaatshouders die per variant worden ingevuld. Gebruik alleen de plaatshouders hieronder, precies zo geschreven, in kleine letters, met een dubbele punt ervoor en erna.',
            '',
            "Productgroep: {$name}",
            $categories !== '' ? "Categorieën: {$categories}" : null,
            (string) $description !== '' ? "Omschrijving: {$description}" : null,
            '',
            'Plaatshouders:',
            $variableLines !== [] ? implode("\n", $variableLines) : '- (geen)',
            '',
            $brandLine,
            '',
            'Regels van Bol:',
            '- Volgorde: merk, serie of productnaam, producttype, belangrijkste vaste kenmerken, dan de kenmerken die per variant verschillen.',
            "- Na invullen maximaal {$max} tekens.",
            "- Geen reclamewoorden ({$banned}), geen prijzen, geen emoji.",
            '- Geen woorden helemaal in hoofdletters, behalve een merk dat zo geschreven wordt.',
            '- Geen | of !. Gebruik een streepje of komma als scheiding.',
            '- Elke plaatshouder die "MOET in het sjabloon" heeft, komt erin; anders krijgen varianten dezelfde titel.',
            $instructions !== '' ? "\nExtra aanwijzingen van de winkel: {$instructions}" : null,
            '',
            'Antwoord als JSON: {"template": "<het sjabloon>"}. Geen uitleg.',
        ], fn ($line) => $line !== null));
    }

    protected function ask(string $prompt): string
    {
        $result = Ai::json($prompt, ['skip_tone_of_voice' => true]);
        $template = is_array($result) ? trim((string) ($result['template'] ?? '')) : '';

        if ($template === '') {
            throw new BolTitleGenerationFailed(__('De AI gaf geen bruikbaar sjabloon terug.'));
        }

        return $template;
    }
}
