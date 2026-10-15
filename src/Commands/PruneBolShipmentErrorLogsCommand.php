<?php

namespace Dashed\DashedEcommerceBol\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Models\OrderLog;

/**
 * Ruimt de identieke foutregels op die de oude kwartierronde elk kwartier
 * opnieuw in de tijdlijn schreef ("Fout bij synchroniseren van verzending met
 * Bol.com ..." en "Niet naar Bol.com gesynchroniseerd ..."). Per bestelling en
 * per onderscheiden regel blijven de eerste en de laatste staan, zodat
 * zichtbaar blijft wanneer het begon en tot wanneer het doorging. Zonder
 * --force wordt er niets verwijderd.
 */
class PruneBolShipmentErrorLogsCommand extends Command
{
    protected $signature = 'bol:prune-shipment-error-logs
        {--order=* : Beperk tot deze order-id(s)}
        {--force : Verwijder echt; zonder deze optie alleen tonen}';

    protected $description = 'Ruim herhaalde Bol-verzendfoutregels in de ordertijdlijn op (dry-run zonder --force)';

    public const PATTERNS = [
        'Fout bij synchroniseren van verzending%',
        'Niet naar Bol.com gesynchroniseerd%',
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $groups = OrderLog::query()
            ->select('order_id', 'note', DB::raw('COUNT(*) as aantal'), DB::raw('MIN(id) as eerste'), DB::raw('MAX(id) as laatste'))
            ->where(function ($q) {
                foreach (self::PATTERNS as $pattern) {
                    $q->orWhere('note', 'like', $pattern);
                }
            })
            ->when($this->option('order'), fn ($q, $ids) => $q->whereIn('order_id', $ids))
            ->groupBy('order_id', 'note')
            ->having('aantal', '>', 2)
            ->orderBy('order_id')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('Geen herhaalde Bol-foutregels gevonden.');

            return self::SUCCESS;
        }

        $totaal = 0;
        $rows = [];
        foreach ($groups as $group) {
            $weg = (int) $group->aantal - 2;
            $totaal += $weg;
            $order = Order::withTrashed()->find($group->order_id);
            $rows[] = [
                $group->order_id,
                $order?->invoice_id ?? '?',
                $order?->bol_order_id ?? '?',
                $group->aantal,
                $weg,
                mb_strimwidth((string) $group->note, 0, 70, '...'),
            ];
        }

        $this->table(['Order', 'Factuur', 'Bol-order', 'Regels', 'Te verwijderen', 'Regel'], $rows);
        $this->line(($force ? 'Verwijderen: ' : 'Dry-run, er wordt niets verwijderd. Te verwijderen met --force: ') . $totaal . ' regels over ' . $groups->pluck('order_id')->unique()->count() . ' bestelling(en).');

        if (! $force) {
            return self::SUCCESS;
        }

        $verwijderd = 0;
        foreach ($groups as $group) {
            $ids = OrderLog::query()
                ->where('order_id', $group->order_id)
                ->where('note', $group->note)
                ->whereNotIn('id', [$group->eerste, $group->laatste])
                ->pluck('id');

            foreach ($ids->chunk(1000) as $chunk) {
                // Definitief weg, geen soft delete: de rijen zijn ruis en
                // moeten de tabel ook echt kleiner maken.
                $verwijderd += OrderLog::query()->whereIn('id', $chunk->all())->forceDelete();
            }
        }

        $this->info("Verwijderd: {$verwijderd} regels.");

        return self::SUCCESS;
    }
}
