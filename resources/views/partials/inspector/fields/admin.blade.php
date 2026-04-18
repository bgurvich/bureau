{{-- Admin/metadata: collapsed by default. Only renders when editing an existing record. --}}
@if ($id)
    @php
        [$adminClass, $adminUserField] = $this->adminModelMap();
        $showOwner = $adminUserField !== null;
    @endphp
    @if ($showOwner || $admin_created_at || $admin_updated_at)
        <details class="text-xs">
            <summary class="cursor-pointer text-neutral-500 hover:text-neutral-300">{{ __('Admin') }}</summary>
            <div class="mt-3 space-y-3">
                @if ($showOwner)
                    <div>
                        <label for="i-admin-owner" class="mb-1 block text-xs text-neutral-400">{{ __('Owner') }}</label>
                        <select wire:model="admin_owner_id" id="i-admin-owner"
                                class="w-full rounded-md border border-neutral-700 bg-neutral-950 px-2 py-2 text-sm text-neutral-100 focus-visible:border-neutral-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-300">
                            <option value="">{{ __('Shared / no owner') }}</option>
                            @foreach ($this->householdUsers as $u)
                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-neutral-500">{{ __('Choose a household member or leave Shared. Transfers the record on save.') }}</p>
                    </div>
                @endif
                @if ($admin_created_at || $admin_updated_at)
                    <dl class="grid grid-cols-2 gap-3 text-[11px]">
                        @if ($admin_created_at)
                            <div>
                                <dt class="text-neutral-500">{{ __('Created') }}</dt>
                                <dd class="mt-0.5 tabular-nums text-neutral-300">{{ $admin_created_at }}</dd>
                            </div>
                        @endif
                        @if ($admin_updated_at)
                            <div>
                                <dt class="text-neutral-500">{{ __('Updated') }}</dt>
                                <dd class="mt-0.5 tabular-nums text-neutral-300">{{ $admin_updated_at }}</dd>
                            </div>
                        @endif
                    </dl>
                @endif
            </div>
        </details>
    @endif
@endif
