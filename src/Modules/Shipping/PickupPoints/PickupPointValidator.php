<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\PickupPoints;

use FluentCartHungary\Core\Shipping\CarrierBinding;

/**
 * Server-side pickup-point validation + snapshot (invariant C §1.9; UX §4.1; snapshot shape C §2).
 *
 * There is NO live provider check on the checkout path: validation runs against the cached directory only
 * (UX §4.1 "Foxpost has no server-side validate → validation against the cached feed only, stated in UI as a
 * snapshot"). The provider fetch itself (MPL-004) happens out of band via {@see PickupDirectory::refreshMpl}.
 */
final class PickupPointValidator
{
    public const ERROR_REQUIRED = 'pickup_required';
    public const ERROR_UNKNOWN = 'pickup_unknown';
    public const ERROR_STALE = 'pickup_stale';
    public const ERROR_CARRIER_MISMATCH = 'pickup_carrier_mismatch';
    public const ERROR_TYPE_MISMATCH = 'pickup_type_mismatch';
    public const ERROR_DIRECTORY_UNAVAILABLE = 'pickup_directory_unavailable';
    public const ERROR_EXPIRED = 'pickup_directory_expired';

    /**
     * @param array{carrier?:string,point_id?:string,delivery_mode?:string} $selection
     * @param array{
     *     required?:bool,
     *     carrier?:string,
     *     allowed_types?:list<string>,
     *     directory_state?:string,
     *     directory?:PickupDirectory,
     *     points?:list<array<string,mixed>>,
     *     lookup?:callable
     * } $context
     * @return array{ok:bool,snapshot:?array,error:?string}
     */
    public function validate(array $selection, array $context): array
    {
        $required = (bool) ($context['required'] ?? true);
        $carrier = (string) ($context['carrier'] ?? '');
        $types = array_values(array_filter(array_map(
            static fn($type): string => strtoupper(trim((string) $type)),
            (array) ($context['allowed_types'] ?? [])
        ), static fn(string $type): bool => $type !== ''));

        $pointId = (string) ($selection['point_id'] ?? '');
        if ($pointId === '') {
            return $required
                ? $this->fail(self::ERROR_REQUIRED)
                : ['ok' => true, 'snapshot' => null, 'error' => null];
        }

        // Selection carrier must not contradict the method's required carrier.
        $selectionCarrier = (string) ($selection['carrier'] ?? '');
        if ($carrier !== '' && $selectionCarrier !== '' && strtolower($selectionCarrier) !== strtolower($carrier)) {
            return $this->fail(self::ERROR_CARRIER_MISMATCH);
        }

        $point = $this->lookup($pointId, $context);
        if ($point === null) {
            // An id that was never in the feed, or was removed/renamed by a refresh, is stale/unknown.
            return $this->fail(self::ERROR_UNKNOWN);
        }

        // 3/3 gate #3: an expired directory (last-good beyond the documented expiry policy) must not
        // persist a selection — the customer gets a visible error and can refresh.
        if ((string) ($context['directory_state'] ?? '') === 'expired') {
            return $this->fail(self::ERROR_EXPIRED);
        }

        $pointCarrier = (string) ($point['carrier'] ?? '');
        if ($carrier !== '' && $pointCarrier !== '' && strtolower($pointCarrier) !== strtolower($carrier)) {
            return $this->fail(self::ERROR_CARRIER_MISMATCH);
        }

        $pointType = strtoupper((string) ($point['type'] ?? ''));
        $deliveryMode = strtoupper((string) ($selection['delivery_mode'] ?? ''));
        $pointCarrier = $carrier !== '' ? $carrier : (string) ($point['carrier'] ?? '');

        if ($types !== [] && !self::typeAccepted($pointCarrier, $pointType, $deliveryMode, $types)) {
            return $this->fail(self::ERROR_TYPE_MISMATCH);
        }

        return [
            'ok' => true,
            'snapshot' => self::snapshot($point, $carrier !== '' ? $carrier : $pointCarrier, $context),
            'error' => null,
        ];
    }

    /**
     * A point satisfies the allowed-type list when ANY of these holds:
     *  - the native point type code is listed verbatim (legacy PM|CS|PP configuration);
     *  - the selected delivery_mode is listed verbatim;
     *  - the point's abstract kind (point|locker) is listed, or the listed legacy code maps to that kind.
     * An empty allowed list means "any type"; a point without any type information still passes an empty
     * list but never an explicit filter (it cannot be classified).
     *
     * @param list<string> $allowed
     */
    public static function typeAccepted(string $carrier, string $pointType, string $deliveryMode, array $allowed): bool
    {
        if ($allowed === []) {
            return true;
        }
        // Callers may pass native codes (legacy, uppercase) or abstract kinds (lowercase) in any case.
        $allowedUpper = array_map(static fn($value): string => strtoupper(trim((string) $value)), $allowed);
        if ($pointType !== '' && in_array(strtoupper($pointType), $allowedUpper, true)) {
            return true;
        }
        if ($deliveryMode !== '' && in_array(strtoupper($deliveryMode), $allowedUpper, true)) {
            return true;
        }
        $kind = CarrierBinding::kindFromNative($carrier, $pointType);
        if ($kind === '') {
            return false;
        }
        foreach ($allowedUpper as $candidate) {
            $candidateLower = strtolower($candidate);
            if ($candidateLower === $kind) {
                return true;
            }
            if (CarrierBinding::kindFromNative($carrier, $candidate) === $kind) {
                return true;
            }
        }
        return false;
    }

    /**
     * Exact snapshot shape: {carrier, point_id, name, address, zip, city, type, raw} (C §2; UT-15/IT-18),
     * extended with provenance fields ONLY when the caller supplies them (checkout 3/3 gate #5):
     * selector_driver (how the customer picked), authoritative_source (where the point really comes
     * from — a different concept), source_fetched_at, directory_version, validated_at,
     * validation_method. Old snapshots are never rewritten and no historical source time is fabricated.
     *
     * @param array<string,mixed> $context
     */
    public static function snapshot(array $point, string $carrier, array $context = []): array
    {
        $snapshot = [
            'carrier' => (string) ($carrier !== '' ? $carrier : ($point['carrier'] ?? '')),
            'point_id' => (string) ($point['point_id'] ?? $point['id'] ?? ''),
            'name' => (string) ($point['name'] ?? ''),
            'address' => (string) ($point['address'] ?? ''),
            'zip' => (string) ($point['zip'] ?? ''),
            'city' => (string) ($point['city'] ?? ''),
            'type' => (string) ($point['type'] ?? ''),
            'raw' => is_array($point['raw'] ?? null) ? $point['raw'] : $point,
        ];
        foreach (['selector_driver', 'authoritative_source', 'source_fetched_at', 'directory_version', 'validated_at', 'validation_method'] as $key) {
            if (!array_key_exists($key, $context) || $context[$key] === null || $context[$key] === '') {
                continue;
            }
            $snapshot[$key] = $context[$key];
        }
        return $snapshot;
    }

    /** @param array<string,mixed> $context */
    private function lookup(string $pointId, array $context): ?array
    {
        if (isset($context['lookup']) && is_callable($context['lookup'])) {
            $point = ($context['lookup'])($pointId);
            return is_array($point) ? $point : null;
        }
        if (isset($context['directory']) && $context['directory'] instanceof PickupDirectory) {
            return $context['directory']->get((string) ($context['carrier'] ?? 'mpl'), $pointId);
        }
        if (isset($context['points']) && is_array($context['points'])) {
            foreach ($context['points'] as $point) {
                if (is_array($point) && (string) ($point['point_id'] ?? $point['id'] ?? '') === $pointId) {
                    return $point;
                }
            }
        }
        return null;
    }

    /** @return array{ok:bool,snapshot:?array,error:string} */
    private function fail(string $code): array
    {
        return ['ok' => false, 'snapshot' => null, 'error' => $code];
    }

    /**
     * Operator/customer-facing Hungarian messages for the error codes (UX §4.1 states). Wrapped in `__()` when
     * WordPress is available so they stay translatable; the raw code is what tests assert.
     */
    public static function message(string $code): string
    {
        $messages = [
            self::ERROR_REQUIRED => 'Kérjük, válasszon átvételi pontot.',
            self::ERROR_UNKNOWN => 'A kiválasztott átvételi pont nem található a listában. Válasszon másikat.',
            self::ERROR_STALE => 'A kiválasztott átvételi pont elavult. Válasszon másikat.',
            self::ERROR_CARRIER_MISMATCH => 'A kiválasztott átvételi pont nem ehhez a szállítási módhoz tartozik.',
            self::ERROR_TYPE_MISMATCH => 'A kiválasztott pont típusa nem illik a szállítási módhoz.',
            self::ERROR_DIRECTORY_UNAVAILABLE => 'A csomagpontok listája jelenleg nem érhető el.',
            self::ERROR_EXPIRED => 'A csomagpontok listája elavult (a frissítés nem sikerült). Frissítse az oldalt vagy válasszon másik szállítási módot.',
        ];
        $text = $messages[$code] ?? 'Ismeretlen átvételi pont hiba.';
        return function_exists('__') ? (string) __($text, 'fluentcart-hungary') : $text;
    }
}
