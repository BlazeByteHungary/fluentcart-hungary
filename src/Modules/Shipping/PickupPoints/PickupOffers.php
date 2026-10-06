<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\PickupPoints;

use FluentCartHungary\Core\Shipping\CarrierBinding;

/**
 * Offer resolution for one pickup point (checkout 3/3 release gate #2).
 *
 * A point can be routable by several native FluentCart shipping methods (same carrier/kind, different
 * rate/service). This class builds the *actually selectable* offer list:
 *  - only methods bound to a pickup service whose kinds accept the point;
 *  - only methods FluentCart itself reports as applicable for the current cart country/state
 *    (`ShippingMethod::getApplicableForCountry`); when FluentCart is unavailable the filter is skipped
 *    and the result says so (`source=unavailable`) — never silently "everything is eligible";
 *  - prices come from the native method configuration through FluentCart's own formatter; the client
 *    never computes a price, and the final amount is whatever FluentCart calculates after the native
 *    method activation.
 *
 * The choice is explicit wherever it matters: the current cart method wins when it is among the offers;
 * a single offer is used directly; with several offers and no current method the server returns 0 so the
 * customer must choose (the client shows the priced offer list) — never "lowest id wins" silently.
 */
final class PickupOffers
{
    /** @var array{ids:list<int>,source:string,country:string,state:string}|null */
    private static ?array $applicableCache = null;
    /** @var array<int,object>|null */
    private static ?array $rowCache = null;

    /**
     * @return array{ids:list<int>,source:string,country:string,state:string}
     */
    public static function applicableMethodIds(): array
    {
        if (self::$applicableCache !== null) {
            return self::$applicableCache;
        }
        [$country, $state] = self::cartCountryState();
        $result = ['ids' => [], 'source' => 'unavailable', 'country' => $country, 'state' => $state];

        $class = 'FluentCart\\App\\Models\\ShippingMethod';
        if (class_exists($class) && method_exists($class, 'getApplicableForCountry')) {
            try {
                $methods = $class::getApplicableForCountry($country, $state);
                // FluentCart returns an ORM collection whose items live behind the iterator: never
                // (array)-cast it (that yields internal properties and silently empties the list).
                if ($methods instanceof \Traversable) {
                    $methods = iterator_to_array($methods);
                } elseif (is_object($methods) && method_exists($methods, 'all')) {
                    $methods = $methods->all();
                }
                $ids = [];
                foreach ((array) $methods as $method) {
                    // getApplicableForCountry() is zone/country scope only — a disabled method must not
                    // become selectable.
                    if (is_object($method) && isset($method->id) && !empty($method->is_enabled)) {
                        $ids[] = (int) $method->id;
                    }
                }
                $result = ['ids' => array_values(array_unique($ids)), 'source' => 'fluentcart_country', 'country' => $country, 'state' => $state];
            } catch (\Throwable $e) {
                // falls through to "unavailable"; the caller must not treat this as full eligibility
            }
        }
        return self::$applicableCache = $result;
    }

    /**
     * Country/state of the current session cart, falling back to the documented store country filter
     * (and finally HU, matching the plugin's launch market). No invented geo detection.
     *
     * @return array{0:string,1:string}
     */
    public static function cartCountryState(): array
    {
        $country = '';
        $state = '';
        $helper = 'FluentCart\\App\\Helpers\\CartHelper';
        if (class_exists($helper)) {
            try {
                $cart = $helper::getCart();
                $data = is_object($cart) ? ($cart->checkout_data ?? []) : [];
                if (is_string($data)) {
                    $data = json_decode($data, true);
                }
                if (is_array($data)) {
                    foreach (['shipping_address', 'billing_address'] as $key) {
                        $address = is_array($data[$key] ?? null) ? $data[$key] : [];
                        if ($country === '' && trim((string) ($address['country'] ?? '')) !== '') {
                            $country = strtoupper(trim((string) $address['country']));
                            $state = trim((string) ($address['state'] ?? ''));
                        }
                    }
                    if ($country === '' && trim((string) ($data['country'] ?? '')) !== '') {
                        $country = strtoupper(trim((string) $data['country']));
                        $state = trim((string) ($data['state'] ?? ''));
                    }
                }
            } catch (\Throwable $e) {
                // fall through to the store default
            }
        }
        if ($country === '' && function_exists('apply_filters')) {
            $filtered = apply_filters('fch/pickup/store_country', 'HU');
            $country = is_string($filtered) && trim($filtered) !== '' ? strtoupper(trim($filtered)) : 'HU';
        }
        if ($country === '') {
            $country = 'HU';
        }
        return [$country, $state];
    }

    /**
     * Offers for one canonical point.
     *
     * @param array<string,mixed> $point
     * @param array<int,array{carrier:string,service:string,kinds:list<string>,legacy:bool}> $bindings
     * @param list<int> $applicableIds
     * @return list<array{method_id:int,title:string,amount:string,price_display:string,price_known:bool,type:string}>
     */
    public static function offersForPoint(array $point, array $bindings, array $applicableIds, string $applicabilitySource = 'fluentcart_country'): array
    {
        $candidates = [];
        ksort($bindings);
        foreach ($bindings as $methodId => $binding) {
            if (!CarrierBinding::isPickupService((string) ($binding['service'] ?? ''))) {
                continue;
            }
            if (!CarrierBinding::acceptsPoint($binding, $point)) {
                continue;
            }
            if ($applicabilitySource !== 'unavailable' && !in_array((int) $methodId, $applicableIds, true)) {
                continue;
            }
            $candidates[] = (int) $methodId;
        }
        if ($candidates === []) {
            return [];
        }

        $rows = self::methodRows($candidates);
        $offers = [];
        foreach ($candidates as $methodId) {
            $row = $rows[$methodId] ?? null;
            $title = $row !== null && trim((string) ($row->title ?? '')) !== ''
                ? (string) $row->title
                : '#' . $methodId;
            $type = $row !== null ? (string) ($row->type ?? '') : '';
            $amount = $row !== null ? (string) ($row->amount ?? '') : '';
            $priceKnown = $type === 'flat_rate' && is_numeric($amount);
            $offers[] = [
                'method_id' => $methodId,
                'title' => $title,
                'amount' => $amount,
                'price_display' => $priceKnown ? self::formatAmount((float) $amount) : '',
                'price_known' => $priceKnown,
                'type' => $type,
            ];
        }
        return $offers;
    }

    /**
     * Deterministic choice policy: keep the CURRENT cart method when it is among the offers; a single
     * offer is used directly; otherwise 0 = the customer must make an explicit choice.
     *
     * @param list<array{method_id:int}> $offers
     */
    public static function choose(array $offers, int $currentMethodId): int
    {
        if ($offers === []) {
            return 0;
        }
        foreach ($offers as $offer) {
            if ((int) $offer['method_id'] === $currentMethodId) {
                return $currentMethodId;
            }
        }
        return count($offers) === 1 ? (int) $offers[0]['method_id'] : 0;
    }

    /**
     * Native price formatting through FluentCart's own helper when available (store currency and
     * separators stay FluentCart's business); plain fallback otherwise.
     */
    public static function formatAmount(float $amount): string
    {
        $helper = 'FluentCart\\App\\Helpers\\Helper';
        if (class_exists($helper) && method_exists($helper, 'formatAmount')) {
            try {
                return (string) $helper::formatAmount($amount, true, null, true, true, true);
            } catch (\Throwable $e) {
                // fall through
            }
        }
        return number_format($amount, $amount === floor($amount) ? 0 : 2, ',', ' ') . ' Ft';
    }

    /** @param list<int> $methodIds @return array<int,object> */
    private static function methodRows(array $methodIds): array
    {
        if (self::$rowCache === null) {
            self::$rowCache = [];
            $class = 'FluentCart\\App\\Models\\ShippingMethod';
            if (class_exists($class)) {
                try {
                    foreach ($class::query()->get() as $row) {
                        if (is_object($row) && isset($row->id)) {
                            self::$rowCache[(int) $row->id] = $row;
                        }
                    }
                } catch (\Throwable $e) {
                    self::$rowCache = [];
                }
            }
        }
        $out = [];
        foreach ($methodIds as $id) {
            if (isset(self::$rowCache[$id])) {
                $out[$id] = self::$rowCache[$id];
            }
        }
        return $out;
    }

    /** Test seam (unit/integration): reset the per-request caches. */
    public static function resetCache(): void
    {
        self::$applicableCache = null;
        self::$rowCache = null;
    }
}
