<?php

namespace Dashed\DashedEcommerceBol\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\OrderLog;
use Dashed\DashedEcommerceCore\Models\OrderReturn;
use Dashed\DashedEcommerceBol\Jobs\HandleBolReturnJob;
use Dashed\DashedEcommerceBol\Classes\BolReturnImporter;

/**
 * Elk kwartier: open Bol-retouren binnenhalen, en retouren in een eindstand
 * waarvan de terugmelding aan Bol nog niet gelukt is opnieuw klaarzetten.
 *
 * Het venster van dertig dagen loopt op het moment dat de retour in zijn
 * eindstand kwam en niet op `updated_at`: elke mislukte poging schrijft
 * `bol_handle_error` en verzet daarmee `updated_at`, dus op `updated_at` zou
 * een retour die permanent faalt zichzelf eeuwig binnen het venster houden.
 * Daarnaast stopt het na MAX_ATTEMPTS pogingen: ruim tien uur kwartieren maal
 * de eigen herkansingen van de job. Wat Bol dan nog niet accepteert heeft een
 * mens nodig, geen wachtrij.
 */
class SyncBolReturnsCommand extends Command
{
    public const MAX_ATTEMPTS = 40;

    public const TAG_ABANDONED = 'order.bol-return-handle-abandoned';

    protected $signature = 'bol:sync-returns';

    protected $description = 'Haal open Bol-retouren binnen en meld verwerkte retouren terug aan Bol';

    public function handle(BolReturnImporter $importer): int
    {
        foreach (cms()->builder('sites') ?: [] as $site) {
            $siteId = (string) $site['id'];
            if (! Customsetting::get('bol_client_id', $siteId)) {
                continue;
            }
            $summary = $importer->import($siteId);
            $this->info(__('Site :site: :created aangemaakt, :skipped overgeslagen, :unmatched gemeld, :failed mislukt.', [
                'site' => $siteId, 'created' => $summary->created, 'skipped' => $summary->skipped, 'unmatched' => $summary->unmatched, 'failed' => $summary->failed,
            ]));
        }

        $candidates = OrderReturn::query()
            ->whereNotNull('bol_return_id')
            ->whereNull('bol_handled_at')
            ->whereIn('status', [OrderReturn::STATUS_HANDLED, OrderReturn::STATUS_CLOSED, OrderReturn::STATUS_REJECTED])
            ->where(DB::raw('COALESCE(processed_at, handled_at, closed_at, rejected_at)'), '>=', now()->subDays(30))
            ->orderBy('id')
            ->get();

        $dispatched = 0;
        $abandoned = 0;
        foreach ($candidates as $return) {
            if ((int) $return->bol_handle_attempts >= self::MAX_ATTEMPTS) {
                $abandoned += $this->markAbandoned($return) ? 1 : 0;

                continue;
            }
            HandleBolReturnJob::dispatch($return);
            $dispatched++;
        }
        $this->info(__(':aantal terugmelding(en) opnieuw klaargezet.', ['aantal' => $dispatched]));
        if ($abandoned) {
            $this->warn(__(':aantal terugmelding(en) opgegeven na te veel pogingen.', ['aantal' => $abandoned]));
        }

        return self::SUCCESS;
    }

    /**
     * Zet de prefix op `bol_handle_error` zodat de beheerder op de retour ziet
     * dat er niet meer geprobeerd wordt, en logt dat één keer: de prefix is
     * zelf de markering dat dit al gebeurd is. `bol_handle_attempts` groeit
     * niet meer zodra er niet meer gedispatcht wordt, dus de prefix blijft
     * gelijk en de tweede sync herkent hem.
     */
    protected function markAbandoned(OrderReturn $return): bool
    {
        $prefix = __('Opgegeven na :n pogingen', ['n' => (int) $return->bol_handle_attempts]);
        $error = (string) $return->bol_handle_error;
        if (str_starts_with($error, $prefix)) {
            return false;
        }

        $return->forceFill(['bol_handle_error' => $prefix . ($error !== '' ? ': ' . $error : '')])->save();
        OrderLog::createLog(
            orderId: $return->order_id,
            tag: self::TAG_ABANDONED,
            note: __('Terugmelding van Bol-retour :id opgegeven na :n pogingen.', ['id' => (string) $return->bol_return_id, 'n' => (int) $return->bol_handle_attempts]),
        );

        return true;
    }
}
