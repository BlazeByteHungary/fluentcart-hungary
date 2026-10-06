<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Shipping;

use FluentCartHungary\Core\Settings\ModuleSettings;

/**
 * Method → carrier/service binding (checkout shipping selection goal: home carrier selection, pickup
 * filters, carrier routing). Storage is our own non-secret ModuleSettings scope `shipping`:
 *
 *   method_{id}_carrier        carrier slug (mpl|gls|dpd|packeta|foxpost)
 *   method_{id}_service        home|pickup|locker
 *   method_{id}_service_types  comma-separated abstract point kinds (point|locker); empty = any
 *
 * Legacy W1/W2 pickup keys remain first-class readers and are kept in sync by the writer:
 *   method_{id}_pickup_carrier (implies service=pickup)
 *   method_{id}_pickup_types   native point codes (PM|CS|PP) or abstract kinds
 *
 * FluentCart cannot host per-method third-party settings (03_VERIFIED_EXTENSION_POINTS §2), so the
 * binding lives here and is consumed by the checkout handler, the pickup directory and the carrier
 * modules' label-routing gate. The class is WordPress-free except for the optional filters and
 * `sanitize_key`, so it is unit-testable.
 */
final class CarrierBinding
{
    public const SERVICE_HOME = 'home';
    public const SERVICE_PICKUP = 'pickup';
    public const SERVICE_LOCKER = 'locker';

    /** @var list<string> */
    public const SERVICES = [self::SERVICE_HOME, self::SERVICE_PICKUP, self::SERVICE_LOCKER];

    /** Abstract point kinds used by the common pickup UI. */
    public const KIND_POINT = 'point';
    public const KIND_LOCKER = 'locker';

    /** @var list<string> */
    public const KINDS = [self::KIND_POINT, self::KIND_LOCKER];

    /**
     * Resolve the binding for one FluentCart shipping method id.
     *
     * @return array{carrier:string,service:string,kinds:list<string>,legacy:bool}
     */
    public static function binding(int $methodId): array
    {
        $empty = ['carrier' => '', 'service' => '', 'kinds' => [], 'legacy' => false];
        if ($methodId <= 0) {
            return $empty;
        }

        try {
            $settings = new ModuleSettings();
        } catch (\Throwable $e) {
            return $empty;
        }

        $carrier = self::key((string) $settings->get('shipping', 'method_' . $methodId . '_carrier', ''));
        $service = strtolower(trim((string) $settings->get('shipping', 'method_' . $methodId . '_service', '')));
        $legacyCarrier = self::key((string) $settings->get('shipping', 'method_' . $methodId . '_pickup_carrier', ''));
        $legacy = false;

        if ($carrier === '' && $legacyCarrier !== '') {
            // W1/W2 configuration: a configured pickup carrier is a pickup binding.
            $carrier = $legacyCarrier;
            $service = $service !== '' ? $service : self::SERVICE_PICKUP;
            $legacy = true;
        }
        if (!in_array($service, self::SERVICES, true)) {
            $service = $carrier !== '' && $legacyCarrier !== '' ? self::SERVICE_PICKUP : '';
        }
        if ($carrier === '') {
            return $empty;
        }

        $kinds = self::parseKinds((string) $settings->get('shipping', 'method_' . $methodId . '_service_types', ''));
        if ($kinds === [] && self::isPickupService($service)) {
            $legacyTypes = (string) $settings->get('shipping', 'method_' . $methodId . '_pickup_types', '');
            $kinds = self::kindsFromLegacy($carrier, $legacyTypes);
        }

        return ['carrier' => $carrier, 'service' => $service, 'kinds' => $kinds, 'legacy' => $legacy];
    }

    public static function forMethod(int $methodId): array
    {
        return self::binding($methodId);
    }

    public static function isPickupService(string $service): bool
    {
        return $service === self::SERVICE_PICKUP || $service === self::SERVICE_LOCKER;
    }

    public static function isValidService(string $service): bool
    {
        return in_array($service, self::SERVICES, true);
    }

    /** @return list<string> */
    public static function parseKinds(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', strtolower(trim($raw))) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (in_array($part, self::KINDS, true) && !in_array($part, $out, true)) {
                $out[] = $part;
            }
        }
        return $out;
    }

    /**
     * Map the legacy `pickup_types` vocabulary (native carrier codes, e.g. MPL PM|CS|PP) to abstract kinds.
     * Abstract values already present are passed through, so mixed lists keep working.
     *
     * @return list<string>
     */
    public static function kindsFromLegacy(string $carrier, string $raw): array
    {
        $parts = preg_split('/[\s,]+/', strtoupper(trim($raw))) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $lower = strtolower($part);
            if (in_array($lower, self::KINDS, true)) {
                if (!in_array($lower, $out, true)) {
                    $out[] = $lower;
                }
                continue;
            }
            $kind = self::kindFromNative($carrier, $part);
            if ($kind === '') {
                $kind = self::kindFromNative('', $part);
            }
            if ($kind !== '' && !in_array($kind, $out, true)) {
                $out[] = $kind;
            }
        }
        return $out;
    }

    /**
     * Map a carrier-native point-type code to the abstract kind. Unknown codes return '' (data-absent; do
     * not invent). The mapping follows the provider contracts:
     *  - MPL: CS (parcel terminal) = locker; PP (PostaPont) / PM (post office) = staffed point;
     *  - Foxpost: every APM-family point is a locker;
     *  - GLS/DPD/Packeta: explicit locker/box markers → locker, otherwise a staffed point.
     */
    public static function kindFromNative(string $carrier, string $type): string
    {
        $type = strtoupper(trim($type));
        if ($type === '') {
            return '';
        }
        $carrier = self::key($carrier);

        if ($carrier === 'mpl') {
            return $type === 'CS' ? self::KIND_LOCKER : (in_array($type, ['PP', 'PM'], true) ? self::KIND_POINT : '');
        }
        if ($carrier === 'foxpost') {
            // Official apt-finder documentation (cdn.foxpost.hu/apt-finder/v1/documentation/): the
            // network mixes FOXPOST (classic automat), Z-BOX (smart locker) and Z-Pont (packeta partner
            // staffed point). The live feed (2026-10-06) carries the prefixed variants "FOXPOST A-BOX"
            // (1837), "FOXPOST Z-BOX" (1832) and "Packeta Z-Pont" (1338).
            if (str_contains($type, 'Z-PONT') || str_contains($type, 'ZPONT')) {
                return self::KIND_POINT;
            }
            if (str_contains($type, 'Z-BOX') || str_contains($type, 'ZBOX') || str_contains($type, 'A-BOX') || str_contains($type, 'ABOX') || $type === 'FOXPOST') {
                return self::KIND_LOCKER;
            }
            return ''; // unknown variant: never inventoried as a kind
        }
        if ($type === 'CS' || str_contains($type, 'LOCKER') || str_contains($type, 'BOX') || str_contains($type, 'APM') || str_contains($type, 'AUTOMATA')) {
            return self::KIND_LOCKER;
        }
        if ($carrier === 'packeta' || $carrier === 'gls' || $carrier === 'dpd') {
            return self::KIND_POINT;
        }
        return '';
    }

    /** Kind of a canonical directory point (uses its native `type`; carrier fallback from the point). */
    public static function kindOfPoint(array $point): string
    {
        $type = strtoupper(trim((string) ($point['type'] ?? '')));
        if ($type === '') {
            return '';
        }
        return self::kindFromNative((string) ($point['carrier'] ?? ''), $type);
    }

    /**
     * Does a binding (method) accept the point? Empty kinds = any point of the bound carrier.
     */
    public static function acceptsPoint(array $binding, array $point): bool
    {
        $carrier = self::key((string) ($binding['carrier'] ?? ''));
        if ($carrier === '' || $carrier !== self::key((string) ($point['carrier'] ?? ''))) {
            return false;
        }
        $kinds = array_values((array) ($binding['kinds'] ?? []));
        if ($kinds === []) {
            return true;
        }
        $kind = self::kindOfPoint($point);
        if ($kind === '') {
            return false;
        }
        return in_array($kind, $kinds, true);
    }

    /**
     * Pick the routable method for a carrier + abstract kind from a `methodId => binding` map (ascending
     * method id keeps the choice deterministic; a method with explicit kinds wins over an any-kind method).
     *
     * @param array<int,array{carrier:string,service:string,kinds:list<string>,legacy:bool}> $bindings
     */
    public static function methodForPoint(array $bindings, string $carrier, string $kind): int
    {
        $carrier = self::key($carrier);
        if ($carrier === '') {
            return 0;
        }
        ksort($bindings);
        $fallback = 0;
        foreach ($bindings as $methodId => $binding) {
            if ((string) ($binding['carrier'] ?? '') !== $carrier || !self::isPickupService((string) ($binding['service'] ?? ''))) {
                continue;
            }
            $kinds = array_values((array) ($binding['kinds'] ?? []));
            if ($kind !== '' && in_array($kind, $kinds, true)) {
                return (int) $methodId;
            }
            if ($kinds === [] && $fallback === 0) {
                $fallback = (int) $methodId;
            }
        }
        return $fallback;
    }

    /** Order-meta snapshot keys (the immutable order-time routing decision). */
    public const META_CARRIER = '_fch_carrier';
    public const META_SERVICE = '_fch_service';
    public const META_STATUS = '_fch_carrier_status';
    public const STATUS_UNRESOLVED = 'unresolved';

    /** ModuleSettings scope/key of the explicit legacy-default carrier policy (empty = no default). */
    public const SETTING_LEGACY_DEFAULT = 'legacy_default_carrier';

    /**
     * Resolve the routing decision for one order WITHOUT guessing:
     *  1. `_fch_carrier` order meta — the immutable snapshot, always wins;
     *  2. an earlier hard "unresolved" marker — stays unresolved (a later method re-binding must not
     *     silently adopt a carrier the merchant never chose for this order);
     *  3. the order's own shipping-method binding — locked into the meta when `$lock` (the standard
     *     call from services/enqueue, so a later admin re-binding cannot change a delivered order);
     *  4. the explicit legacy-default policy setting — locked the same way;
     *  5. otherwise unresolved. Orders with no shipping method and no shipping amount are
     *     `applicable=false` (digital / no delivery; silent skip, no admin state).
     *
     * @return array{carrier:string,service:string,source:string,resolved:bool,applicable:bool}
     */
    public static function resolveForOrder(object $order, bool $lock = false): array
    {
        $empty = ['carrier' => '', 'service' => '', 'source' => 'none', 'resolved' => false, 'applicable' => true];

        $metaCarrier = self::meta($order, self::META_CARRIER);
        if ($metaCarrier !== '') {
            return [
                'carrier' => $metaCarrier,
                'service' => self::meta($order, self::META_SERVICE),
                'source' => 'order_snapshot',
                'resolved' => true,
                'applicable' => true,
            ];
        }
        if (self::meta($order, self::META_STATUS) === self::STATUS_UNRESOLVED) {
            return ['carrier' => '', 'service' => '', 'source' => 'marked_unresolved', 'resolved' => false, 'applicable' => true];
        }

        $methodId = self::methodIdForOrder($order);
        if ($methodId > 0) {
            $binding = self::binding($methodId);
            if ($binding['carrier'] !== '') {
                $service = (string) $binding['service'];
                if ($lock) {
                    self::writeSnapshot($order, (string) $binding['carrier'], $service);
                }
                return ['carrier' => (string) $binding['carrier'], 'service' => $service, 'source' => 'method_binding', 'resolved' => true, 'applicable' => true];
            }
        } elseif ((float) ($order->shipping_total ?? 0) <= 0.0) {
            return ['carrier' => '', 'service' => '', 'source' => 'not_applicable', 'resolved' => false, 'applicable' => false];
        }

        $default = self::legacyDefaultCarrier();
        if ($default !== '') {
            $service = self::serviceForOrder($order);
            if ($lock) {
                self::writeSnapshot($order, $default, $service);
            }
            return ['carrier' => $default, 'service' => $service, 'source' => 'legacy_default_policy', 'resolved' => true, 'applicable' => true];
        }

        return $empty;
    }

    /**
     * Routing gate used by the carrier modules (enqueue) and the shipment services (execution, so a
     * pending/retried job cannot bypass the decision). Locks the resolution and records the actionable
     * unresolved state exactly once.
     *
     * @return array{allow:bool,reason:string,resolution:array{carrier:string,service:string,source:string,resolved:bool,applicable:bool}}
     */
    public static function guardForCarrier(object $order, string $carrier): array
    {
        $carrier = self::key($carrier);
        $resolution = self::resolveForOrder($order, true);
        if ($resolution['resolved']) {
            return ['allow' => $resolution['carrier'] === $carrier, 'reason' => $resolution['carrier'] === $carrier ? 'ok' : 'carrier_mismatch', 'resolution' => $resolution];
        }
        if ($resolution['applicable'] === false) {
            return ['allow' => false, 'reason' => 'not_applicable', 'resolution' => $resolution];
        }
        self::markUnresolved($order);
        if (function_exists('do_action')) {
            do_action('fch/shipping/carrier_unresolved', (int) ($order->id ?? $order->ID ?? 0), $carrier);
        }
        return ['allow' => false, 'reason' => 'carrier_unresolved', 'resolution' => $resolution];
    }

    /** Backwards-compatible boolean gate (default read-only). */
    public static function isBoundTo(object $order, string $carrier, bool $lock = false): bool
    {
        $carrier = self::key($carrier);
        if ($carrier === '') {
            return false;
        }
        $resolution = self::resolveForOrder($order, $lock);
        return $resolution['resolved'] && $resolution['carrier'] === $carrier;
    }

    /** Persist the merchant's manual carrier assignment (clears the unresolved marker). */
    public static function assignCarrier(object $order, string $carrier, string $service = ''): bool
    {
        $carrier = self::key($carrier);
        if ($carrier === '') {
            return false;
        }
        $service = self::isValidService($service) ? $service : self::serviceForOrder($order);
        self::writeSnapshot($order, $carrier, $service);
        if (function_exists('do_action')) {
            do_action('fch/shipping/carrier_assigned', (int) ($order->id ?? $order->ID ?? 0), $carrier, $service);
        }
        return true;
    }

    public static function markUnresolved(object $order): void
    {
        self::setMeta($order, self::META_STATUS, self::STATUS_UNRESOLVED);
        self::setMeta($order, self::META_STATUS . '_at', gmdate('c'));
    }

    public static function clearUnresolved(object $order): void
    {
        self::setMeta($order, self::META_STATUS, '');
    }

    public static function isUnresolved(object $order): bool
    {
        return self::meta($order, self::META_STATUS) === self::STATUS_UNRESOLVED;
    }

    /** Explicit, merchant-configured fallback policy for orders without any binding/snapshot. */
    public static function legacyDefaultCarrier(): string
    {
        try {
            $settings = new ModuleSettings();
        } catch (\Throwable $e) {
            return '';
        }
        return self::key((string) $settings->get('shipping', self::SETTING_LEGACY_DEFAULT, ''));
    }

    /**
     * FluentCart 1.7.0 stores the chosen shipping method in `fct_orders.config` JSON
     * (`config.shipping_method_id`); the order table has no own column. Older/synthetic callers may
     * still expose a plain `shipping_method_id` property, which keeps working first.
     */
    public static function methodIdForOrder(object $order): int
    {
        $direct = (int) ($order->shipping_method_id ?? 0);
        if ($direct > 0) {
            return $direct;
        }
        $config = $order->config ?? null;
        if (is_string($config)) {
            $config = json_decode($config, true);
        } elseif (is_object($config)) {
            $config = (array) $config;
        }
        return is_array($config) ? (int) ($config['shipping_method_id'] ?? 0) : 0;
    }

    /** Service derived from the order itself (pickup snapshot present → pickup, otherwise home). */
    public static function serviceForOrder(object $order): string
    {
        $service = self::meta($order, self::META_SERVICE);
        if (self::isValidService($service)) {
            return $service;
        }
        $point = self::meta($order, '_fch_pickup_point');
        if ($point !== '') {
            $decoded = json_decode($point, true);
            if (is_array($decoded) && trim((string) ($decoded['point_id'] ?? '')) !== '') {
                return self::SERVICE_PICKUP;
            }
        }
        return self::SERVICE_HOME;
    }

    private static function writeSnapshot(object $order, string $carrier, string $service): void
    {
        self::setMeta($order, self::META_CARRIER, $carrier);
        self::setMeta($order, self::META_SERVICE, self::isValidService($service) ? $service : self::serviceForOrder($order));
        self::clearUnresolved($order);
    }

    private static function meta(object $order, string $key): string
    {
        if (!method_exists($order, 'getMeta')) {
            return '';
        }
        $value = $order->getMeta($key, '');
        if (is_array($value) || is_object($value)) {
            return '';
        }
        return trim((string) $value);
    }

    private static function setMeta(object $order, string $key, string $value): void
    {
        if (!method_exists($order, 'updateMeta')) {
            return;
        }
        $order->updateMeta($key, $value);
    }

    private static function key(string $value): string
    {
        if (function_exists('sanitize_key')) {
            return (string) sanitize_key($value);
        }
        return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower(trim($value)));
    }
}
