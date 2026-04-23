<?php

declare(strict_types=1);

namespace App\Livewire\Inspector\Concerns;

use Illuminate\Support\Str;

/**
 * Standard save-tail every inspector form runs after it persists its
 * record: fire the two events the shell + picker components listen for.
 * Forms invoke `finalizeSave()` as the last line of their save()
 * method; if they also need persistAdminOwner() / persistTagList()
 * (from HasAdminPanel / HasTagList) to flush picker state, they call
 * those themselves right before finalizeSave().
 *
 * The `type` string the dispatched events carry is derived from the
 * form's class name (AccountForm → "account", TaxEstimatedPaymentForm
 * → "tax_estimated_payment"). Override `inspectorType()` if a form
 * diverges from that convention.
 */
trait FinalizesSave
{
    protected function finalizeSave(): void
    {
        $type = $this->inspectorType();
        $this->dispatch('inspector-saved', type: $type, id: $this->id);
        $this->dispatch('inspector-form-saved', type: $type, id: $this->id);
    }

    /**
     * Resolves AccountForm → "account", PhysicalMailForm →
     * "physical_mail", TaxEstimatedPaymentForm → "tax_estimated_payment".
     * Overridable for forms whose class name and shell-type diverge.
     */
    protected function inspectorType(): string
    {
        $class = class_basename(static::class);
        $stripped = (string) preg_replace('/Form$/', '', $class);

        return Str::snake($stripped);
    }
}
