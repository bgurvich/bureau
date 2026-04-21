<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Proves the latest spatie/laravel-backup archive is restorable. Scheduled
 * monthly. Without this, "backups are running" is a lie you only discover
 * when you need one.
 *
 * Flow:
 *   1. Locate the newest .zip on the backup disk.
 *   2. Copy it to a temp dir, extract with 7z (AES-256 encrypted archive).
 *   3. Create a throwaway DB, pipe the SQL dump into it.
 *   4. Spot-check row counts in sentinel tables against production.
 *   5. Tear down the temp DB + files (unless --keep).
 *
 * Exits non-zero on any failure so the scheduler surfaces it through the
 * standard laravel-backup notification channels.
 */
class VerifyRestoreSnapshot extends Command
{
    protected $signature = 'snapshots:verify-restore
        {--archive= : Path to a specific archive; defaults to the newest on the backup disk}
        {--keep : Keep the throwaway DB + extracted files for manual inspection}
        {--strict : Fail on any row-count drift, even a single row}';

    protected $description = 'Restore the latest backup archive into a throwaway DB and verify row counts match production.';

    /**
     * Tables that must exist and be populated in both production and the
     * restored DB. Row-count drift beyond the tolerance fails the check.
     * Not every table — just the growth ones where "zero rows restored"
     * unambiguously means something's broken.
     *
     * @var string[]
     */
    private const SENTINEL_TABLES = [
        'households',
        'users',
        'accounts',
        'transactions',
        'contacts',
        'contracts',
        'documents',
        'tasks',
        'media',
        'recurring_rules',
    ];

    public function handle(): int
    {
        $this->info('Verifying latest backup archive…');

        $archivePath = $this->resolveArchive();
        if ($archivePath === null) {
            $this->error('No backup archive found.');
            $this->warn('Run `php artisan backup:run` first.');

            return self::FAILURE;
        }
        $this->line("  archive: {$archivePath}");

        // Everything lands under one temp root so `--keep` produces a tidy trail
        // and cleanup is a single deleteDirectory call.
        $tempRoot = storage_path('app/backup-verify-'.now()->format('Ymd-His'));
        File::makeDirectory($tempRoot, 0700, true);

        $verifyDb = 'bureau_verify_'.now()->format('YmdHis');
        $cleanup = function () use ($tempRoot, $verifyDb, &$cleanup): void {
            $cleanup = fn () => null; // idempotent — register once via trap
            try {
                $this->dropDatabase($verifyDb);
            } catch (Throwable) {
                // already gone
            }
            File::deleteDirectory($tempRoot);
        };

        try {
            $localArchive = $tempRoot.'/archive.zip';
            $this->copyArchiveLocally($archivePath, $localArchive);

            $this->extractArchive($localArchive, $tempRoot);
            $sqlDump = $this->locateSqlDump($tempRoot);
            $this->line("  dump:    {$sqlDump}");

            $this->createDatabase($verifyDb);
            $this->restoreDump($sqlDump, $verifyDb);

            $issues = $this->compareRowCounts($verifyDb);

            if ($issues !== []) {
                foreach ($issues as $issue) {
                    $this->error("  ✗ {$issue}");
                }

                if (! $this->option('keep')) {
                    $cleanup();
                }

                return self::FAILURE;
            }

            $this->info('  ✓ all sentinel tables restored with matching counts');
        } catch (Throwable $e) {
            $this->error('verify-restore failed: '.$e->getMessage());
            if (! $this->option('keep')) {
                $cleanup();
            }

            return self::FAILURE;
        }

        if ($this->option('keep')) {
            $this->warn("Kept verification DB `{$verifyDb}` and files at {$tempRoot} (--keep).");
        } else {
            $cleanup();
        }

        return self::SUCCESS;
    }

    private function resolveArchive(): ?string
    {
        $override = $this->option('archive');
        if (is_string($override) && $override !== '') {
            return file_exists($override) ? $override : null;
        }

        /** @var string[] $disks */
        $disks = config('backup.backup.destination.disks', ['local']);
        $diskName = $disks[0] ?? 'local';
        $backupName = (string) config('backup.backup.name', 'Bureau');

        $disk = Storage::disk($diskName);
        $files = collect($disk->allFiles($backupName))
            ->filter(fn (string $path) => str_ends_with($path, '.zip'))
            ->sortDesc()
            ->values();

        if ($files->isEmpty()) {
            return null;
        }

        /** @var string $remote */
        $remote = $files->first();

        // Normalise to an absolute local path — subsequent 7z and mysql calls
        // need a real filesystem path, not a flysystem-relative one.
        if (method_exists($disk, 'path')) {
            return $disk->path($remote);
        }

        // Remote disk (s3/b2) — stream into $tempRoot later. Return the
        // disk-relative path with a sentinel prefix so copyArchiveLocally
        // knows to fetch from the disk.
        return "disk://{$diskName}/{$remote}";
    }

    private function copyArchiveLocally(string $source, string $destination): void
    {
        if (str_starts_with($source, 'disk://')) {
            [, $rest] = explode('disk://', $source, 2);
            [$diskName, $path] = explode('/', $rest, 2);
            $stream = Storage::disk($diskName)->readStream($path);
            if ($stream === false || $stream === null) {
                throw new RuntimeException("Could not read archive from disk '{$diskName}': {$path}");
            }
            $dest = fopen($destination, 'wb');
            if ($dest === false) {
                throw new RuntimeException("Could not open {$destination} for writing.");
            }
            stream_copy_to_stream($stream, $dest);
            fclose($dest);
            fclose($stream);

            return;
        }

        if (! @copy($source, $destination)) {
            throw new RuntimeException("copy({$source} → {$destination}) failed.");
        }
    }

    private function extractArchive(string $archive, string $targetDir): void
    {
        $password = (string) config('backup.backup.password', '');

        $args = ['7z', 'x', '-y', '-o'.$targetDir];
        if ($password !== '') {
            // `-p<password>` must be a single argument with no space.
            $args[] = '-p'.$password;
        }
        $args[] = $archive;

        $process = new Process($args);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            // Strip the password from any error output that might echo it back.
            $err = str_replace($password, '***', $process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException("7z extract failed: {$err}");
        }
    }

    private function locateSqlDump(string $root): string
    {
        // spatie/db-dumper writes one .sql per connection under db-dumps/.
        $candidates = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'sql') {
                $candidates[] = $file->getPathname();
            }
        }
        if ($candidates === []) {
            throw new RuntimeException('No .sql file found inside the archive.');
        }

        return $candidates[0];
    }

    private function createDatabase(string $name): void
    {
        // Reject anything that isn't a safe identifier so the string
        // interpolation below can't produce a SQL-injection vector — the
        // name comes from our own timestamp, but this is cheap insurance.
        if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new RuntimeException("Unsafe verify DB name: {$name}");
        }
        DB::statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private function dropDatabase(string $name): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            return;
        }
        DB::statement("DROP DATABASE IF EXISTS `{$name}`");
    }

    private function restoreDump(string $sqlFile, string $verifyDb): void
    {
        $conn = (string) config('database.default');
        $cfg = (array) config("database.connections.{$conn}");

        // Shell out to `mysql` for the import. Reading the whole dump into PHP
        // and pushing via PDO::exec chokes on anything over a few MB; piping
        // through the CLI is the canonical restore path anyway.
        //
        // Credentials flow through MYSQL_PWD to keep them off the argv
        // visible in `ps`; mysql(1) accepts it and emits a soft warning on
        // stderr which we suppress by redirecting to a temp file.
        $args = [
            'mysql',
            '--host='.($cfg['host'] ?? '127.0.0.1'),
            '--port='.($cfg['port'] ?? '3306'),
            '--user='.($cfg['username'] ?? 'root'),
            '--default-character-set=utf8mb4',
            $verifyDb,
        ];

        $process = Process::fromShellCommandline(
            implode(' ', array_map('escapeshellarg', $args)).' < '.escapeshellarg($sqlFile)
        );
        $process->setEnv(['MYSQL_PWD' => (string) ($cfg['password'] ?? '')]);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysql restore failed: '.$process->getErrorOutput());
        }
    }

    /**
     * @return string[] list of human-readable issues; empty array = pass
     */
    private function compareRowCounts(string $verifyDb): array
    {
        $conn = (string) config('database.default');
        $cfg = (array) config("database.connections.{$conn}");

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $cfg['host'] ?? '127.0.0.1',
            $cfg['port'] ?? '3306',
            $verifyDb,
        );
        try {
            $verify = new PDO($dsn, (string) ($cfg['username'] ?? ''), (string) ($cfg['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Could not connect to verify DB: '.$e->getMessage());
        }

        // Drift tolerance: backup was taken at a single moment; production
        // keeps accepting writes after. 1% above the restored count is
        // generous; --strict collapses the tolerance to zero.
        $tolerancePct = $this->option('strict') ? 0.0 : 1.0;

        $issues = [];
        foreach (self::SENTINEL_TABLES as $table) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                continue;
            }
            $prod = (int) DB::table($table)->count();

            $stmt = $verify->query("SELECT COUNT(*) FROM `{$table}`");
            if ($stmt === false) {
                $issues[] = "{$table}: COUNT(*) query failed on verify DB";

                continue;
            }
            $restored = (int) $stmt->fetchColumn();

            if ($prod > 0 && $restored === 0) {
                $issues[] = "{$table}: production has {$prod} rows, restored has 0";

                continue;
            }

            if ($prod === 0) {
                continue; // nothing to compare
            }

            $maxDrift = max((int) ceil(($tolerancePct / 100) * $prod), 0);
            $drift = $prod - $restored;

            // Only negative drift (restored < production) is concerning —
            // post-backup writes naturally grow production. Positive drift
            // (restored > production) would mean rows vanished post-dump,
            // which is suspicious and always worth flagging.
            if ($drift < 0) {
                $issues[] = "{$table}: restored ({$restored}) exceeds production ({$prod}) — rows deleted since dump?";
            } elseif ($drift > $maxDrift) {
                $issues[] = "{$table}: restored {$restored} vs production {$prod} (drift {$drift} > tolerance {$maxDrift})";
            }
        }

        return $issues;
    }
}
