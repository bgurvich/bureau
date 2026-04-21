<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

// Full end-to-end verification (real `backup:run` → extract → mysql restore)
// requires p7zip, mysqldump, and the mysql CLI on PATH plus a reachable
// MariaDB socket — too much for the test DB runner. Those moving parts are
// exercised by running `php artisan snapshots:verify-restore` manually in
// dev + the scheduled monthly run in prod. Here we just pin the wiring:
// the command is registered, the no-archive path fails cleanly, and the
// --archive override is honoured.

it('fails cleanly when no backup archive exists', function () {
    Storage::fake('local');

    $exitCode = Artisan::call('snapshots:verify-restore');

    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('No backup archive found');
});

it('fails when the --archive path does not exist', function () {
    $exitCode = Artisan::call('snapshots:verify-restore', [
        '--archive' => '/tmp/does-not-exist-'.uniqid().'.zip',
    ]);

    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('No backup archive found');
});
