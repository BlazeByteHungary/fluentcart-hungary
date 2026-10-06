<?php

use FluentCartHungary\Core\Jobs\JobInterface;
use FluentCartHungary\Core\Jobs\JobRunner;
use FluentCartHungary\Core\Jobs\JobSpec;

$autoDriver = (new JobRunner())->driverName();

update_option('fch_queue_driver', 'db', false);
$runner = new JobRunner();
$dbDriver = $runner->driverName();

$runner->registerHandler(new class implements JobInterface {
    public function hook(): string
    {
        return 'fch_test_job';
    }

    public function handle(array $args): void
    {
        update_option('fch_test_job_ran', $args['n'] ?? -1, false);
    }
});

$jobKey = 'fch:990900:refund:0:selftest';
$created = $runner->enqueue(new JobSpec($jobKey, 'fch_test_job', ['n' => 77], 5, 3, 'core'));
$executed = $runner->runDue(5);
$row = $runner->store()->findByKey($jobKey);

$result = [
    'auto_driver' => $autoDriver,
    'db_driver' => $dbDriver,
    'created' => $created,
    'executed' => $executed,
    'state' => $row ? (string) $row->state : null,
    'ran' => (int) get_option('fch_test_job_ran', -1),
];

delete_option('fch_test_job_ran');
update_option('fch_queue_driver', 'auto', false);

echo wp_json_encode($result);
