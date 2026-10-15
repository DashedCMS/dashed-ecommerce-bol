<?php

namespace Dashed\DashedEcommerceBol\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Dashed\DashedEcommerceBol\Classes\Bol;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedEcommerceCore\Models\Order;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Meldt de verzending van één Bol-bestelling aan Bol, zodra die op Verzonden
 * of Afgehandeld staat en een track & trace heeft. Uniek per bestelling: de
 * statuswissel en een later binnenkomende T&T zetten allebei deze job klaar.
 */
class SyncBolShipmentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public Order $order)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->order->id;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $order = $this->order->fresh();

        if (! $order || ! Bol::shouldSyncShipment($order)) {
            return;
        }

        Bol::syncShipment($order);
    }
}
