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
    public const MAX_LENGTH = 150;

    public const BANNED_WORDS = ['gratis', 'actie', 'beste', 'aanbieding', 'nieuw'];

    public const FORBIDDEN_CHARACTERS = ['|', '!'];

    /**
     * @param  array<string, array{name: string, kind: string, options: list<string>}>  $variables
     * @param  list<array<string, mixed>>  $attributeSets
     * @return list<string>
     */
    public static function problems(string $template, array $variables, array $attributeSets): array
    {
        if (trim($template) === '') {
            return [__('Het sjabloon is leeg.')];
        }

        $problems = [];

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

        foreach (self::BANNED_WORDS as $word) {
            if (preg_match('/(?<![\p{L}])' . preg_quote($word, '/') . '(?![\p{L}])/iu', $rest)) {
                $problems[] = __('Bevat het reclamewoord ":woord".', ['woord' => $word]);
            }
        }

        foreach (self::FORBIDDEN_CHARACTERS as $character) {
            if (str_contains($rest, $character)) {
                $problems[] = __('Bevat het teken ":teken".', ['teken' => $character]);
            }
        }

        return $problems;
    }
}
