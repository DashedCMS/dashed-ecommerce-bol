<?php

namespace Dashed\DashedEcommerceBol\Classes;

use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\Product;

/**
 * De levertijd (deliveryCode) die een product op Bol krijgt. Het CMS praat
 * niet met de Offers API van Bol: het aanbod loopt via de productfeed naar
 * Channable, en Channable zet de levertijd op het aanbod. Daarom gaat de code
 * als feedveld `bol_delivery_code` mee, en koppelt de winkel dat veld in
 * Channable één keer aan de levertijd.
 *
 * Een pre-order product (print-on-demand: geen directe voorraad, wel
 * verkoopbaar) krijgt de ruimere levertijd uit de Bol-instellingen; de
 * overige producten houden hun huidige levertijd, tenzij daar bewust een
 * standaard voor is ingesteld.
 */
class BolDeliveryCode
{
    public const DEFAULT_PRE_ORDER = '3-5d';

    public const SETTING_PRE_ORDER = 'bol_delivery_code_pre_order';

    public const SETTING_DEFAULT = 'bol_delivery_code_default';

    /** @return array<string, string> De deliveryCodes van de Bol Retailer API. */
    public static function options(): array
    {
        $options = [];
        foreach (range(23, 12) as $hour) {
            $options["24uurs-{$hour}"] = __('Besteld voor :uur:00, morgen in huis', ['uur' => $hour]);
        }

        return $options + [
            '1-2d' => __('1-2 werkdagen'),
            '2-3d' => __('2-3 werkdagen'),
            '3-5d' => __('3-5 werkdagen'),
            '4-8d' => __('4-8 werkdagen'),
            '1-8d' => __('1-8 werkdagen'),
            'MijnLeverbelofte' => __('Mijn leverbelofte (Bol bepaalt)'),
            'VVB' => __('Verzenden via bol.com (VVB)'),
        ];
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::options());
    }

    public static function preOrderCode(?string $siteId = null): string
    {
        $code = Customsetting::get(self::SETTING_PRE_ORDER, $siteId);

        return self::isValid($code) ? $code : self::DEFAULT_PRE_ORDER;
    }

    public static function defaultCode(?string $siteId = null): ?string
    {
        $code = Customsetting::get(self::SETTING_DEFAULT, $siteId);

        return self::isValid($code) ? $code : null;
    }

    /** Null betekent: niets meesturen, de levertijd in Channable of op Bol blijft staan. */
    public static function forProduct(Product $product): ?string
    {
        $siteId = self::siteIdFor($product);

        return $product->isPreorderable()
            ? self::preOrderCode($siteId)
            : self::defaultCode($siteId);
    }

    private static function siteIdFor(Product $product): ?string
    {
        $siteIds = $product->site_ids ?? [];

        return $siteIds[0] ?? (Sites::getFirstSite()['id'] ?? null);
    }
}
