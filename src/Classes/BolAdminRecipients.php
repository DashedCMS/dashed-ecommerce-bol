<?php

namespace Dashed\DashedEcommerceBol\Classes;

use Dashed\DashedCore\Models\User;
use Dashed\DashedCore\Models\Customsetting;

/**
 * De ingestelde adressen van déze site, anders de superadmins. Bewust niet
 * via Mails::getAdminNotificationEmails(): die leest de actieve site, en de
 * Bol-syncs lopen langs alle sites achter elkaar.
 */
class BolAdminRecipients
{
    /** @return array<int, string> */
    public static function for(?string $siteId): array
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
