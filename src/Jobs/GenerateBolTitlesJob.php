<?php

namespace Dashed\DashedEcommerceBol\Jobs;

use Throwable;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Filament\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedEcommerceCore\Models\ProductGroup;
use Dashed\DashedEcommerceBol\Classes\BolTitleWriter;
use Dashed\DashedEcommerceBol\Classes\BolTitleGenerator;
use Dashed\DashedCore\Jobs\Concerns\HandlesQueueFailures;

/**
 * Genereert Bol-titelsjablonen voor een selectie productgroepen. Eén job met
 * een lus in plaats van een batch: een fout bij één groep wordt geteld en de
 * rest loopt door, en de beheerder krijgt aan het eind één melding.
 */
class GenerateBolTitlesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use HandlesQueueFailures;

    public $tries = 1;

    public $timeout = 1800;

    /**
     * @param  list<int>  $productGroupIds
     */
    public function __construct(
        public array $productGroupIds,
        public bool $overwrite,
        public User $user,
    ) {
    }

    public function handle(BolTitleGenerator $generator): void
    {
        $done = 0;
        $skipped = 0;
        $failed = [];

        $groups = ProductGroup::query()->whereIn('id', $this->productGroupIds)->orderBy('id')->get();

        foreach ($groups as $group) {
            if (self::skipReason($group, $this->overwrite) !== null) {
                $skipped++;

                continue;
            }

            try {
                $proposal = $generator->generate($group, BolTitleGenerator::localesFor($group));

                if ($proposal->hasWarnings()) {
                    $failed[] = $group->name . ': ' . $proposal->warningSummary();

                    continue;
                }

                BolTitleWriter::write($group, $proposal->templates);
                $done++;
            } catch (Throwable $e) {
                report($e);
                $failed[] = $group->name . ': ' . $e->getMessage();
            }
        }

        $body = __(':gelukt gelukt, :overgeslagen overgeslagen, :mislukt mislukt.', [
            'gelukt' => $done,
            'overgeslagen' => $skipped,
            'mislukt' => count($failed),
        ]);
        if ($failed !== []) {
            $body .= ' ' . implode(' ', $failed);
        }

        $notification = Notification::make()
            ->title(__('Bol-titels genereren is klaar'))
            ->body($body);

        if ($failed === []) {
            $notification->success();
        } else {
            $notification->warning();
        }

        $notification->sendToDatabase($this->user);
    }

    /**
     * Eén poging en een half uur: loopt de job daar tegenaan (time-out,
     * geheugen, een fout buiten de lus), dan komt er geen eindmelding uit
     * handle(). Zonder deze melding wacht de beheerder dan eeuwig. Wat al
     * geschreven is blijft staan, dus opnieuw starten zonder overschrijven
     * gaat verder waar het stopte.
     */
    public function failed(Throwable $e): void
    {
        try {
            Notification::make()
                ->title(__('Bol-titels genereren is gestopt'))
                ->body(__('Het genereren is voortijdig gestopt. De groepen die al klaar waren hebben hun sjabloon. Start het opnieuw met overschrijven uit om verder te gaan waar het stopte.'))
                ->danger()
                ->sendToDatabase($this->user);
        } catch (Throwable $notifyError) {
            report($notifyError);
        }

        $this->reportFailure($e);
    }

    /**
     * @return array<string, mixed>
     */
    public function extraLogContext(): array
    {
        return [
            'requested_by' => $this->user->getKey(),
            'product_groups' => count($this->productGroupIds),
            'overwrite' => $this->overwrite,
        ];
    }

    public static function skipReason(ProductGroup $group, bool $overwrite): ?string
    {
        if (BolTitleGenerator::localesFor($group) === []) {
            return __('Geen naam');
        }

        if (! $group->products()->exists()) {
            return __('Geen producten');
        }

        if (! $overwrite && BolTitleWriter::hasTemplate($group)) {
            return __('Heeft al een sjabloon');
        }

        return null;
    }
}
