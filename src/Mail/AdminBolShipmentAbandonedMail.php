<?php

namespace Dashed\DashedEcommerceBol\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedCore\Notifications\DTOs\TelegramSummary;
use Dashed\DashedCore\Notifications\Contracts\SendsToTelegram;

/** Beheerdersmelding: de verzending van een Bol-bestelling kon na herhaalde pogingen niet aan Bol gemeld worden. */
class AdminBolShipmentAbandonedMail extends Mailable implements SendsToTelegram
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Order $order, public string $reason, public int $attempts)
    {
    }

    public function build()
    {
        $siteId = $this->order->site_id ?: null;

        return $this
            ->from(Customsetting::get('site_from_email', $siteId), Customsetting::get('site_name', $siteId))
            ->subject(__('Verzending van Bol-bestelling :id niet gemeld aan Bol', ['id' => $this->order->bol_order_id]))
            ->html($this->htmlBody());
    }

    public function telegramSummary(): TelegramSummary
    {
        return new TelegramSummary(
            title: __('Bol-verzending niet gemeld: :id', ['id' => (string) $this->order->bol_order_id]),
            fields: [__('Fout') => $this->reason, __('Pogingen') => (string) $this->attempts],
            adminUrl: $this->orderUrl(),
        );
    }

    protected function orderUrl(): ?string
    {
        return rescue(fn () => route('filament.dashed.resources.orders.view', ['record' => $this->order->id]), null, false);
    }

    protected function htmlBody(): string
    {
        $html = '<p>' . e(__('De verzending van bestelling :nummer (Bol-bestelling :bol) is na :pogingen pogingen niet aan Bol gemeld. Er wordt niet meer automatisch geprobeerd; Bol ziet de bestelling dus als niet verzonden.', [
            'nummer' => $this->order->invoice_id,
            'bol' => $this->order->bol_order_id,
            'pogingen' => $this->attempts,
        ])) . '</p>';
        $html .= '<p><strong>' . e(__('Laatste fout')) . ':</strong> ' . e($this->reason) . '</p>';
        $html .= '<p>' . e(__('Controleer de bestelling bij Bol en probeer het daarna opnieuw met: php artisan bol:sync-shipments-to-bol --order=:id', ['id' => $this->order->id])) . '</p>';
        if ($url = $this->orderUrl()) {
            $html .= '<p><a href="' . e($url) . '">' . e(__('Open de bestelling in het CMS')) . '</a></p>';
        }

        return $html;
    }
}
