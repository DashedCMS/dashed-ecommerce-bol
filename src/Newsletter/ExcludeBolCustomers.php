<?php

declare(strict_types=1);

namespace Dashed\DashedEcommerceBol\Newsletter;

use Illuminate\Support\Facades\DB;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedNewsletter\Facades\Newsletter;
use Dashed\DashedNewsletter\Models\NewsletterList;
use Dashed\DashedNewsletter\Models\NewsletterSubscriber;
use Dashed\DashedNewsletter\Models\NewsletterSuppression;
use Dashed\DashedNewsletter\Exceptions\SuppressedEmailException;

/**
 * Houdt klanten die via Bol.com kochten uit de nieuwsbrief.
 *
 * Die klant is klant van Bol en heeft jou geen toestemming gegeven om hem te
 * mailen. Tot v4.15.0 gold alleen order_origin = Bol, en dat liet twee gaten:
 * een kassa- of vervangende order met het doorstuuradres van Bol
 * (@verkopen.bol.com) telde als gewone bestelling en werd ingeschreven, en een
 * contact met bron "bestelling" gold hier als eigen aanmelding en bleef staan.
 * Nu geldt het adres zelf: elk adres op een Bol-domein, langs welke weg ook, en
 * elk adres van een Bol-bestelling dat zich niet zelf heeft aangemeld.
 *
 * Deze klasse staat in het bol-pakket en niet in dashed-newsletter, om
 * dezelfde reden als NewsletterOrderAPI in het webshoppakket staat: de
 * nieuwsbriefmodule hoort niet te weten dat marktplaatsen bestaan.
 */
class ExcludeBolCustomers
{
    /**
     * Bronnen die betekenen dat iemand zijn adres zelf bij jou heeft ingevuld.
     *
     * Zo iemand blijft staan, ook als hij daarnaast via Bol koopt: die
     * toestemming heeft hij aan jou gegeven en niet aan Bol. Geldt nooit voor
     * een adres op een Bol-domein: dat is een doorstuuradres van Bol.
     *
     * "bestelling" staat er bewust niet in: langs die weg kwamen de
     * Bol-adressen binnen. Een import ('laposta'), 'handmatig' en 'app' ook
     * niet: daar heeft iemand anders het adres ingevoerd.
     */
    public const EIGEN_AANMELDING = ['formulier', 'popup', 'afmeldpagina'];

    /** @var array<int, string> */
    protected static array $extraEigenAanmelding = [];

    /** Een project met een eigen aanmeldbron (een actielijst met eigen formulier) meldt die hier aan. */
    public static function eigenAanmelding(string ...$bronnen): void
    {
        self::$extraEigenAanmelding = array_values(array_unique([...self::$extraEigenAanmelding, ...$bronnen]));
    }

    public static function isEigenAanmelding(?string $bron): bool
    {
        return in_array((string) $bron, [...self::EIGEN_AANMELDING, ...self::$extraEigenAanmelding], true);
    }

    /**
     * Hangt de bewaking aan de modellen. Op de Eloquent-gebeurtenissen en niet
     * op OrderCreatedEvent: dat event vuurt alleen vanuit de checkout, en de
     * Bol-sync en de kassa slaan een bestelling op met een kale save().
     */
    public static function register(): void
    {
        NewsletterSubscriber::saving(fn (NewsletterSubscriber $contact) => self::guard($contact));

        Order::saved(function (Order $order): void {
            if (! $order->wasRecentlyCreated && ! $order->wasChanged('email')) {
                return;
            }

            // Binnen rescue(): de nieuwsbrief mag de sync of de kassa nooit stoppen.
            rescue(fn () => self::forOrder($order));
        });
    }

    public static function isBolDomain(?string $email): bool
    {
        $domein = (string) substr(strrchr(mb_strtolower(trim((string) $email)), '@') ?: '', 1);

        return $domein === 'bol.com' || str_ends_with($domein, '.bol.com');
    }

    public static function isBolCustomer(?string $email, ?string $bron = null): bool
    {
        $email = mb_strtolower(trim((string) $email));

        if ($email === '') {
            return false;
        }

        if (self::isBolDomain($email)) {
            return true;
        }

        if (self::isEigenAanmelding($bron)) {
            return false;
        }

        return DB::table('dashed__orders')->where('order_origin', 'Bol')->where('email', $email)->exists();
    }

    /**
     * Een Bol-adres wordt nooit actief, langs welke weg het ook binnenkomt:
     * bestelling, import, handmatig, koppeling of een rechtstreekse create().
     *
     * @throws SuppressedEmailException
     */
    public static function guard(NewsletterSubscriber $contact): void
    {
        if ($contact->status !== NewsletterSubscriber::STATUS_ACTIVE) {
            return;
        }

        if ($contact->exists && ! $contact->isDirty(['status', 'email', 'source'])) {
            return;
        }

        if (! self::isBolCustomer($contact->email, $contact->source)) {
            return;
        }

        throw SuppressedEmailException::for(NewsletterSuppression::block(
            self::siteOf($contact),
            (string) $contact->email,
            NewsletterSuppression::REASON_MARKETPLACE,
            'bol',
        ));
    }

    /** Blokkeer en verwijder de klant achter deze bestelling: een Bol-bestelling, of elke bestelling met een Bol-adres. */
    public static function forOrder(Order $order): void
    {
        $email = (string) ($order->email ?? '');

        if ((string) ($order->order_origin ?? '') !== 'Bol' && ! self::isBolDomain($email)) {
            return;
        }

        self::forEmail((string) ($order->site_id ?: Sites::getActive()), $email);
    }

    /** Zet het adres op de blokkadelijst en haalt het van elke lijst. Geeft het aantal verwijderde inschrijvingen. */
    public static function forEmail(string $siteId, string $email): int
    {
        $email = mb_strtolower(trim($email));

        if ($email === '' || $siteId === '') {
            return 0;
        }

        $contacten = NewsletterSubscriber::where('email', $email)->get();

        // Heeft dit adres ergens een eigen aanmelding, dan blijft alles staan.
        // Blokkeren en tegelijk de inschrijving sparen zou elkaar tegenspreken:
        // hij staat er dan wel op maar krijgt niets. Een Bol-domein heeft nooit
        // een eigen aanmelding.
        if (! self::isBolDomain($email)) {
            foreach ($contacten as $contact) {
                if (self::isEigenAanmelding($contact->source)) {
                    return 0;
                }
            }
        }

        NewsletterSuppression::block($siteId, $email, NewsletterSuppression::REASON_MARKETPLACE, 'bol');

        $aantal = 0;

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
            $aantal++;
        }

        return $aantal;
    }

    /**
     * Alle adressen die uitgesloten horen te zijn: van elke Bol-bestelling,
     * van elke bestelling met een Bol-adres en van elk contact op een Bol-domein.
     *
     * @return array<string, string> e-mailadres => site
     */
    public static function addresses(): array
    {
        $adressen = [];

        DB::table('dashed__orders')
            ->whereNotNull('email')
            ->where(fn ($q) => $q->where('order_origin', 'Bol')->orWhere('email', 'like', '%bol.com'))
            ->select('id', 'site_id', 'email', 'order_origin')
            ->orderBy('id')
            ->chunk(1000, function ($orders) use (&$adressen): void {
                foreach ($orders as $order) {
                    $email = mb_strtolower(trim((string) $order->email));

                    if ($email !== '' && ($order->order_origin === 'Bol' || self::isBolDomain($email))) {
                        $adressen[$email] ??= (string) ($order->site_id ?: Sites::getActive());
                    }
                }
            });

        foreach (NewsletterSubscriber::where('email', 'like', '%bol.com')->get() as $contact) {
            $email = mb_strtolower(trim((string) $contact->email));

            if (self::isBolDomain($email)) {
                $adressen[$email] ??= self::siteOf($contact);
            }
        }

        return $adressen;
    }

    /**
     * Met terugwerkende kracht: elk Bol-adres geblokkeerd en van elke lijst af. Idempotent.
     *
     * @return array{adressen: int, verwijderd: int}
     */
    public static function cleanUp(): array
    {
        $adressen = self::addresses();
        $verwijderd = 0;

        foreach ($adressen as $email => $siteId) {
            $verwijderd += self::forEmail($siteId, (string) $email);
        }

        return ['adressen' => count($adressen), 'verwijderd' => $verwijderd];
    }

    protected static function siteOf(NewsletterSubscriber $contact): string
    {
        return (string) (NewsletterList::find($contact->newsletter_list_id)?->site_id ?: Sites::getActive());
    }
}
