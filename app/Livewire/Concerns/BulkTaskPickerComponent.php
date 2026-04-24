<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Livewire\Component;

/**
 * Base class for any Livewire component that needs the bulk-task
 * Goal + Project pickers. Exists so PHPStan sees the trait as
 * actually consumed — the real consumers are Volt SFCs (tasks-index,
 * tasks-bulk-modal, mobile tasks-bulk) that aren't in PHPStan's
 * scan path. Keeping a concrete class here means `trait.unused`
 * doesn't fire on scans that only see app/.
 */
abstract class BulkTaskPickerComponent extends Component
{
    use BulkTaskPickers;
}
