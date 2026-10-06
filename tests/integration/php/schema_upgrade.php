<?php

use FluentCartHungary\Core\Database\Migrator;
use FluentCartHungary\Core\Database\Schema;

$before = Migrator::installedVersion();
$ran1 = Migrator::upgrade();
$v1 = Migrator::installedVersion();
$ran2 = Migrator::upgrade();
$v2 = Migrator::installedVersion();

$schemaOk = true;
$missing = [];
foreach (Schema::requiredIndexes() as $table => $indexes) {
    $present = Schema::indexNames($table);
    foreach ($indexes as $index) {
        if (!in_array($index, $present, true)) {
            $schemaOk = false;
            $missing[] = $table . ':' . $index;
        }
    }
}

echo wp_json_encode([
    'before' => $before,
    'target' => FCH_SCHEMA_VERSION,
    'ran1' => $ran1,
    'ran2' => $ran2,
    'v1' => $v1,
    'v2' => $v2,
    'idempotent' => $v1 === $v2 && $v2 === FCH_SCHEMA_VERSION,
    'schema_ok' => $schemaOk,
    'missing' => $missing,
]);
