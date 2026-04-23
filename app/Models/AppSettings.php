<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton bag of app-wide key/value settings. One row (id=1) seeded
 * by the migration — never create a second. Use AppSettings::instance()
 * to fetch; never `AppSettings::create()` / ::find() by id.
 */
class AppSettings extends Model
{
    protected $table = 'app_settings';

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
    ];

    public static function instance(): self
    {
        return self::query()->firstOrCreate(['id' => 1], ['data' => []]);
    }
}
