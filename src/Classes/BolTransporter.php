<?php

namespace Dashed\DashedEcommerceBol\Classes;

use Dashed\DashedCore\Models\Customsetting;

/**
 * De transporteur waarmee een verzending zonder track & trace aan Bol wordt
 * gemeld. Bol accepteert een shipment met alleen een transporterCode; een
 * bestelling die op Verzonden gaat zonder code (briefpost, of een beheerder
 * die de status met de hand zet) gaat daarmee tóch naar Bol, in plaats van
 * stil te blijven staan terwijl Bol de levertijd ziet verlopen.
 */
class BolTransporter
{
    public const SETTING_WITHOUT_TNT = 'bol_transporter_without_tnt';

    public const DEFAULT_WITHOUT_TNT = 'BRIEFPOST';

    /** @return array<string, string> Transportercodes van de Bol Retailer API. */
    public static function options(): array
    {
        return [
            'BRIEFPOST' => __('Briefpost (zonder track & trace)'),
            'TNT_BRIEF' => __('PostNL brievenbuspakje'),
            'BPOST_BRIEF' => __('bpost brievenbuspost'),
            'DHL-GLOBAL-MAIL' => __('DHL Global Mail'),
            'TNT' => 'PostNL',
            'DHL' => 'DHL',
            'DHLFORYOU' => 'DHL For You',
            'DPD-NL' => 'DPD Nederland',
            'DPD-BE' => 'DPD België',
            'BPOST_BE' => 'bpost',
            'GLS' => 'GLS',
            'UPS' => 'UPS',
            'OTHER' => __('Anders'),
        ];
    }

    public static function withoutTrackAndTrace(?string $siteId = null): string
    {
        $code = Customsetting::get(self::SETTING_WITHOUT_TNT, $siteId);

        return $code !== null && array_key_exists($code, self::options()) ? $code : self::DEFAULT_WITHOUT_TNT;
    }
}
