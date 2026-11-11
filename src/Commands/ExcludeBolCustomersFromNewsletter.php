<?php

declare(strict_types=1);

namespace Dashed\DashedEcommerceBol\Commands;

use Illuminate\Console\Command;
use Dashed\DashedEcommerceBol\Newsletter\ExcludeBolCustomers;

/**
 * Loopt de bestaande Bol-adressen langs en sluit die klanten uit.
 *
 * Vanaf nu gaat dat vanzelf bij elke nieuwe bestelling; dit is voor alles wat
 * er al stond. Veilig om vaker te draaien: blokkeren is idempotent en een
 * contact dat al weg is wordt overgeslagen.
 */
class ExcludeBolCustomersFromNewsletter extends Command
{
    protected $signature = 'dashed:exclude-bol-newsletter-contacts {--dry-run : Alleen tonen wat er zou gebeuren}';

    protected $description = 'Blokkeer Bol.com-klanten voor de nieuwsbrief en haal ze van de lijsten.';

    public function handle(): int
    {
        if (! app()->bound('newsletter')) {
            $this->warn('De nieuwsbriefmodule is niet geinstalleerd; er valt niets uit te sluiten.');

            return self::SUCCESS;
        }

        $adressen = collect(ExcludeBolCustomers::addresses())
            ->map(fn (string $siteId, string $email): array => ['email' => $email, 'site_id' => $siteId])
            ->values();

        $this->info($adressen->count() . ' uniek(e) Bol-adres(sen) gevonden.');

        if ($this->option('dry-run')) {
            $opLijst = \Dashed\DashedNewsletter\Models\NewsletterSubscriber::with('list')
                ->whereIn('email', $adressen->pluck('email')->all())
                ->where('status', \Dashed\DashedNewsletter\Models\NewsletterSubscriber::STATUS_ACTIVE)
                ->orderBy('email')
                ->get();

            $this->line('Daarvan staan er nu ' . $opLijst->count() . ' actief op een nieuwsbrieflijst.');

            // De bron per contact is het spoor naar de weg waarlangs het adres
            // binnenkwam; zonder die kolom valt niet te zeggen welk lek dicht moet.
            if ($opLijst->isNotEmpty()) {
                $this->table(
                    ['E-mailadres', 'Lijst', 'Bron', 'Aangemeld op'],
                    $opLijst->map(fn ($contact): array => [
                        $contact->email,
                        $contact->list?->name ?? ('#' . $contact->newsletter_list_id),
                        (string) $contact->source,
                        (string) ($contact->subscribed_at ?? $contact->created_at),
                    ])->all(),
                );
            }

            $this->comment('Niets gewijzigd (dry run).');

            return self::SUCCESS;
        }

        $verwijderd = 0;

        foreach ($adressen as $regel) {
            $verwijderd += ExcludeBolCustomers::forEmail($regel['site_id'], $regel['email']);
        }

        $this->line($verwijderd . ' inschrijving(en) van de lijsten gehaald.');
        $this->info('Klaar. Zie Communicatie, Blokkadelijst, reden "Marktplaats".');

        return self::SUCCESS;
    }
}
