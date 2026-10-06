<?php
/**
 * Uninstall handler (05_DATA_SCHEMA §10.4). Non-destructive by default: tables and rows are kept unless
 * the explicit `fch_remove_data_on_uninstall` opt-in is enabled.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$options = [
    'fch_schema_version',
    'fch_schema_state',
    'fch_modules',
    'fch_queue_driver',
    'fch_remove_data_on_uninstall',
    'fch_credentials_v1',
    'fch_plugin_first_activated',
];

foreach ($options as $option) {
    delete_option($option);
}

$removeData = (bool) get_option('fch_remove_data_on_uninstall', false);

if ($removeData) {
    global $wpdb;
    foreach (['fch_external_operations', 'fch_payment_attempts', 'fch_invoice_documents', 'fch_shipments', 'fch_webhook_events', 'fch_jobs'] as $base) {
        $table = $wpdb->prefix . $base;
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }
}
