<?php
declare(strict_types=1);

namespace FluentCartHungary\Modules\Billing\Szamlazz;

use FluentCartHungary\Core\Settings\ModuleSettings;
use FluentCartHungary\Core\Webhooks\WebhookEventRepository;

/**
 * Számlázz.hu IPN (Autokassza payment notification) ingress.
 *
 * 04_SZAMLAZZ_HU_CONTRACT.md §5 IPN: HTTP POST `application/x-www-form-urlencoded` with
 * `szlahu_szamlaszam`, `szlahu_dijbekero_szama`, `szlahu_rendelesszam`, `szlahu_bruttovegosszeg`,
 * `szlahu_kifizetettbrutto`, `szlahu_fizetesmod`, `szlahu_kifizdat`; the receiver must reply HTTP 200,
 * Számlázz retries up to 10× every 3 min and keeps only the newest IPN per invoice.
 *
 * Order-independent ingress (05_DATA_SCHEMA §7.5): this handler only *records* a deduplicated raw event.
 * Mark-paid is gated OFF by default (`ipn_enabled`, `ipn_mark_paid`); even when enabled it never mutates a
 * document's state without a separate query proof (the reconciler), it only records and announces.
 */
final class SzamlazzWebhookHandler
{
    public const PROVIDER = 'szamlazz';
    public const QUERY_VAR = 'fch_szamlazz_ipn';

    private ModuleSettings $settings;

    public function __construct(?ModuleSettings $settings = null)
    {
        $this->settings = $settings ?? new ModuleSettings();
    }

    public function isEnabled(): bool
    {
        return (bool) SzamlazzModule::setting('ipn_enabled', false);
    }

    /** Register the ingress only when the merchant enabled IPN (module requirement). */
    public function register(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        add_action('init', [$this, 'maybeHandle'], 20);
    }

    /** Read the request from the ingress point and reply HTTP 200 as required by the contract. */
    public function maybeHandle(): void
    {
        if (!isset($_GET[self::QUERY_VAR])) {
            return;
        }
        $payload = [];
        foreach (['szlahu_szamlaszam', 'szlahu_dijbekero_szama', 'szlahu_rendelesszam', 'szlahu_bruttovegosszeg', 'szlahu_kifizetettbrutto', 'szlahu_fizetesmod', 'szlahu_kifizdat'] as $field) {
            if (isset($_POST[$field])) {
                $payload[$field] = sanitize_text_field((string) wp_unslash((string) $_POST[$field]));
            }
        }

        $this->handle($payload);

        if (function_exists('status_header')) {
            status_header(200);
        }
        echo 'OK';
        exit;
    }

    /**
     * Record one IPN. Dedup key is `szamlaszam + kifizdat` (05_DATA_SCHEMA §5.5 Számlázz rule).
     *
     * @param array<string,string> $payload
     * @return array{ok:bool,duplicate:bool,event_id:?int,marked_paid:bool}
     */
    public function handle(array $payload): array
    {
        $szamlaszam = trim((string) ($payload['szlahu_szamlaszam'] ?? ''));
        $kifizdat = trim((string) ($payload['szlahu_kifizdat'] ?? ''));
        $dedupKey = $szamlaszam . '|' . $kifizdat;
        if ($szamlaszam === '') {
            $dedupKey = hash('sha256', http_build_query($payload));
        }

        $mode = $this->settings->mode();
        $account = $this->settings->accountId(SzamlazzInvoiceService::SETTINGS_SCOPE);

        $repository = new WebhookEventRepository();
        $ingested = $repository->ingest([
            'provider' => self::PROVIDER,
            'mode' => $mode,
            'account_id' => $account,
            'dedup_key' => $dedupKey,
            'raw_body' => wp_json_encode($payload),
            'signature_valid' => null,
            'event_type' => 'ipn',
            'external_ref' => $szamlaszam,
        ]);

        // Never change document state here: only record + announce. A paid notification does not prove our
        // document's existence/identity; that is the reconciler's job (query proof, invariant §1.4/§1.6).
        $markedPaid = false;
        if (!empty($ingested['duplicate'])) {
            do_action('fch/szamlazz/ipn_duplicate', $ingested['row'], $payload);
            return ['ok' => true, 'duplicate' => true, 'event_id' => (int) $ingested['row']->id, 'marked_paid' => false];
        }

        $repository->markProcessed((int) $ingested['row']->id);
        do_action('fch/szamlazz/ipn_received', $ingested['row'], $payload);

        if ($this->isEnabled() && (bool) SzamlazzModule::setting('ipn_mark_paid', false)) {
            // Gated bookkeeping only: announce for a future server-side amount/ref check; no state write.
            do_action('fch/szamlazz/ipn_paid_candidate', $szamlaszam, $payload);
            $markedPaid = false;
        }

        return ['ok' => true, 'duplicate' => false, 'event_id' => (int) $ingested['row']->id, 'marked_paid' => $markedPaid];
    }
}
