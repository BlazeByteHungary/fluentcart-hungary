<?php

use FluentCartHungary\Core\Admin\Diagnostics;
use FluentCartHungary\Core\ModuleRegistry;

$states = ModuleRegistry::instance()->states();
$data = Diagnostics::data();

echo wp_json_encode([
    'core' => $states['core'] ?? null,
    'boom' => $states['boom'] ?? null,
    'has_boom' => isset($states['boom']),
    'diagnostics_ok' => is_array($data) && isset($data['modules']),
    'module_count' => count($data['modules']),
]);
