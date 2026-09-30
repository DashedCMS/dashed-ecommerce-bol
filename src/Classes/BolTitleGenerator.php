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
     * Waarmee de titel moet beginnen: de plaatshouder van een kenmerk of
     * filter Merk van de groep, anders het merk uit de instelling, anders
     * niets. Gedeeld door de prompt en de controle.
     *
     * @param  array<string, array{name: string, kind: string, options: list<string>}>  $variables
     */
    public static function brandStart(array $variables): ?string
    {
        $brandKey = collect(self::BRAND_NAMES)->first(fn ($key) => isset($variables[$key]));
        if ($brandKey !== null) {
            return ':' . $brandKey . ':';
        }

        $brand = trim((string) Customsetting::get('bol_title_brand'));

        return $brand !== '' ? $brand : null;
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

            $brand = self::brandStart($context['variables']);

            $template = $this->ask($prompt);
            $problems = BolTitleRules::problems($template, $context['variables'], $context['sets'], $brand);

            if ($problems !== []) {
                $template = $this->ask($prompt
                    . "\n\nJe vorige voorstel was: \"{$template}\". Dat is afgekeurd om deze redenen:\n- "
                    . implode("\n- ", $problems)
                    . "\nLever een verbeterd sjabloon.");
                $problems = BolTitleRules::problems($template, $context['variables'], $context['sets'], $brand);
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

        $brand = self::brandStart($context['variables']);
        $brandLine = match (true) {
            $brand === null => 'Er is geen merk bekend; begin met de serie of productnaam.',
            str_starts_with($brand, ':') => "Het merk staat in de plaatshouder {$brand}; begin het sjabloon daarmee.",
            default => "Het merk is \"{$brand}\"; begin het sjabloon letterlijk daarmee.",
        };

        $instructions = trim((string) Customsetting::get('bol_title_instructions'));
        $max = BolTitleRules::MAX_LENGTH;
        $target = BolTitleRules::TARGET_LENGTH;
        $visible = BolTitleRules::VISIBLE_ON_MOBILE;
        $banned = implode(', ', BolTitleRules::BANNED_WORDS);
        $characters = implode(' ', BolTitleRules::FORBIDDEN_CHARACTERS);

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
            'Een goede Bol-titel is kort, feitelijk en zoekbaar. Geen reclametekst.',
            '',
            'Opbouw, in deze volgorde:',
            '- Merk, serie of model, productnaam, producttype, dan 1 of 2 onderscheidende kenmerken (kleur, maat, materiaal, aantal).',
            '- Het format dat Bol aanhoudt: [Merk] [Serie] [Productnaam] - [Producttype] - [Kenmerk]. Scheid de delen met " - ".',
            '- Voorbeeld: Lovora Wave Vaas - 3D geprint - Zandbeige - 25 cm',
            '',
            'Lengte:',
            "- Op mobiel zijn alleen de eerste {$visible} tekens zichtbaar: merk en producttype moeten daarin staan.",
            "- Mik na invullen op hooguit {$target} tekens; de harde grens is {$max}.",
            '',
            'Niet doen:',
            "- Geen prijzen, acties of levertijden, en geen reclamewoorden ({$banned}).",
            '- Geen woorden in hoofdletters om iets te benadrukken; alleen een merk dat zo geschreven wordt.',
            "- Geen speciale tekens of symbolen ({$characters}) en geen emoji.",
            '- Geen verkopersinfo of winkelnaam, tenzij dat het merk is.',
            '- Geen volledige zinnen en geen vage of seizoensgebonden termen (zoals "perfect kerstcadeau").',
            '- Geen meerdere synoniemen achter elkaar (vaas, bloemenvaas, bloempot); Bol koppelt synoniemen zelf.',
            '- Getallen in cijfers: "2" en niet "twee".',
            '- Schrijf in de taal van het land waar verkocht wordt (de taalcode hierboven), niet in het Engels.',
            '- Elke plaatshouder die "MOET in het sjabloon" heeft, komt erin; anders krijgen varianten dezelfde titel. Houd de variantkenmerken achteraan.',
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
