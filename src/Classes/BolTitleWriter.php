<?php

namespace Dashed\DashedEcommerceBol\Classes;

use Dashed\DashedEcommerceCore\Models\ProductGroup;
use Dashed\DashedEcommerceCore\Jobs\UpdateProductInformationJob;

/**
 * Schrijft een Bol-titelsjabloon in de custom blocks van een productgroep. Raakt
 * alleen de sleutel bol-product-title in de meegegeven talen. Zet daarna de
 * productsync klaar: bol_title staat in dashed__product_feed_data, en een
 * custom block opslaan vuurt de saved-hook van de groep niet.
 */
class BolTitleWriter
{
    public const KEY = 'bol-product-title';

    /**
     * @param  array<string, string|null>  $templates  locale => sjabloon
     */
    public static function write(ProductGroup $group, array $templates): int
    {
        $templates = array_filter(
            array_map(fn ($template) => trim((string) $template), $templates),
            fn (string $template) => $template !== ''
        );

        if ($templates === []) {
            return 0;
        }

        $customBlock = $group->customBlocks()->firstOrNew([]);
        $translations = $customBlock->exists ? $customBlock->getTranslations('blocks') : [];

        foreach ($templates as $locale => $template) {
            $blocks = is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
            $blocks[self::KEY] = $template;
            $translations[$locale] = $blocks;
        }

        $customBlock->setTranslations('blocks', $translations);
        $customBlock->save();

        $group->unsetRelation('customBlocks');

        UpdateProductInformationJob::dispatch($group)->onQueue('ecommerce');

        return count($templates);
    }

    public static function hasTemplate(ProductGroup $group): bool
    {
        $translations = $group->customBlocks?->getTranslations('blocks') ?? [];

        foreach ($translations as $blocks) {
            if (is_array($blocks) && filled($blocks[self::KEY] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
