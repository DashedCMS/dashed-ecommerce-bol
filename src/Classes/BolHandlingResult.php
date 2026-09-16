<?php

namespace Dashed\DashedEcommerceBol\Classes;

/** De afhandelresultaten van de Bol Retailer API die wij gebruiken. */
class BolHandlingResult
{
    public const RECEIVED = 'RETURN_RECEIVED';
    public const NOT_MEETING_CONDITIONS = 'RETURN_DOES_NOT_MEET_CONDITIONS';
    public const CUSTOMER_KEEPS_PAID = 'CUSTOMER_KEEPS_PRODUCT_PAID';

    /** @return array<string, string> keuzes voor de beheerder bij sluiten en afkeuren */
    public static function options(): array
    {
        return [
            self::NOT_MEETING_CONDITIONS => __('Voldoet niet aan de voorwaarden (klant krijgt niets terug)'),
            self::CUSTOMER_KEEPS_PAID => __('Klant houdt het product en krijgt zijn geld'),
        ];
    }
}
