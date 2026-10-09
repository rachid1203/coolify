<?php

namespace App\Support;

use App\Models\Application;
use App\Models\CloudProviderToken;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\IntegrationToken;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\S3Storage;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\StandaloneSqlite;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use WeakMap;

class ProjectAccess
{
    /** @var WeakMap<User, Collection<int, int>>|null */
    private static ?WeakMap $projectIds = null;

    /** @var WeakMap<User, Collection<int, int>>|null */
    private static ?WeakMap $teamIds = null;

    public static function register(): void
    {
        Project::addGlobalScope('projectMembership', function (Builder $builder): void {
            self::restrictColumn($builder, 'id');
        });

        Environment::addGlobalScope('projectMembership', function (Builder $builder): void {
            self::restrictColumn($builder, 'project_id');
        });

        foreach (self::environmentResources() as $model) {
            $model::addGlobalScope('projectMembership', function (Builder $builder): void {
                self::restrictEnvironment($builder);
            });
        }

        foreach ([ServiceApplication::class, ServiceDatabase::class] as $model) {
            $model::addGlobalScope('projectMembership', function (Builder $builder): void {
                self::restrictViaService($builder);
            });
        }

        foreach ([PrivateKey::class, S3Storage::class, CloudProviderToken::class, IntegrationToken::class, GithubApp::class, GitlabApp::class] as $model) {
            $model::addGlobalScope('projectMembership', function (Builder $builder): void {
                if (! self::restricts(auth()->user())) {
                    return;
                }

                $builder->whereRaw('0 = 1');
            });
        }

        Service::saving(function (Service $service): void {
            if (! auth()->user()?->isProjectScoped()) {
                return;
            }

            if ($service->is_container_label_escape_enabled) {
                throw ValidationException::withMessages([
                    'dockerComposeRaw' => 'Project members cannot enable container label escape.',
                ]);
            }

            self::assertComposeIsSafe($service->docker_compose_raw);
            self::assertComposeIsSafe($service->docker_compose);
        });

        LocalPersistentVolume::saving(function (LocalPersistentVolume $volume): void {
            if (! auth()->user()?->isProjectScoped() || blank($volume->host_path)) {
                return;
            }

            throw ValidationException::withMessages([
                'host_path' => 'Project members cannot bind host paths.',
            ]);
        });

        LocalFileVolume::saving(function (LocalFileVolume $volume): void {
            if (! auth()->user()?->isProjectScoped()) {
                return;
            }

            throw ValidationException::withMessages([
                'fs_path' => 'Project members cannot mount files from the server.',
            ]);
        });

        Livewire::componentHook(ProjectScopedComponentGuard::class);
    }

    public static function restricts(?User $user): bool
    {
        if ($user === null || $user->getKey() === null || $user->isInstanceAdmin()) {
            return false;
        }

        if ($user->projectMembershipProjectIds()->isEmpty()) {
            return false;
        }

        $team = $user->currentTeam();
        if ($team === null) {
            return false;
        }

        return ! $user->teams->contains('id', $team->id);
    }

    /**
     * @return Collection<int, int>
     */
    public static function projectIds(User $user): Collection
    {
        $cache = self::$projectIds ??= new WeakMap;
        if (isset($cache[$user])) {
            return $cache[$user];
        }

        if ($user->getKey() === null) {
            return $cache[$user] = collect();
        }

        return $cache[$user] = DB::table('project_members')
            ->where('user_id', $user->id)
            ->pluck('project_id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    public static function teamIds(User $user): Collection
    {
        $cache = self::$teamIds ??= new WeakMap;
        if (isset($cache[$user])) {
            return $cache[$user];
        }

        if ($user->getKey() === null) {
            return $cache[$user] = collect();
        }

        return $cache[$user] = DB::table('project_members')
            ->join('projects', 'projects.id', '=', 'project_members.project_id')
            ->where('project_members.user_id', $user->id)
            ->distinct()
            ->pluck('projects.team_id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    public static function flushUserId(int $userId): void
    {
        foreach ([self::$projectIds, self::$teamIds] as $cache) {
            if ($cache === null) {
                continue;
            }

            $stale = [];
            foreach ($cache as $user => $ids) {
                if ((int) $user->id === $userId) {
                    $stale[] = $user;
                }
            }

            foreach ($stale as $user) {
                unset($cache[$user]);
            }
        }
    }

    public static function allowsView(User $user, ?int $teamId, ?object $resource = null): bool
    {
        if ($teamId !== null && $user->teams->contains('id', $teamId)) {
            return true;
        }

        return self::isMemberOfResource($user, $resource);
    }

    public static function allowsManage(User $user, ?int $teamId, ?object $resource = null): bool
    {
        if ($teamId !== null && $user->isAdminOfTeam($teamId)) {
            return true;
        }

        return self::isMemberOfResource($user, $resource);
    }

    public static function canOperateInCurrentTeam(User $user): bool
    {
        if ($user->getKey() === null) {
            return false;
        }

        $team = $user->currentTeam();
        if ($team === null) {
            return false;
        }

        return $user->hasProjectOnTeam((int) $team->id);
    }

    public static function isMemberOfResource(User $user, ?object $resource): bool
    {
        if ($user->getKey() === null || $resource === null) {
            return false;
        }

        $projectId = self::projectIdFrom($resource);
        if ($projectId === null) {
            return false;
        }

        return $user->projectMembershipProjectIds()->contains($projectId);
    }

    public static function addMember(Project $project, string $email, bool $canCreateProjects, User $actor): User
    {
        if (! $actor->can('manageMembers', $project->team)) {
            throw new AuthorizationException;
        }

        $email = strtolower(trim($email));
        $user = User::query()->where('email', $email)->first();

        if ($user && $user->teams()->where('teams.id', $project->team_id)->exists()) {
            throw ValidationException::withMessages([
                'email' => $email.' already has access to every project on this team.',
            ]);
        }

        if ($user && $project->members()->where('users.id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => $email.' is already a member of this project.',
            ]);
        }

        if ($user === null) {
            $user = User::query()->create([
                'name' => str($email)->before('@')->toString(),
                'email' => $email,
                'password' => Hash::make(Str::password()),
                'force_password_reset' => true,
            ]);
        }

        $project->members()->attach($user->id, [
            'can_create_projects' => $canCreateProjects,
        ]);
        self::flushUserId((int) $user->id);
        self::preferTeam($user, $project->team);
        self::notify($user, $project);

        return $user;
    }

    public static function removeMember(ProjectMember $membership): void
    {
        $userId = (int) $membership->user_id;
        $teamId = (int) $membership->project()->value('team_id');
        $membership->delete();
        self::flushUserId($userId);

        $user = User::query()->find($userId);
        if ($user && ! $user->hasProjectOnTeam($teamId) && (int) $user->current_team_id === $teamId) {
            $user->clearStoredTeamIfMatches($teamId);
        }
    }

    public static function grantCreatedProject(Project $project, ?User $user = null): void
    {
        $user ??= auth()->user();
        if ($user === null || $user->isAdminOfTeam((int) $project->team_id)) {
            return;
        }

        if (! $user->canCreateProjectsOnTeam((int) $project->team_id)) {
            return;
        }

        $project->members()->syncWithoutDetaching([
            $user->id => ['can_create_projects' => true],
        ]);
        self::flushUserId((int) $user->id);
    }

    /**
     * @return EloquentCollection<int, User>
     */
    public static function membersForApi(Team $team): EloquentCollection
    {
        $members = $team->members->each(function (User $member): void {
            $member->setAttribute('membership', 'team');
            $member->setAttribute('role', $member->pivot->role ?? null);
        });
        $members->makeHidden([
            'pivot',
            'email_change_code',
            'email_change_code_expires_at',
        ]);

        $known = $members->pluck('id');
        $extra = new EloquentCollection;
        $memberships = ProjectMember::query()
            ->whereIn('project_id', $team->projects()->select('projects.id'))
            ->with(['user', 'project:id,uuid,name'])
            ->get()
            ->groupBy('user_id');

        foreach ($memberships as $userId => $rows) {
            if ($known->contains($userId)) {
                continue;
            }

            $user = $rows->first()->user;
            if ($user === null) {
                continue;
            }

            $user->setAttribute('membership', 'project');
            $user->setAttribute('role', 'project');
            $user->setAttribute('projects', $rows->map(fn (ProjectMember $row): array => [
                'uuid' => $row->project->uuid,
                'name' => $row->project->name,
                'can_create_projects' => (bool) $row->can_create_projects,
            ])->values()->all());
            $user->makeHidden([
                'pivot',
                'email_change_code',
                'email_change_code_expires_at',
            ]);
            $extra->push($user);
        }

        return new EloquentCollection($members->concat($extra)->all());
    }

    public static function assertComposeIsSafe(?string $compose): void
    {
        if (blank($compose)) {
            return;
        }

        $unsafe = preg_match('/docker\.sock/i', $compose) === 1
            || preg_match('/^\s*-\s+[\'"]?(?:\/|\.\.?\/)/m', $compose) === 1
            || preg_match('/^\s*(privileged:\s*true|pid:\s*[\'"]?host|network_mode:\s*[\'"]?host)\b/mi', $compose) === 1;

        if ($unsafe) {
            throw ValidationException::withMessages([
                'dockerComposeRaw' => 'Project members cannot mount the Docker socket, bind host paths, or use privileged host settings.',
            ]);
        }
    }

    private static function preferTeam(User $user, Team $team): void
    {
        $personal = $user->teams()->where('personal_team', true)->first();
        $pointsAtEmptyPersonal = $personal
            && (int) $user->current_team_id === (int) $personal->id
            && ! $personal->projects()->exists();

        if ($user->current_team_id !== null && ! $pointsAtEmptyPersonal) {
            return;
        }

        $user->forceFill(['current_team_id' => $team->id])->saveQuietly();
    }

    private static function notify(User $user, Project $project): void
    {
        if (! is_transactional_emails_enabled()) {
            return;
        }

        $mail = new MailMessage;
        $mail->view('emails.invitation-link', [
            'team' => $project->name.' ('.$project->team->name.')',
            'invitation_link' => route('login'),
        ]);
        $mail->subject('You have been added to '.$project->name.' on '.config('app.name').'.');
        send_user_an_email($mail, $user->email);
    }

    private static function projectIdFrom(object $resource): ?int
    {
        if ($resource instanceof Project) {
            return $resource->getKey() === null ? null : (int) $resource->getKey();
        }

        if ($resource->getKey() === null) {
            return null;
        }

        if (isset($resource->project_id) && $resource->project_id) {
            return (int) $resource->project_id;
        }

        if (method_exists($resource, 'resourceable')) {
            $parent = $resource->resourceable;
            if ($parent === null) {
                return null;
            }

            return self::projectIdFrom($parent);
        }

        if (method_exists($resource, 'environment')) {
            return $resource->environment?->project_id ? (int) $resource->environment->project_id : null;
        }

        if (method_exists($resource, 'service')) {
            return $resource->service?->environment?->project_id
                ? (int) $resource->service->environment->project_id
                : null;
        }

        return null;
    }

    private static function restrictColumn(Builder $builder, string $column): void
    {
        $user = auth()->user();
        if (! self::restricts($user)) {
            return;
        }

        $ids = $user->projectMembershipProjectIds();
        $builder->whereIn($builder->qualifyColumn($column), $ids->isEmpty() ? [-1] : $ids->all());
    }

    private static function restrictEnvironment(Builder $builder): void
    {
        $user = auth()->user();
        if (! self::restricts($user)) {
            return;
        }

        $ids = $user->projectMembershipProjectIds();
        $builder->whereIn($builder->qualifyColumn('environment_id'), function ($query) use ($ids): void {
            $query->select('id')->from('environments')->whereIn('project_id', $ids->isEmpty() ? [-1] : $ids->all());
        });
    }

    private static function restrictViaService(Builder $builder): void
    {
        $user = auth()->user();
        if (! self::restricts($user)) {
            return;
        }

        $ids = $user->projectMembershipProjectIds();
        $builder->whereIn($builder->qualifyColumn('service_id'), function ($query) use ($ids): void {
            $query->select('services.id')
                ->from('services')
                ->join('environments', 'environments.id', '=', 'services.environment_id')
                ->whereIn('environments.project_id', $ids->isEmpty() ? [-1] : $ids->all());
        });
    }

    /**
     * @return list<class-string>
     */
    private static function environmentResources(): array
    {
        return [
            Application::class,
            Service::class,
            StandalonePostgresql::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneRedis::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
            StandaloneClickhouse::class,
            StandaloneMongodb::class,
            StandaloneSqlite::class,
        ];
    }
}
