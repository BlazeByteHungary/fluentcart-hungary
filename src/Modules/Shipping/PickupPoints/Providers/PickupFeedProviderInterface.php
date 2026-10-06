<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\PickupPoints\Providers;

/**
 * W2 pickup-feed contract (04_UNKNOWNS_AND_BLOCKERS §carrier feeds).
 *
 * A provider fetches its carrier's points (API/feed/static import) and returns CANONICAL points
 * (`PickupDirectory::normalizePoint()` shape). The caller caches them in the per-carrier option; the
 * checkout path never touches the network. Providers must be pure data fetchers: no direct option writes,
 * no checkout redirects, no exceptions for expected failures (return the error string instead).
 */
interface PickupFeedProviderInterface
{
    /** Same slug as `fch_shipments.carrier` and the `method_{id}_pickup_carrier` setting. */
    public function carrier(): string;

    /**
     * @param array{postCode?:string,postCodeOnLabel?:string,city?:string,limit?:int} $query
     * @param object|null $client injectable client for tests (mock transport rules apply)
     * @return array{ok:bool,points:list<array<string,mixed>>,error:?string}
     */
    public function fetch(array $query = [], ?object $client = null): array;
}
