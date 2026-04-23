<?php

namespace App\Support;

use App\Models\AppSettings;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Arr;

/**
 * Schema-driven settings accessor for the three scopes (app, household,
 * user). Schema lives in config/settings.php — including any env()
 * fallback values the config author wants to honour (env() is only
 * called from config files where it's config-cache-safe).
 *
 * Read order: DB bag value → schema default (which may itself inline
 *             an env() fallback) → the $default passed to get().
 *
 * Writes go to the DB bag only. Callers that need raw access can hit
 * the Model columns directly (AppSettings::instance()->data,
 * Household::data, User::settings).
 */
class Settings
{
    /**
     * Read a setting by scope + key. DB → schema default → caller's
     * $default, in that order.
     */
    public static function get(string $scope, string $key, mixed $default = null): mixed
    {
        $bag = self::bag($scope);
        if (array_key_exists($key, $bag)) {
            return $bag[$key];
        }

        $schema = self::schemaFor($scope, $key);
        if ($schema !== null && array_key_exists('default', $schema)) {
            return $schema['default'];
        }

        return $default;
    }

    /**
     * Write a single setting value to the DB bag for the scope. Silent
     * no-op when the scope's owner (household/user) isn't resolvable.
     */
    public static function set(string $scope, string $key, mixed $value): void
    {
        match ($scope) {
            'app' => self::writeApp($key, $value),
            'household' => self::writeHousehold($key, $value),
            'user' => self::writeUser($key, $value),
            default => null,
        };
    }

    /**
     * Replace the whole bag for a scope. Used by the editor's save path.
     *
     * @param  array<string, mixed>  $data
     */
    public static function replace(string $scope, array $data): void
    {
        match ($scope) {
            'app' => self::replaceApp($data),
            'household' => self::replaceHousehold($data),
            'user' => self::replaceUser($data),
            default => null,
        };
    }

    /**
     * Current bag for a scope — only what's been explicitly saved.
     * Defaults live in the schema, not in the bag.
     *
     * @return array<string, mixed>
     */
    public static function bag(string $scope): array
    {
        return match ($scope) {
            'app' => (array) (AppSettings::instance()->data ?? []),
            'household' => self::householdBag(),
            'user' => self::userBag(),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private static function householdBag(): array
    {
        $h = CurrentHousehold::get();
        if ($h === null) {
            return [];
        }

        return (array) ($h->data ?? []);
    }

    /** @return array<string, mixed> */
    private static function userBag(): array
    {
        $u = auth()->user();
        if ($u === null) {
            return [];
        }

        return (array) ($u->settings ?? []);
    }

    /**
     * Every setting definition for a scope, with an `effective` entry
     * added that reflects the current value after the read chain.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function schema(string $scope): array
    {
        $rows = (array) config("settings.{$scope}", []);
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['key'])) {
                continue;
            }
            $out[] = $row + [
                'effective' => self::get($scope, (string) $row['key']),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function schemaFor(string $scope, string $key): ?array
    {
        foreach ((array) config("settings.{$scope}", []) as $row) {
            if (is_array($row) && ($row['key'] ?? null) === $key) {
                return $row;
            }
        }

        return null;
    }

    private static function writeApp(string $key, mixed $value): void
    {
        $row = AppSettings::instance();
        $data = (array) ($row->data ?? []);
        Arr::set($data, $key, $value);
        $row->data = $data;
        $row->save();
    }

    private static function writeHousehold(string $key, mixed $value, ?Household $household = null): void
    {
        $household ??= CurrentHousehold::get();
        if ($household === null) {
            return;
        }
        $data = (array) ($household->data ?? []);
        Arr::set($data, $key, $value);
        $household->data = $data;
        $household->save();
    }

    private static function writeUser(string $key, mixed $value, ?User $user = null): void
    {
        $user ??= auth()->user();
        if ($user === null) {
            return;
        }
        $data = (array) ($user->settings ?? []);
        Arr::set($data, $key, $value);
        $user->setAttribute('settings', $data);
        $user->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function replaceApp(array $data): void
    {
        $row = AppSettings::instance();
        $row->data = $data;
        $row->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function replaceHousehold(array $data, ?Household $household = null): void
    {
        $household ??= CurrentHousehold::get();
        if ($household === null) {
            return;
        }
        $household->data = $data;
        $household->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function replaceUser(array $data, ?User $user = null): void
    {
        $user ??= auth()->user();
        if ($user === null) {
            return;
        }
        $user->setAttribute('settings', $data);
        $user->save();
    }
}
