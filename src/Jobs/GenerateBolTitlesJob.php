<?php

namespace Dashed\DashedEcommerceBol\Jobs;

use Throwable;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Dashed\DashedCore\Models\BackgroundTask;
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

    public const TASK_TYPE = 'bol-titles';

    public $tries = 1;

    public $timeout = 1800;

    /**
     * @param  list<int>  $productGroupIds
     */
    public function __construct(
        public array $productGroupIds,
        public bool $overwrite,
        public User $user,
        public ?int $taskId = null,
    ) {
    }

    /**
     * Eén taak voor alle porties van "Ontbrekende Bol-titels genereren": de
     * instellingenpagina zet per BolSettingsPage::BOL_TITLE_CHUNK groepen een
     * job klaar, en zonder gedeelde taak kreeg elke portie een eigen regel in
     * het takenicoon. Het totaal is het aantal groepen over alle porties.
     */
    public static function beginSharedTask(int $total, ?User $user): ?int
    {
        if (! class_exists(BackgroundTask::class)) {
            return null;
        }

        return rescue(
            fn () => BackgroundTask::begin(self::TASK_TYPE, __('Ontbrekende Bol-titels genereren'), $total, $user, notify: false)->getKey(),
            null,
            report: false,
        );
    }

    public function handle(BolTitleGenerator $generator): void
    {
        $done = 0;
        $skipped = 0;
        $failed = [];

        $groups = ProductGroup::query()->whereIn('id', $this->productGroupIds)->orderBy('id')->get();

        // In het takenicoon van dashed-core, zonder eigen melding: de
        // eindmelding hieronder blijft de melding in de bel. Een portie van
        // de instellingenpagina telt mee in de gedeelde taak.
        $task = class_exists(BackgroundTask::class) && $this->taskId === null
            ? BackgroundTask::begin(self::TASK_TYPE, __('Bol-titels genereren'), $groups->count(), $this->user, notify: false)
            : null;

        // Een groep die sinds het klaarzetten verwijderd is, telt meteen als
        // afgehandeld, anders haalt de gedeelde taak zijn totaal nooit.
        $this->advanceSharedTask(count($this->productGroupIds) - $groups->count());

        foreach ($groups as $index => $group) {
            $task?->progress($done + $skipped + count($failed));

            if ($index > 0) {
                $this->advanceSharedTask(1);
            }

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

        $task?->finish($body);

        if ($groups->isNotEmpty()) {
            $this->advanceSharedTask(1);
        }
        $this->finishSharedTaskIfComplete();
    }

    /**
     * Ophogen in de database en niet via advance(): de porties kunnen naast
     * elkaar draaien, en een teller in het geheugen van één job zou de
     * voortgang van de andere overschrijven. Alleen een lopende taak: een
     * portie die al mislukt meldde, mag niet weer op bezig springen.
     */
    protected function advanceSharedTask(int $by): void
    {
        if ($this->taskId === null || $by < 1 || ! class_exists(BackgroundTask::class)) {
            return;
        }

        rescue(fn () => BackgroundTask::query()
            ->whereKey($this->taskId)
            ->active()
            ->increment('done', $by), report: false);
    }

    /**
     * De portie die het totaal haalt sluit de taak af. Voorwaardelijk in één
     * update, zodat twee porties die tegelijk eindigen hem niet allebei
     * afsluiten.
     */
    protected function finishSharedTaskIfComplete(): void
    {
        if ($this->taskId === null || ! class_exists(BackgroundTask::class)) {
            return;
        }

        rescue(fn () => BackgroundTask::query()
            ->whereKey($this->taskId)
            ->active()
            ->whereColumn('done', '>=', 'total')
            ->update([
                'status' => BackgroundTask::STATUS_COMPLETED,
                'message' => __('Alle porties zijn verwerkt. De uitkomst per portie staat in je meldingen.'),
                'finished_at' => now(),
                'updated_at' => now(),
            ]), report: false);
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
        // Een verse instantie: de taak uit handle() is hier niet bekend. Een
        // portie zet de gedeelde taak op mislukt; de andere porties lopen
        // door en melden zich nog in de bel, maar de taak blijft mislukt.
        if (class_exists(BackgroundTask::class)) {
            rescue(fn () => ($this->taskId !== null
                ? BackgroundTask::query()->whereKey($this->taskId)
                : BackgroundTask::query()
                    ->where('type', self::TASK_TYPE)
                    ->where('user_id', $this->user->getKey())
                    ->latest('id'))
                ->active()
                ->first()
                ?->fail($e), report: false);
        }

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
            'background_task' => $this->taskId,
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
