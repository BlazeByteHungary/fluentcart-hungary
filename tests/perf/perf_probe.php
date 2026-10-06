<?php
/**
 * Phase-07 perf/security probe — run on the lab with:
 *   wp eval-file tests/perf/perf_probe.php
 * Mock/local only; no external calls; synthetic data; cleans up after itself. Prints one JSON line.
 */

use FluentCartHungary\Core\Jobs\JobInterface;
use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Jobs\JobSpec;
use FluentCartHungary\Core\Jobs\JobStore;
use FluentCartHungary\Core\Ledger\LedgerRepository;
use FluentCartHungary\Core\Ledger\OperationRunner;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
use FluentCartHungary\Core\Webhooks\WebhookEventRepository;
use FluentCartHungary\Modules\Shipping\PickupPoints\PickupDirectory;

if (!class_exists('FchPerfNoopJob', false)) {
    class FchPerfNoopJob implements JobInterface
    {
        public const HOOK = 'fch_perf_noop';

        public function hook(): string
        {
            return self::HOOK;
        }

        public function handle(array $args): void
        {
        }
    }
}

$out = ['ok' => false];

try {
    if (!defined('FCH_CREDENTIALS_KEY')) {
        define('FCH_CREDENTIALS_KEY', 'lab-only-synthetic-credentials-key');
    }
    global $wpdb;

    $directory = new PickupDirectory();
    $points = [];
    $cities = ['Budapest', 'Debrecen', 'Szeged', 'Miskolc', 'Pécs', 'Győr', 'Nyíregyháza', 'Kecskemét'];
    for ($i = 1; $i <= 10000; $i++) {
        $points[] = [
            'id' => 'P-' . $i,
            'name' => 'Csomagpont ' . $i . ' ' . $cities[$i % 8],
            'zip' => (string) (1000 + ($i % 9000)),
            'city' => $cities[$i % 8],
            'address' => 'Fő utca ' . ($i % 200) . '.',
            'type' => ['PM', 'CS', 'PP'][$i % 3],
            'lat' => 47.0 + ($i % 100) / 1000,
            'lng' => 19.0 + ($i % 100) / 1000,
        ];
    }
    $directory->importStatic($points, 'mpl');

    $queries = ['Budapest', 'Debrecen', '2000', 'Fő', 'P-500', 'Szeged'];
    $times = [];
    for ($i = 0; $i < 200; $i++) {
        $q = $queries[$i % count($queries)];
        $t0 = microtime(true);
        $directory->search('mpl', $q, 30);
        $times[] = (microtime(true) - $t0) * 1000;
    }
    sort($times);
    $out['pickup_search'] = [
        'points' => 10000,
        'queries' => 200,
        'avg_ms' => round(array_sum($times) / count($times), 2),
        'p95_ms' => round($times[(int) floor(count($times) * 0.95)], 2),
        'max_ms' => round(max($times), 2),
    ];

    // Queue drain: 200 jobs through the pull driver (the async ActionScheduler path schedules instead
    // of pulling in-process; the DB driver is the always-available fallback, ADR-005).
    $store = new JobStore();
    $dbRunner = new JobRunner(new \FluentCartHungary\Core\Jobs\DbQueueDriver($store));
    $dbRunner->registerHandler(new FchPerfNoopJob());
    for ($i = 0; $i < 200; $i++) {
        $store->insert(new JobSpec('fch:perf:' . $i, FchPerfNoopJob::HOOK, [], 10, 3, 'perf'));
    }
    $wpdb->query("UPDATE {$store->table()} SET available_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE job_key LIKE 'fch:perf:%'");
    $t0 = microtime(true);
    $processed = $dbRunner->runDue(200);
    $queueMs = (microtime(true) - $t0) * 1000;
    $done = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$store->table()} WHERE job_key LIKE 'fch:perf:%' AND state = 'SUCCEEDED'");
    $out['queue_drain'] = [
        'inserted' => 200,
        'pull_driver' => $dbRunner->driverName(),
        'drained' => $done,
        'ms' => round($queueMs, 1),
        'default_driver_on_lab' => JobRunner::instance()->driverName(),
    ];

    // Redaction + payload cap through a real runner flow.
    $runner = new OperationRunner();
    $run = $runner->run([
        'idempotency_key' => 'fch:990999:payment_start:0',
        'module' => 'payments', 'provider' => 'barion', 'kind' => 'payment_start',
        'mode' => 'test', 'account_id' => 'acc-perf', 'order_id' => 990999,
    ], static function (): ProviderCallResult {
        return ProviderCallResult::success(
            ['external_id' => 'perf-1'],
            ['api_key' => 'SUPERSECRET-xyz', 'amount' => 1000, 'long' => str_repeat('a', 20000)],
            ['pos_key' => 'SUPERSECRET-xyz', 'status' => 'Succeeded']
        );
    });
    $row = (new LedgerRepository())->getById((int) $run['row']->id);
    $req = (string) ($row->request_payload_redacted ?? '');
    $res = (string) ($row->response_payload_redacted ?? '');
    $out['redaction'] = [
        'secret_absent' => !str_contains($req, 'SUPERSECRET') && !str_contains($res, 'SUPERSECRET'),
        'redaction_marker' => str_contains($req, '***REDACTED***'),
        'request_len' => strlen($req),
        'response_len' => strlen($res),
        'cap_ok' => strlen($req) <= 8000 && strlen($res) <= 8000,
        'row_bytes' => strlen((string) $row->request_payload_redacted) + strlen((string) $row->response_payload_redacted),
    ];

    // Webhook raw-body retention prune.
    $webhooks = new WebhookEventRepository();
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$webhooks->table()} (provider, mode, account_id, dedup_key, payload_hash, raw_body, received_at) VALUES ('barion','test','acc-perf','perf-old',%s,'sensitive-body', UTC_TIMESTAMP() - INTERVAL 100 DAY)",
        hash('sha256', 'sensitive-body')
    ));
    $pruned = $webhooks->pruneRawBodies(90);
    $oldRaw = $wpdb->get_var($wpdb->prepare("SELECT raw_body FROM {$webhooks->table()} WHERE dedup_key = %s", 'perf-old'));
    $out['retention'] = ['pruned_rows' => $pruned, 'old_body_after' => $oldRaw === null ? 'NULL' : 'PRESENT'];

    // External destinations audit: no plain-http request endpoints in shipped source. XML namespace
    // URLs (`xmlns="http://…"`) are identifiers, never fetched — excluded explicitly.
    $srcRoot = dirname(__DIR__, 2) . '/src';
    $http = [];
    $namespaceHits = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $code = (string) file_get_contents($file->getPathname());
            if (!preg_match_all("#['\"]http://[^'\"]+#", $code, $m)) {
                continue;
            }
            foreach ($m[0] as $hit) {
                $url = trim($hit, "'\"");
                // XML namespace identifiers (never fetched); the Számlázz agent API itself uses HTTPS.
                if (str_contains($url, 'www.w3.org') || str_contains($url, 'www.szamlazz.hu/')) {
                    $namespaceHits++;
                    continue;
                }
                $http[] = basename($file->getPathname()) . ':' . $url;
            }
        }
    }
    $out['external_http_urls'] = $http;
    $out['xml_namespace_hits_excluded'] = $namespaceHits;

    // HTTPS base assertion for every provider client.
    $httpsBases = [
        'BarionClient.php' => ['https://api.barion.com', 'https://api.test.barion.com'],
        'MplClient.php' => ['https://sandbox.api.posta.hu', 'https://core.api.posta.hu'],
        'SzamlazzClient.php' => ['https://www.szamlazz.hu'],
    ];
    $missingHttps = [];
    foreach ($httpsBases as $file => $bases) {
        $path = $srcRoot . '/Modules/' . (str_starts_with($file, 'Barion') ? 'Payments/Barion/' : (str_starts_with($file, 'Mpl') ? 'Shipping/Mpl/' : 'Billing/Szamlazz/')) . $file;
        $code = is_file($path) ? (string) file_get_contents($path) : '';
        foreach ($bases as $base) {
            if (!str_contains($code, $base)) {
                $missingHttps[] = $file . ':' . $base;
            }
        }
    }
    $out['https_bases_ok'] = $missingHttps === [];

    // Cleanup synthetic rows.
    $wpdb->query("DELETE FROM {$store->table()} WHERE job_key LIKE 'fch:perf:%'");
    $wpdb->query($wpdb->prepare("DELETE FROM {$webhooks->table()} WHERE dedup_key = %s", 'perf-old'));
    $wpdb->query("DELETE FROM {$wpdb->prefix}fch_external_operations WHERE idempotency_key = 'fch:990999:payment_start:0'");
    delete_option('fch_pickup_dir_mpl');

    $out['ok'] = ($out['queue_drain']['drained'] === 200)
        && $out['redaction']['secret_absent']
        && $out['redaction']['cap_ok']
        && $out['retention']['old_body_after'] === 'NULL'
        && $out['external_http_urls'] === []
        && $out['https_bases_ok'];
} catch (\Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo wp_json_encode($out) . "\n";
