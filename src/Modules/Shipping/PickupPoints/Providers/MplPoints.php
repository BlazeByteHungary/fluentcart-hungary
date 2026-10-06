<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\PickupPoints\Providers;

use FluentCartHungary\Modules\Shipping\Mpl\MplClient;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupDirectory;

/**
 * MPL delivery-place feed, expressed through the W2 provider contract (wraps the proven W1 path).
 */
final class MplPoints implements PickupFeedProviderInterface
{
    public function carrier(): string
    {
        return PickupDirectory::MPL_CARRIER;
    }

    public function fetch(array $query = [], ?object $client = null): array
    {
        if (!class_exists(MplClient::class)) {
            return ['ok' => false, 'points' => [], 'error' => 'mpl_client_missing'];
        }
        try {
            $client ??= new MplClient('test');
        } catch (\Throwable $e) {
            return ['ok' => false, 'points' => [], 'error' => 'mpl_client_unavailable'];
        }
        if (!method_exists($client, 'hasCredentials') || !$client->hasCredentials()) {
            return ['ok' => false, 'points' => [], 'error' => 'mpl_credentials_missing'];
        }

        $response = $client->queryDeliveryPlaces($query, PickupDirectory::MPL_SERVICE_POINT_TYPES);
        if (!$response->isSuccess()) {
            return ['ok' => false, 'points' => [], 'error' => 'mpl_http_' . $response->status];
        }

        $points = [];
        foreach ((array) $response->json() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $point = PickupDirectory::mapMplResult($row, $this->carrier());
            if ($point !== null) {
                $points[] = $point;
            }
        }
        return ['ok' => true, 'points' => $points, 'error' => null];
    }
}
