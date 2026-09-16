<?php

namespace Dashed\DashedEcommerceBol\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedCore\Notifications\DTOs\TelegramSummary;
use Dashed\DashedCore\Notifications\Contracts\SendsToTelegram;

/** Beheerdersmelding: een open Bol-retour past bij geen bestelling of regel bij ons. Niets is aangemaakt. */
class AdminBolReturnUnmatchedMail extends Mailable implements SendsToTelegram
{
    use Queueable;
    use SerializesModels;

    public function __construct(public array $bolReturn, public ?Order $order, public string $reason)
    {
    }

    public function build()
    {
        $id = (string) ($this->bolReturn['returnId'] ?? '?');

        return $this
            ->from(Customsetting::get('site_from_email'), Customsetting::get('site_name'))
            ->subject(__('Bol-retour :id kon niet in het systeem worden gezet', ['id' => $id]))
            ->html($this->htmlBody($id));
    }

    public function telegramSummary(): TelegramSummary
    {
        return new TelegramSummary(
            title: __('Bol-retour niet plaatsbaar: :id', ['id' => (string) ($this->bolReturn['returnId'] ?? '?')]),
            fields: [__('Reden') => $this->reason],
            adminUrl: $this->orderUrl(),
        );
    }

    protected function orderUrl(): ?string
    {
        return $this->order ? rescue(fn () => route('filament.dashed.resources.orders.view', ['record' => $this->order->id]), null, false) : null;
    }

    protected function htmlBody(string $id): string
    {
        $bolOrderId = (string) ($this->bolReturn['returnItems'][0]['orderId'] ?? '?');
        $html = '<p>' . e(__('Bol meldt retour :id op Bol-bestelling :order, maar die past bij geen bestelling of regel in het CMS. Er is niets aangemaakt; de retour blijft bij Bol open tot dit is opgelost.', ['id' => $id, 'order' => $bolOrderId])) . '</p>';
        $html .= '<p><strong>' . e(__('Reden')) . ':</strong> ' . e($this->reason) . '</p>';
        if ($url = $this->orderUrl()) {
            $html .= '<p><a href="' . e($url) . '">' . e(__('Open de bestelling in het CMS')) . '</a></p>';
        }
        $html .= '<ul>';
        foreach ((array) ($this->bolReturn['returnItems'] ?? []) as $item) {
            $html .= '<li>' . e(__('EAN :ean, :aantal stuks, rma :rma', ['ean' => $item['ean'] ?? '?', 'aantal' => $item['expectedQuantity'] ?? '?', 'rma' => $item['rmaId'] ?? '?'])) . '</li>';
        }

        return $html . '</ul>';
    }
}
