<?php

use FluentCartHungary\Core\Admin\AdminShell;

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
if ($admins === []) {
    echo wp_json_encode(['error' => 'no administrator user']);
    return;
}
$adminId = (int) $admins[0];

wp_set_current_user($adminId);
$adminAllowed = AdminShell::userCanAccess();
$adminResponse = rest_do_request(new \WP_REST_Request('GET', '/fch/v1/diagnostics'));
$adminStatus = $adminResponse->get_status();

ob_start();
\FluentCartHungary\Core\Admin\Diagnostics::render();
$rendered = (string) ob_get_clean();
// The workspace was renamed/rebranded ("Magyar integrációk"); assert on a stable marker instead:
// the plugin version rendered by the diagnostics summary (FCH_VERSION is language-neutral).
$renderOk = str_contains($rendered, (string) FCH_VERSION);

$sub = get_user_by('login', 'fch_subscriber');
if ($sub === false) {
    $subId = wp_insert_user([
        'user_login' => 'fch_subscriber',
        'user_pass' => wp_generate_password(24),
        'user_email' => 'fch_subscriber@example.invalid',
        'role' => 'subscriber',
    ]);
} else {
    $subId = (int) $sub->ID;
}

wp_set_current_user((int) $subId);
$subAllowed = AdminShell::userCanAccess();
$subResponse = rest_do_request(new \WP_REST_Request('GET', '/fch/v1/diagnostics'));
$subStatus = $subResponse->get_status();

wp_set_current_user($adminId);

echo wp_json_encode([
    'admin_allowed' => $adminAllowed,
    'admin_status' => $adminStatus,
    'render_ok' => $renderOk,
    'subscriber_allowed' => $subAllowed,
    'subscriber_status' => $subStatus,
]);
