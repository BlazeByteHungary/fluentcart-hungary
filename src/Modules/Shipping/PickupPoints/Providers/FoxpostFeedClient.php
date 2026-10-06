<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\PickupPoints\Providers;

use FluentCartHungary\Core\Http\BaseClient;
use FluentCartHungary\Core\Http\Response;

/**
 * Read-only client for the public Foxpost CDN point feed `https://cdn.foxpost.hu/foxplus.json`
 * (04_FOXPOST_CONTRACT.md §3/§4; live-checked 2026-10-06: HTTP 200, ~7.24 MB, 5007 HU points).
 *
 * The feed is an unauthenticated CDN JSON document, so this client sends no credentials and never follows
 * redirects (BaseClient defaults). All transport goes through BaseClient, so the `fch/http/pre_request`
 * mock seam keeps tests network-free. A hard size cap protects the options table from a hostile/oversized
 * response.
 */
final class FoxpostFeedClient extends BaseClient
{
    public const CARRIER = 'foxpost';
    public const CDN_URL = 'https://cdn.foxpost.hu/foxplus.json';
    public const MAX_BYTES = 33554432; // 32 MiB

    public function __construct()
    {
        parent::__construct('');
        $this->timeout = 30;
    }

    public function fetchAll(): Response
    {
        $url = self::CDN_URL;
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('fch/pickup/foxpost/feed_url', $url);
            if (is_string($filtered) && str_starts_with($filtered, 'https://')) {
                $url = $filtered;
            }
        }

        $response = $this->request('GET', $url);
        if ($response->isSuccess() && strlen($response->body) > self::MAX_BYTES) {
            return new Response($response->status, $response->headers, '', 'feed_too_large');
        }
        return $response;
    }
}
