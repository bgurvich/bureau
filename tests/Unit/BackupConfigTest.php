<?php

declare(strict_types=1);

it('keeps local-only disks when AWS_BUCKET is unset', function () {
    putenv('AWS_BUCKET=');
    $_ENV['AWS_BUCKET'] = '';

    $config = require base_path('config/backup.php');

    expect($config['backup']['destination']['disks'])
        ->toBe(['local']);
});

it('adds the s3 off-site disk when AWS_BUCKET is configured', function () {
    putenv('AWS_BUCKET=bureau-backups');
    $_ENV['AWS_BUCKET'] = 'bureau-backups';

    $config = require base_path('config/backup.php');

    expect($config['backup']['destination']['disks'])
        ->toEqualCanonicalizing(['local', 's3']);

    // Restore for any other test that touches env.
    putenv('AWS_BUCKET=');
    $_ENV['AWS_BUCKET'] = '';
});
