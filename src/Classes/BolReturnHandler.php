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
 * bol_handled_at, op de retour zelf én op elke regel: een regel die al
 * gemeld is wordt bij een herkansing overgeslagen, dus een PUT die halverwege
 * faalt stuurt bij de volgende poging alleen de nog openstaande regels
 * opnieuw. De betaling wordt hooguit één keer geboekt, ook als de
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

        // Eigen try, want dit is de stap waar het geld in zit: een registrar
        // die gooit (een creditorder die intussen al betaald is, een bedrag
        // dat niet klopt) liet eerder geen enkel spoor achter en de job
        // verdween in de mislukte-jobs-tabel.
        if ($return->status === OrderReturn::STATUS_HANDLED && $return->credit_order_id && ! $return->isRefunded()) {
            try {
                $this->registrar->register($return, $return->creditedAmount(), 'Via Bol', 'bol', ['bol_return_id' => $return->bol_return_id]);
            } catch (Throwable $e) {
                $this->fail($return, $order->id, $e);

                throw $e;
            }
        }

        $results = [];

        try {
            foreach ($return->lines()->with('orderProduct')->get() as $line) {
                if (! $line->bol_rma_id || $line->bol_handled_at) {
                    continue;
                }
                [$result, $quantity] = $this->resultFor($return, $line);
                $this->bol->handle($siteId, $line->bol_rma_id, $result, $quantity);
                $line->forceFill(['bol_handled_at' => now()])->save();
                $results[] = $line->bol_rma_id . ': ' . $result . ' x' . $quantity;
            }
        } catch (Throwable $e) {
            $this->fail($return, $order->id, $e);

            throw $e;
        }

        // Alle regels zijn deze aanroep gelukt of waren al eerder gemeld
        // (bol_handled_at stond al), dus dit is altijd waar zodra de try
        // hierboven zonder exception eindigt. De query maakt dat expliciet
        // in plaats van er stilzwijgend op te vertrouwen.
        $stillOpen = $return->lines()->whereNotNull('bol_rma_id')->whereNull('bol_handled_at')->exists();
        if (! $stillOpen) {
            $return->forceFill(['bol_handled_at' => now(), 'bol_handle_error' => null])->save();
            OrderLog::createLog(orderId: $order->id, tag: self::TAG_HANDLED, note: __('Bol-retour :id teruggemeld: :regels', ['id' => $return->bol_return_id, 'regels' => implode(', ', $results)]));
        }
    }

    /**
     * Eén plek voor het spoor van een mislukte poging: de melding op de
     * retour, de orderlog, en de teller waarop SyncBolReturnsCommand de
     * herkansing afkapt.
     */
    protected function fail(OrderReturn $return, int $orderId, Throwable $e): void
    {
        $return->forceFill([
            'bol_handle_error' => $e->getMessage(),
            'bol_handle_attempts' => (int) $return->bol_handle_attempts + 1,
        ])->save();
        OrderLog::createLog(orderId: $orderId, tag: self::TAG_FAILED, note: __('Terugmelding aan Bol mislukt: :fout', ['fout' => $e->getMessage()]));
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
