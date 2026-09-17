<?php

namespace Dashed\DashedEcommerceBol\Classes;

use Dashed\DashedCore\Models\User;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Models\OrderLog;
use Dashed\DashedCore\Notifications\AdminNotifier;
use Dashed\DashedEcommerceBol\Mail\AdminBolReturnUnmatchedMail;

/**
 * Orderlog, mail en push hooguit één keer per Bol-retour: de retour blijft bij
 * Bol open en komt elke sync opnieuw langs. De cachesleutel wordt pas geclaimd
 * na een geslaagde verzending, zodat een mail die niet weg kon het volgende
 * kwartier opnieuw gaat.
 */
class BolReturnUnmatchedNotifier
{
    public const TAG = 'order.bol-return-unmatched';

    public function notify(string $siteId, array $bolReturn, ?Order $order, string $reason): void
    {
        $id = (string) ($bolReturn['returnId'] ?? '');

        // Ook de orderlog valt onder de guard: de retour blijft bij Bol open en
        // komt elke sync opnieuw langs, dus buiten de guard groeide het
        // orderlogboek elk kwartier met dezelfde regel.
        $key = 'bol-return-unmatched:' . $siteId . ':' . $id;
        if ($id === '' || Cache::has($key)) {
            return;
        }

        if ($order) {
            OrderLog::createLog(orderId: $order->id, tag: self::TAG, note: __('Bol-retour :id niet in het systeem gezet: :reden', ['id' => $id, 'reden' => $reason]));
        }

        $recipients = $this->recipients($siteId);
        if ($recipients === []) {
            return;
        }

        rescue(function () use ($siteId, $recipients, $bolReturn, $order, $reason, $key) {
            AdminNotifier::send(new AdminBolReturnUnmatchedMail($bolReturn, $order, $reason, $siteId), $recipients);
            Cache::add($key, true, now()->addDays(30));
        }, null, true);
    }

    /**
     * De ingestelde adressen van déze site, anders de superadmins. Bewust niet
     * via Mails::getAdminNotificationEmails(): die leest de actieve site, en de
     * sync loopt langs alle sites achter elkaar.
     *
     * @return array<int, string>
     */
    protected function recipients(string $siteId): array
    {
        $emails = [];
        foreach ((array) (Customsetting::get('notification_invoice_emails', $siteId, []) ?: []) as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && ! in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }
        if ($emails) {
            return $emails;
        }

        return User::query()->where('role', 'superadmin')->whereNotNull('email')->orderBy('id')
            ->pluck('email')->map(fn ($e) => strtolower(trim((string) $e)))->filter()->unique()->values()->all();
    }
}
