<?php

namespace Dashed\DashedEcommerceBol\Classes;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedEcommerceCore\Models\ProductGroup;
use Dashed\DashedEcommerceCore\Classes\BolTitleTemplate;

/**
 * De plaatshouders en voorbeeldsets van een groep in één taal. Gecachet omdat
 * de modal bij elke wijziging van het sjabloon opnieuw controleert, en de
 * sets per product een handvol queries kosten.
 */
class BolTitleContext
{
    public const CACHE_SECONDS = 600;

    /**
     * @return array{variables: array<string, array{name: string, kind: string, options: list<string>}>, sets: list<array<string, mixed>>}
     */
    public static function for(ProductGroup $group, string $locale, bool $fresh = false): array
    {
        $key = "bol-title-context:{$group->id}:{$locale}";

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::CACHE_SECONDS, fn () => self::inLocale($locale, fn () => [
            'variables' => BolTitleTemplate::variables($group),
            'sets' => BolTitleTemplate::attributeSets($group),
        ]));
    }

    /**
     * De feed wordt per taal onder App::setLocale() gebouwd en leest namen in
     * de actieve taal; doe hier hetzelfde, en zet de taal altijd terug.
     */
    public static function inLocale(string $locale, callable $callback): mixed
    {
        $original = App::getLocale();
        App::setLocale($locale);

        try {
            return $callback();
        } finally {
            App::setLocale($original);
        }
    }
}
