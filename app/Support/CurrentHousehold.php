<?php

namespace App\Support;

use App\Models\Household;

class CurrentHousehold
{
    protected static ?Household $household = null;

    public static function set(?Household $household): void
    {
        static::$household = $household;
    }

    public static function get(): ?Household
    {
        if (static::$household) {
            return static::$household;
        }

        if ($user = auth()->user()) {
            return static::$household = $user->defaultHousehold;
        }

        return null;
    }

    public static function id(): ?int
    {
        return static::get()?->id;
    }

    public static function requireId(): int
    {
        $id = static::id();
        if ($id === null) {
            throw new \RuntimeException('No current household resolved.');
        }
        return $id;
    }
}
