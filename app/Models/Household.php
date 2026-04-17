<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;

class Household extends Model implements Tenant, TenantWithDatabase
{
    use HasFactory, HasDatabase, HasDomains;

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
    ];

    public function getTenantKeyName(): string
    {
        return 'id';
    }

    public function getTenantKey()
    {
        return $this->id;
    }

    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'default_currency', 'created_at', 'updated_at'];
    }

    public function getInternal(string $key)
    {
        return $this->getAttribute($key);
    }

    public function setInternal(string $key, $value): static
    {
        $this->setAttribute($key, $value);
        return $this;
    }

    public function run(callable $callback)
    {
        return $callback($this);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'household_user')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }
}
