<?php

namespace Dashed\DashedEcommerceBol\Commands;

use Illuminate\Console\Command;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\OrderReturn;
use Dashed\DashedEcommerceBol\Jobs\HandleBolReturnJob;
use Dashed\DashedEcommerceBol\Classes\BolReturnImporter;

/**
 * Elk kwartier: open Bol-retouren binnenhalen, en retouren in een eindstand
 * waarvan de terugmelding aan Bol nog niet gelukt is opnieuw klaarzetten.
 */
class SyncBolReturnsCommand extends Command
{
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

        $retry = OrderReturn::query()
            ->whereNotNull('bol_return_id')
            ->whereNull('bol_handled_at')
            ->whereIn('status', [OrderReturn::STATUS_HANDLED, OrderReturn::STATUS_CLOSED, OrderReturn::STATUS_REJECTED])
            ->where('updated_at', '>=', now()->subDays(30))
            ->orderBy('id')
            ->get();
        foreach ($retry as $return) {
            HandleBolReturnJob::dispatch($return);
        }
        $this->info(__(':aantal terugmelding(en) opnieuw klaargezet.', ['aantal' => $retry->count()]));

        return self::SUCCESS;
    }
}
