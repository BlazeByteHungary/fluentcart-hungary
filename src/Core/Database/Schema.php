<?php
declare(strict_types=1);

namespace FluentCartHungary\Core\Database;

/**
 * Exact DDL for the plugin tables (05_DATA_SCHEMA §5.1–§5.6). Engine InnoDB, utf8mb4/utf8mb4_unicode_ci.
 * Money is integer minor units (BIGINT); no floats anywhere. Schema v2 adds the W1 mirrors:
 * fch_payment_attempts, fch_invoice_documents, fch_shipments, fch_webhook_events.
 */
final class Schema
{
    public const TABLES = [
        'fch_external_operations',
        'fch_payment_attempts',
        'fch_invoice_documents',
        'fch_shipments',
        'fch_webhook_events',
        'fch_jobs',
    ];

    public static function table(string $base): string
    {
        global $wpdb;
        return $wpdb->prefix . $base;
    }

    public static function tableExists(string $table): bool
    {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $found === $table;
    }

    /** @return list<string> */
    public static function indexNames(string $table): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $names = [];
        foreach ($rows as $row) {
            if (isset($row['Key_name'])) {
                $names[] = (string) $row['Key_name'];
            }
        }
        return array_values(array_unique($names));
    }

    public static function createExternalOperations(): string
    {
        $table = self::table('fch_external_operations');
        return "CREATE TABLE IF NOT EXISTS `{$table}` (
  id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  idempotency_key           VARCHAR(191)    NOT NULL,
  order_id                  BIGINT UNSIGNED NULL,
  order_uuid                VARCHAR(100)    NULL,
  module                    VARCHAR(16)     NOT NULL,
  provider                  VARCHAR(32)     NOT NULL,
  kind                      VARCHAR(40)     NOT NULL,
  owner_type                VARCHAR(24)     NULL,
  owner_id                  BIGINT UNSIGNED NULL,
  state                     VARCHAR(32)     NOT NULL DEFAULT 'NOT_STARTED',
  local_error_code          VARCHAR(64)     NULL,
  local_error_message       TEXT            NULL,
  mode                      VARCHAR(8)      NOT NULL,
  account_id                VARCHAR(64)     NOT NULL,
  attempt_count             INT UNSIGNED    NOT NULL DEFAULT 0,
  max_attempts              SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  next_retry_at             DATETIME        NULL,
  external_id               VARCHAR(191)    NULL,
  external_ref2             VARCHAR(191)    NULL,
  business_reference        VARCHAR(191)    NULL,
  currency                  CHAR(3)         NULL,
  amount_minor              BIGINT          NULL,
  request_fingerprint       CHAR(64)        NULL,
  order_fin_snapshot_hash   CHAR(64)        NULL,
  request_payload_redacted  MEDIUMTEXT      NULL,
  response_payload_redacted MEDIUMTEXT      NULL,
  uncertain_reason          VARCHAR(64)     NULL,
  lease_owner               VARCHAR(64)     NULL,
  lease_expires_at          DATETIME        NULL,
  reconciled_at             DATETIME        NULL,
  resolved_by               BIGINT UNSIGNED NULL,
  resolved_note             TEXT            NULL,
  superseded_by_id          BIGINT UNSIGNED NULL,
  created_at                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fch_extop_idem (idempotency_key),
  KEY idx_fch_extop_order_kind_state (order_id, kind, state),
  KEY idx_fch_extop_provider_state (provider, state, mode),
  KEY idx_fch_extop_retry (state, next_retry_at),
  KEY idx_fch_extop_lease (state, lease_expires_at),
  KEY idx_fch_extop_external (provider, mode, external_id),
  KEY idx_fch_extop_owner (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    public static function createPaymentAttempts(): string
    {
        $table = self::table('fch_payment_attempts');
        return "CREATE TABLE IF NOT EXISTS `{$table}` (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id              BIGINT UNSIGNED NOT NULL,
  order_uuid            VARCHAR(100)    NULL,
  provider              VARCHAR(32)     NOT NULL,
  mode                  VARCHAR(8)      NOT NULL,
  account_id            VARCHAR(64)     NOT NULL,
  attempt_no            INT UNSIGNED    NOT NULL,
  idempotency_key       VARCHAR(191)    NOT NULL,
  operation_id          BIGINT UNSIGNED NULL,
  state                 VARCHAR(32)     NOT NULL DEFAULT 'NOT_STARTED',
  payment_type          VARCHAR(24)     NULL,
  external_payment_id   VARCHAR(100)    NULL,
  external_request_id   VARCHAR(100)    NULL,
  external_ref2         VARCHAR(191)    NULL,
  redirect_url          TEXT            NULL,
  callback_url          TEXT            NULL,
  amount_minor          BIGINT          NOT NULL,
  charged_amount_minor  BIGINT          NULL,
  refunded_amount_minor BIGINT          NOT NULL DEFAULT 0,
  currency              CHAR(3)         NOT NULL,
  raw_state             VARCHAR(48)     NULL,
  verification_source   VARCHAR(24)     NULL,
  verified_at           DATETIME        NULL,
  started_at            DATETIME        NULL,
  completed_at          DATETIME        NULL,
  expires_at            DATETIME        NULL,
  last_state_check_at   DATETIME        NULL,
  created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fch_pay_attempt (order_id, provider, mode, account_id, attempt_no),
  UNIQUE KEY uq_fch_pay_idem (idempotency_key),
  KEY idx_fch_pay_ext (provider, mode, external_payment_id),
  KEY idx_fch_pay_ref (provider, mode, external_request_id),
  KEY idx_fch_pay_recon (state, last_state_check_at),
  KEY idx_fch_pay_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    public static function createInvoiceDocuments(): string
    {
        $table = self::table('fch_invoice_documents');
        return "CREATE TABLE IF NOT EXISTS `{$table}` (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id                   BIGINT UNSIGNED NOT NULL,
  order_uuid                 VARCHAR(100)    NULL,
  provider                   VARCHAR(32)     NOT NULL,
  mode                       VARCHAR(8)      NOT NULL,
  account_id                 VARCHAR(64)     NOT NULL,
  doc_role                   VARCHAR(32)     NOT NULL,
  provider_doc_type          VARCHAR(48)     NULL,
  sequence_no                INT UNSIGNED    NOT NULL,
  idempotency_key            VARCHAR(191)    NOT NULL,
  vendor_id                  VARCHAR(191)    NULL,
  external_id                VARCHAR(100)    NULL,
  external_ref2              VARCHAR(191)    NULL,
  operation_id               BIGINT UNSIGNED NULL,
  state                      VARCHAR(32)     NOT NULL DEFAULT 'NOT_STARTED',
  document_number            VARCHAR(100)    NULL,
  gross_minor                BIGINT          NULL,
  net_minor                  BIGINT          NULL,
  vat_minor                  BIGINT          NULL,
  currency                   CHAR(3)         NULL,
  vat_snapshot               JSON            NULL,
  order_fin_snapshot_hash    CHAR(64)        NULL,
  is_selected_invoicer       TINYINT(1)      NOT NULL DEFAULT 0,
  selected_invoicer_provider VARCHAR(32)     NULL,
  auto_doc_guard             VARCHAR(191) GENERATED ALWAYS AS (
                               IF(is_selected_invoicer = 1
                                  AND state NOT IN ('PROVEN_REJECTED','SUPERSEDED','MANUALLY_RESOLVED'),
                                  CONCAT(order_id, ':', doc_role), NULL)
                             ) STORED,
  storno_of_document_id      BIGINT UNSIGNED NULL,
  issued_at                  DATETIME        NULL,
  fulfillment_date           DATE            NULL,
  due_date                   DATE            NULL,
  pdf_ref                    VARCHAR(255)    NULL,
  pdf_sha256                 CHAR(64)        NULL,
  created_at                 DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fch_inv_idem (idempotency_key),
  UNIQUE KEY uq_fch_inv_seq (order_id, doc_role, provider, mode, account_id, sequence_no),
  UNIQUE KEY uq_fch_inv_auto_guard (auto_doc_guard),
  KEY idx_fch_inv_vendor (provider, mode, vendor_id),
  KEY idx_fch_inv_number (provider, mode, document_number),
  KEY idx_fch_inv_order (order_id, is_selected_invoicer),
  KEY idx_fch_inv_storno (storno_of_document_id),
  KEY idx_fch_inv_state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    public static function createShipments(): string
    {
        $table = self::table('fch_shipments');
        return "CREATE TABLE IF NOT EXISTS `{$table}` (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id             BIGINT UNSIGNED NOT NULL,
  order_uuid           VARCHAR(100)    NULL,
  carrier              VARCHAR(32)     NOT NULL,
  mode                 VARCHAR(8)      NOT NULL,
  account_id           VARCHAR(64)     NOT NULL,
  shipment_no          INT UNSIGNED    NOT NULL,
  idempotency_key      VARCHAR(191)    NOT NULL,
  operation_id         BIGINT UNSIGNED NULL,
  state                VARCHAR(32)     NOT NULL DEFAULT 'NOT_STARTED',
  label_state          VARCHAR(16)     NOT NULL DEFAULT 'NONE',
  external_shipment_id VARCHAR(100)    NULL,
  external_ref2        VARCHAR(191)    NULL,
  external_ref3        VARCHAR(191)    NULL,
  barcode              VARCHAR(64)     NULL,
  tracking_number      VARCHAR(100)    NULL,
  carrier_reference    VARCHAR(191)    NULL,
  label_ref            VARCHAR(255)    NULL,
  label_sha256         CHAR(64)        NULL,
  parcels              JSON            NULL,
  items                JSON            NULL,
  package_count        INT UNSIGNED    NOT NULL DEFAULT 1,
  pickup_point         JSON            NULL,
  cod_amount_minor     BIGINT          NULL,
  currency             CHAR(3)         NULL,
  cancelled_at         DATETIME        NULL,
  created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fch_ship_idem (idempotency_key),
  UNIQUE KEY uq_fch_ship_no (order_id, carrier, mode, account_id, shipment_no),
  KEY idx_fch_ship_tracking (carrier, mode, tracking_number),
  KEY idx_fch_ship_barcode (carrier, mode, barcode),
  KEY idx_fch_ship_ext (carrier, mode, external_shipment_id),
  KEY idx_fch_ship_state (state),
  KEY idx_fch_ship_label (label_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    public static function createWebhookEvents(): string
    {
        $table = self::table('fch_webhook_events');
        return "CREATE TABLE IF NOT EXISTS `{$table}` (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider         VARCHAR(32)     NOT NULL,
  mode             VARCHAR(8)      NOT NULL,
  account_id       VARCHAR(64)     NOT NULL,
  dedup_key        VARCHAR(191)    NOT NULL,
  payload_hash     CHAR(64)        NOT NULL,
  raw_body         MEDIUMTEXT      NULL,
  headers_json     JSON            NULL,
  signature_valid  TINYINT(1)      NULL,
  event_type       VARCHAR(64)     NULL,
  external_ref     VARCHAR(191)    NULL,
  order_id         BIGINT UNSIGNED NULL,
  state            VARCHAR(16)     NOT NULL DEFAULT 'RECEIVED',
  process_attempts INT UNSIGNED    NOT NULL DEFAULT 0,
  last_error       TEXT            NULL,
  received_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at     DATETIME        NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fch_wh_dedup (provider, mode, account_id, dedup_key),
  KEY idx_fch_wh_state (state, received_at),
  KEY idx_fch_wh_order (order_id),
  KEY idx_fch_wh_ref (provider, external_ref),
  KEY idx_fch_wh_hash (provider, payload_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    public static function createJobs(): string
    {
        $table = self::table('fch_jobs');
        return "CREATE TABLE IF NOT EXISTS `{$table}` (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_key          VARCHAR(191)    NOT NULL,
  hook             VARCHAR(191)    NOT NULL,
  args_json        JSON            NULL,
  state            VARCHAR(16)     NOT NULL DEFAULT 'PENDING',
  priority         SMALLINT        NOT NULL DEFAULT 10,
  attempts         INT UNSIGNED    NOT NULL DEFAULT 0,
  max_attempts     INT UNSIGNED    NOT NULL DEFAULT 5,
  available_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lease_owner      VARCHAR(64)     NULL,
  lease_expires_at DATETIME        NULL,
  last_error       TEXT            NULL,
  scheduled_by     VARCHAR(64)     NULL,
  completed_at     DATETIME        NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fch_jobs_key (job_key),
  KEY idx_fch_jobs_claim (state, available_at, priority),
  KEY idx_fch_jobs_lease (state, lease_expires_at),
  KEY idx_fch_jobs_hook (hook)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    /** @return array<string,list<string>> table => required index names */
    public static function requiredIndexes(): array
    {
        return [
            self::table('fch_external_operations') => [
                'PRIMARY',
                'uq_fch_extop_idem',
                'idx_fch_extop_order_kind_state',
                'idx_fch_extop_provider_state',
                'idx_fch_extop_retry',
                'idx_fch_extop_lease',
                'idx_fch_extop_external',
                'idx_fch_extop_owner',
            ],
            self::table('fch_payment_attempts') => [
                'PRIMARY',
                'uq_fch_pay_attempt',
                'uq_fch_pay_idem',
                'idx_fch_pay_ext',
                'idx_fch_pay_ref',
                'idx_fch_pay_recon',
                'idx_fch_pay_order',
            ],
            self::table('fch_invoice_documents') => [
                'PRIMARY',
                'uq_fch_inv_idem',
                'uq_fch_inv_seq',
                'uq_fch_inv_auto_guard',
                'idx_fch_inv_vendor',
                'idx_fch_inv_number',
                'idx_fch_inv_order',
                'idx_fch_inv_storno',
                'idx_fch_inv_state',
            ],
            self::table('fch_shipments') => [
                'PRIMARY',
                'uq_fch_ship_idem',
                'uq_fch_ship_no',
                'idx_fch_ship_tracking',
                'idx_fch_ship_barcode',
                'idx_fch_ship_ext',
                'idx_fch_ship_state',
                'idx_fch_ship_label',
            ],
            self::table('fch_webhook_events') => [
                'PRIMARY',
                'uq_fch_wh_dedup',
                'idx_fch_wh_state',
                'idx_fch_wh_order',
                'idx_fch_wh_ref',
                'idx_fch_wh_hash',
            ],
            self::table('fch_jobs') => [
                'PRIMARY',
                'uq_fch_jobs_key',
                'idx_fch_jobs_claim',
                'idx_fch_jobs_lease',
                'idx_fch_jobs_hook',
            ],
        ];
    }
}
