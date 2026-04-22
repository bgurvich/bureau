<?php

declare(strict_types=1);

namespace App\Livewire\Inspector\Concerns;

use App\Models\User;
use App\Support\CurrentHousehold;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;

/**
 * Admin panel (Owner picker + Created/Updated timestamps) for inspector
 * child components. Children override adminOwnerClass/adminOwnerField to
 * opt in; everything else — loadAdminMeta(), persistAdminOwner(),
 * householdUsers computed, admin_* state — is handled here. The admin
 * Blade partial reads these directly, so @include'ing it from the
 * extracted view just works.
 */
trait HasAdminPanel
{
    public ?int $admin_owner_id = null;

    public string $admin_created_at = '';

    public string $admin_updated_at = '';

    /** Class-string of the Eloquent model this inspector edits. */
    protected function adminOwnerClass(): ?string
    {
        return null;
    }

    /** Column on the model that stores the owner user id, or null for no-owner types. */
    protected function adminOwnerField(): ?string
    {
        return null;
    }

    /** @return array{0: class-string|null, 1: string|null} */
    public function adminModelMap(): array
    {
        return [$this->adminOwnerClass(), $this->adminOwnerField()];
    }

    protected function loadAdminMeta(): void
    {
        [$class, $userField] = $this->adminModelMap();
        if (! $class || ! $this->id) {
            return;
        }

        /** @var Model|null $model */
        $model = $class::find($this->id);
        if (! $model) {
            return;
        }

        $this->admin_owner_id = $userField ? ($model->getAttribute($userField) ?: null) : null;
        $created = $model->getAttribute('created_at');
        $this->admin_created_at = $created instanceof CarbonInterface ? $created->format('Y-m-d H:i') : '';
        $updated = $model->getAttribute('updated_at');
        $this->admin_updated_at = $updated instanceof CarbonInterface ? $updated->format('Y-m-d H:i') : '';
    }

    protected function persistAdminOwner(): void
    {
        if (! $this->id) {
            return;
        }

        [$class, $userField] = $this->adminModelMap();
        if (! $class || ! $userField) {
            return;
        }

        $newOwner = $this->admin_owner_id ?: null;

        /** @var Model|null $model */
        $model = $class::find($this->id);
        if (! $model) {
            return;
        }

        if ((int) ($model->getAttribute($userField) ?: 0) === (int) ($newOwner ?? 0)) {
            return;
        }

        $model->forceFill([$userField => $newOwner])->save();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function householdUsers(): Collection
    {
        $household = CurrentHousehold::get();
        if (! $household) {
            return new Collection;
        }

        /** @var Collection<int, User> $users */
        $users = $household->users()->orderBy('users.name')->get(['users.id', 'users.name']);

        return $users;
    }
}
