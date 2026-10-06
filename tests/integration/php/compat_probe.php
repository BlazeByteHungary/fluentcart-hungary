<?php

use FluentCartHungary\Core\Admin\Diagnostics;
use FluentCartHungary\Core\Compat\FluentCartCompat;
use FluentCartHungary\Core\Database\Migrator;
use FluentCartHungary\Core\ModuleRegistry;

$probe = FluentCartCompat::probe();
$states = ModuleRegistry::instance()->states();

echo wp_json_encode([
    'fc_active' => $probe['active'],
    'fc_version' => $probe['version'],
    'core_state' => $states['core'] ?? null,
    'schema_installed' => Migrator::installedVersion(),
    'tables_ok' => Migrator::isInstalled(),
    'diagnostics_ok' => is_array(Diagnostics::data()),
]);
