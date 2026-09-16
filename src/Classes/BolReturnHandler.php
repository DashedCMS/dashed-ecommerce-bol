<?php

namespace Dashed\DashedEcommerceBol\Classes;

use Throwable;
use Dashed\DashedEcommerceCore\Models\OrderLog;
use Dashed\DashedEcommerceCore\Models\OrderReturn;
use Dashed\DashedEcommerceCore\Services\OrderReturn\RefundRegistrar;

/**
 * Meldt de uitkomst van een Bol-retour per regel terug aan Bol en boekt bij
 * een verwerkte retour de betaling "Via Bol" op de creditorder (Bol betaalt
 * de klant; wij verrekenen via de Bol-afrekening). Idempotent op
 * bol_handled_at; de betaling wordt hooguit één keer geboekt, ook als de
 * terugmelding daarna faalt en herkanst wordt.
 */
class BolReturnHandler
{
    public const TAG_HANDLED = 'order.bol-return-handled';
    public const TAG_FAILED = 'order.bol-return-handle-failed';

    public function __construct(protected BolReturns $bol, protected RefundRegistrar $registrar)
    {
    }

    public function handle(OrderReturn $return): void
    {
        if (! $return->bol_return_id || $return->bol_handled_at || ! $return->order) {
            return;
        }
        if (! in_array($return->status, [OrderReturn::STATUS_HANDLED, OrderReturn::STATUS_CLOSED, OrderReturn::STATUS_REJECTED], true)) {
            return;
        }

        $order = $return->order;
        $siteId = (string) $order->site_id;

        if ($return->status === OrderReturn::STATUS_HANDLED && $return->credit_order_id && ! $return->isRefunded()) {
            $this->registrar->register($return, $return->creditedAmount(), 'Via Bol', 'bol', ['bol_return_id' => $return->bol_return_id]);
        }

        $results = [];
        try {
            foreach ($return->lines()->with('orderProduct')->get() as $line) {
                if (! $line->bol_rma_id) {
                    continue;
                }
                [$result, $quantity] = $this->resultFor($return, $line);
                $this->bol->handle($siteId, $line->bol_rma_id, $result, $quantity);
                $results[] = $line->bol_rma_id . ': ' . $result . ' x' . $quantity;
            }
        } catch (Throwable $e) {
            $return->forceFill(['bol_handle_error' => $e->getMessage()])->save();
            OrderLog::createLog(orderId: $order->id, tag: self::TAG_FAILED, note: __('Terugmelding aan Bol mislukt: :fout', ['fout' => $e->getMessage()]));

            throw $e;
        }

        $return->forceFill(['bol_handled_at' => now(), 'bol_handle_error' => null])->save();
        OrderLog::createLog(orderId: $order->id, tag: self::TAG_HANDLED, note: __('Bol-retour :id teruggemeld: :regels', ['id' => $return->bol_return_id, 'regels' => implode(', ', $results)]));
    }

    /** @return array{0: string, 1: int} */
    protected function resultFor(OrderReturn $return, $line): array
    {
        if ($return->status === OrderReturn::STATUS_HANDLED) {
            return (int) $line->processed_quantity > 0
                ? [BolHandlingResult::RECEIVED, (int) $line->processed_quantity]
                : [BolHandlingResult::NOT_MEETING_CONDITIONS, (int) $line->quantity];
        }

        return [$return->bol_handling_result ?: BolHandlingResult::NOT_MEETING_CONDITIONS, (int) $line->quantity];
    }
}
