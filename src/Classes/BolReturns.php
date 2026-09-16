<?php

namespace Dashed\DashedEcommerceBol\Classes;

use RuntimeException;
use Illuminate\Support\Facades\Http;
use Dashed\DashedCore\Models\Customsetting;

/**
 * Dun laagje om de retour-endpoints van de Bol Retailer API (v10). Tests
 * binden hier een nep voor in de container. Token, retry en throttle zoals
 * Bol::syncOrders(); process-status-poll zoals Bol::sendShipmentGroup().
 */
class BolReturns
{
    protected const HEADERS = [
        'Accept' => 'application/vnd.retailer.v10+json',
        'Content-Type' => 'application/vnd.retailer.v10+json',
    ];

    /** Alle open FBR-retouren, over alle pagina's; ruwe `returns[]` van Bol. */
    public function open(string $siteId): array
    {
        $token = $this->token($siteId);
        $all = [];
        $page = 1;
        $count = 50;

        while ($count === 50) {
            $response = Http::withToken($token)
                ->withHeaders(self::HEADERS)
                ->retry(3)
                ->get(Bol::APIURL . '/retailer/returns', [
                    'page' => $page,
                    'handled' => 'false',
                    'fulfilment-method' => 'FBR',
                ])
                ->throw()
                ->json();

            $returns = $response['returns'] ?? [];
            $all = array_merge($all, $returns);
            $count = count($returns);
            $page++;
            usleep(Bol::REQUEST_THROTTLE_MICROSECONDS);
        }

        return $all;
    }

    /** Meldt één retourregel af; gooit als Bol het niet accepteert. */
    public function handle(string $siteId, string $rmaId, string $handlingResult, int $quantityReturned): void
    {
        $token = $this->token($siteId);

        $response = Http::withToken($token)
            ->withHeaders(self::HEADERS)
            ->retry(3)
            ->put(Bol::APIURL . '/retailer/returns/' . $rmaId, [
                'handlingResult' => $handlingResult,
                'quantityReturned' => $quantityReturned,
            ])
            ->throw()
            ->json();

        $link = $response['links'][0]['href'] ?? null;
        while (($response['status'] ?? null) === 'PENDING' && $link) {
            sleep(2);
            $polled = Http::withToken($token)->withHeaders(self::HEADERS)->get($link)->json();
            if (! is_array($polled)) {
                break;
            }
            $response = $polled;
        }

        if (($response['status'] ?? null) !== 'SUCCESS') {
            throw new RuntimeException($response['errorMessage'] ?? ('Bol gaf status ' . ($response['status'] ?? 'onbekend') . ' voor rma ' . $rmaId));
        }
    }

    protected function token(string $siteId): string
    {
        Bol::refreshToken($siteId);
        $token = (string) Customsetting::get('bol_access_token', $siteId);
        if ($token === '') {
            throw new RuntimeException(__('Geen Bol-token voor site :site', ['site' => $siteId]));
        }

        return $token;
    }
}
