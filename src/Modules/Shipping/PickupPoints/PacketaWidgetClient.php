<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\PickupPoints;

use FluentCartHungary\Core\Http\BaseClient;
use FluentCartHungary\Core\Http\Response;

/**
 * Packeta Widget v6 server-side validation client (checkout 3/3 gate #4).
 *
 * Documented endpoint (docs.packeta.com — "widget" / "validate PUDO"): POST
 * https://widget.packeta.com/v6/pps/api/widget/v1/validate → {isValid, point, errors}. This is the
 * only documented way to confirm a widget-selected PUDO against the provider; the widget callback alone
 * is never treated as authoritative. The request carries the PUBLIC widget apiKey (never the secret API
 * password) plus the widget point id. Transport goes through BaseClient, so the `fch/http/pre_request`
 * mock seam keeps tests network-free; without a merchant account the whole picker stays disabled.
 */
final class PacketaWidgetClient extends BaseClient
{
    public const CARRIER = 'packeta';
    public const VALIDATE_URL = 'https://widget.packeta.com/v6/pps/api/widget/v1/validate';

    public function __construct()
    {
        parent::__construct('');
        $this->timeout = 15;
    }

    /**
     * @return array{ok:bool,is_valid:bool,error:?string}
     */
    public function validate(string $apiKey, string $pointId): array
    {
        $apiKey = trim($apiKey);
        $pointId = trim($pointId);
        if ($apiKey === '' || $pointId === '') {
            return ['ok' => false, 'is_valid' => false, 'error' => 'missing_input'];
        }

        $url = self::VALIDATE_URL;
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('fch/pickup/packeta/validate_url', $url);
            if (is_string($filtered) && str_starts_with($filtered, 'https://')) {
                $url = $filtered;
            }
        }

        $response = $this->request('POST', $url, [
            'body' => (string) json_encode(['apiKey' => $apiKey, 'id' => $pointId]),
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        if (!$response->isSuccess()) {
            return ['ok' => false, 'is_valid' => false, 'error' => 'http_' . ($response->error ?? $response->status)];
        }
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'is_valid' => false, 'error' => 'invalid_json'];
        }
        return ['ok' => true, 'is_valid' => (bool) ($decoded['isValid'] ?? false), 'error' => null];
    }
}
