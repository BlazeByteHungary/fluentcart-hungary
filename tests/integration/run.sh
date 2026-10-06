#!/usr/bin/env bash
# W0 integration suite against the isolated FluentCart lab (WP 7.1.2 + FluentCart 1.7.0).
# No network. Requires the lab MariaDB to be running (see docs/implementation/W0_TEST_RESULTS.md).
set -u

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$DIR/../../.." && pwd)"
PLUGIN_SRC="$REPO/fluentcart-hungary"
LAB="${FCH_LAB:-/tmp/kilo/fc-lab}"
LAB_PLUGIN="$LAB/wp/wp-content/plugins/fluentcart-hungary"
LAB_MU="$LAB/wp/wp-content/mu-plugins"
WP="$DIR/wp.sh"

PASS=0
FAIL=0
NOTRUN=0

pass() { PASS=$((PASS + 1)); echo "PASS  $1"; }
fail() { FAIL=$((FAIL + 1)); echo "FAIL  $1 :: $2"; }
skip() { NOTRUN=$((NOTRUN + 1)); echo "NOT_RUN  $1 :: $2"; }

check_contains() { # name needle haystack
  if [[ "$3" == *"$2"* ]]; then pass "$1"; else fail "$1" "expected substring not found: [$2] in [$3]"; fi
}

check_eq() { # name expected actual
  if [[ "$2" == "$3" ]]; then pass "$1"; else fail "$1" "expected [$2] got [$3]"; fi
}

if [[ ! -S "$LAB/mysql.sock" ]]; then
  echo "NOT_RUN  lab database :: $LAB/mysql.sock missing; start MariaDB first" >&2
  exit 2
fi

echo "== W0 integration run $(date -u +%Y-%m-%dT%H:%M:%SZ) =="

# sync plugin (repo copy is the source of truth; lab copy is disposable)
rm -rf "$LAB_PLUGIN"
cp -r "$PLUGIN_SRC" "$LAB_PLUGIN"
mkdir -p "$LAB_MU"
rm -f "$LAB_MU/fch-test-throwing-module.php"

# T00: activation creates the schema (v2) from scratch
"$WP" plugin deactivate fluentcart-hungary >/dev/null 2>&1 || true
"$WP" eval 'global $wpdb; foreach (["fch_external_operations","fch_payment_attempts","fch_invoice_documents","fch_shipments","fch_webhook_events","fch_jobs"] as $t) { $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}{$t}`"); } delete_option("fch_schema_version"); delete_option("fch_schema_state");' >/dev/null 2>&1
"$WP" plugin activate fluentcart-hungary >/dev/null 2>&1
OUT="$("$WP" eval 'global $wpdb; echo \FluentCartHungary\Core\Database\Migrator::installedVersion() . "|" . count($wpdb->get_col("SHOW TABLES LIKE \"{$wpdb->prefix}fch_%\""));' 2>/dev/null)"
check_eq "T00 activation creates schema v2 from scratch" "2|6" "$OUT"

# deterministic baseline: ensure FC active, reset our state
"$WP" plugin activate fluent-cart >/dev/null 2>&1 || true
"$WP" plugin activate fluentcart-hungary >/dev/null 2>&1 || true
"$WP" eval 'global $wpdb; foreach (["fch_external_operations","fch_payment_attempts","fch_invoice_documents","fch_shipments","fch_webhook_events","fch_jobs"] as $t) { $wpdb->query("TRUNCATE TABLE `{$wpdb->prefix}{$t}`"); } foreach (["fch_queue_driver","fch_modules","fch_credentials_v1","fch_remove_data_on_uninstall","fch_test_job_ran","fch_artifact_migration"] as $o) { delete_option($o); } delete_transient("fch_core_selftest"); echo "reset";' >/dev/null 2>&1

# T01: activation with FluentCart present
OUT="$("$WP" plugin list --fields=name,status --format=csv 2>/dev/null)"
check_contains "T01 activation with FluentCart present" "fluentcart-hungary,active" "$OUT"

# T02: schema DDL (via $wpdb; the lab's mysql client is unavailable)
OUT="$("$WP" eval 'global $wpdb; $r=$wpdb->get_row("SHOW CREATE TABLE {$wpdb->prefix}fch_external_operations", ARRAY_N); echo $r[1];' 2>/dev/null)"
check_contains "T02a external_operations unique idempotency_key" "uq_fch_extop_idem" "$OUT"
check_contains "T02b external_operations InnoDB/utf8mb4" "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" "$OUT"
check_contains "T02c external_operations lease index" "idx_fch_extop_lease" "$OUT"
check_contains "T02d external_operations retry index" "idx_fch_extop_retry" "$OUT"
OUT="$("$WP" eval 'global $wpdb; $r=$wpdb->get_row("SHOW CREATE TABLE {$wpdb->prefix}fch_jobs", ARRAY_N); echo $r[1];' 2>/dev/null)"
check_contains "T02e jobs unique job_key" "uq_fch_jobs_key" "$OUT"
check_contains "T02f jobs claim index" "idx_fch_jobs_claim" "$OUT"
check_contains "T02g jobs InnoDB/utf8mb4" "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" "$OUT"
OUT="$("$WP" eval 'global $wpdb; $r=$wpdb->get_row("SHOW CREATE TABLE {$wpdb->prefix}fch_invoice_documents", ARRAY_N); echo $r[1];' 2>/dev/null)"
check_contains "T02h invoice_documents generated auto_doc_guard" "auto_doc_guard" "$OUT"
check_contains "T02i invoice_documents single-invoicer unique guard" "uq_fch_inv_auto_guard" "$OUT"
check_contains "T02j invoice_documents unique per-role sequence" "uq_fch_inv_seq" "$OUT"
OUT="$("$WP" eval 'global $wpdb; $r=$wpdb->get_row("SHOW CREATE TABLE {$wpdb->prefix}fch_payment_attempts", ARRAY_N); echo $r[1];' 2>/dev/null)"
check_contains "T02k payment_attempts unique attempt_no" "uq_fch_pay_attempt" "$OUT"
check_contains "T02l payment_attempts unique idempotency_key" "uq_fch_pay_idem" "$OUT"
OUT="$("$WP" eval 'global $wpdb; $r=$wpdb->get_row("SHOW CREATE TABLE {$wpdb->prefix}fch_shipments", ARRAY_N); echo $r[1];' 2>/dev/null)"
check_contains "T02m shipments unique idempotency_key" "uq_fch_ship_idem" "$OUT"
check_contains "T02n shipments unique per-carrier shipment_no" "uq_fch_ship_no" "$OUT"
OUT="$("$WP" eval 'global $wpdb; $r=$wpdb->get_row("SHOW CREATE TABLE {$wpdb->prefix}fch_webhook_events", ARRAY_N); echo $r[1];' 2>/dev/null)"
check_contains "T02o webhook_events unique dedup key" "uq_fch_wh_dedup" "$OUT"

# T03: duplicate ledger claim (single process)
OUT="$("$WP" eval-file "$DIR/php/ledger_claim.php" 2>/dev/null)"
check_contains "T03 duplicate ledger claim returns one row" '"first_created":true,"second_created":false,"same_id":true,"count":1' "$OUT"

# T04: duplicate ledger claim (two parallel processes)
"$WP" eval 'global $wpdb; $t=$wpdb->prefix."fch_external_operations"; $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE idempotency_key=%s","fch:990002:payment_start:0"));' >/dev/null 2>&1
export FCH_LEDGER_KEY="fch:990002:payment_start:0"
PAR_OUT="$(printf '1\n2\n' | xargs -P2 -I{} bash -c '"$0" eval-file "$1"' "$WP" "$DIR/php/ledger_claim_once.php" 2>/dev/null)"
CREATED_COUNT="$(grep -o '"created":true' <<<"$PAR_OUT" | wc -l | tr -d ' ')"
COUNT="$("$WP" eval 'global $wpdb; $t=$wpdb->prefix."fch_external_operations"; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE idempotency_key=%s","fch:990002:payment_start:0"));' 2>/dev/null)"
check_eq "T04a parallel claim: exactly one winner" "1" "$CREATED_COUNT"
check_eq "T04b parallel claim: exactly one row" "1" "$COUNT"

# T05: credential encrypt/decrypt + masking
OUT="$("$WP" eval-file "$DIR/php/credentials.php" 2>/dev/null)"
check_contains "T05 credential round-trip + masked + at-rest opaque" '"stored":true,"roundtrip":true,"masked_clean":true,"at_rest_opaque":true' "$OUT"

# T06: throwing module does not break the plugin
cp "$DIR/mu-plugins/fch-test-throwing-module.php" "$LAB_MU/fch-test-throwing-module.php"
OUT="$("$WP" eval-file "$DIR/php/module_throw.php" 2>/dev/null)"
check_contains "T06 module isolation: core active, throwing module degraded" '"core":"active","boom":"degraded","has_boom":true,"diagnostics_ok":true' "$OUT"
rm -f "$LAB_MU/fch-test-throwing-module.php"

# T07: admin vs subscriber permissions
OUT="$("$WP" eval-file "$DIR/php/admin_permissions.php" 2>/dev/null)"
check_contains "T07 admin 200 + render ok / subscriber 403" '"admin_allowed":true,"admin_status":200,"render_ok":true,"subscriber_allowed":false,"subscriber_status":403' "$OUT"

# T08: queue driver selection + DB job execution
OUT="$("$WP" eval-file "$DIR/php/queue_db_job.php" 2>/dev/null)"
check_contains "T08 ActionScheduler primary + DbQueueDriver job run" '"auto_driver":"action_scheduler","db_driver":"db_queue","created":true,"executed":1,"state":"SUCCEEDED","ran":77' "$OUT"

# T09: schema upgrade idempotent
OUT="$("$WP" eval-file "$DIR/php/schema_upgrade.php" 2>/dev/null)"
check_contains "T09 schema upgrade idempotent + indexes present" '"idempotent":true,"schema_ok":true,"missing":[]' "$OUT"

# T15: single-invoicer guard (generated column) blocks a 2nd automatic document and frees after rejection
OUT="$("$WP" eval '
$repo = new \FluentCartHungary\Core\Ledger\InvoiceDocumentRepository();
$a = $repo->getOrCreate(["order_id"=>990101,"provider"=>"szamlazz","mode"=>"test","account_id"=>"acc1","doc_role"=>"invoice","idempotency_key"=>"fch:990101:create_invoice:0","is_selected_invoicer"=>1]);
$b = $repo->getOrCreate(["order_id"=>990101,"provider"=>"billingo","mode"=>"test","account_id"=>"acc1","doc_role"=>"invoice","idempotency_key"=>"fch:990101:create_invoice:1","is_selected_invoicer"=>1]);
$count1 = (int) $GLOBALS["wpdb"]->get_var("SELECT COUNT(*) FROM {$GLOBALS["wpdb"]->prefix}fch_invoice_documents WHERE order_id=990101");
$GLOBALS["wpdb"]->query("UPDATE {$GLOBALS["wpdb"]->prefix}fch_invoice_documents SET state=\"PROVEN_REJECTED\" WHERE id=" . (int) $a["row"]->id);
$c = $repo->getOrCreate(["order_id"=>990101,"provider"=>"billingo","mode"=>"test","account_id"=>"acc1","doc_role"=>"invoice","idempotency_key"=>"fch:990101:create_invoice:2","is_selected_invoicer"=>1]);
$count2 = (int) $GLOBALS["wpdb"]->get_var("SELECT COUNT(*) FROM {$GLOBALS["wpdb"]->prefix}fch_invoice_documents WHERE order_id=990101");
echo wp_json_encode(["created_first"=>$a["created"],"guard_blocked_second"=>($b["created"]===false && ($b["guard"]??"")==="single_invoicer"),"count_one"=>$count1===1,"guard_freed_after_reject"=>$c["created"],"count_two"=>$count2]);
' 2>/dev/null)"
check_contains "T15 single-invoicer guard blocks 2nd auto doc + frees after rejection" '"created_first":true,"guard_blocked_second":true,"count_one":true,"guard_freed_after_reject":true,"count_two":2' "$OUT"

# T16: webhook dedup — same (provider,mode,account,dedup_key) never inserts twice
OUT="$("$WP" eval '
$repo = new \FluentCartHungary\Core\Webhooks\WebhookEventRepository();
$r1 = $repo->ingest(["provider"=>"barion","mode"=>"test","account_id"=>"acc1","dedup_key"=>"pay-990201","raw_body"=>"{\"paymentId\":\"pay-990201\"}"]);
$r2 = $repo->ingest(["provider"=>"barion","mode"=>"test","account_id"=>"acc1","dedup_key"=>"pay-990201","raw_body"=>"{\"paymentId\":\"pay-990201\"}"]);
$c = $repo->countByState();
echo wp_json_encode(["created"=>$r1["created"],"duplicate_second"=>$r2["duplicate"],"same_row"=>$r1["row"]->id === $r2["row"]->id,"received"=>$c["RECEIVED"] ?? null]);
' 2>/dev/null)"
check_contains "T16 webhook dedup single row, duplicate flagged" '"created":true,"duplicate_second":true,"same_row":true,"received":1' "$OUT"

# T17: payment attempt idempotency + attempt_no uniqueness
OUT="$("$WP" eval '
$repo = new \FluentCartHungary\Core\Ledger\PaymentAttemptRepository();
$base = ["order_id"=>990301,"provider"=>"barion","mode"=>"test","account_id"=>"acc1","attempt_no"=>1,"amount_minor"=>12345,"currency"=>"HUF"];
$a = $repo->getOrCreate($base + ["idempotency_key"=>"fch:990301:payment_start:0"]);
$b = $repo->getOrCreate($base + ["idempotency_key"=>"fch:990301:payment_start:0"]);
$c = $repo->getOrCreate($base + ["idempotency_key"=>"fch:990301:payment_start:1"]);
echo wp_json_encode(["created"=>$a["created"],"idempotent_second"=>$b["created"]===false && ($b["row"]->id === $a["row"]->id),"attempt_dup_same_row"=>$c["created"]===false && ($c["row"]->id === $a["row"]->id),"next_attempt_no"=>$repo->nextAttemptNo(990301,"barion","test","acc1")]);
' 2>/dev/null)"
check_contains "T17 payment attempt idempotency + attempt uniqueness" '"created":true,"idempotent_second":true,"attempt_dup_same_row":true,"next_attempt_no":2' "$OUT"

# T18: shipment idempotency + per-carrier shipment_no uniqueness
OUT="$("$WP" eval '
$repo = new \FluentCartHungary\Core\Ledger\ShipmentRepository();
$base = ["order_id"=>990401,"carrier"=>"mpl","mode"=>"test","account_id"=>"acc1","shipment_no"=>1];
$a = $repo->getOrCreate($base + ["idempotency_key"=>"fch:990401:label_create:0"]);
$b = $repo->getOrCreate($base + ["idempotency_key"=>"fch:990401:label_create:0"]);
$c = $repo->getOrCreate($base + ["idempotency_key"=>"fch:990401:label_create:1"]);
echo wp_json_encode(["created"=>$a["created"],"idempotent_second"=>$b["created"]===false && ($b["row"]->id === $a["row"]->id),"shipment_dup_same_row"=>$c["created"]===false && ($c["row"]->id === $a["row"]->id),"next_shipment_no"=>$repo->nextShipmentNo(990401,"mpl","test","acc1")]);
' 2>/dev/null)"
check_contains "T18 shipment idempotency + shipment_no uniqueness" '"created":true,"idempotent_second":true,"shipment_dup_same_row":true,"next_shipment_no":2' "$OUT"

# T19: OperationRunner guarded flow — success / timeout->UNCERTAIN+reconcile job / local error->NOT_STARTED
OUT="$("$WP" eval '
use FluentCartHungary\Core\Ledger\OperationRunner;
use FluentCartHungary\Core\Ledger\ProviderCallResult;
$mk = function ($kind, $n, $order, $dispatch) {
    return (new OperationRunner())->run(["idempotency_key" => "fch:$order:$kind:$n", "module" => "payments", "provider" => "barion", "kind" => $kind, "mode" => "test", "account_id" => "acc1", "order_id" => $order], $dispatch);
};
$ok = $mk("payment_start", 0, 990501, function ($row) { return ProviderCallResult::success(["external_id" => "pay-1", "external_ref2" => "req-1"], ["amount" => 1000], ["status" => "Succeeded"]); });
$to = $mk("payment_start", 0, 990502, function ($row) { return ProviderCallResult::uncertain("TIMEOUT_AFTER_DISPATCH", [], ["raw" => "lost"]); });
$le = $mk("payment_start", 1, 990503, function ($row) { return ProviderCallResult::localError("VALIDATION", "bad amount", ["amount" => -1]); });
$job = (new \FluentCartHungary\Core\Jobs\JobStore())->findByKey("fch:reconcile:" . $to["row"]->id);
echo wp_json_encode([
    "success_state" => $ok["state"],
    "success_external_id" => $ok["row"]->external_id ?? null,
    "uncertain_state" => $to["state"],
    "reconcile_job" => $job !== null && $job->state === "PENDING" && $job->hook === "fch_reconcile_uncertain",
    "local_error_state" => $le["state"],
    "local_error_code" => $le["row"]->local_error_code ?? null,
    "retry_scheduled" => $le["row"]->next_retry_at !== null,
]);
' 2>/dev/null)"
check_contains "T19 OperationRunner success/uncertain/local-error flow" '"success_state":"EXTERNAL_CONFIRMED","success_external_id":"pay-1","uncertain_state":"UNCERTAIN","reconcile_job":true,"local_error_state":"NOT_STARTED","local_error_code":"VALIDATION","retry_scheduled":true' "$OUT"

# T20: reconciliation queue manual resolution (audited) UNCERTAIN -> AWAITING -> MANUALLY_RESOLVED
OUT="$("$WP" eval '
$run = (new \FluentCartHungary\Core\Ledger\OperationRunner())->run(["idempotency_key"=>"fch:990601:payment_start:0","module"=>"payments","provider"=>"barion","kind"=>"payment_start","mode"=>"test","account_id"=>"acc1","order_id"=>990601], function ($row) { return \FluentCartHungary\Core\Ledger\ProviderCallResult::uncertain("TIMEOUT_AFTER_DISPATCH"); });
$id = (int) $run["row"]->id;
$r = (new \FluentCartHungary\Core\Admin\ReconciliationQueue())->resolve($id, "manual", "operator verified refunded", 1);
$row = (new \FluentCartHungary\Core\Ledger\LedgerRepository())->getById($id);
echo wp_json_encode(["run_state"=>$run["state"],"resolve_ok"=>$r["ok"],"state"=>$r["state"],"resolved_by"=>(int) $row->resolved_by,"note"=>$row->resolved_note]);
' 2>/dev/null)"
check_contains "T20 reconciliation queue manual resolve audited" '"run_state":"UNCERTAIN","resolve_ok":true,"state":"MANUALLY_RESOLVED","resolved_by":1,"note":"operator verified refunded"' "$OUT"

# T13: crash recovery requeues an expired lease (priority -10 so this job is claimed first)
OUT="$("$WP" eval 'global $wpdb; $store=new \FluentCartHungary\Core\Jobs\JobStore(); $key="fch:990901:label_create:0:lease"; $store->insert(new \FluentCartHungary\Core\Jobs\JobSpec($key,"fch_test_lease",[],-10,3,"core")); $row=$store->findByKey($key); $claimed=$store->claimNext("test-worker",300); $t=$store->table(); $wpdb->query($wpdb->prepare("UPDATE `{$t}` SET lease_expires_at = UTC_TIMESTAMP() - INTERVAL 10 SECOND WHERE id=%d",(int)$row->id)); $released=$store->releaseExpired(); $after=$store->findById((int)$row->id); echo wp_json_encode(["claimed"=>(int)$claimed->id===(int)$row->id,"released"=>$released,"state"=>$after->state]);' 2>/dev/null)"
check_contains "T13 crash recovery requeues expired lease" '"claimed":true,"released":1,"state":"PENDING"' "$OUT"

# T14: HTTP base client is injectable without network (mock seam exists)
OUT="$("$WP" eval 'add_filter("fch/http/pre_request", function(){ return new \FluentCartHungary\Core\Http\Response(200,["X-Test"=>"1"],"{\"ok\":true}"); }); $c=new \FluentCartHungary\Core\Http\BaseClient("https://example.invalid"); $r=$c->get("/probe"); echo wp_json_encode(["status"=>$r->status,"success"=>$r->isSuccess(),"ok"=>$r->json()["ok"] ?? null,"header"=>$r->header("X-Test")]);' 2>/dev/null)"
check_contains "T14 HTTP base client injectable without network" '"status":200,"success":true,"ok":true,"header":"1"' "$OUT"

# T11: boot with FluentCart absent (no fatal)
"$WP" plugin deactivate fc-hungary-spike >/dev/null 2>&1 || true
"$WP" plugin deactivate fluent-cart >/dev/null 2>&1 || true
OUT="$("$WP" eval-file "$DIR/php/compat_probe.php" 2>/tmp/fch_fc_absent_err.txt)"
RC=$?
if [[ $RC -ne 0 ]]; then
  fail "T11a no fatal with FluentCart absent" "wp eval exit=$RC; $(tail -1 /tmp/fch_fc_absent_err.txt 2>/dev/null)"
else
  pass "T11a no fatal with FluentCart absent"
fi
check_contains "T11b FC absent probe + core still active" '"fc_active":false,"fc_version":null,"core_state":"active"' "$OUT"
check_contains "T11c tables intact while FC absent" '"tables_ok":true' "$OUT"
"$WP" plugin activate fluent-cart >/dev/null 2>&1
"$WP" plugin activate fc-hungary-spike >/dev/null 2>&1 || true

# T10: deactivate/reactivate keeps data (DB-only reads so no plugin class is needed while inactive)
LEDGER_BEFORE="$("$WP" eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fch_external_operations");' 2>/dev/null)"
JOBS_BEFORE="$("$WP" eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fch_jobs");' 2>/dev/null)"
"$WP" plugin deactivate fluentcart-hungary >/dev/null 2>&1
TABLES="$("$WP" eval 'global $wpdb; echo count($wpdb->get_col("SHOW TABLES LIKE \"{$wpdb->prefix}fch_%\""));' 2>/dev/null)"
LEDGER_DEACT="$("$WP" eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fch_external_operations");' 2>/dev/null)"
"$WP" plugin activate fluentcart-hungary >/dev/null 2>&1
LEDGER_REACT="$("$WP" eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fch_external_operations");' 2>/dev/null)"
JOBS_REACT="$("$WP" eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fch_jobs");' 2>/dev/null)"
check_eq "T10a tables survive deactivation" "6" "$TABLES"
check_eq "T10b ledger data survives deactivation" "$LEDGER_BEFORE" "$LEDGER_DEACT"
check_eq "T10c ledger data survives reactivation" "$LEDGER_BEFORE" "$LEDGER_REACT"
check_eq "T10d jobs data survives reactivation" "$JOBS_BEFORE" "$JOBS_REACT"

# T12: no secret material in the shipped plugin code (tests excluded; fixtures are synthetic)
if grep -RIl -e 'lab-only-synthetic' -e 'SECRET-abc' "$PLUGIN_SRC/src" "$PLUGIN_SRC/fluentcart-hungary.php" "$PLUGIN_SRC/uninstall.php" >/dev/null 2>&1; then
  fail "T12 no secrets committed in shipped plugin code" "found secret-like strings"
else
  pass "T12 no secrets committed in shipped plugin code"
fi

# T21: missing-dependency degradation — our plugin boots without FluentCart, no fatal
"$WP" plugin deactivate fluent-cart >/dev/null 2>&1 || true
FCH_ALIVE="$("$WP" eval 'echo class_exists("FluentCartHungary\\Core\\Plugin") ? "yes" : "no";' 2>/dev/null)"
FC_STATE="$("$WP" eval 'echo \FluentCartHungary\Core\Compat\FluentCartCompat::isFcActive() ? "active" : "absent";' 2>/dev/null)"
"$WP" plugin activate fluent-cart >/dev/null 2>&1 || true
check_eq "T21a plugin boots with FluentCart missing (no fatal)" "yes" "$FCH_ALIVE"
check_eq "T21b compat probe reports FC absent" "absent" "$FC_STATE"

# T22: module isolation — enabled modules boot independently; disabling one does not touch siblings
"$WP" eval 'update_option("fch_modules", ["core"=>true,"payments/barion"=>true,"payments/simplepay"=>true,"billing/szamlazz"=>true,"shipping/mpl"=>true,"shipping/gls"=>true,"shipping/dpd"=>true,"shipping/packeta"=>true,"shipping/foxpost"=>true,"shipping/pickuppoints"=>true,"billing/billingo"=>true,"payments/simplepay"=>true]);' >/dev/null 2>&1
OUT="$("$WP" eval '$m=\FluentCartHungary\Core\ModuleRegistry::instance(); $s=$m->states(); unset($s["core"]); $bad=[]; foreach($s as $id=>$st){ if($st==="inactive"||$st==="blocked") $bad[]=$id.":".$st; } echo wp_json_encode(["core"=>$m->state("core"),"bad"=>$bad]);' 2>/dev/null)"
check_contains "T22a all enabled modules boot (none inactive/blocked)" '"core":"active","bad":[]' "$OUT"
"$WP" eval 'update_option("fch_modules", ["core"=>true,"payments/barion"=>false,"billing/szamlazz"=>true,"shipping/mpl"=>true,"shipping/pickuppoints"=>true]);' >/dev/null 2>&1
OUT="$("$WP" eval '$s=\FluentCartHungary\Core\ModuleRegistry::instance()->states(); echo wp_json_encode(["barion"=>$s["payments/barion"]??null,"szamlazz_inactive"=>($s["billing/szamlazz"]??"missing")==="inactive","mpl_inactive"=>($s["shipping/mpl"]??"missing")==="inactive"]);' 2>/dev/null)"
check_contains "T22b disabling barion leaves siblings booted" '"barion":"inactive","szamlazz_inactive":false,"mpl_inactive":false' "$OUT"
"$WP" eval 'delete_option("fch_modules");' >/dev/null 2>&1

# T36: fresh-boot module registration audit — every module's promised business hooks must be present
# in the live registries (job handlers, reconcilers, carrier/pickup registries, FC gateways) and the
# diagnostics view must match the boot states.
"$WP" eval 'update_option("fch_modules", ["core"=>true,"payments/barion"=>true,"payments/simplepay"=>true,"billing/szamlazz"=>true,"billing/billingo"=>true,"shipping/mpl"=>true,"shipping/gls"=>true,"shipping/dpd"=>true,"shipping/packeta"=>true,"shipping/foxpost"=>true,"shipping/pickuppoints"=>true]);' >/dev/null 2>&1
OUT="$("$WP" eval-file "$DIR/php/module_registration.php" 2>/dev/null | tail -1)"
check_contains "T36 module registration + diagnostics audit" '"ok":true' "$OUT"

# T30: provider mock flows (optional; FCH_WITH_FLOWS=1). Mock-only: no real provider calls.
if [ "${FCH_WITH_FLOWS:-0}" = "1" ]; then
  for flow in barion szamlazz mpl pickup pickup_checkout_ux; do
    OUT="$("$WP" eval-file "$DIR/php/${flow}_flow.php" 2>/dev/null | tail -1)"
    check_contains "T30 ${flow}_flow ok (mock-only)" '"ok":true' "$OUT"
  done
  # T41: directory lifecycle + binding/search/routing (checkout 2/3 goal; guarded while it lands)
  if [ -f "$DIR/php/pickup_directory_lifecycle.php" ]; then
    OUT="$("$WP" eval-file "$DIR/php/pickup_directory_lifecycle.php" 2>/dev/null | tail -1)"
    check_contains "T41 pickup_directory_lifecycle ok (mock-only)" '"ok":true' "$OUT"
  fi
  # T42: carrier routing gate (checkout 3/3 goal): per-carrier mock create counts, unresolved legacy,
  # default policy, retry/idempotency, immutable snapshot (guard while it lands).
  if [ -f "$DIR/php/pickup_carrier_routing.php" ]; then
    OUT="$("$WP" eval-file "$DIR/php/pickup_carrier_routing.php" 2>/dev/null | tail -1)"
    check_contains "T42 pickup_carrier_routing ok (mock-only)" '"ok":true' "$OUT"
  fi
  # T43: offers + native eligibility (checkout 3/3 gate #2) — real FC shipping-method rows.
  if [ -f "$DIR/php/pickup_offers_eligibility.php" ]; then
    OUT="$("$WP" eval-file "$DIR/php/pickup_offers_eligibility.php" 2>/dev/null | tail -1)"
    check_contains "T43 pickup_offers_eligibility ok (mock-only)" '"ok":true' "$OUT"
  fi
  # T44: directory fresh/stale/expired lifecycle gate (checkout 3/3 gate #3).
  if [ -f "$DIR/php/pickup_directory_expiry.php" ]; then
    OUT="$("$WP" eval-file "$DIR/php/pickup_directory_expiry.php" 2>/dev/null | tail -1)"
    check_contains "T44 pickup_directory_expiry ok (mock-only)" '"ok":true' "$OUT"
  fi
  # T45: selector drivers — widget resolve, locked refresh, Packeta validate (checkout 3/3 gate #4).
  if [ -f "$DIR/php/pickup_widget_drivers.php" ]; then
    OUT="$("$WP" eval-file "$DIR/php/pickup_widget_drivers.php" 2>/dev/null | tail -1)"
    check_contains "T45 pickup_widget_drivers ok (mock-only)" '"ok":true' "$OUT"
  fi
  OUT="$("$WP" eval-file "$DIR/php/cod_invoice_policy.php" 2>/dev/null | tail -1)"
  check_contains "T30 cod_invoice_policy ok (mock-only, real FC orders)" '"ok":true' "$OUT"
  OUT="$("$WP" eval-file "$DIR/php/artifact_migration_lab.php" 2>/dev/null | tail -1)"
  check_contains "T31 artifact_migration ok (real uploads tree)" '"ok":true' "$OUT"
  # W2 carrier flows (present once the carrier modules land; guarded so the suite stays green until then)
  for w2 in gls dpd packeta foxpost; do
    if [ -f "$DIR/php/${w2}_flow.php" ]; then
      OUT="$("$WP" eval-file "$DIR/php/${w2}_flow.php" 2>/dev/null | tail -1)"
      check_contains "T32 ${w2}_flow ok (mock-only)" '"ok":true' "$OUT"
    fi
  done
  # W3 payment flows (guarded while the module lands)
  if [ -f "$DIR/php/simplepay_flow.php" ]; then
    OUT="$("$WP" eval-file "$DIR/php/simplepay_flow.php" 2>/dev/null | tail -1)"
    check_contains "T33 simplepay_flow ok (mock-only)" '"ok":true' "$OUT"
  fi
  # W3 Billingo + shared invoicing routing flow (guarded while the module lands)
  if [ -f "$DIR/php/billingo_flow.php" ]; then
    OUT="$("$WP" eval-file "$DIR/php/billingo_flow.php" 2>/dev/null | tail -1)"
    check_contains "T34 billingo_flow ok (mock-only)" '"ok":true' "$OUT"
  fi
  # W3 carrier -> invoicer routing across all five label paths, event-driven (boot-time registrations
  # required; the flow itself asserts the modules were active from boot before it runs anything).
  if [ -f "$DIR/php/carrier_invoice_routing.php" ]; then
    "$WP" eval 'update_option("fch_modules", ["core"=>true,"payments/barion"=>true,"payments/simplepay"=>true,"billing/szamlazz"=>true,"billing/billingo"=>true,"shipping/mpl"=>true,"shipping/gls"=>true,"shipping/dpd"=>true,"shipping/packeta"=>true,"shipping/foxpost"=>true,"shipping/pickuppoints"=>true]);' >/dev/null 2>&1
    OUT="$("$WP" eval-file "$DIR/php/carrier_invoice_routing.php" 2>/dev/null | tail -1)"
    check_contains "T35 carrier_invoice_routing ok (event-driven, mock-only)" '"ok":true' "$OUT"
  fi
  # W4 refund lifecycle across FluentCart + Barion/SimplePay (boot-time gateway registrations required).
  if [ -f "$DIR/php/refund_flow.php" ]; then
    "$WP" eval 'update_option("fch_modules", ["core"=>true,"payments/barion"=>true,"payments/simplepay"=>true,"billing/szamlazz"=>true,"billing/billingo"=>true,"shipping/mpl"=>true,"shipping/gls"=>true,"shipping/dpd"=>true,"shipping/packeta"=>true,"shipping/foxpost"=>true,"shipping/pickuppoints"=>true]);' >/dev/null 2>&1
    OUT="$("$WP" eval-file "$DIR/php/refund_flow.php" 2>/dev/null | tail -1)"
    check_contains "T37 refund_flow ok (mock-only)" '"ok":true' "$OUT"
  fi
  # W4 invoice correction lifecycle (storno/corrected + refund linkage) across both invoicers.
  if [ -f "$DIR/php/invoice_correction_flow.php" ]; then
    "$WP" eval 'update_option("fch_modules", ["core"=>true,"payments/barion"=>true,"payments/simplepay"=>true,"billing/szamlazz"=>true,"billing/billingo"=>true,"shipping/mpl"=>true,"shipping/gls"=>true,"shipping/dpd"=>true,"shipping/packeta"=>true,"shipping/foxpost"=>true,"shipping/pickuppoints"=>true]);' >/dev/null 2>&1
    OUT="$("$WP" eval-file "$DIR/php/invoice_correction_flow.php" 2>/dev/null | tail -1)"
    check_contains "T38 invoice_correction_flow ok (mock-only)" '"ok":true' "$OUT"
  fi
  # W4 logistics lifecycle (claims + shipment cancellation) with the real module boot.
  if [ -f "$DIR/php/logistics_lifecycle.php" ]; then
    "$WP" eval 'update_option("fch_modules", ["core"=>true,"payments/barion"=>true,"payments/simplepay"=>true,"billing/szamlazz"=>true,"billing/billingo"=>true,"shipping/mpl"=>true,"shipping/gls"=>true,"shipping/dpd"=>true,"shipping/packeta"=>true,"shipping/foxpost"=>true,"shipping/pickuppoints"=>true]);' >/dev/null 2>&1
    OUT="$("$WP" eval-file "$DIR/php/logistics_lifecycle.php" 2>/dev/null | tail -1)"
    check_contains "T39 logistics_lifecycle ok (mock-only)" '"ok":true' "$OUT"
  fi
  # W4 crossed-lifecycle verification (refund cascade, uncertain refund with proved correction,
  # unpaid COD separate lifecycles, late events after a closed chain).
  if [ -f "$DIR/php/cross_lifecycle.php" ]; then
    "$WP" eval 'update_option("fch_modules", ["core"=>true,"payments/barion"=>true,"payments/simplepay"=>true,"billing/szamlazz"=>true,"billing/billingo"=>true,"shipping/mpl"=>true,"shipping/gls"=>true,"shipping/dpd"=>true,"shipping/packeta"=>true,"shipping/foxpost"=>true,"shipping/pickuppoints"=>true]);' >/dev/null 2>&1
    OUT="$("$WP" eval-file "$DIR/php/cross_lifecycle.php" 2>/dev/null | tail -1)"
    check_contains "T40 cross_lifecycle ok (mock-only)" '"ok":true' "$OUT"
  fi
fi

echo ""
echo "INTEGRATION SUMMARY: $PASS passed, $FAIL failed, $NOTRUN not run"
[[ $FAIL -eq 0 ]]
