<div>
    <form wire:submit="add" class="application-settings-form">
        <x-application.settings-section title="Project members"
            description="Give someone access to one project without adding them to the rest of the team.">
            <x-slot:actions>
                <x-forms.button type="submit">
                    <x-reicon name="plus" class="size-3.5" />
                    Add member
                </x-forms.button>
            </x-slot:actions>

            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input id="email" type="email" label="Email address" placeholder="teammate@example.com" required />
                @if ($projectUuid)
                    <input type="hidden" wire:model="selectedProjectUuid">
                @else
                    <x-forms.listbox id="selectedProjectUuid" label="Project" :options="$projects->map(fn ($project) => [
                        'value' => $project->uuid,
                        'label' => $project->name,
                    ])->values()->all()" />
                @endif
                <x-forms.checkbox id="canCreateProjects" label="Can create projects"
                    helper="Lets this person deploy their own applications by creating a new project on this team's servers. They become a member of that project only." />
            </div>
        </x-application.settings-section>
    </form>

    <x-application.settings-section title="{{ $projectUuid ? 'People on this project' : 'All project members' }}" class="mt-6">
        @if ($memberships->isEmpty())
            <x-empty size="sm" title="No project members"
                description="People added here can open this project and nothing else on the team." />
        @else
            <div class="data-table flex flex-col">
                <div class="data-table-header grid grid-cols-[1.2fr_1.4fr_1fr_auto_auto] gap-3 px-4 py-2 text-[11px] font-medium uppercase tracking-wide text-neutral-400 dark:text-fg-faint">
                    <span>Name</span>
                    <span>Email</span>
                    <span>Project</span>
                    <span>Own projects</span>
                    <span class="text-right">Actions</span>
                </div>
                @foreach ($memberships as $membership)
                    <div wire:key="project-member-{{ $membership->id }}"
                        class="grid grid-cols-[1.2fr_1.4fr_1fr_auto_auto] items-center gap-3 border-t border-neutral-200 px-4 py-2.5 text-[13px] dark:border-white/[0.08]">
                        <span class="truncate">{{ $membership->user?->name }}</span>
                        <span class="truncate text-neutral-500 dark:text-fg-dim">{{ $membership->user?->email }}</span>
                        <span class="truncate">{{ $membership->project?->name }}</span>
                        <span>{{ $membership->can_create_projects ? 'Allowed' : 'No' }}</span>
                        <span class="flex justify-end gap-2">
                            <button type="button" wire:click="toggleCreateProjects({{ $membership->id }})"
                                class="text-[12px] text-neutral-500 hover:text-black dark:text-fg-dim dark:hover:text-fg">
                                {{ $membership->can_create_projects ? 'Revoke create' : 'Allow create' }}
                            </button>
                            <button type="button" wire:click="remove({{ $membership->id }})"
                                class="text-[12px] text-red-600 hover:text-red-700">
                                Remove
                            </button>
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-application.settings-section>
</div>
