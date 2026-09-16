<?php

namespace Dashed\DashedEcommerceBol\Classes;

use Throwable;
use InvalidArgumentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Models\OrderLog;
use Dashed\DashedEcommerceCore\Models\OrderReturn;
use Dashed\DashedEcommerceCore\Models\OrderProduct;
use Dashed\DashedEcommerceCore\Services\OrderReturn\ReturnRegistrar;
use Dashed\DashedEcommerceCore\Services\OrderReturn\ReturnableLines;

/**
 * Zet open Bol-retouren om in gewone OrderReturns via ReturnRegistrar: direct
 * goedgekeurd, zonder klantmail (Bol praat met de klant), regels gekoppeld op
 * Bol-ordernummer en EAN. Idempotent op bol_return_id. Wat niet past wordt
 * gemeld en niet aangemaakt; het blijft bij Bol open tot iemand het oplost.
 */
class BolReturnImporter
{
    public const TAG_IMPORTED = 'order.bol-return-imported';

    public function __construct(
        protected BolReturns $bol,
        protected ReturnRegistrar $registrar,
        protected BolReturnUnmatchedNotifier $notifier,
    ) {
    }

    public function import(string $siteId): BolReturnImportSummary
    {
        $summary = new BolReturnImportSummary();

        foreach ($this->bol->open($siteId) as $bolReturn) {
            try {
                $this->importOne($siteId, (array) $bolReturn, $summary);
            } catch (Throwable $e) {
                $summary->failed++;
                report($e);
                $orderId = $this->findOrder($siteId, (array) $bolReturn)?->id;
                if ($orderId) {
                    OrderLog::createLog(orderId: $orderId, tag: 'order.bol-return-import-failed', note: 'Bol-retour ' . ($bolReturn['returnId'] ?? '?') . ': ' . $e->getMessage());
                }
            }
        }

        return $summary;
    }

    protected function importOne(string $siteId, array $bolReturn, BolReturnImportSummary $summary): void
    {
        $returnId = (string) ($bolReturn['returnId'] ?? '');
        $items = $bolReturn['returnItems'] ?? null;
        if (! is_array($items)) {
            throw new InvalidArgumentException('returnItems ontbreekt of is geen lijst');
        }
        $items = array_values(array_filter($items, fn ($i) => is_array($i) && ! ($i['handled'] ?? false)));

        if ($returnId === '' || ($bolReturn['fulfilmentMethod'] ?? 'FBR') !== 'FBR' || $items === []) {
            $summary->skipped++;

            return;
        }

        if (OrderReturn::query()->where('bol_return_id', $returnId)->exists()) {
            $summary->skipped++;

            return;
        }

        $order = $this->findOrder($siteId, $bolReturn);
        if (! $order) {
            $this->unmatched($siteId, $bolReturn, null, __('Bol-bestelling :id is niet bekend in het CMS.', ['id' => (string) ($items[0]['orderId'] ?? '?')]), $summary);

            return;
        }

        // Eén keer geladen, want Bol geeft één RMA per orderregel: twee
        // retouritems met dezelfde EAN horen dus bij twee verschillende
        // orderregels als die bestaan (Bol::syncOrder() maakt per Bol-
        // orderitem een eigen OrderProduct op bol_id). Zonder dit zou de
        // tweede regel de eerste orderregel claimen en de eerste rma-id
        // verdwijnen (samengevoegd door ReturnRegistrar::normalize()).
        $orderProducts = $order->orderProducts()->with('product')->orderBy('id')->get();
        $claimed = [];

        $lines = [];
        $rmaByProduct = [];
        foreach ($items as $item) {
            if ((string) ($item['orderId'] ?? '') !== (string) $order->bol_order_id) {
                $this->unmatched($siteId, $bolReturn, $order, __('De retourregels horen bij verschillende Bol-bestellingen.'), $summary);

                return;
            }
            $ean = (string) ($item['ean'] ?? '');
            $orderProduct = $this->findLine($orderProducts, $ean, $claimed);
            $quantity = (int) ($item['expectedQuantity'] ?? 0);
            if (! $orderProduct) {
                $reason = $this->hasMatchingLine($orderProducts, $ean)
                    ? __('Bol meldt meer retourregels voor EAN :ean dan er orderregels zijn.', ['ean' => $ean ?: '?'])
                    : __('EAN :ean staat niet op deze bestelling.', ['ean' => $ean ?: '?']);
                $this->unmatched($siteId, $bolReturn, $order, $reason, $summary);

                return;
            }
            if ($quantity < 1 || $quantity > ReturnableLines::remaining($orderProduct)) {
                $this->unmatched($siteId, $bolReturn, $order, __('Voor :naam vraagt Bol :aantal terug, maar er kan nog maar :restant.', ['naam' => $orderProduct->name, 'aantal' => $quantity, 'restant' => ReturnableLines::remaining($orderProduct)]), $summary);

                return;
            }
            $claimed[] = $orderProduct->id;
            $lines[] = [
                'order_product_id' => $orderProduct->id,
                'quantity' => $quantity,
                'return_reason_id' => null,
                'reason_note' => $this->reasonNote((array) ($item['returnReason'] ?? [])),
            ];
            $rmaByProduct[$orderProduct->id] = (string) ($item['rmaId'] ?? '');
        }

        $registered = (string) ($bolReturn['registrationDateTime'] ?? '');
        $first = $items[0];
        $note = __('Bol-retour :id, geregistreerd :datum, vervoerder :vervoerder, track & trace :code', [
            'id' => $returnId,
            'datum' => $registered ? Carbon::parse($registered)->format('d-m-Y H:i') : '?',
            'vervoerder' => (string) ($first['transporterName'] ?? '?'),
            'code' => (string) ($first['trackAndTrace'] ?? '?'),
        ]);

        try {
            $return = $this->registrar->register($order, $lines, ['notify_customer' => false, 'admin_note' => $note]);
        } catch (InvalidArgumentException $e) {
            $this->unmatched($siteId, $bolReturn, $order, $e->getMessage(), $summary);

            return;
        }

        $return->forceFill(['bol_return_id' => $returnId])->save();
        foreach ($return->lines as $line) {
            if (isset($rmaByProduct[$line->order_product_id])) {
                $line->forceFill(['bol_rma_id' => $rmaByProduct[$line->order_product_id]])->save();
            }
        }

        OrderLog::createLog(orderId: $order->id, tag: self::TAG_IMPORTED, note: __('Bol-retour :id binnengehaald als retour #:retour', ['id' => $returnId, 'retour' => $return->id]));
        $summary->created++;
    }

    protected function unmatched(string $siteId, array $bolReturn, ?Order $order, string $reason, BolReturnImportSummary $summary): void
    {
        $summary->unmatched++;
        $this->notifier->notify($siteId, $bolReturn, $order, $reason);
    }

    protected function findOrder(string $siteId, array $bolReturn): ?Order
    {
        $bolOrderId = (string) ($bolReturn['returnItems'][0]['orderId'] ?? '');
        if ($bolOrderId === '') {
            return null;
        }

        return Order::query()->where('bol_order_id', $bolOrderId)->whereNull('credit_for_order_id')->orderBy('id')->first();
    }

    /**
     * Eerste nog niet geclaimde orderregel die op de EAN matcht. Een orderregel
     * mag hooguit één keer per import geclaimd worden, zodat twee retouritems
     * met dezelfde EAN op twee verschillende orderregels belanden in plaats
     * van dezelfde regel te delen (en daarmee een rma-id te verliezen).
     *
     * @param  Collection<int, OrderProduct>  $orderProducts
     * @param  array<int, int>  $claimed
     */
    protected function findLine(Collection $orderProducts, string $ean, array $claimed): ?OrderProduct
    {
        if ($ean === '') {
            return null;
        }

        return $orderProducts
            ->filter(fn (OrderProduct $op) => $this->eanMatches($op, $ean) && ! in_array($op->id, $claimed, true))
            ->first();
    }

    /** @param  Collection<int, OrderProduct>  $orderProducts */
    protected function hasMatchingLine(Collection $orderProducts, string $ean): bool
    {
        return $ean !== '' && $orderProducts->contains(fn (OrderProduct $op) => $this->eanMatches($op, $ean));
    }

    protected function eanMatches(OrderProduct $op, string $ean): bool
    {
        if (! ReturnableLines::isReturnable($op)) {
            return false;
        }

        $productEan = (string) ($op->product?->ean ?? '');

        return $productEan !== '' ? $productEan === $ean : (string) $op->sku === $ean;
    }

    protected function reasonNote(array $reason): ?string
    {
        $parts = array_filter([
            trim((string) ($reason['mainReason'] ?? '')),
            trim((string) ($reason['detailedReason'] ?? '')),
            trim((string) ($reason['customerComments'] ?? '')),
        ]);

        return $parts ? implode('. ', $parts) : null;
    }
}
