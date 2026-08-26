<?php

declare(strict_types=1);

namespace Dashed\DashedEcommerceBol\Newsletter;

use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedNewsletter\Facades\Newsletter;
use Dashed\DashedNewsletter\Models\NewsletterSubscriber;
use Dashed\DashedNewsletter\Models\NewsletterSuppression;

/**
 * Houdt klanten die via Bol.com kochten uit de nieuwsbrief.
 *
 * Die klant is klant van Bol en heeft jou geen toestemming gegeven om hem te
 * mailen. NewsletterOrderAPI slaat een Bol-bestelling al over bij het
 * aanmelden, maar dat dekt maar een weg naar binnen: een import, een
 * handmatige toevoeging of een koppeling brengt zo'n adres alsnog op een
 * lijst. Vandaar dat het adres hier ook op de blokkadelijst komt, want die
 * wordt bij elke verzending getoetst.
 *
 * Deze klasse staat in het bol-pakket en niet in dashed-newsletter, om
 * dezelfde reden als NewsletterOrderAPI in het webshoppakket staat: de
 * nieuwsbriefmodule hoort niet te weten dat marktplaatsen bestaan.
 */
class ExcludeBolCustomers
{
    /**
     * Bronnen die betekenen dat iemand zich zelf bij jou heeft aangemeld.
     *
     * Zo iemand blijft staan, ook als hij daarnaast via Bol koopt: die
     * toestemming heeft hij aan jou gegeven en niet aan Bol. Afrekenen met een
     * vinkje telt mee, want dat is net zo goed een eigen handeling; een
     * Bol-bestelling komt daar sowieso nooit doorheen, want
     * NewsletterOrderAPI weigert die al.
     *
     * Wat er niet in staat is bewust: een import ('laposta') draagt een
     * toestemming die wij niet kunnen nagaan, en 'handmatig' of 'app'
     * betekent dat iemand het adres heeft ingetypt. Dat laatste is precies
     * wat deze regel moet voorkomen.
     */
    public const EIGEN_AANMELDING = ['formulier', 'popup', 'bestelling', 'afmeldpagina'];

    /** Blokkeer en verwijder de klant achter deze bestelling, als het er een van Bol is. */
    public static function forOrder(Order $order): void
    {
        if ((string) ($order->order_origin ?? '') !== 'Bol') {
            return;
        }

        self::forEmail((string) ($order->site_id ?? ''), (string) ($order->email ?? ''));
    }

    public static function forEmail(string $siteId, string $email): void
    {
        $email = mb_strtolower(trim($email));

        if ($email === '' || $siteId === '') {
            return;
        }

        $contacten = NewsletterSubscriber::where('email', $email)->get();

        // Heeft dit adres ergens een eigen aanmelding, dan blijft alles staan.
        // Blokkeren en tegelijk de inschrijving sparen zou elkaar tegenspreken:
        // hij staat er dan wel op maar krijgt niets.
        foreach ($contacten as $contact) {
            if (in_array((string) $contact->source, self::EIGEN_AANMELDING, true)) {
                return;
            }
        }

        NewsletterSuppression::block($siteId, $email, NewsletterSuppression::REASON_MARKETPLACE, 'bol');

        foreach ($contacten as $contact) {
            if ($contact->status !== NewsletterSubscriber::STATUS_ACTIVE) {
                continue;
            }

            // Cleaned en niet unsubscribed: hij heeft zich niet afgemeld, wij
            // halen hem eraf. Dat verschil hoort in de tijdlijn te staan, en
            // het houdt de afmeldcijfers van een campagne eerlijk: anders
            // tellen honderden systeemacties mee als afmeldingen.
            Newsletter::changeStatus(
                subscriber: $contact,
                status: NewsletterSubscriber::STATUS_CLEANED,
                source: 'bol',
            );
        }
    }
}
