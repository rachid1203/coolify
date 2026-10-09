<?php

namespace App\Livewire\Project;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Support\ProjectAccess;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Members extends Component
{
    use AuthorizesRequests;

    public ?string $projectUuid = null;

    public string $email = '';

    public bool $canCreateProjects = false;

    public string $selectedProjectUuid = '';

    public function mount(?string $projectUuid = null): void
    {
        $this->authorize('manageMembers', currentTeam());
        $this->projectUuid = $projectUuid;
        $this->selectedProjectUuid = $projectUuid ?? '';
    }

    public function add(): void
    {
        try {
            $this->authorize('manageMembers', currentTeam());
            $this->validate([
                'email' => ['required', 'email'],
                'canCreateProjects' => ['boolean'],
                'selectedProjectUuid' => ['required', 'string'],
            ]);

            ProjectAccess::addMember(
                $this->projectFor($this->selectedProjectUuid),
                $this->email,
                $this->canCreateProjects,
                auth()->user(),
            );
            $this->reset('email', 'canCreateProjects');
            $this->dispatch('success', 'Project member added.');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            handleError($exception, $this);
        }
    }

    public function remove(int $membershipId): void
    {
        try {
            $this->authorize('manageMembers', currentTeam());
            ProjectAccess::removeMember($this->membership($membershipId));
            $this->dispatch('success', 'Project member removed.');
        } catch (\Throwable $exception) {
            handleError($exception, $this);
        }
    }

    public function toggleCreateProjects(int $membershipId): void
    {
        try {
            $this->authorize('manageMembers', currentTeam());
            $membership = $this->membership($membershipId);
            $membership->update([
                'can_create_projects' => ! $membership->can_create_projects,
            ]);
            $this->dispatch('success', 'Project member updated.');
        } catch (\Throwable $exception) {
            handleError($exception, $this);
        }
    }

    public function render()
    {
        $projects = Project::query()->where('team_id', currentTeam()->id)->orderBy('name')->get(['id', 'uuid', 'name']);
        $memberships = ProjectMember::query()
            ->whereIn('project_id', $projects->pluck('id'))
            ->when($this->projectUuid, function ($query) use ($projects): void {
                $query->where('project_id', $projects->firstWhere('uuid', $this->projectUuid)?->id);
            })
            ->with(['user:id,name,email', 'project:id,uuid,name'])
            ->orderBy('id')
            ->get();

        return view('livewire.project.members', [
            'projects' => $projects,
            'memberships' => $memberships,
        ]);
    }

    private function projectFor(string $uuid): Project
    {
        return Project::query()
            ->where('team_id', currentTeam()->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function membership(int $membershipId): ProjectMember
    {
        return ProjectMember::query()
            ->whereKey($membershipId)
            ->whereIn('project_id', Project::query()->where('team_id', currentTeam()->id)->select('id'))
            ->firstOrFail();
    }
}
