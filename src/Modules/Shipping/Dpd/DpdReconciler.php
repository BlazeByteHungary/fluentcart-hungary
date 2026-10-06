<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\Dpd;

use FluentCartHungary\Core\Ledger\ProviderCallResult;

/**
 * DPD timeout reconciliation placeholder — deliberately NOT registered.
 *
 * 04_DPD_CONTRACT.md §7 / §10 DPD-UNK-08: `reference1..4` and `customerId` exist, and an
 * `ExternalShipmentIdsSearchDTO` schema is documented, but **no operation path searches by reference**
 * (matrix DPD-906 → UNPROVEN). The only documented lookup is by numeric `shipmentId`/`parcelNumber`, which
 * a timed-out create does not have. Therefore, per 04_UNKNOWNS_AND_BLOCKERS.md §2 (DPD row: "safe recovery
 * requires manual reconciliation") and §7 timeout-uncertainty table, DPD runs as a **manual reconciliation
 * queue**:
 *
 *  - DpdModule::registerHooks() does NOT call `ReconcilerRegistry::register('dpd', …)`;
 *  - an UNCERTAIN DPD label_create therefore stays on the operator queue (ReconcileUncertainJob reports
 *    `NO_RECONCILER:dpd` and never re-POSTs `/shipments`);
 *  - `supported()` returns false and this class documents the blocked reason, so probe/report output can
 *    state it explicitly rather than implying a read-back path exists.
 *
 * It is kept as a concrete, testable statement of the contract gap — not a working reconciler.
 */
final class DpdReconciler
{
    public const PROVIDER = 'dpd';
    public const QUERY_BY_REFERENCE_DOCUMENTED = false;
    public const REASON = 'no_lookup_by_reference_endpoint';

    public static function supported(): bool
    {
        return self::QUERY_BY_REFERENCE_DOCUMENTED;
    }

    /**
     * If ever invoked (it must not be), it can only report that no documented lookup exists — never
     * invent a query. Returning UNCERTAIN keeps the row in the manual queue.
     */
    public function __invoke(object $ledgerRow): ProviderCallResult
    {
        return ProviderCallResult::uncertain('DPD_QUERY_BY_REFERENCE_MISSING_DOC');
    }
}
