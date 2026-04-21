<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Support\CurrentHousehold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RescanMediaFolders extends Command
{
    protected $signature = 'media:rescan
        {--household= : Restrict to a single household id}
        {--disk=local : Filesystem disk the folder paths resolve against}';

    protected $description = 'Walk each MediaFolder.path and upsert Media rows for files found. Idempotent — existing rows by (disk, path) are left alone.';

    public function handle(): int
    {
        $diskName = (string) $this->option('disk');
        $householdFilter = $this->option('household');

        $disk = Storage::disk($diskName);

        $households = Household::query()
            ->when($householdFilter, fn ($q) => $q->where('id', $householdFilter))
            ->get();

        $created = 0;
        $skipped = 0;
        $scanned = 0;

        foreach ($households as $household) {
            CurrentHousehold::set($household);

            $folders = MediaFolder::where('active', true)->get();
            foreach ($folders as $folder) {
                if (! $disk->directoryExists($folder->path)) {
                    $this->warn("  {$household->name} · {$folder->path}: directory missing on {$diskName} — skipping");

                    continue;
                }

                foreach ($disk->allFiles($folder->path) as $relativePath) {
                    $scanned++;

                    // Skip internal backups / dumps
                    if (str_starts_with($relativePath, 'Laravel/')
                        || str_starts_with($relativePath, 'backups/')) {
                        continue;
                    }

                    if (Media::where('disk', $diskName)->where('path', $relativePath)->exists()) {
                        $skipped++;

                        continue;
                    }

                    $mime = $disk->mimeType($relativePath) ?: null;
                    $size = (int) ($disk->size($relativePath) ?: 0);

                    Media::create([
                        'folder_id' => $folder->id,
                        'disk' => $diskName,
                        'source' => 'folder',
                        'path' => $relativePath,
                        'original_name' => basename($relativePath),
                        'mime' => $mime,
                        'size' => $size,
                        'captured_at' => now(),
                        // Queue images for OCR if they look like docs. Inventory-style
                        // photos skip — they're attached via capture flow, not rescan.
                        'ocr_status' => $mime && str_starts_with($mime, 'image/') ? 'pending' : null,
                    ]);
                    $created++;
                }

                $folder->forceFill(['last_scanned_at' => now()])->save();
            }
        }

        $this->info("  Scanned {$scanned} files — created {$created}, skipped {$skipped} already-known.");

        return self::SUCCESS;
    }
}
