<?php

/**
 * Bureau-specific config. Right now it just carries seeder defaults so
 * DatabaseSeeder can read them from config instead of env() (larastan
 * flags env calls outside config files — and for good reason: env() is
 * null once config is cached).
 *
 * Extend this file as other Bureau-only toggles appear.
 */
return [
    'seed' => [
        'owner_email' => env('SEED_OWNER_EMAIL', 'owner@bureau.homes'),
        'owner_name' => env('SEED_OWNER_NAME', 'Owner'),
        'owner_password' => env('SEED_OWNER_PASSWORD', 'change-me'),
    ],
];
