<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Shipping\PickupPoints;

use FluentCartHungary\Core\Shipping\CarrierBinding;

/**
 * FluentCart checkout hook callbacks for the pickup-point module (separated from the module so the
 * integration flow can invoke them directly).
 *
 * Seams (03_VERIFIED_EXTENSION_POINTS §3; constraints §3):
 *  - render: `fluent_cart/checkout_form_opening` (CheckoutRenderer.php:205) — our own field inside the form;
 *  - capture/invalidate: `fluent_cart/checkout/before_patch_checkout_data` (WebCheckoutHandler.php:869) at
 *    priority 20, after FC ShippingModule's priority 9 (app/Modules/Shipping/ShippingModule.php:20);
 *  - hard validation: `fluent_cart/checkout/validate_data` (CheckoutApi.php:1036) and
 *    `fluent_cart/checkout/validate_before_process` (CheckoutApi.php:115);
 *  - snapshot: `fluent_cart/checkout/prepare_other_data` (CheckoutApi.php:282) → Order::updateMeta()
 *    (app/Models/Order.php:502-522);
 *  - fragment: `fluent_cart/checkout/after_patch_checkout_data_fragments` (WebCheckoutHandler.php:1019);
 *  - cache meta: `fluent_cart/checkout/localize_data` (AssetLoader.php:742).
 *
 * Every callback is defensive: absent or differently-shaped FC payloads must never fatal.
 */
final class PickupCheckoutHandler
{
    public const FIELD = '_fch_pickup_point';
    public const META_POINT = '_fch_pickup_point';
    public const META_CARRIER = '_fch_carrier';
    public const META_DELIVERY_MODE = '_fch_delivery_mode';
    /** Bound service of the chosen method: home|pickup|locker (2/3 goal carrier routing). */
    public const META_SERVICE = '_fch_service';
    public const SEARCH_ACTION = 'fch_pickup_search';
    public const RESOLVE_ACTION = 'fch_pickup_resolve';
    /** A missing widget point may trigger at most one locked directory refresh per carrier/minute. */
    public const WIDGET_REFRESH_LOCK = 60;
    public const SAVE_ACTION = 'fch_pickup_save';
    public const REV_KEY = '_fch_pickup_rev';
    public const SAVED_AT_KEY = '_fch_pickup_saved_at';

    private PickupDirectory $directory;
    private PickupPointValidator $validator;

    public function __construct(?PickupDirectory $directory = null, ?PickupPointValidator $validator = null)
    {
        $this->directory = $directory ?? new PickupDirectory();
        $this->validator = $validator ?? new PickupPointValidator();
    }

    // ---- render ------------------------------------------------------------------------------------

    /** @param mixed $context `['cart'=>Cart]` from `fluent_cart/checkout_form_opening`. */
    public function renderField($context = null): void
    {
        $esc = static fn(string $v): string => function_exists('esc_attr') ? esc_attr($v) : htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $escHtml = static fn(string $v): string => function_exists('esc_html') ? esc_html($v) : htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $selected = $this->currentSelection($context);

        echo '<div class="fch-pickup" data-fch-pickup-root data-fluent-cart-checkout-page-form-input-wrapper>';
        printf(
            '<input type="hidden" id="%1$s" name="%1$s" value="%2$s" data-fch-pickup-input />',
            $esc(self::FIELD),
            $esc($selected !== null ? (string) $this->jsonEncode($selected) : '')
        );
        echo '<div class="fch-pickup__field">';
        printf(
            '<button type="button" class="fch-pickup__trigger" data-fch-pickup-open hidden aria-haspopup="dialog" aria-expanded="false" aria-describedby="fch-pickup-error">%s</button>',
            $escHtml($this->t('Válasszon átvételi pontot'))
        );
        echo '<span class="fch-pickup__status" data-fch-pickup-status aria-live="polite">';
        if ($selected !== null) {
            echo $escHtml($this->selectedLabel($selected));
        }
        echo '</span></div>';
        // FC's native checkout error path (FluentCartCheckout.js showValidationErrors) fills the element
        // with [data-fluent-cart-checkout-page-form-error][for="<input name>"] and adds `show_error`.
        printf(
            '<span class="fch-pickup__error" id="fch-pickup-error" role="alert" data-fch-pickup-error data-fluent-cart-checkout-page-form-error for="%s"></span>',
            $esc(self::FIELD)
        );

        echo '<div class="fch-pickup__modal" data-fch-pickup-modal hidden role="dialog" aria-modal="true" aria-labelledby="fch-pickup-title">';
        echo '<div class="fch-pickup__sheet">';
        printf('<h2 id="fch-pickup-title" class="fch-pickup__title">%s</h2>', $escHtml($this->t('Átvételi pont kiválasztása')));
        echo '<div class="fch-pickup__filters" data-fch-pickup-filters></div>';
        echo '<div class="fch-pickup__search"><label class="screen-reader-text" for="fch-pickup-q">' . $escHtml($this->t('Keresés')) . '</label>';
        printf('<input type="search" id="fch-pickup-q" data-fch-pickup-q autocomplete="off" placeholder="%s" />', $esc($this->t('Irányítószám, város vagy pont neve')));
        echo '</div>';
        echo '<div class="fch-pickup__state" data-fch-pickup-state aria-live="polite" aria-busy="false"></div>';
        echo '<ul class="fch-pickup__list" role="listbox" data-fch-pickup-list aria-activedescendant=""></ul>';
        echo '<div class="fch-pickup__more" data-fch-pickup-more></div>';
        echo '<div class="fch-pickup__actions">';
        printf('<button type="button" class="fch-pickup__retry" data-fch-pickup-retry hidden>%s</button>', $escHtml($this->t('Újrapróbálás')));
        printf('<button type="button" class="fch-pickup__fallback" data-fch-pickup-fallback>%s</button>', $escHtml($this->t('Házhozszállítás választása')));
        printf('<button type="button" class="fch-pickup__close" data-fch-pickup-close>%s</button>', $escHtml($this->t('Bezárás')));
        echo '</div></div></div></div>';
    }

    /** Enqueue our assets on the FluentCart checkout page (FC page detection, AssetLoader.php:101-132). */
    public function enqueueAssets(): void
    {
        if (!function_exists('wp_enqueue_script')) {
            return;
        }
        if (!self::isCheckoutPage()) {
            return;
        }
        wp_enqueue_style('fch-pickup-points', $this->assetUrl('pickup-points.css'), [], defined('FCH_VERSION') ? FCH_VERSION : '0.1.0');
        wp_enqueue_script(
            'fch-pickup-points',
            $this->assetUrl('pickup-points.js'),
            function_exists('wp_script_is') && wp_script_is('fct-checkout', 'registered') ? ['fct-checkout'] : [],
            defined('FCH_VERSION') ? FCH_VERSION : '0.1.0',
            true
        );
    }

    private static function isCheckoutPage(): bool
    {
        $class = 'FluentCart\\App\\Services\\TemplateService';
        if (class_exists($class) && method_exists($class, 'getCurrentFcPageType')) {
            return (string) $class::getCurrentFcPageType() === 'checkout';
        }
        // Fallback: FC checkout uses the `fluent-cart` query/request values (03_VERIFIED §3).
        return isset($_GET['fluent-cart']) && (string) $_GET['fluent-cart'] === 'checkout';
    }

    private function assetUrl(string $file): string
    {
        $base = defined('FCH_URL') ? FCH_URL : '';
        return $base . 'assets/pickup-points/' . $file;
    }

    // ---- capture / invalidate ----------------------------------------------------------------------

    /**
     * @param mixed $fillData
     * @param mixed $data
     * @return mixed
     */
    public function beforePatchCheckoutData($fillData, $data = [])
    {
        if (!is_array($fillData)) {
            return $fillData;
        }
        if (!is_array($data)) {
            $data = [];
        }

        $allData = is_array($data['all_data'] ?? null) ? $data['all_data'] : [];
        $changes = is_array($data['changes'] ?? null) ? $data['changes'] : [];
        $cart = $data['cart'] ?? null;

        if (!isset($fillData['checkout_data']) || !is_array($fillData['checkout_data'])) {
            $fillData['checkout_data'] = $this->checkoutData($cart);
        }

        $incoming = $this->parseSelection($allData[self::FIELD] ?? null);
        $stored = $this->parseSelection($fillData['checkout_data'][self::FIELD] ?? null);

        if ($incoming === null) {
            $incoming = $stored;
        }

        $methodId = $this->selectedMethodId(
            is_array($changes) ? $changes : [],
            $allData,
            $fillData['checkout_data']
        );
        $context = PickupPointsModule::methodContext($methodId);

        if ($incoming === null) {
            $this->dropSelection($fillData['checkout_data']);
            return $fillData;
        }

        // Method no longer requires a pickup point, or the method changed to a different carrier:
        // revalidate against the current method context; on failure the stored point is DROPPED (invariant §1.9).
        if (empty($context['required'])) {
            $this->dropSelection($fillData['checkout_data']);
            return $fillData;
        }

        $result = $this->validator->validate($incoming, [
            'required' => true,
            'carrier' => (string) $context['carrier'],
            'allowed_types' => (array) $context['allowed_types'],
            'directory' => $this->directory,
            'directory_state' => $this->directoryState((string) $context['carrier']),
         ] + $this->snapshotProvenance((string) $context['carrier']));

        if (!$result['ok']) {
            $this->dropSelection($fillData['checkout_data']);
            $fillData['checkout_data'][self::FIELD . '_error'] = (string) $result['error'];
            return $fillData;
        }

        $deliveryMode = (string) ($incoming['delivery_mode'] ?? $result['snapshot']['type'] ?? '');
        $fillData['checkout_data'][self::FIELD] = [
            'carrier' => (string) $result['snapshot']['carrier'],
            'point_id' => (string) $result['snapshot']['point_id'],
            'delivery_mode' => $deliveryMode,
        ];
        unset($fillData['checkout_data'][self::FIELD . '_error']);

        return $fillData;
    }

    // ---- validation --------------------------------------------------------------------------------

    /**
     * @param mixed $errors
     * @param mixed $context `['data'=>..., 'cart'=>...]`
     * @return mixed
     */
    public function validateData($errors, $context = [])
    {
        if (!is_array($errors)) {
            $errors = [];
        }
        if (!is_array($context)) {
            return $errors;
        }

        $data = is_array($context['data'] ?? null) ? $context['data'] : $context;
        $cart = $context['cart'] ?? null;
        $methodId = $this->selectedMethodId([], $data, $this->checkoutData($cart));
        $methodContext = PickupPointsModule::methodContext($methodId);

        if (empty($methodContext['required'])) {
            return $errors;
        }

        $selection = $this->parseSelection($data[self::FIELD] ?? null)
            ?? $this->parseSelection($this->checkoutData($cart)[self::FIELD] ?? null);

        $result = $this->validator->validate($selection ?? [], [
            'required' => true,
            'carrier' => (string) $methodContext['carrier'],
            'allowed_types' => (array) $methodContext['allowed_types'],
            'directory' => $this->directory,
            'directory_state' => $this->directoryState((string) $methodContext['carrier']),
         ] + $this->snapshotProvenance((string) $methodContext['carrier']));

        if (!$result['ok']) {
            $errors[self::FIELD] = [
                $result['error'] === PickupPointValidator::ERROR_REQUIRED ? 'required' : 'invalid'
                    => PickupPointValidator::message((string) $result['error']),
            ];
        }

        return $errors;
    }

    /**
     * Final safety net only.
     *
     * The pickup error MUST surface as a per-field checkout error, which FluentCart builds from the
     * `fluent_cart/checkout/validate_data` filter (CheckoutApi.php:1036-1043 → `{status:'failed',
     * errors:{field:{code:msg}}}` → FluentCartCheckout.js `showValidationErrors`). A WP_Error from
     * `validate_before_process` produces only `{status:'failed', message}` (CheckoutApi.php:115-122) which
     * the UI cannot attach to the pickup block (PA-DEF-01), so this callback defers to validateData() and
     * never short-circuits the checkout with a message-only failure.
     *
     * @param mixed $valid
     * @param mixed $data
     * @return mixed
     */
    public function validateBeforeProcess($valid, $data = [])
    {
        return $valid;
    }

    // ---- snapshot ----------------------------------------------------------------------------------

    /** @param mixed $data `['cart','order','prev_order','request_data','validated_data']` */
    public function prepareOtherData($data = []): void
    {
        if (!is_array($data)) {
            return;
        }
        $order = $data['order'] ?? null;
        if (!is_object($order) || !method_exists($order, 'updateMeta')) {
            return;
        }

        $requestData = is_array($data['request_data'] ?? null) ? $data['request_data'] : [];
        $cart = $data['cart'] ?? null;

        $methodId = $this->selectedMethodId([], $requestData, $this->checkoutData($cart));
        $methodContext = PickupPointsModule::methodContext($methodId);

        if (empty($methodContext['required'])) {
            // Home-bound methods still snapshot the carrier/service so label routing and the order screen
            // see the same binding without a pickup point (2/3 goal: home carrier selection).
            $binding = CarrierBinding::binding($methodId);
            if ($binding['carrier'] !== '' && $binding['service'] === CarrierBinding::SERVICE_HOME) {
                $order->updateMeta(self::META_CARRIER, (string) $binding['carrier']);
                $order->updateMeta(self::META_SERVICE, CarrierBinding::SERVICE_HOME);
            }
            return;
        }

        $selection = $this->parseSelection($requestData[self::FIELD] ?? null)
            ?? $this->parseSelection($this->checkoutData($cart)[self::FIELD] ?? null);
        if ($selection === null) {
            return;
        }

        $result = $this->validator->validate($selection, [
            'required' => true,
            'carrier' => (string) $methodContext['carrier'],
            'allowed_types' => (array) $methodContext['allowed_types'],
            'directory' => $this->directory,
            'directory_state' => $this->directoryState((string) $methodContext['carrier']),
         ] + $this->snapshotProvenance((string) $methodContext['carrier']));
        if (!$result['ok'] || !is_array($result['snapshot'])) {
            return;
        }

        $snapshot = $result['snapshot'];
        $deliveryMode = (string) ($selection['delivery_mode'] ?? $snapshot['type'] ?? '');

        $order->updateMeta(self::META_POINT, (string) $this->jsonEncode($snapshot));
        $order->updateMeta(self::META_CARRIER, (string) $snapshot['carrier']);
        $order->updateMeta(self::META_DELIVERY_MODE, $deliveryMode);
        $order->updateMeta(self::META_SERVICE, (string) ($methodContext['service'] !== '' ? $methodContext['service'] : CarrierBinding::SERVICE_PICKUP));
    }

    // ---- fragment / cache meta --------------------------------------------------------------------

    /** @param mixed $fragments @param mixed $context @return mixed */
    public function afterPatchFragments($fragments, $context = [])
    {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $cart = is_array($context) ? ($context['cart'] ?? null) : null;
        $selection = $this->currentSelectionFromCart($cart);
        $label = $selection !== null ? $this->selectedLabel($selection) : '';

        $fragments[] = [
            'selector' => '[data-fch-pickup-status]',
            'content' => '<span data-fch-pickup-status aria-live="polite">'
                . (function_exists('esc_html') ? esc_html($label) : htmlspecialchars($label, ENT_QUOTES, 'UTF-8'))
                . '</span>',
            'type' => 'replace',
        ];
        return $fragments;
    }

    /**
     * Expose directory cache meta + per-method pickup map to the checkout script as `window.fch_pickup`.
     *
     * @param mixed $data
     * @param mixed $cart
     * @return mixed
     */
    public function localizeData($data, $cart = null)
    {
        if (!is_array($data)) {
            return $data;
        }
        $cartObject = $this->loadCart();
        $data['fch_pickup'] = [
            'ajaxUrl' => function_exists('admin_url') ? admin_url('admin-ajax.php') : '',
            'nonce' => function_exists('wp_create_nonce') ? wp_create_nonce('fluentcart') : '',
            'searchAction' => self::SEARCH_ACTION,
            'saveAction' => self::SAVE_ACTION,
            'resolveAction' => self::RESOLVE_ACTION,
            'drivers' => PickupPointsModule::drivers(),
            'field' => self::FIELD,
            'rev' => (int) ($this->checkoutData($cartObject)[self::REV_KEY] ?? 0),
            'limit' => 30,
            'pageSize' => 20,
            'methods' => PickupPointsModule::methodsMap(),
            'carriers' => $this->carrierMeta(),
            'serviceLabels' => [
                CarrierBinding::SERVICE_HOME => $this->t('Házhozszállítás'),
                CarrierBinding::SERVICE_PICKUP => $this->t('Csomagpont'),
                CarrierBinding::SERVICE_LOCKER => $this->t('Automata'),
            ],
            'kindLabels' => [
                CarrierBinding::KIND_POINT => $this->t('Csomagpont'),
                CarrierBinding::KIND_LOCKER => $this->t('Automata'),
            ],
            'i18n' => [
                'loading' => $this->t('Betöltés…'),
                'empty' => $this->t('Nincs találat a megadott városra/irányítószámra. Módosítsa a keresést.'),
                'error' => $this->t('A csomagpontok betöltése nem sikerült.'),
                'retry' => $this->t('Újrapróbálás'),
                'fallback' => $this->t('Házhozszállítás választása'),
                'fallbackList' => $this->t('Szállítási módok mutatása'),
                'fallbackInfo' => $this->t('Válasszon szállítási módot a listából.'),
                'choose' => $this->t('Kiválasztás'),
                'selectPrompt' => $this->t('Válasszon átvételi pontot'),
                'saving' => $this->t('Mentés…'),
                'saved' => $this->t('Kiválasztva'),
                'saveError' => $this->t('A kiválasztott pont mentése nem sikerült. Próbálja újra.'),
                'filterCarrier' => $this->t('Fuvarozó'),
                'filterKind' => $this->t('Típus'),
                'filterAll' => $this->t('Összes'),
                'loadMore' => $this->t('További találatok'),
                'results' => $this->t('találat'),
                'pageInfo' => $this->t('oldal'),
                'switching' => $this->t('Szállítási mód váltása…'),
                'switchFailed' => $this->t('A szállítási mód váltása nem sikerült ehhez a ponthoz.'),
                'offerTitle' => $this->t('Válassza ki az alkalmazandó szállítási ajánlatot'),
                'offerConfirm' => $this->t('Ajánlat kiválasztása'),
                'offerBack' => $this->t('Vissza a listához'),
                'offerPriceUnknown' => $this->t('A díjat a kosár számolja'),
                'noOffers' => $this->t('Ehhez a ponthoz nincs alkalmazható szállítási ajánlat.'),
                'widgetOpen' => $this->t('Választás a térképes widgetben'),
                'widgetClose' => $this->t('Widget bezárása'),
                'widgetLoading' => $this->t('Widget betöltése…'),
                'widgetError' => $this->t('A widget betöltése vagy a pont ellenőrzése nem sikerült. Válasszon a listából.'),
                'widgetNotFound' => $this->t('A widgetben kiválasztott pont nem található a hivatalos listában. Válasszon a listából vagy próbálja újra.'),
                'widgetResultLoaded' => $this->t('A widgetben kiválasztott pont ellenőrizve.'),
                'stale' => $this->t('A pontlista régi; a választás pillanatnyi állapotot rögzít.'),
                'expired' => $this->t('A pontlista elavult (a frissítés nem sikerült); ez a lista nem használható választáshoz.'),
                'clearFilters' => $this->t('Szűrők törlése'),
                'groupHome' => $this->t('Házhozszállítás'),
                'groupPickup' => $this->t('Csomagpont'),
                'groupLocker' => $this->t('Automata'),
                'groupUnbound' => $this->t('Nincs futár hozzárendelve'),
            ],
        ];
        return $data;
    }

    /**
     * AJAX search backed by the cached directories ONLY — never network (UX §4.1).
     *
     * v2 (checkout 2/3 goal): filters across every pickup-bound carrier (or one carrier), by abstract
     * point kind (point|locker), with server-side pagination and a per-point routable FluentCart method
     * id. Only points for which a bound method exists are returned, so a selection can always activate a
     * native method/rate.
     */
    public function ajaxSearch(): void
    {
        if (function_exists('check_ajax_referer')) {
            check_ajax_referer('fluentcart', 'nonce');
        }

        $methods = PickupPointsModule::methodsMap();
        $bindings = [];
        $eligible = [];
        foreach ($methods as $methodId => $entry) {
            if (!CarrierBinding::isPickupService((string) ($entry['service'] ?? ''))) {
                continue;
            }
            $carrier = (string) ($entry['carrier'] ?? '');
            if ($carrier === '') {
                continue;
            }
            $bindings[(int) $methodId] = [
                'carrier' => $carrier,
                'service' => (string) $entry['service'],
                'kinds' => (array) ($entry['types'] ?? []),
                'legacy' => false,
            ];
            if (!in_array($carrier, $eligible, true)) {
                $eligible[] = $carrier;
            }
        }

        // An explicitly empty `carrier` means "all eligible carriers"; only an ABSENT parameter falls
        // back to the current method's carrier (otherwise the "Összes" filter could never widen).
        $carrierProvided = array_key_exists('carrier', $_GET);
        $carrier = isset($_GET['carrier']) ? sanitize_key((string) wp_unslash($_GET['carrier'])) : '';
        $query = isset($_GET['q']) ? sanitize_text_field((string) wp_unslash($_GET['q'])) : '';
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 20;
        $perPage = max(1, min(50, $perPage));

        $rawKinds = isset($_GET['kinds']) ? (string) wp_unslash($_GET['kinds']) : '';
        $kinds = CarrierBinding::parseKinds($rawKinds);
        if ($kinds === [] && $rawKinds === '' && isset($_GET['method_id'])) {
            $methodId = (int) $_GET['method_id'];
            if (isset($bindings[$methodId])) {
                $kinds = (array) $bindings[$methodId]['kinds'];
                if (!$carrierProvided) {
                    $carrier = (string) $bindings[$methodId]['carrier'];
                }
            }
        }

        $searchCarriers = $carrier !== '' && in_array($carrier, $eligible, true) ? [$carrier] : $eligible;
        if ($searchCarriers === []) {
            $searchCarriers = array_values(array_unique(array_merge($eligible, [PickupDirectory::MPL_CARRIER])));
        }

        // Native eligibility + offers for the CURRENT cart context (3/3 gate #2). Never "all bound
        // methods"; when FluentCart is unavailable the source marker says so.
        $eligibility = PickupOffers::applicableMethodIds();
        $cartData = $this->checkoutData($this->loadCart());
        $currentMethodId = (int) ($cartData['shipping_data']['shipping_method_id'] ?? 0);

        // Directory lifecycle state per carrier: an expired (beyond the documented expiry policy) list
        // is never selectable; a stale list is selectable but visibly flagged.
        $dirStates = [];
        $versions = [];
        foreach ($searchCarriers as $name) {
            $entry = $this->directory->load($name);
            $dirStates[$name] = PickupDirectory::stateAt($entry, time());
            $versions[$name] = (string) ($entry['version'] ?? '');
        }
        // The fingerprint covers the directory generations AND the cart-context eligibility set: an
        // address/country change that alters the applicable methods also resets paging.
        $directoryVersion = substr(sha1((string) wp_json_encode([
            'versions' => $versions,
            'eligibility' => $eligibility['ids'],
            'country' => $eligibility['country'],
            'state' => $eligibility['state'],
        ])), 0, 16);

        // Version-consistent pagination: "load more" from a previous directory generation resets to
        // page 1 instead of silently mixing rows (3/3 gate #3).
        $versionReset = false;
        $clientVersion = isset($_GET['dir_version']) ? sanitize_text_field((string) wp_unslash($_GET['dir_version'])) : '';
        if ($clientVersion !== '' && $clientVersion !== $directoryVersion && $page > 1) {
            $versionReset = true;
            $page = 1;
        }

        $pageResult = $this->directory->searchMulti($searchCarriers, $query, $kinds, $page, $perPage);
        $public = [];
        foreach ($pageResult['points'] as $point) {
            $pointCarrier = (string) ($point['carrier'] ?? '');
            if (($dirStates[$pointCarrier] ?? 'expired') === 'expired') {
                continue; // expired directory: last-good kept for ops, but not selectable
            }
            $kind = CarrierBinding::kindOfPoint($point);
            $offers = PickupOffers::offersForPoint($point, $bindings, $eligibility['ids'], $eligibility['source']);
            if ($offers === []) {
                continue; // no selectable native offer in this cart context — never offer a dead row
            }
            $public[] = [
                'point_id' => (string) ($point['point_id'] ?? ''),
                'carrier' => $pointCarrier,
                'kind' => $kind,
                // 0 = several applicable offers and the current method is not among them: the customer
                // must choose explicitly (the client renders the priced offer list).
                'method_id' => PickupOffers::choose($offers, $currentMethodId),
                'offers' => $offers,
                'name' => (string) ($point['name'] ?? ''),
                'address' => (string) ($point['address'] ?? ''),
                'zip' => (string) ($point['zip'] ?? ''),
                'city' => (string) ($point['city'] ?? ''),
                'type' => (string) ($point['type'] ?? ''),
            ];
        }

        $dirs = [];
        $allFresh = true;
        foreach ($searchCarriers as $name) {
            $entry = $this->directory->load($name);
            $state = $dirStates[$name] ?? PickupDirectory::stateAt($entry, time());
            $fresh = $state === 'fresh';
            $allFresh = $allFresh && ($state !== 'expired' && ($fresh || $entry['points'] === []));
            $dirs[$name] = [
                'count' => count($entry['points']),
                'fresh' => $fresh,
                'state' => $state,
                'age_seconds' => PickupDirectory::ageAt($entry, time()),
                'source' => $entry['source'],
                'version' => (string) ($entry['version'] ?? ''),
                'last_error' => (string) ($entry['last_error'] ?? ''),
            ];
        }

        if (function_exists('wp_send_json')) {
            wp_send_json([
                'ok' => true,
                'carrier' => $carrier,
                'kinds' => $kinds,
                'fresh' => $allFresh,
                'age_seconds' => $carrier !== '' ? $this->directory->ageSeconds($carrier) : null,
                'eligibility' => [
                    'source' => (string) $eligibility['source'],
                    'country' => (string) $eligibility['country'],
                    'state' => (string) $eligibility['state'],
                    'current_method_id' => $currentMethodId,
                ],
                'directory_version' => $directoryVersion,
                'version_reset' => $versionReset,
                'total' => (int) $pageResult['total'],
                'page' => (int) $pageResult['page'],
                'per_page' => (int) $pageResult['per_page'],
                'pages' => (int) $pageResult['pages'],
                'per_carrier' => (array) $pageResult['per_carrier'],
                'dirs' => $dirs,
                'points' => $public,
            ]);
        }
    }

    /**
     * Narrow, session/cart-bound pickup draft save (PA-DEF-02).
     *
     * FluentCart's own `save_checkout_data` whitelist (WebCheckoutHandler.php:780-810) cannot persist
     * `_fch_pickup_point`: a point-only change normalizes to "no changes" and early-returns
     * (WebCheckoutHandler.php:841-845). This endpoint writes ONLY the pickup draft (+ revision metadata)
     * into the current cart's `checkout_data`, preserving every other key, after validating the point
     * against the current server-side shipping context. The FluentCart place-order validation
     * (`fluent_cart/checkout/validate_data`) remains the final authority.
     *
     * The cart is resolved from the request session (CartHelper::getCart) — a client cannot select
     * another cart hash. A monotonic compare-and-set revision keeps late/parallel intents from
     * resurrecting an invalidated point.
     */
    /**
     * AJAX entry for a widget point id (checkout 3/3 gate #4). The widget payload is NOT authoritative:
     * the id is mapped to the cached directory and only then resolved into offers; a missing point gets
     * at most one locked, official directory refresh per carrier/minute, then a visible not-found result
     * (the client falls back to the list — a blind widget payload can never become an order).
     */
    public function ajaxResolve(): void
    {
        if (function_exists('check_ajax_referer')) {
            check_ajax_referer('fluentcart', 'nonce');
        }
        $carrier = isset($_GET['carrier']) ? sanitize_key((string) wp_unslash($_GET['carrier'])) : '';
        $widgetId = isset($_GET['widget_id']) ? sanitize_text_field((string) wp_unslash($_GET['widget_id'])) : '';
        if (function_exists('wp_send_json')) {
            wp_send_json($this->resolveWidgetPoint($carrier, $widgetId));
        }
    }

    /** @return array<string,mixed> */
    public function resolveWidgetPoint(string $carrier, string $widgetId): array
    {
        $carrier = strtolower(trim($carrier));
        $driver = PickupPointsModule::driverFor($carrier);
        if (!in_array((string) $driver['driver'], [PickupPointsModule::DRIVER_FOXPOST_WIDGET, PickupPointsModule::DRIVER_PACKETA_WIDGET, PickupPointsModule::DRIVER_GLS_WIDGET], true)) {
            return ['ok' => false, 'found' => false, 'code' => 'driver_disabled', 'message' => $this->t('Ehhez a fuvarozóhoz nincs widget választó engedélyezve.')];
        }
        if (empty($driver['enabled'])) {
            return ['ok' => false, 'found' => false, 'code' => 'driver_unavailable', 'message' => $this->t('A widget választó jelenleg nem elérhető (hiányzó kulcs vagy feltételes driver).')];
        }
        if ($widgetId === '') {
            return ['ok' => true, 'found' => false, 'code' => 'widget_cancelled', 'message' => ''];
        }

        // Packeta documents a server-side widget validate endpoint; use it (never trust the callback).
        if ($carrier === 'packeta' && class_exists(PacketaWidgetClient::class)) {
            $apiKey = (string) ($driver['api_key'] ?? '');
            $client = new PacketaWidgetClient();
            $validation = $client->validate($apiKey, $widgetId);
            if (!$validation['ok']) {
                return ['ok' => false, 'found' => false, 'code' => 'packeta_validate_failed', 'message' => $this->t('A Packeta pont ellenőrzése nem sikerült.')];
            }
            if (empty($validation['is_valid'])) {
                return ['ok' => true, 'found' => false, 'code' => 'widget_point_not_found', 'message' => $this->t('A kiválasztott pont nem érvényes a szolgáltatónál.')];
            }
        }

        $point = $this->directory->findByWidgetId($carrier, $widgetId);
        $refreshed = false;
        if ($point === null && PickupPointsModule::refreshEnabled()) {
            $lockKey = 'fch_pickup_widget_refresh_at_' . $carrier;
            $last = function_exists('get_option') ? (int) get_option($lockKey, 0) : 0;
            if ((time() - $last) >= self::WIDGET_REFRESH_LOCK) {
                if (function_exists('update_option')) {
                    update_option($lockKey, time(), false);
                }
                $result = PickupPointsModule::refreshCarrier($carrier);
                $refreshed = true;
                if (!empty($result['ok'])) {
                    $point = $this->directory->findByWidgetId($carrier, $widgetId);
                }
            }
        }
        if ($point === null) {
            return [
                'ok' => true,
                'found' => false,
                'refreshed' => $refreshed,
                'code' => 'widget_point_not_found',
                'message' => $this->t('A kiválasztott pont nem található a hivatalos listában. Válasszon a listából.'),
            ];
        }

        $bindings = [];
        foreach (PickupPointsModule::methodsMap() as $methodId => $entry) {
            if (!CarrierBinding::isPickupService((string) ($entry['service'] ?? ''))) {
                continue;
            }
            $bindings[(int) $methodId] = [
                'carrier' => (string) ($entry['carrier'] ?? ''),
                'service' => (string) ($entry['service'] ?? ''),
                'kinds' => (array) ($entry['types'] ?? []),
                'legacy' => false,
            ];
        }
        $eligibility = PickupOffers::applicableMethodIds();
        $offers = PickupOffers::offersForPoint($point, $bindings, $eligibility['ids'], $eligibility['source']);
        if ($offers === []) {
            return [
                'ok' => true,
                'found' => false,
                'refreshed' => $refreshed,
                'code' => 'widget_point_not_selectable',
                'message' => $this->t('Ehhez a ponthoz nincs alkalmazható szállítási ajánlat ebben a kosárban.'),
            ];
        }
        $cartData = $this->checkoutData($this->loadCart());
        $currentMethodId = (int) ($cartData['shipping_data']['shipping_method_id'] ?? 0);

        return [
            'ok' => true,
            'found' => true,
            'refreshed' => $refreshed,
            'point' => [
                'point_id' => (string) ($point['point_id'] ?? ''),
                'carrier' => (string) ($point['carrier'] ?? $carrier),
                'kind' => CarrierBinding::kindOfPoint($point),
                'method_id' => PickupOffers::choose($offers, $currentMethodId),
                'offers' => $offers,
                'name' => (string) ($point['name'] ?? ''),
                'address' => (string) ($point['address'] ?? ''),
                'zip' => (string) ($point['zip'] ?? ''),
                'city' => (string) ($point['city'] ?? ''),
                'type' => (string) ($point['type'] ?? ''),
            ],
        ];
    }

    public function handleSaveAjax(): void
    {
        if (function_exists('check_ajax_referer')) {
            check_ajax_referer('fluentcart', 'nonce');
        }
        $respond = static function (array $payload): void {
            if (function_exists('wp_send_json')) {
                wp_send_json($payload);
            }
            exit;
        };

        $cart = $this->loadCart();
        if ($cart === null) {
            $respond(['ok' => false, 'code' => 'no_cart', 'message' => $this->t('A kosár nem található.')]);
        }

        $respond($this->saveSelection($cart, [
            'point' => isset($_POST['point']) ? wp_unslash((string) $_POST['point']) : '',
            'base_rev' => isset($_POST['base_rev']) ? (int) $_POST['base_rev'] : -1,
            'method_id' => isset($_POST['method_id']) ? (int) $_POST['method_id'] : 0,
        ]));
    }

    /**
     * Core of the save endpoint (kept free of request/session glue so it can be tested directly).
     *
     * @param object $cart Cart model (checkout_data array + save()); resolved from the request session.
     * @param array{point?:string,base_rev?:int,method_id?:int} $request
     * @return array<string,mixed> response payload
     */
    public function saveSelection($cart, array $request): array
    {
        $checkout = $this->checkoutData($cart);
        $storedRev = max(0, (int) ($checkout[self::REV_KEY] ?? 0));

        $rawPoint = (string) ($request['point'] ?? '');
        $selection = $rawPoint !== '' ? $this->parseSelection($rawPoint) : null;
        $baseRev = array_key_exists('base_rev', $request) ? (int) $request['base_rev'] : -1;
        $clientMethod = max(0, (int) ($request['method_id'] ?? 0));

        // Server-side shipping context from the cart; the client method id is accepted only as the
        // bootstrap value while FluentCart has not persisted the method yet.
        $methodId = (int) ($checkout['shipping_data']['shipping_method_id'] ?? 0);
        if ($clientMethod > 0 && $methodId > 0 && $clientMethod !== $methodId) {
            return [
                'ok' => false,
                'stale' => true,
                'code' => 'context_mismatch',
                'rev' => $storedRev,
                'point' => $this->storedPublicPoint($checkout),
                'method_id' => $methodId,
            ];
        }
        if ($methodId <= 0) {
            $methodId = $clientMethod;
        }
        if ($methodId <= 0) {
            return ['ok' => false, 'code' => 'context_unavailable', 'message' => $this->t('A szállítási mód még nem rögzült. Próbálja újra.')];
        }
        $context = PickupPointsModule::methodContext($methodId);

        if ($baseRev >= 0 && $baseRev !== $storedRev) {
            return [
                'ok' => false,
                'conflict' => true,
                'rev' => $storedRev,
                'point' => $this->storedPublicPoint($checkout),
                'method_id' => $methodId,
            ];
        }

        $desired = null; // null = clear the draft
        if (!empty($context['required']) && $selection !== null) {
            $result = $this->validator->validate($selection, [
                'required' => true,
                'carrier' => (string) $context['carrier'],
                'allowed_types' => (array) $context['allowed_types'],
                'directory' => $this->directory,
                'directory_state' => $this->directoryState((string) $context['carrier']),
             ] + $this->snapshotProvenance((string) $context['carrier']));
            if ($result['ok'] && is_array($result['snapshot'])
                && !$this->methodIsOfferedForPoint((array) $result['snapshot'], $methodId)) {
                // The requested native method is not an applicable offer for this point in the current
                // cart context (disabled method, other country/zone, kind mismatch): reject visibly.
                return [
                    'ok' => false,
                    'code' => 'pickup_method_not_applicable',
                    'message' => $this->t('A kiválasztott szállítási ajánlat ehhez a ponthoz nem alkalmazható ebben a kosárban.'),
                    'rev' => $storedRev,
                    'point' => $this->storedPublicPoint($checkout),
                ];
            }
            if (!$result['ok'] || !is_array($result['snapshot'])) {
                return [
                    'ok' => false,
                    'code' => (string) $result['error'],
                    'message' => PickupPointValidator::message((string) $result['error']),
                    'rev' => $storedRev,
                    'point' => $this->storedPublicPoint($checkout),
                    'method_id' => $methodId,
                ];
            }
            // One schema everywhere: the draft carries the full validated snapshot (including the 3/3
            // provenance extension) plus the legacy delivery_mode, so the order path can persist it
            // unchanged. Old drafts with the 3-key shape keep working (they are just a cache).
            $desired = $result['snapshot'];
            $desired['delivery_mode'] = (string) ($selection['delivery_mode'] ?? $result['snapshot']['type'] ?? '');
        }

        if ($desired === null) {
            unset($checkout[self::FIELD], $checkout[self::FIELD . '_error']);
        } else {
            $checkout[self::FIELD] = $desired;
            unset($checkout[self::FIELD . '_error']);
        }
        $checkout[self::REV_KEY] = $storedRev + 1;
        $checkout[self::SAVED_AT_KEY] = time();
        $this->persistCheckoutData($cart, $checkout);

        $public = $desired !== null ? $this->publicPoint($desired) : null;
        return [
            'ok' => true,
            'rev' => $storedRev + 1,
            'point' => $public,
            'method_id' => $methodId,
        ];
    }

    // ---- helpers -----------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function carrierMeta(): array
    {
        $out = [];
        // W2: every registered feed provider is exposed; MPL stays available even without a provider.
        $carriers = array_values(array_unique(array_merge(
            [PickupDirectory::MPL_CARRIER],
            array_keys(\FluentCartHungary\Modules\Shipping\PickupPoints\Providers\PickupFeedRegistry::all())
        )));
        foreach ($carriers as $carrier) {
            $entry = $this->directory->load($carrier);
            $kinds = ['point' => 0, 'locker' => 0];
            foreach ($entry['points'] as $point) {
                $kind = CarrierBinding::kindOfPoint($point);
                if (isset($kinds[$kind])) {
                    $kinds[$kind]++;
                }
            }
            $out[$carrier] = [
                'count' => count($entry['points']),
                'fresh' => PickupDirectory::isFreshAt($entry, time()),
                'age_seconds' => PickupDirectory::ageAt($entry, time()),
                'source' => $entry['source'],
                'label' => PickupPointsModule::carrierLabel($carrier),
                'version' => (string) ($entry['version'] ?? ''),
                'kinds' => $kinds,
            ];
        }
        return $out;
    }

    /** @param mixed $context @return array<string,mixed>|null */
    private function currentSelection($context): ?array
    {
        $cart = is_array($context) ? ($context['cart'] ?? null) : null;
        return $this->currentSelectionFromCart($cart);
    }

    private function currentSelectionFromCart($cart): ?array
    {
        return $this->parseSelection($this->checkoutData($cart)[self::FIELD] ?? null);
    }

    private function selectedLabel(array $selection): string
    {
        $point = $this->directory->get((string) ($selection['carrier'] ?? ''), (string) ($selection['point_id'] ?? ''));
        if ($point === null) {
            return '';
        }
        $zip = (string) ($point['zip'] ?? '');
        $city = (string) ($point['city'] ?? '');
        return trim((string) ($point['name'] ?? '') . ' — ' . trim($zip . ' ' . $city), ' —');
    }

    /** @param mixed $raw @return array<string,mixed>|null */
    public function parseSelection($raw): ?array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            return null;
        }
        $pointId = (string) ($raw['point_id'] ?? '');
        if ($pointId === '') {
            return null;
        }
        return [
            'carrier' => (string) ($raw['carrier'] ?? ''),
            'point_id' => $pointId,
            'delivery_mode' => (string) ($raw['delivery_mode'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function checkoutData($cart): array
    {
        if (is_object($cart) && isset($cart->checkout_data) && is_array($cart->checkout_data)) {
            return $cart->checkout_data;
        }
        if (is_array($cart) && is_array($cart['checkout_data'] ?? null)) {
            return $cart['checkout_data'];
        }
        return [];
    }

    /**
     * @param array<string,mixed> $changes
     * @param array<string,mixed> $allData
     * @param array<string,mixed> $checkoutData
     */
    private function selectedMethodId(array $changes, array $allData, array $checkoutData): int
    {
        foreach ([
            $changes['shipping_method_id'] ?? null,
            $allData['shipping_method_id'] ?? null,
            $allData['fc_selected_shipping_method'] ?? null,
            $checkoutData['shipping_data']['shipping_method_id'] ?? null,
        ] as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }
        return 0;
    }

    private function jsonEncode(array $value): string
    {
        if (function_exists('wp_json_encode')) {
            return (string) wp_json_encode($value);
        }
        return (string) json_encode($value);
    }

    /** Session-bound cart for the save endpoint; never selected from a client-provided hash. */
    private function loadCart()
    {
        $class = 'FluentCart\\App\\Helpers\\CartHelper';
        if (!class_exists($class) || !method_exists($class, 'getCart')) {
            return null;
        }
        try {
            $cart = $class::getCart();
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_object($cart) || !isset($cart->stage) || (string) $cart->stage === 'completed') {
            return null;
        }
        return $cart;
    }

    /** @param mixed $cart @param array<string,mixed> $checkout */
    private function persistCheckoutData($cart, array $checkout): bool
    {
        if (!is_object($cart) || !method_exists($cart, 'save')) {
            return false;
        }
        try {
            $cart->checkout_data = $checkout;
            return (bool) $cart->save();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @param array<string,mixed> $checkout @return array<string,mixed>|null */
    /**
     * Snapshot provenance for one carrier (3/3 gate #5): selector_driver is HOW the customer picked
     * (local list vs an official widget) and authoritative_source is WHERE the point really comes from —
     * two separate concepts. source_fetched_at is the directory's own fetch time (never backfilled).
     *
     * @return array<string,mixed>
     */
    private function snapshotProvenance(string $carrier): array
    {
        $carrier = strtolower(trim($carrier));
        if ($carrier === '') {
            return [];
        }
        $entry = $this->directory->load($carrier);
        $driver = PickupPointsModule::driverFor($carrier);
        return [
            'selector_driver' => (string) ($driver['driver'] ?? 'local'),
            'authoritative_source' => (string) ($entry['source'] ?? ''),
            'source_fetched_at' => (int) ($entry['fetched_at'] ?? 0),
            'directory_version' => (string) ($entry['version'] ?? ''),
            'validated_at' => time(),
            'validation_method' => 'directory_cache',
        ];
    }

    /** Lifecycle state of the carrier directory for the save/validation gate (3/3 gate #3). */
    private function directoryState(string $carrier): string
    {
        $carrier = strtolower(trim($carrier));
        if ($carrier === '') {
            return 'expired';
        }
        return PickupDirectory::stateAt($this->directory->load($carrier), time());
    }

    /**
     * Is the native method an applicable offer for this snapshot point in the current cart context?
     * Uses the same offer resolution as the search response (single source of truth).
     */
    private function methodIsOfferedForPoint(array $snapshot, int $methodId): bool
    {
        $bindings = [];
        foreach (PickupPointsModule::methodsMap() as $id => $entry) {
            if (!CarrierBinding::isPickupService((string) ($entry['service'] ?? ''))) {
                continue;
            }
            $bindings[(int) $id] = [
                'carrier' => (string) ($entry['carrier'] ?? ''),
                'service' => (string) ($entry['service'] ?? ''),
                'kinds' => (array) ($entry['types'] ?? []),
                'legacy' => false,
            ];
        }
        $eligibility = PickupOffers::applicableMethodIds();
        $offers = PickupOffers::offersForPoint(
            ['carrier' => (string) ($snapshot['carrier'] ?? ''), 'type' => (string) ($snapshot['type'] ?? '')],
            $bindings,
            $eligibility['ids'],
            $eligibility['source']
        );
        foreach ($offers as $offer) {
            if ((int) $offer['method_id'] === $methodId) {
                return true;
            }
        }
        return false;
    }

    private function storedPublicPoint(array $checkout): ?array
    {
        $selection = $this->parseSelection($checkout[self::FIELD] ?? null);
        return $selection !== null ? $this->publicPoint($selection) : null;
    }

    /** @param array<string,mixed> $selection @return array<string,mixed>|null */
    private function publicPoint(array $selection): ?array
    {
        $carrier = (string) ($selection['carrier'] ?? '');
        $pointId = (string) ($selection['point_id'] ?? '');
        if ($carrier === '' || $pointId === '') {
            return null;
        }
        $point = $this->directory->get($carrier, $pointId);
        $out = [
            'carrier' => $carrier,
            'point_id' => $pointId,
            'type' => (string) ($point['type'] ?? ($selection['delivery_mode'] ?? '')),
        ];
        foreach (['name', 'address', 'zip', 'city'] as $key) {
            if (is_array($point) && isset($point[$key])) {
                $out[$key] = (string) $point[$key];
            }
        }
        return $out;
    }

    /**
     * Remove the draft selection and bump the revision so late saves that still carry the old point
     * conflict instead of resurrecting it.
     *
     * @param array<string,mixed> $checkout
     */
    private function dropSelection(array &$checkout): void
    {
        $had = isset($checkout[self::FIELD]) || isset($checkout[self::FIELD . '_error']);
        unset($checkout[self::FIELD], $checkout[self::FIELD . '_error']);
        if ($had || isset($checkout[self::REV_KEY])) {
            $checkout[self::REV_KEY] = max(0, (int) ($checkout[self::REV_KEY] ?? 0)) + 1;
        }
    }

    private function t(string $text): string
    {
        return function_exists('__') ? (string) __($text, 'fluentcart-hungary') : $text;
    }
}
