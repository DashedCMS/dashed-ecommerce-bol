<?php

namespace Dashed\DashedEcommerceBol\Classes;

use Illuminate\Support\Facades\Cache;
use Dashed\DashedCore\Classes\Mails;
use Dashed\DashedCore\Models\User;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Models\OrderLog;
use Dashed\DashedCore\Notifications\AdminNotifier;
use Dashed\DashedEcommerceBol\Mail\AdminBolReturnUnmatchedMail;

/** Orderlog als de bestelling bekend is; mail en push hooguit één keer per Bol-retour, sleutel pas na geslaagde verzending. */
class BolReturnUnmatchedNotifier
{
    public const TAG = 'order.bol-return-unmatched';

    public function notify(string $siteId, array $bolReturn, ?Order $order, string $reason): void
    {
        $id = (string) ($bolReturn['returnId'] ?? '');

        if ($order) {
            OrderLog::createLog(orderId: $order->id, tag: self::TAG, note: __('Bol-retour :id niet in het systeem gezet: :reden', ['id' => $id, 'reden' => $reason]));
        }

        $key = 'bol-return-unmatched:' . $siteId . ':' . $id;
        if ($id === '' || Cache::has($key)) {
            return;
        }

        $recipients = $this->recipients();
        if ($recipients === []) {
            return;
        }

        rescue(function () use ($recipients, $bolReturn, $order, $reason, $key) {
            AdminNotifier::send(new AdminBolReturnUnmatchedMail($bolReturn, $order, $reason), $recipients);
            Cache::add($key, true, now()->addDays(30));
        }, null, true);
    }

    /** @return array<int, string> */
    protected function recipients(): array
    {
        $emails = [];
        foreach ((array) (Mails::getAdminNotificationEmails() ?: []) as $email) {
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
