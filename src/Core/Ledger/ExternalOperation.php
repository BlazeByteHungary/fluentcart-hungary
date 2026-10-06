<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Ledger;

/**
 * Pure external-operation vocabulary and guarded state machine (05_DATA_SCHEMA §6/§7).
 * No WordPress or database dependency: unit-testable in isolation.
 */
final class ExternalOperation
{
    public const NOT_STARTED = 'NOT_STARTED';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const EXTERNAL_CONFIRMED = 'EXTERNAL_CONFIRMED';
    public const PROVEN_REJECTED = 'PROVEN_REJECTED';
    public const UNCERTAIN = 'UNCERTAIN';
    public const AWAITING_RECONCILIATION = 'AWAITING_RECONCILIATION';
    public const MANUALLY_RESOLVED = 'MANUALLY_RESOLVED';
    public const SUPERSEDED = 'SUPERSEDED';

    /** @var list<string> */
    public const STATES = [
        self::NOT_STARTED,
        self::IN_PROGRESS,
        self::EXTERNAL_CONFIRMED,
        self::PROVEN_REJECTED,
        self::UNCERTAIN,
        self::AWAITING_RECONCILIATION,
        self::MANUALLY_RESOLVED,
        self::SUPERSEDED,
    ];

    /** @var list<string> */
    public const TERMINAL = [
        self::EXTERNAL_CONFIRMED,
        self::PROVEN_REJECTED,
        self::MANUALLY_RESOLVED,
        self::SUPERSEDED,
    ];

    /**
     * Ledger transitions exactly as 05_DATA_SCHEMA §7.1. IN_PROGRESS -> NOT_STARTED models the
     * "definitive local error before dispatch => stays NOT_STARTED" row of that table.
     *
     * @var array<string,list<string>>
     */
    private const LEDGER_TRANSITIONS = [
        self::NOT_STARTED => [self::IN_PROGRESS, self::SUPERSEDED],
        self::IN_PROGRESS => [self::EXTERNAL_CONFIRMED, self::PROVEN_REJECTED, self::UNCERTAIN, self::NOT_STARTED],
        self::UNCERTAIN => [self::AWAITING_RECONCILIATION],
        self::AWAITING_RECONCILIATION => [self::EXTERNAL_CONFIRMED, self::PROVEN_REJECTED, self::MANUALLY_RESOLVED, self::SUPERSEDED],
        self::EXTERNAL_CONFIRMED => [],
        self::PROVEN_REJECTED => [],
        self::MANUALLY_RESOLVED => [],
        self::SUPERSEDED => [],
    ];

    /**
     * Entity mirrors additionally allow a proved outcome to be superseded by a newer document/row
     * (invoice storno/corrected §7.3, shipment cancel §7.4). Superseding is never a regression.
     *
     * @var array<string,list<string>>
     */
    private const MIRROR_TRANSITIONS = [
        self::NOT_STARTED => [self::IN_PROGRESS, self::SUPERSEDED],
        self::IN_PROGRESS => [self::EXTERNAL_CONFIRMED, self::PROVEN_REJECTED, self::UNCERTAIN, self::NOT_STARTED],
        self::UNCERTAIN => [self::AWAITING_RECONCILIATION, self::SUPERSEDED],
        self::AWAITING_RECONCILIATION => [self::EXTERNAL_CONFIRMED, self::PROVEN_REJECTED, self::MANUALLY_RESOLVED, self::SUPERSEDED],
        self::EXTERNAL_CONFIRMED => [self::SUPERSEDED],
        self::PROVEN_REJECTED => [self::SUPERSEDED],
        self::MANUALLY_RESOLVED => [],
        self::SUPERSEDED => [],
    ];

    /** @var list<string> */
    public const KINDS = [
        'create_invoice',
        'create_receipt',
        'create_proforma',
        'storno_invoice',
        'storno_receipt',
        'corrected_invoice',
        'payment_start',
        'payment_capture',
        'refund',
        'cancel_authorization',
        'label_create',
        'label_reprint',
        'manifest_close',
        'shipment_cancel',
        'claim_create',
        'tracking_poll',
        'credential_test',
    ];

    public static function isKnownState(string $state): bool
    {
        return in_array($state, self::STATES, true);
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }

    public static function isKnownKind(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    /** @return list<string> */
    public static function allowedTransitions(string $from, string $profile = 'ledger'): array
    {
        $map = $profile === 'mirror' ? self::MIRROR_TRANSITIONS : self::LEDGER_TRANSITIONS;
        return $map[$from] ?? [];
    }

    public static function isValidTransition(string $from, string $to, string $profile = 'ledger'): bool
    {
        if (!self::isKnownState($from) || !self::isKnownState($to)) {
            return false;
        }
        return in_array($to, self::allowedTransitions($from, $profile), true);
    }

    /**
     * @throws \DomainException on an invalid transition (INVALID_TRANSITION; must never be written silently).
     */
    public static function assertTransition(string $from, string $to, string $profile = 'ledger'): void
    {
        if (!self::isValidTransition($from, $to, $profile)) {
            throw new \DomainException(sprintf('INVALID_TRANSITION: %s -> %s (%s)', $from, $to, $profile));
        }
    }

    /** Canonical per-order operation key: fch:{order_id}:{kind}:{n} (C§2). */
    public static function idempotencyKey(int|string $orderId, string $kind, int $sequence): string
    {
        if (!self::isKnownKind($kind)) {
            throw new \InvalidArgumentException(sprintf('Unknown operation kind "%s".', $kind));
        }
        if ($sequence < 0) {
            throw new \InvalidArgumentException('Sequence must be >= 0.');
        }
        $orderId = (string) $orderId;
        if ($orderId === '' || str_contains($orderId, ':')) {
            throw new \InvalidArgumentException('Order id must be a non-empty token without ":".');
        }
        return sprintf('fch:%s:%s:%d', $orderId, $kind, $sequence);
    }

    /** @return array{order_id:string,kind:string,sequence:int}|null */
    public static function parseIdempotencyKey(string $key): ?array
    {
        if (!preg_match('/^fch:([^:]+):([a-z_]+):(\d+)$/', $key, $m)) {
            return null;
        }
        return ['order_id' => $m[1], 'kind' => $m[2], 'sequence' => (int) $m[3]];
    }
}
