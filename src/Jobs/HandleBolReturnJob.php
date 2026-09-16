<?php

namespace Dashed\DashedEcommerceBol\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Dashed\DashedEcommerceCore\Models\OrderReturn;
use Dashed\DashedEcommerceBol\Classes\BolReturnHandler;

/** Enige ingang naar BolReturnHandler; uniek per retour, herkanst bij een fout. */
class HandleBolReturnJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public OrderReturn $orderReturn)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->orderReturn->id;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(BolReturnHandler $handler): void
    {
        $handler->handle($this->orderReturn->fresh() ?? $this->orderReturn);
    }
}
