<?php

namespace App\Policies;

use App\Models\EnvironmentVariable;
use App\Models\User;
use App\Support\ProjectAccess;

class EnvironmentVariablePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, EnvironmentVariable $environmentVariable): bool
    {
        $teamId = $this->getTeamId($environmentVariable);

        return ProjectAccess::allowsView($user, $teamId, $environmentVariable);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin() || ProjectAccess::canOperateInCurrentTeam($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, EnvironmentVariable $environmentVariable): bool
    {
        $teamId = $this->getTeamId($environmentVariable);

        return ProjectAccess::allowsManage($user, $teamId, $environmentVariable);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, EnvironmentVariable $environmentVariable): bool
    {
        $teamId = $this->getTeamId($environmentVariable);

        return ProjectAccess::allowsManage($user, $teamId, $environmentVariable);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, EnvironmentVariable $environmentVariable): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, EnvironmentVariable $environmentVariable): bool
    {
        return false;
    }

    /**
     * Determine whether the user can manage environment variables.
     */
    public function manageEnvironment(User $user, EnvironmentVariable $environmentVariable): bool
    {
        $teamId = $this->getTeamId($environmentVariable);

        return ProjectAccess::allowsManage($user, $teamId, $environmentVariable);
    }

    private function getTeamId(EnvironmentVariable $environmentVariable): ?int
    {
        $resource = $environmentVariable->resourceable;

        if (! $resource || ! method_exists($resource, 'team')) {
            return null;
        }

        return $resource->team()?->id;
    }
}
