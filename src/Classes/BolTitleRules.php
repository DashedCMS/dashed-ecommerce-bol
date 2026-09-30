<?php

namespace Dashed\DashedEcommerceBol\Classes;

use Illuminate\Support\Str;
use Dashed\DashedEcommerceCore\Classes\BolTitleTemplate;

/**
 * De controle op een Bol-titelsjabloon, los van de AI zodat de modal hem bij
 * elke wijziging opnieuw kan draaien. Geeft Nederlandse meldingen terug; een
 * lege lijst betekent dat het sjabloon mag.
 */
class BolTitleRules
{
    /**
     * Harde grens na invullen. De AI mikt op TARGET_LENGTH (korter scoort
     * beter bij Bol), maar een titel tussen die twee wordt niet geweigerd.
     */
    public const MAX_LENGTH = 100;

    public const TARGET_LENGTH = 70;

    /** Op mobiel toont Bol ongeveer zoveel tekens; merk en producttype horen erin. */
    public const VISIBLE_ON_MOBILE = 35;

    public const BANNED_WORDS = [
        'gratis', 'actie', 'beste', 'aanbieding', 'nieuw', 'korting', 'sale',
        'uitverkoop', 'goedkoop', 'perfect', 'morgen in huis', 'levertijd', 'kerstcadeau',
    ];

    public const FORBIDDEN_CHARACTERS = ['|', '!', '?', '&', '*', '#', '@', '€', '$', '<', '>', '=', '~', '^'];

    /** Getallen horen in cijfers. "een" ontbreekt bewust: dat is ook een lidwoord. */
    public const NUMBER_WORDS = ['twee', 'drie', 'vier', 'vijf', 'zes', 'zeven', 'acht', 'negen', 'tien'];

    /** Woorden in hoofdletters vanaf deze lengte; korter (XL, LED) mag. */
    public const SHOUTING_MIN_LETTERS = 4;

    /**
     * @param  array<string, array{name: string, kind: string, options: list<string>}>  $variables
     * @param  list<array<string, mixed>>  $attributeSets
     * @param  string|null  $brand  waarmee de titel moet beginnen: een plaatshouder als
     *                              :merk: of het merk letterlijk; null als er geen merk bekend is
     * @return list<string>
     */
    public static function problems(string $template, array $variables, array $attributeSets, ?string $brand = null): array
    {
        if (trim($template) === '') {
            return [__('Het sjabloon is leeg.')];
        }

        $problems = [];
        $brand = filled($brand) ? trim($brand) : null;

        if ($brand !== null && ! Str::startsWith(Str::lower(ltrim($template)), Str::lower($brand))) {
            $problems[] = __('Begin de titel met het merk (:merk).', ['merk' => $brand]);
        }

        // Bekende plaatshouders eerst wegstrepen: een naam als "Breedte (cm)"
        // past niet in het plaatshouderpatroon en zou anders als onbekend tellen.
        $rest = $template;
        $used = [];
        foreach (array_keys($variables) as $key) {
            if (str_contains($rest, ':' . $key . ':')) {
                $used[] = $key;
                $rest = str_replace(':' . $key . ':', ' ', $rest);
            }
        }

        preg_match_all(BolTitleTemplate::PLACEHOLDER_PATTERN, $rest, $matches);
        foreach (array_unique($matches[0]) as $placeholder) {
            $key = Str::lower(trim($placeholder, ':'));
            if (isset($variables[$key])) {
                // Verkeerd geschreven maar herkenbaar (bijv. :Kleur: voor :kleur:):
                // telt al als gebruikt, anders meldt de ontbrekende-filterlus hem nogmaals.
                $used[] = $key;
                $problems[] = __('Onbekende plaatshouder :plaatshouder. Schrijf hem als :juist.', ['plaatshouder' => $placeholder, 'juist' => ':' . $key . ':']);
            } else {
                $problems[] = __('Onbekende plaatshouder :plaatshouder.', ['plaatshouder' => $placeholder]);
            }
        }

        foreach ($variables as $key => $variable) {
            if ($variable['kind'] === 'filter' && ! in_array($key, $used, true)) {
                $problems[] = __('De variatiefilter :plaatshouder ontbreekt, dan krijgen varianten dezelfde titel.', ['plaatshouder' => ':' . $key . ':']);
            }
        }

        $longest = collect(BolTitleTemplate::renderForSets($template, $attributeSets))
            ->sortByDesc(fn (string $title) => mb_strlen($title))
            ->first();
        if ($longest !== null && mb_strlen($longest) > self::MAX_LENGTH) {
            $problems[] = __('Te lang: :lengte tekens voor ":titel" (maximaal :max).', [
                'lengte' => mb_strlen($longest),
                'titel' => $longest,
                'max' => self::MAX_LENGTH,
            ]);
        }

        // Het merk zelf mag hoofdletters of een & bevatten (H&M, IKEA), dus
        // de tekst- en tekencontroles kijken naar het sjabloon zonder merk.
        if ($brand !== null && ! str_starts_with($brand, ':')) {
            $rest = str_ireplace($brand, ' ', $rest);
        }

        foreach (self::BANNED_WORDS as $word) {
            if (self::containsWord($rest, $word)) {
                $problems[] = __('Bevat het reclamewoord ":woord".', ['woord' => $word]);
            }
        }

        foreach (self::NUMBER_WORDS as $word) {
            if (self::containsWord($rest, $word)) {
                $problems[] = __('Schrijf ":woord" als cijfer.', ['woord' => $word]);
            }
        }

        preg_match_all('/(?<![\p{L}\p{N}])\p{Lu}{' . self::SHOUTING_MIN_LETTERS . ',}(?![\p{L}\p{N}])/u', $rest, $shouting);
        foreach (array_unique($shouting[0]) as $word) {
            $problems[] = __('Schrijf ":woord" niet in hoofdletters.', ['woord' => $word]);
        }

        foreach (self::FORBIDDEN_CHARACTERS as $character) {
            if (str_contains($rest, $character)) {
                $problems[] = __('Bevat het teken ":teken".', ['teken' => $character]);
            }
        }

        if (preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $rest)) {
            $problems[] = __('Bevat een emoji.');
        }

        return $problems;
    }

    protected static function containsWord(string $text, string $word): bool
    {
        return (bool) preg_match('/(?<![\p{L}])' . preg_quote($word, '/') . '(?![\p{L}])/iu', $text);
    }
}
