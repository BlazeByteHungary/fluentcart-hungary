/**
 * FluentCart Hungary — pickup-point selector (vanilla; no Core file is modified).
 *
 * Wired to the REAL FluentCart checkout paths:
 *  - error display: FluentCartCheckout.js `showValidationErrors` fills
 *    `[data-fluent-cart-checkout-page-form-error][for="<input name>"]` and toasts; our block renders
 *    exactly that element (id `fch-pickup-error`, role="alert") so the server-side place-order error
 *    becomes visible (PA-DEF-01).
 *  - save: FluentCart's `save_checkout_data` whitelist cannot persist a point-only change, so the
 *    selection is saved through the module's own narrow endpoint (`fch_pickup_save`) with a
 *    compare-and-set revision (PA-DEF-02). The hidden input still rides in the form POST, so the
 *    final `validate_data` server check stays authoritative.
 *  - selection flow (checkout 2/3 goal): the list is server-paginated and filterable by carrier and
 *    point kind across every pickup-bound method. Choosing a point of another bound carrier activates
 *    that carrier's NATIVE FluentCart shipping method (radio click) and then saves the point in the
 *    new context — the server returns the routable method id per row, so the client never guesses.
 *  - home carrier selection: the plugin never invents shipping methods; the native FluentCart method
 *    rows are grouped/labelled with their bound carrier + service, and an unbound legacy method is
 *    marked as a configuration gap.
 */
(function () {
    'use strict';

    if (window.fchPickupInitialized) { return; }
    window.fchPickupInitialized = true;

    var CFG = window.fch_pickup || {};
    var I18N = CFG.i18n || {};
    var PAGE_SIZE = Number(CFG.pageSize || 20) || 20;

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function parseJson(value) {
        try { var v = JSON.parse(value); return v && typeof v === 'object' ? v : null; }
        catch (e) { return null; }
    }

    function t(key, fallback) { return I18N[key] || fallback; }

    ready(function () {
        var root = document.querySelector('[data-fch-pickup-root]');
        if (!root) { return; }

        var input = root.querySelector('[data-fch-pickup-input]');
        var openBtn = root.querySelector('[data-fch-pickup-open]');
        var status = root.querySelector('[data-fch-pickup-status]');
        var errorEl = root.querySelector('[data-fch-pickup-error]');
        var modal = root.querySelector('[data-fch-pickup-modal]');
        var list = root.querySelector('[data-fch-pickup-list]');
        var state = root.querySelector('[data-fch-pickup-state]');
        var q = root.querySelector('[data-fch-pickup-q]');
        var retryBtn = root.querySelector('[data-fch-pickup-retry]');
        var closeBtn = root.querySelector('[data-fch-pickup-close]');
        var fallbackBtn = root.querySelector('[data-fch-pickup-fallback]');
        var saveUrl = (CFG.ajaxUrl || '') + '?action=' + encodeURIComponent(CFG.saveAction || 'fch_pickup_save');

        var state_ = {
            token: 0,
            controller: null,
            activeIndex: -1,
            lastFocus: null,
            searchTimer: null,
            query: '',
            methodId: '',
            carrier: '',
            types: [],
            required: false,
            rev: Number(CFG.rev || 0) || 0,
            hasStored: !!(input.value),
            serverPoint: input.value || '',
            statusNote: '',
            // server-paginated list state
            page: 0,
            pages: 1,
            total: 0,
            filterCarrier: '',
            filterKinds: [],
            dirVersion: '',
            driverGen: 0,
            activeDriver: null,
            rows: [],
            switchTarget: '',
            switchUntil: 0,
            // single-flight save queue: the latest user intent always wins
            saving: false,
            pending: null
        };

        function methodsMap() { return CFG.methods || {}; }
        function carriersMeta() { return CFG.carriers || {}; }
        function kindLabels() { return CFG.kindLabels || {}; }
        function serviceLabels() { return CFG.serviceLabels || {}; }

        function selectedRadio() {
            return document.querySelector('input[name="fc_shipping_method"]:checked')
                || document.querySelector('input[name="shipping_method_id"]');
        }

        function selectedMethodId() {
            var checked = selectedRadio();
            return checked ? String(checked.value) : '';
        }

        function contextFor(methodId) {
            var cfg = methodsMap()[String(methodId)];
            if (!cfg || !cfg.required) { return { methodId: String(methodId || ''), required: false, carrier: '', types: [] }; }
            return {
                methodId: String(methodId),
                required: true,
                carrier: cfg.carrier || '',
                types: Array.isArray(cfg.types) ? cfg.types : []
            };
        }

        function currentContext() { return contextFor(selectedMethodId()); }

        function carrierLabel(slug) {
            var meta = carriersMeta()[slug];
            if (meta && meta.label) { return meta.label; }
            var methods = methodsMap();
            for (var id in methods) {
                if (methods.hasOwnProperty(id) && methods[id].carrier === slug && methods[id].label) { return methods[id].label; }
            }
            return String(slug || '').toUpperCase();
        }

        function eligiblePickupCarriers() {
            var methods = methodsMap();
            var out = [];
            for (var id in methods) {
                if (!methods.hasOwnProperty(id)) { continue; }
                var cfg = methods[id];
                if (cfg && cfg.required && cfg.carrier && out.indexOf(cfg.carrier) === -1) { out.push(cfg.carrier); }
            }
            return out;
        }

        // ---- status / error ------------------------------------------------------------------------

        function setStatus(text) {
            if (status) { status.textContent = text || ''; }
        }

        function showError(message) {
            if (!errorEl) { return; }
            errorEl.textContent = message || I18N.saveError || '';
            errorEl.classList.add('show_error');
            if (openBtn) { openBtn.setAttribute('aria-invalid', 'true'); }
        }

        function clearError() {
            if (!errorEl) { return; }
            errorEl.textContent = '';
            errorEl.classList.remove('show_error');
            if (openBtn) { openBtn.removeAttribute('aria-invalid'); }
        }

        // A native FC error (place order) is written into our error element; keep the trigger visible
        // and marked invalid so the customer can reopen the selector.
        if (errorEl && window.MutationObserver) {
            new MutationObserver(function () {
                if (String(errorEl.textContent || '').trim() !== '') {
                    errorEl.classList.add('show_error');
                    if (openBtn) {
                        openBtn.hidden = !currentContext().required ? openBtn.hidden : false;
                        openBtn.setAttribute('aria-invalid', 'true');
                    }
                }
            }).observe(errorEl, { childList: true, characterData: true, subtree: true });
        }

        // FluentCart renders place-order errors per known checkout field only (its internal field map)
        // and shows the rest in a 1.5s toast. Our pickup error must stay attached to the pickup block
        // (role=alert), so a NARROW response observer mirrors `errors._fch_pickup_point` into our error
        // element. FC's own handling is untouched (the response is cloned; the original is returned).
        if (!window.__fchPickupFetchBridge && typeof window.fetch === 'function') {
            window.__fchPickupFetchBridge = true;
            var nativeFetch = window.fetch.bind(window);
            window.fetch = function (input, init) {
                var url = typeof input === 'string' ? input : ((input && input.url) || '');
                var promise = nativeFetch(input, init);
                if (url.indexOf('fluent_cart_place_order') !== -1) {
                    promise.then(function (response) {
                        try {
                            response.clone().json().then(function (data) {
                                if (!data || typeof data !== 'object') { return; }
                                if (data.errors && data.errors._fch_pickup_point) {
                                    var parts = data.errors._fch_pickup_point;
                                    var msg = parts.required || parts.invalid || Object.values(parts)[0] || '';
                                    if (msg) { showError(String(msg)); setStatus(String(msg)); }
                                } else if (data.status === 'success') {
                                    clearError();
                                }
                            }).catch(function () { /* body unreadable: ignore */ });
                        } catch (e) { /* clone unsupported: ignore */ }
                    }).catch(function () { /* network error: FC shows its own message */ });
                }
                return promise;
            };
        }

        function setState(kind, html) {
            if (!state) { return; }
            state.setAttribute('aria-busy', kind === 'loading' ? 'true' : 'false');
            state.setAttribute('data-state', kind);
            state.innerHTML = html || '';
            if (retryBtn) { retryBtn.hidden = kind !== 'error'; }
        }

        // ---- modal ---------------------------------------------------------------------------------

        function focusable() {
            return Array.prototype.slice.call(modal.querySelectorAll('button, input, [href], select, textarea, [tabindex]:not([tabindex="-1"])'))
                .filter(function (el) { return !el.hidden && !el.disabled && el.offsetParent !== null; });
        }

        function openModal() {
            var ctx = currentContext();
            if (!ctx.required) { return; }
            state_.lastFocus = document.activeElement;
            modal.hidden = false;
            openBtn.setAttribute('aria-expanded', 'true');
            state_.statusNote = '';
            clearError();
            // Fresh modal session: filter defaults follow the method that opened the selector.
            state_.filterCarrier = ctx.carrier || '';
            state_.filterKinds = ctx.types.length === 1 ? ctx.types.slice() : [];
            state_.page = 0;
            state_.rows = [];
            buildFilters();
            if (q) {
                q.value = state_.query || '';
                q.focus();
            }
            loadPoints(state_.query || '', false);
        }

        function closeModal() {
            if (!modal || modal.hidden) { return; }
            teardownDriver();
            modal.hidden = true;
            openBtn.setAttribute('aria-expanded', 'false');
            if (state_.lastFocus && typeof state_.lastFocus.focus === 'function') { state_.lastFocus.focus(); }
        }

        function updateFallbackUi() {
            if (!fallbackBtn) { return; }
            var ctx = currentContext();
            var cfg = ctx.required ? methodsMap()[ctx.methodId] : null;
            var target = cfg && cfg.fallback_method ? String(cfg.fallback_method) : '';
            var radio = target ? document.querySelector('input[name="fc_shipping_method"][value="' + target + '"]') : null;
            fallbackBtn.textContent = (radio ? t('fallback', 'Házhozszállítás választása') : t('fallbackList', 'Szállítási módok mutatása'));
        }

        // ---- filters (carrier + kind, server-side) -------------------------------------------------

        function buildFilters() {
            var host = root.querySelector('[data-fch-pickup-filters]');
            if (!host) { return; }
            var carriers = eligiblePickupCarriers();
            var html = '<label class="fch-pickup__filter"><span>' + esc(t('filterCarrier', 'Fuvarozó')) + '</span>'
                + '<select data-fch-pickup-carrier-filter><option value="">' + esc(t('filterAll', 'Összes')) + '</option>';
            carriers.forEach(function (slug) {
                var count = carriersMeta()[slug] && carriersMeta()[slug].count ? ' (' + carriersMeta()[slug].count + ')' : '';
                html += '<option value="' + esc(slug) + '"' + (slug === state_.filterCarrier ? ' selected' : '') + '>' + esc(carrierLabel(slug)) + count + '</option>';
            });
            html += '</select></label>';
            html += '<label class="fch-pickup__filter"><span>' + esc(t('filterKind', 'Típus')) + '</span>'
                + '<select data-fch-pickup-kind-filter><option value="">' + esc(t('filterAll', 'Összes')) + '</option>'
                + '<option value="point"' + (state_.filterKinds.length === 1 && state_.filterKinds[0] === 'point' ? ' selected' : '') + '>' + esc(kindLabels().point || 'Csomagpont') + '</option>'
                + '<option value="locker"' + (state_.filterKinds.length === 1 && state_.filterKinds[0] === 'locker' ? ' selected' : '') + '>' + esc(kindLabels().locker || 'Automata') + '</option>'
                + '</select></label>';
            html += '<button type="button" class="fch-pickup__clearfilters" data-fch-pickup-clear>' + esc(t('clearFilters', 'Szűrők törlése')) + '</button>';
            var driverSlug = state_.filterCarrier || currentContext().carrier || '';
            var driver = driverSlug ? (CFG.drivers || {})[driverSlug] : null;
            if (driver && driver.enabled && driver.driver && driver.driver !== 'local') {
                html += '<button type="button" class="fch-pickup__widget" data-fch-pickup-widget>'
                    + esc(t('widgetOpen', 'Választás a térképes widgetben')) + ' — ' + esc(carrierLabel(driverSlug)) + '</button>';
            }
            host.innerHTML = html;

            var carrierSel = host.querySelector('[data-fch-pickup-carrier-filter]');
            var kindSel = host.querySelector('[data-fch-pickup-kind-filter]');
            var clearBtn = host.querySelector('[data-fch-pickup-clear]');
            if (carrierSel) {
                carrierSel.addEventListener('change', function () {
                    state_.filterCarrier = String(carrierSel.value || '');
                    loadPoints(state_.query || '', false);
                });
            }
            if (kindSel) {
                kindSel.addEventListener('change', function () {
                    state_.filterKinds = kindSel.value ? [String(kindSel.value)] : [];
                    loadPoints(state_.query || '', false);
                });
            }
            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    state_.filterCarrier = '';
                    state_.filterKinds = [];
                    state_.query = '';
                    var search = root.querySelector('[data-fch-pickup-q]');
                    if (search) { search.value = ''; }
                    buildFilters();
                    loadPoints('', false);
                });
            }
            var widgetBtn = host.querySelector('[data-fch-pickup-widget]');
            if (widgetBtn) {
                widgetBtn.addEventListener('click', function () {
                    activateWidget(String((CFG.drivers || {})[state_.filterCarrier] ? state_.filterCarrier : (currentContext().carrier || '')));
                });
            }
        }

        // ---- search with request/context tokens ----------------------------------------------------

        function staleNote(data) {
            // Directory-health note shared by the result summary and the empty state: an expired
            // directory yields zero points, and the shopper must see why it is empty (and that a
            // refresh/retry or a method switch is the way forward) instead of a silent empty list.
            var dirs = (data && data.dirs) || {};
            for (var slug in dirs) {
                if (!dirs.hasOwnProperty(slug) || !dirs[slug]) { continue; }
                if (dirs[slug].state === 'expired') {
                    return ' <span class="fch-pickup__stale">' + esc(t('expired', 'A pontlista elavult (a frissítés nem sikerült); ez a lista nem használható választáshoz.')) + '</span>';
                }
                if (dirs[slug].fresh === false && Number(dirs[slug].count || 0) > 0) {
                    return ' <span class="fch-pickup__stale">' + esc(t('stale', 'A pontlista régi.')) + '</span>';
                }
            }
            return '';
        }

        function summaryHtml(data) {
            var total = Number(data.total || 0);
            var page = Number(data.page || 1);
            var pages = Number(data.pages || 1);
            return esc(total + ' ' + t('results', 'találat')) + ' · ' + esc(page + '. / ' + pages + '. ' + t('pageInfo', 'oldal')) + staleNote(data);
        }

        function renderRows(rows, append) {
            if (!list) { return; }
            if (!append) {
                list.innerHTML = '';
                state_.rows = [];
                state_.activeIndex = -1;
                list.setAttribute('aria-activedescendant', '');
            }
            var current = parseJson(input.value);
            rows.forEach(function (p) {
                var i = state_.rows.length;
                state_.rows.push(p);
                var li = document.createElement('li');
                li.className = 'fch-pickup__item';
                li.id = 'fch-pickup-opt-' + i;
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', 'false');
                li.tabIndex = -1;
                li.dataset.index = String(i);
                li.dataset.carrier = String(p.carrier || '');
                li.dataset.pointId = String(p.point_id || '');
                li.dataset.methodId = String(p.method_id || '');
                if (current && String(current.point_id) === String(p.point_id) && String(current.carrier) === String(p.carrier || '')) {
                    li.classList.add('is-current');
                    li.setAttribute('aria-selected', 'true');
                    state_.activeIndex = i;
                }
                var badge = esc(carrierLabel(p.carrier)) + (p.kind ? ' · ' + esc(kindLabels()[p.kind] || p.kind) : '');
                li.innerHTML = '<span class="fch-pickup__name">' + esc(p.name) + '</span>'
                    + '<span class="fch-pickup__badge" data-kind="' + esc(p.kind || '') + '">' + badge + '</span>'
                    + '<span class="fch-pickup__meta">' + esc([p.zip, p.city].filter(Boolean).join(' ')) + '</span>'
                    + '<span class="fch-pickup__addr">' + esc(p.address) + '</span>'
                    + '<button type="button" class="fch-pickup__choose" data-fch-pickup-choose>' + esc(t('choose', 'Kiválasztás')) + '</button>';
                li.addEventListener('click', function () { selectPoint(p, li); });
                list.appendChild(li);
            });
            if (state_.activeIndex >= 0) {
                var active = list.querySelectorAll('.fch-pickup__item')[state_.activeIndex];
                if (active) {
                    list.setAttribute('aria-activedescendant', active.id);
                    active.scrollIntoView({ block: 'nearest' });
                }
            }
        }

        function renderListFooter(data, query) {
            var host = root.querySelector('[data-fch-pickup-more]');
            if (!host) { return; }
            var hasMore = Number(data.page || 1) < Number(data.pages || 1);
            if (!hasMore) { host.innerHTML = ''; return; }
            host.innerHTML = '<button type="button" class="fch-pickup__loadmore" data-fch-pickup-loadmore>'
                + esc(t('loadMore', 'További találatok')) + '</button>';
            var btn = host.querySelector('[data-fch-pickup-loadmore]');
            if (btn) { btn.addEventListener('click', function () { loadPoints(query || '', true); }); }
        }

        function loadPoints(query, append) {
            if (!list) { return; }
            var ctx = currentContext();
            if (!ctx.required) { return; }
            state_.query = query || '';

            // Only the newest request for the current context may render: bump the token, clear the
            // previous list so a stale item cannot be chosen while loading, and abort the old request.
            state_.token += 1;
            var token = state_.token;
            if (state_.controller && typeof state_.controller.abort === 'function') {
                try { state_.controller.abort(); } catch (e) { /* ignore */ }
            }
            state_.controller = typeof AbortController === 'function' ? new AbortController() : null;
            if (!append) {
                list.innerHTML = '';
                state_.activeIndex = -1;
                list.setAttribute('aria-activedescendant', '');
                setState('loading', '<span class="fch-pickup__skeleton" aria-hidden="true"></span>');
            }
            var page = append ? Math.max(1, state_.page + 1) : 1;

            var url = (CFG.ajaxUrl || '') + '?action=' + encodeURIComponent(CFG.searchAction || 'fch_pickup_search')
                + '&carrier=' + encodeURIComponent(state_.filterCarrier || '')
                + '&kinds=' + encodeURIComponent((state_.filterKinds || []).join(','))
                + '&method_id=' + encodeURIComponent(ctx.methodId || '')
                + '&page=' + encodeURIComponent(String(page))
                + '&per_page=' + encodeURIComponent(String(PAGE_SIZE))
                + '&dir_version=' + encodeURIComponent(state_.dirVersion || '')
                + '&q=' + encodeURIComponent(query || '')
                + '&nonce=' + encodeURIComponent(CFG.nonce || '');

            fetch(url, { credentials: 'include', signal: state_.controller ? state_.controller.signal : undefined })
                .then(function (r) { if (!r.ok) { throw new Error('http_' + r.status); } return r.json(); })
                .then(function (data) {
                    if (token !== state_.token) { return; }                    // a newer search won
                    if (modal.hidden) { return; }                              // modal closed meanwhile
                    if (currentContext().methodId !== ctx.methodId) { return; } // method changed meanwhile
                    if (!data || !data.ok) { throw new Error('bad_payload'); }
                    if (data.directory_version) { state_.dirVersion = String(data.directory_version); }
                    if (data.version_reset) {
                        // The directory generation changed while paging: never mix old and new rows —
                        // restart the list from page 1 of the fresh generation.
                        append = false;
                    }
                    state_.page = Number(data.page || 1);
                    state_.pages = Number(data.pages || 1);
                    state_.total = Number(data.total || 0);
                    renderRows((data.points || []), !!append);
                    if (state_.rows.length === 0) {
                        setState('empty', esc(t('empty', 'Nincs találat a megadott városra/irányítószámra. Módosítsa a keresést.')) + staleNote(data));
                    } else {
                        setState('ready', summaryHtml(data));
                    }
                    renderListFooter(data, query);
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') { return; }
                    if (token !== state_.token) { return; }
                    setState('error', '<p>' + esc(t('error', 'A csomagpontok betöltése nem sikerült.')) + '</p>');
                });
        }

        // ---- selection save (single-flight + server revision CAS) ----------------------------------

        function saveNow(point, methodIdOverride) {
            var ctx = currentContext();
            var payload = point ? JSON.stringify({ carrier: point.carrier, point_id: point.point_id, delivery_mode: point.delivery_mode || '' }) : '';
            var body = new URLSearchParams();
            body.append('point', payload);
            body.append('base_rev', String(state_.rev));
            body.append('method_id', String(methodIdOverride || selectedMethodId() || ctx.methodId || ''));
            body.append('nonce', CFG.nonce || '');

            return fetch(saveUrl, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) { return r.json(); }).then(function (resp) {
                if (!resp || typeof resp !== 'object') { throw new Error('bad_save_payload'); }
                if (resp.rev != null) { state_.rev = Number(resp.rev) || state_.rev; }

                if (resp.ok) {
                    state_.switchTarget = '';
                    applyServerPoint(resp.point, true);
                    clearError();
                    return { done: true };
                }
                if (resp.conflict) {
                    // Another tab/intent wrote first: adopt the server state, then let the queue re-send
                    // THIS tab's latest intent on top of the fresh revision.
                    applyServerPoint(resp.point, true);
                    return { done: false };
                }
                if (resp.stale && resp.code === 'context_mismatch') {
                    // Carrier switch in flight: FluentCart has not persisted the new method yet. The
                    // client keeps its intent and retries until the server-side context catches up.
                    if (state_.switchTarget && Date.now() < state_.switchUntil) {
                        return { done: false, retry: true, delay: 350 };
                    }
                    applyServerPoint(resp.point, true);
                    return { done: true };
                }
                if (resp.stale) {
                    applyServerPoint(resp.point, true);
                    return { done: true };
                }
                if (resp.code === 'context_unavailable') {
                    return { done: false, retry: true, message: resp.message };
                }
                showError(resp.message || I18N.saveError);
                setStatus(resp.message || I18N.saveError);
                return { done: true };
            }).catch(function () {
                showError(I18N.saveError);
                setStatus(I18N.saveError);
                return { done: true };
            });
        }

        /** A failed carrier switch must never leave a point that the server did not accept. */
        function revertToServerState() {
            input.value = state_.serverPoint || '';
            state_.hasStored = !!state_.serverPoint;
            state_.switchTarget = '';
            showError(I18N.switchFailed || 'A szállítási mód váltása nem sikerült ehhez a ponthoz.');
            setStatus(I18N.switchFailed || '');
        }

        function queueSave(point, methodIdOverride) {
            state_.pending = { point: point, methodId: methodIdOverride || '' };
            if (state_.saving) { return; }
            pumpSaves();
        }

        function pumpSaves() {
            if (state_.saving || !state_.pending) { return; }
            var intent = state_.pending;
            state_.pending = null;
            state_.saving = true;
            var attempt = 0;
            var run = function () {
                saveNow(intent.point, intent.methodId).then(function (result) {
                    attempt += 1;
                    var limit = state_.switchTarget ? 10 : 3;
                    if (!result.done && attempt <= limit) {
                        if (result.delay) {
                            setTimeout(run, result.delay);
                        } else {
                            run();
                        }
                        return;
                    }
                    if (!result.done) {
                        // Retries exhausted while switching: consistent state, visible error, no
                        // optimistic "saved" point remains in the form.
                        if (state_.switchTarget) { revertToServerState(); }
                        else if (result.message) { showError(result.message); }
                    } else if (result.message) { showError(result.message); }
                    state_.saving = false;
                    pumpSaves();
                });
            };
            run();
        }

        /** Reflect the authoritative server state in the DOM (never fabricates a saved state). */
        function applyServerPoint(point, fromServer) {
            if (point && point.point_id) {
                input.value = JSON.stringify({ carrier: point.carrier, point_id: point.point_id, delivery_mode: point.type || '' });
                state_.serverPoint = input.value;
                state_.hasStored = true;
                var label = [point.name, [point.zip, point.city].filter(Boolean).join(' ')].filter(Boolean).join(' — ');
                setStatus(label || t('saved', 'Kiválasztva'));
            } else if (fromServer) {
                input.value = '';
                state_.serverPoint = '';
                state_.hasStored = false;
                setStatus(state_.statusNote || '');
            }
        }

        /** Activate a native FluentCart shipping method without touching FC internals (radio click). */
        function activateMethod(methodId) {
            var radio = document.querySelector('input[name="fc_shipping_method"][value="' + methodId + '"]');
            if (!radio) { return false; }
            state_.switchTarget = String(methodId);
            state_.switchUntil = Date.now() + 8000;
            if (!radio.checked) {
                radio.click();
            }
            return true;
        }

        /**
         * Several applicable offers and no current one: show the priced offer list (server-computed
         * prices, never client math) and let the customer choose explicitly.
         */
        function renderOffers(point) {
            if (!list) { return; }
            var offers = Array.isArray(point.offers) ? point.offers : [];
            if (offers.length === 0) {
                setState('empty', esc(t('noOffers', 'Ehhez a ponthoz nincs alkalmazható szállítási ajánlat.')));
                return;
            }
            var html = '<p class="fch-pickup__offertitle">' + esc(t('offerTitle', 'Válassza ki az alkalmazandó szállítási ajánlatot')) + '</p>';
            html += '<ul class="fch-pickup__offerlist">';
            offers.forEach(function (offer, i) {
                var price = offer.price_display || ('<span class="fch-pickup__offerunknown">' + esc(t('offerPriceUnknown', 'A díjat a kosár számolja')) + '</span>');
                html += '<li><button type="button" class="fch-pickup__offer" data-offer-index="' + i + '">'
                    + '<span class="fch-pickup__offername">' + esc(offer.title || ('#' + offer.method_id)) + '</span>'
                    + '<span class="fch-pickup__offerprice">' + price + '</span></button></li>';
            });
            html += '</ul>';
            html += '<div class="fch-pickup__actions"><button type="button" class="fch-pickup__back" data-fch-pickup-back>' + esc(t('offerBack', 'Vissza a listához')) + '</button></div>';
            list.innerHTML = html;
            list.querySelectorAll('[data-offer-index]').forEach(function (btn2) {
                btn2.addEventListener('click', function () {
                    var offer = offers[parseInt(btn2.dataset.offerIndex, 10)];
                    if (offer) { selectPoint(point, null, String(offer.method_id)); }
                });
            });
            var back = list.querySelector('[data-fch-pickup-back]');
            if (back) { back.addEventListener('click', function () { loadPoints(state_.query || '', false); }); }
            setState('ready', esc((point.name || '') + ' — ' + (kindLabels()[point.kind] || '')));
        }

        function selectPoint(point, itemEl, chosenMethodId) {
            var ctx = currentContext();
            if (!ctx.required) { return; }
            if (itemEl && String(itemEl.dataset.carrier || '') !== String(point.carrier || '')) {
                showError(I18N.saveError);
                return;
            }
            var offers = Array.isArray(point.offers) ? point.offers : [];
            var targetMethod = String(chosenMethodId || point.method_id || '');
            if (!targetMethod && offers.length > 1) {
                // Explicit choice required (server returned method_id=0: several applicable offers).
                renderOffers(point);
                return;
            }
            if (!targetMethod && offers.length === 1) {
                targetMethod = String(offers[0].method_id || '');
            }
            if (offers.length > 0) {
                var known = false;
                offers.forEach(function (offer) { if (String(offer.method_id) === targetMethod) { known = true; } });
                if (!known) {
                    showError(I18N.saveError);
                    return;
                }
            }
            var selection = { carrier: point.carrier || ctx.carrier, point_id: point.point_id, delivery_mode: point.type || '' };
            state_.statusNote = '';
            input.value = JSON.stringify(selection);
            var label = [point.name, [point.zip, point.city].filter(Boolean).join(' ')].filter(Boolean).join(' — ');
            setStatus(label);
            clearError();
            closeModal();

            var currentMethod = selectedMethodId();
            if (targetMethod && targetMethod !== currentMethod) {
                if (activateMethod(targetMethod)) {
                    setStatus(label + ' — ' + t('switching', 'Szállítási mód váltása…'));
                } else {
                    revertToServerState();
                    return;
                }
            } else {
                state_.switchTarget = '';
            }
            queueSave({ carrier: selection.carrier, point_id: selection.point_id, delivery_mode: selection.delivery_mode, name: point.name, zip: point.zip, city: point.city }, targetMethod);
        }

        // ---- selector drivers (official widgets; same resolve/save path) ---------------------------

        function teardownDriver() {
            state_.driverGen += 1;
            if (state_.activeDriver && typeof state_.activeDriver.cleanup === 'function') {
                try { state_.activeDriver.cleanup(); } catch (e) { /* cosmetic */ }
            }
            state_.activeDriver = null;
            var host = modal.querySelector('[data-fch-pickup-widget-host]');
            if (host) { host.innerHTML = ''; host.hidden = true; }
        }

        function widgetHost() {
            var host = modal.querySelector('[data-fch-pickup-widget-host]');
            if (!host) {
                host = document.createElement('div');
                host.setAttribute('data-fch-pickup-widget-host', '1');
                host.className = 'fch-pickup__widgethost';
                host.hidden = true;
                var actions = modal.querySelector('.fch-pickup__actions');
                if (actions && actions.parentNode) {
                    actions.parentNode.insertBefore(host, actions);
                } else {
                    modal.appendChild(host);
                }
            }
            return host;
        }

        /** Resolve a widget point id through the server (never trust the callback payload). */
        function resolveWidgetId(carrier, widgetId, gen) {
            var url = (CFG.ajaxUrl || '') + '?action=' + encodeURIComponent(CFG.resolveAction || 'fch_pickup_resolve')
                + '&carrier=' + encodeURIComponent(carrier)
                + '&widget_id=' + encodeURIComponent(widgetId)
                + '&nonce=' + encodeURIComponent(CFG.nonce || '');
            return fetch(url, { credentials: 'include' }).then(function (r) { return r.json(); }).catch(function () { return null; });
        }

        function handleWidgetResult(data, gen) {
            if (gen !== state_.driverGen) { return; }        // a closed/replaced widget context never writes
            if (modal.hidden) { return; }
            teardownDriver();
            if (!data || !data.ok) {
                showError((data && data.message) || I18N.widgetError || '');
                return;
            }
            if (!data.found) {
                showError((data && data.message) || I18N.widgetNotFound || '');
                return;
            }
            clearError();
            setStatus((I18N.widgetResultLoaded || '') + ' ' + (data.point && data.point.name ? data.point.name : ''));
            selectPoint(data.point, null, '');
        }

        function activateWidget(carrier) {
            var driver = (CFG.drivers || {})[carrier];
            if (!driver || !driver.enabled) { return; }
            teardownDriver();
            var gen = state_.driverGen;
            var host = widgetHost();
            host.hidden = false;
            host.innerHTML = '<p class="fch-pickup__widgetloading">' + esc(t('widgetLoading', 'Widget betöltése…')) + '</p>'
                + '<button type="button" class="fch-pickup__widgetclose" data-fch-pickup-widget-close>' + esc(t('widgetClose', 'Widget bezárása')) + '</button>';

            var cleanup = function () { /* per-driver teardown hook */ };
            state_.activeDriver = { carrier: carrier, cleanup: cleanup };

            var closeBtn = host.querySelector('[data-fch-pickup-widget-close]');
            if (closeBtn) { closeBtn.addEventListener('click', function () { teardownDriver(); }); }

            if (driver.driver === 'foxpost_widget') {
                var iframe = document.createElement('iframe');
                iframe.className = 'fch-pickup__widgetframe';
                iframe.setAttribute('title', 'Foxpost');
                iframe.setAttribute('loading', 'lazy');
                iframe.src = driver.iframe;
                host.insertBefore(iframe, host.firstChild);
                var onMessage = function (event) {
                    // Both the documented origin AND the active iframe's contentWindow are verified (the
                    // official sample's origin check is commented out — never copied as-is).
                    if (event.origin !== driver.origin) { return; }
                    if (!iframe.contentWindow || event.source !== iframe.contentWindow) { return; }
                    var payload = event.data;
                    if (typeof payload === 'string') {
                        try { payload = JSON.parse(payload); } catch (e) { return; }
                    }
                    if (!payload || typeof payload !== 'object') { return; }
                    var widgetId = String(payload.operator_id || payload.place_id || '');
                    if (widgetId === '') { return; }
                    resolveWidgetId(carrier, widgetId, gen).then(function (data) { handleWidgetResult(data, gen); });
                };
                window.addEventListener('message', onMessage, false);
                cleanup = function () { window.removeEventListener('message', onMessage, false); };
                state_.activeDriver.cleanup = cleanup;
                return;
            }

            if (driver.driver === 'packeta_widget') {
                var scriptId = 'fch-packeta-widget-lib';
                var start = function () {
                    if (!window.Packeta || !window.Packeta.Widget || typeof window.Packeta.Widget.pick !== 'function') {
                        showError(I18N.widgetError || ''); return;
                    }
                    try {
                        window.Packeta.Widget.pick(driver.api_key, function (point) {
                            if (gen !== state_.driverGen || modal.hidden) { return; }
                            if (!point || !point.id) { teardownDriver(); return; } // cancel/null: keep previous
                            resolveWidgetId(carrier, String(point.id), gen).then(function (data) { handleWidgetResult(data, gen); });
                        }, { language: 'hu', country: 'hu' });
                    } catch (e) {
                        showError(I18N.widgetError || '');
                    }
                };
                if (document.getElementById(scriptId)) { start(); return; }
                var script = document.createElement('script');
                script.id = scriptId;
                script.async = true;
                script.src = driver.widget_url;
                script.onload = start;
                script.onerror = function () { showError(I18N.widgetError || ''); };
                document.head.appendChild(script);
                return;
            }
            if (driver.driver === 'gls_widget') {
                // Documented custom element inside an isolated same-origin iframe: the <base href> lives
                // in the iframe document only (never on the storefront), and the change event is relayed
                // to this page where the source iframe is verified before any resolution.
                var frame = document.createElement('iframe');
                frame.className = 'fch-pickup__widgetframe';
                frame.setAttribute('title', 'GLS');
                host.insertBefore(frame, host.firstChild);
                var doc = frame.contentDocument;
                try {
                    doc.open();
                    doc.write('<!DOCTYPE html><html><head><base href="' + esc(driver.base || '') + '"></head><body style="margin:0">'
                        + '<div style="height:100%"><gls-dpm country="' + esc(driver.country || 'hu') + '" language="' + esc(driver.language || 'hu') + '" style="display:block;height:100%"></gls-dpm></div>'
                        + '<script type="module">'
                        + 'var el=document.querySelector("gls-dpm");'
                        + 'el.addEventListener("change",function(e){try{parent.postMessage({fchGlS:true,id:e.detail&&e.detail.id,name:e.detail&&e.detail.name},""+location.origin);}catch(x){}});'
                        + 'var s=document.createElement("script");s.type="module";s.src="' + esc(driver.asset || '') + '";document.head.appendChild(s);'
                        + '</scr' + 'ipt></body></html>');
                    doc.close();
                } catch (e) {
                    showError(I18N.widgetError || '');
                    return;
                }
                var onGlSMessage = function (event) {
                    if (!frame.contentWindow || event.source !== frame.contentWindow) { return; }
                    if (event.origin !== window.location.origin) { return; }
                    var payload = event.data;
                    if (!payload || !payload.fchGlS) { return; }
                    var widgetId = String(payload.id || '');
                    if (widgetId === '') { return; }
                    resolveWidgetId('gls', widgetId, gen).then(function (data) { handleWidgetResult(data, gen); });
                };
                window.addEventListener('message', onGlSMessage, false);
                cleanup = function () { window.removeEventListener('message', onGlSMessage, false); };
                state_.activeDriver.cleanup = cleanup;
                return;
            }
            // Unknown driver: keep the list, never load anything.
            showError(I18N.widgetError || '');
        }

        // ---- fallback (honest home-delivery behaviour, PA-DEF-05) ----------------------------------

        function fallbackAction() {
            var ctx = currentContext();
            var cfg = ctx.required ? methodsMap()[ctx.methodId] : null;
            var target = cfg && cfg.fallback_method ? String(cfg.fallback_method) : '';
            var radio = target ? document.querySelector('input[name="fc_shipping_method"][value="' + target + '"]') : null;

            closeModal();
            queueSave(null); // clearing the draft is idempotent and also happens server-side on the method change

            if (radio) {
                state_.statusNote = '';
                radio.click(); // native FluentCart path: recalculates fees and saves the method
                return;
            }
            state_.statusNote = I18N.fallbackInfo || 'Válasszon szállítási módot a listából.';
            setStatus(state_.statusNote);
            var wrap = document.querySelector('[data-fluent-cart-checkout-page-shipping-methods-wrapper]')
                || document.querySelector('[data-fluent-cart-checkout-page-form-section]');
            if (wrap) {
                wrap.scrollIntoView({ block: 'center' });
                var first = wrap.querySelector('input[name="fc_shipping_method"]');
                if (first && typeof first.focus === 'function') { first.focus(); }
            }
        }

        // ---- home carrier grouping / labels (native FC method rows only) ---------------------------

        var METHODS_WRAPPER = '[data-fluent-cart-checkout-page-shipping-methods-wrapper]';
        var GROUP_LABEL = {
            home: t('groupHome', 'Házhozszállítás'),
            pickup: t('groupPickup', 'Csomagpont'),
            locker: t('groupLocker', 'Automata'),
            none: t('groupUnbound', 'Nincs futár hozzárendelve')
        };

        /**
         * The row for one radio = the lowest ancestor whose PARENT already contains more than one
         * shipping-method radio (FC wraps the radios in one radiogroup). If no such wrapper exists
         * (a flat list), the direct child of the methods wrapper is used; if neither, skip.
         */
        function methodRow(container, radio) {
            var node = radio;
            while (node && node.parentNode && node.parentNode !== container) {
                var parent = node.parentNode;
                var siblings = parent.querySelectorAll ? parent.querySelectorAll('input[name="fc_shipping_method"]').length : 0;
                if (siblings > 1 && node.querySelectorAll('input[name="fc_shipping_method"]').length === 1) {
                    return node;
                }
                node = parent;
            }
            if (node && node.parentNode === container) { return node; }
            return null;
        }

        function placeWithinShippingBlock() {
            var container = document.querySelector(METHODS_WRAPPER);
            if (!container || !container.parentNode) { return; }
            // The pickup selector belongs to the shipping block: keep it directly after the native
            // method list (idempotent; runs after every FC fragment re-render).
            if (root.parentNode !== container.parentNode || root.previousElementSibling !== container) {
                container.parentNode.insertBefore(root, container.nextSibling);
            }
        }

        function applyMethodBranding() {
            var container = document.querySelector(METHODS_WRAPPER);
            if (!container) { return; }
            try {
                placeWithinShippingBlock();
                var oldHeaders = container.querySelectorAll('[data-fch-group]');
                Array.prototype.forEach.call(oldHeaders, function (h) { h.parentNode && h.parentNode.removeChild(h); });
                var oldBadges = container.querySelectorAll('[data-fch-method-badge]');
                Array.prototype.forEach.call(oldBadges, function (b) { b.parentNode && b.parentNode.removeChild(b); });

                var radios = container.querySelectorAll('input[name="fc_shipping_method"]');
                var lastGroup = null;
                Array.prototype.forEach.call(radios, function (radio) {
                    var row = methodRow(container, radio);
                    if (!row || row === radio) { return; }
                    var methodId = String(radio.value || '');
                    var cfg = methodsMap()[methodId];
                    var group = 'none';
                    var badgeText = GROUP_LABEL.none;
                    var badgeKind = 'none';
                    if (cfg) {
                        group = cfg.service === 'home' ? 'home' : (cfg.service === 'locker' ? 'locker' : 'pickup');
                        badgeText = cfg.label + ' · ' + (serviceLabels()[cfg.service] || cfg.service);
                        badgeKind = cfg.service;
                    }

                    if (lastGroup !== group) {
                        var header = document.createElement('div');
                        header.className = 'fch-pickup__group';
                        header.setAttribute('data-fch-group', group);
                        header.textContent = GROUP_LABEL[group];
                        row.parentNode.insertBefore(header, row);
                        lastGroup = group;
                    }

                    var badge = row.querySelector('[data-fch-method-badge]');
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'fch-pickup__methodbadge';
                        badge.setAttribute('data-fch-method-badge', '1');
                        row.appendChild(badge);
                    }
                    badge.setAttribute('data-kind', badgeKind);
                    badge.textContent = badgeText;
                });
            } catch (e) { /* grouping is cosmetic; never break checkout */ }
        }

        var brandingScheduled = false;
        function scheduleBranding() {
            if (brandingScheduled) { return; }
            brandingScheduled = true;
            var run = function () {
                brandingScheduled = false;
                applyMethodBranding();
            };
            if (window.requestAnimationFrame) { window.requestAnimationFrame(run); } else { setTimeout(run, 50); }
        }

        if (window.MutationObserver) {
            ready(function () {
                var container = document.querySelector(METHODS_WRAPPER);
                if (container) {
                    new MutationObserver(scheduleBranding).observe(container, { childList: true, subtree: true });
                }
            });
            // FC may render the shipping step after our init; re-check a few times, cheaply.
            setTimeout(scheduleBranding, 400);
            setTimeout(scheduleBranding, 1600);
        }

        // ---- visibility / context transitions ------------------------------------------------------

        function refreshVisibility() {
            var ctx = currentContext();
            var methodChanged = state_.methodId !== ctx.methodId;
            var wasRequired = state_.required;
            state_.methodId = ctx.methodId;
            state_.carrier = ctx.carrier;
            state_.types = ctx.types;
            state_.required = ctx.required;

            openBtn.hidden = !ctx.required;
            updateFallbackUi();

            if (!ctx.required) {
                teardownDriver();
                closeModal();
                // Server-side draft clear on home delivery / carrier change (invariant §1.9).
                if (methodChanged && wasRequired && state_.hasStored) {
                    queueSave(null);
                }
            } else if (methodChanged) {
                clearError();
                state_.statusNote = '';
                state_.query = '';
            }
            scheduleBranding();
        }

        // ---- events ---------------------------------------------------------------------------------

        openBtn.addEventListener('click', openModal);
        if (closeBtn) { closeBtn.addEventListener('click', closeModal); }
        if (retryBtn) { retryBtn.addEventListener('click', function () { loadPoints(q ? q.value : '', false); }); }
        if (fallbackBtn) { fallbackBtn.addEventListener('click', function (event) { event.preventDefault(); fallbackAction(); }); }

        if (q) {
            q.addEventListener('input', function () {
                clearTimeout(state_.searchTimer);
                state_.searchTimer = setTimeout(function () { loadPoints(q.value, false); }, 250);
            });
        }

        modal.addEventListener('click', function (event) {
            if (event.target === modal) { closeModal(); }
        });

        document.addEventListener('keydown', function (event) {
            if (modal.hidden) { return; }
            if (event.key === 'Escape') { event.preventDefault(); closeModal(); return; }

            if (event.key === 'Tab') {
                var items = focusable();
                if (!items.length) { return; }
                var first = items[0];
                var last = items[items.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault(); last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault(); first.focus();
                }
                return;
            }

            var opts = list.querySelectorAll('.fch-pickup__item');
            if (!opts.length) { return; }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                state_.activeIndex = event.key === 'ArrowDown'
                    ? Math.min(state_.activeIndex + 1, opts.length - 1)
                    : Math.max(state_.activeIndex - 1, 0);
                opts.forEach(function (el, i) {
                    el.setAttribute('aria-selected', i === state_.activeIndex ? 'true' : 'false');
                    el.classList.toggle('is-active', i === state_.activeIndex);
                });
                var active = opts[state_.activeIndex];
                if (active) {
                    list.setAttribute('aria-activedescendant', active.id);
                    active.scrollIntoView({ block: 'nearest' });
                }
            } else if (event.key === 'Enter' && state_.activeIndex >= 0) {
                event.preventDefault();
                var target = opts[state_.activeIndex];
                if (target) { target.click(); }
            }
        });

        // FluentCart re-renders shipping methods / saves checkout data: re-evaluate the context after
        // any checkout change (idempotent; no duplicate listeners because init runs once).
        document.addEventListener('change', function (event) {
            var t2 = event.target;
            if (t2 && (t2.name === 'fc_shipping_method' || t2.name === 'shipping_method_id' || t2.name === '_fct_pay_method')) {
                refreshVisibility();
            }
        });
        document.addEventListener('fluentCartCheckoutDataChanged', refreshVisibility);

        refreshVisibility();
        updateFallbackUi();
        scheduleBranding();
    });
})();
