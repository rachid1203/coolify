<?php

use App\Livewire\Dashboard;
use App\Livewire\Project\AddEmpty;
use App\Livewire\Project\Members;
use App\Livewire\SwitchTeam;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Support\ProjectAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['id' => 0, 'is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create(['name' => 'Shared Team']);

    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member->id, ['role' => 'member']);

    $this->projectUser = User::factory()->create(['email' => 'project-user@example.com']);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Team Key',
        'description' => 'hidden from project members',
        'private_key' => 'secret-key-material',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $keyId,
        'ip' => '10.1.2.3',
    ]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();

    $this->project = Project::factory()->create([
        'name' => 'Assigned Project',
        'team_id' => $this->team->id,
    ]);
    $this->otherProject = Project::factory()->create([
        'name' => 'Hidden Project',
        'team_id' => $this->team->id,
    ]);
    $this->environment = Environment::query()->where('project_id', $this->project->id)->firstOrFail();
    $this->otherEnvironment = Environment::query()->where('project_id', $this->otherProject->id)->firstOrFail();

    $this->application = Application::factory()->create([
        'name' => 'Visible App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
    $this->otherApplication = Application::factory()->create([
        'name' => 'Hidden App',
        'environment_id' => $this->otherEnvironment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $this->project->members()->attach($this->projectUser->id, ['can_create_projects' => false]);
});

function projectMembershipToken(User $user, Team $team, array $abilities): string
{
    session(['currentTeam' => $team]);

    return $user->createToken('project-membership', $abilities)->plainTextToken;
}

function actAsProjectMember(): void
{
    test()->actingAs(test()->projectUser);
    session(['currentTeam' => test()->team]);
}

test('a team admin can add, update, and remove a project member from the project page', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(Members::class, ['projectUuid' => $this->project->uuid])
        ->set('email', 'invitee@example.com')
        ->set('canCreateProjects', true)
        ->call('add')
        ->assertHasNoErrors();

    $invitee = User::query()->where('email', 'invitee@example.com')->firstOrFail();
    $membership = $this->project->projectMemberships()->where('user_id', $invitee->id)->firstOrFail();
    expect($membership->can_create_projects)->toBeTrue()
        ->and($invitee->teams()->where('teams.id', $this->team->id)->exists())->toBeFalse();

    Livewire::test(Members::class, ['projectUuid' => $this->project->uuid])
        ->call('toggleCreateProjects', $membership->id);

    expect($membership->fresh()->can_create_projects)->toBeFalse();

    Livewire::test(Members::class, ['projectUuid' => $this->project->uuid])
        ->call('remove', $membership->id);

    expect($this->project->projectMemberships()->where('user_id', $invitee->id)->exists())->toBeFalse();
});

test('a project member cannot manage project members', function () {
    actAsProjectMember();

    Livewire::test(Members::class, ['projectUuid' => $this->project->uuid])
        ->assertForbidden();
});

test('an admin of another team cannot add a member to this project', function () {
    $otherTeam = Team::factory()->create();
    $outsider = User::factory()->create();
    $otherTeam->members()->attach($outsider->id, ['role' => 'owner']);

    $this->actingAs($outsider);
    session(['currentTeam' => $otherTeam]);

    Livewire::test(Members::class)
        ->set('email', 'intruder@example.com')
        ->set('selectedProjectUuid', $this->project->uuid)
        ->call('add')
        ->assertStatus(404);

    expect(User::query()->where('email', 'intruder@example.com')->exists())->toBeFalse();
});

test('a project member only sees assigned projects and can update applications inside them', function () {
    actAsProjectMember();

    expect(Project::ownedByCurrentTeam()->pluck('name')->all())->toBe(['Assigned Project'])
        ->and(Application::query()->pluck('name')->all())->toBe(['Visible App'])
        ->and($this->projectUser->can('view', $this->project))->toBeTrue()
        ->and($this->projectUser->can('view', $this->otherProject))->toBeFalse()
        ->and($this->projectUser->can('update', $this->application))->toBeTrue()
        ->and($this->projectUser->can('update', $this->otherApplication))->toBeFalse()
        ->and($this->projectUser->can('deploy', $this->application))->toBeTrue()
        ->and($this->member->can('update', $this->application))->toBeFalse()
        ->and(PrivateKey::ownedByCurrentTeam()->count())->toBe(0)
        ->and($this->projectUser->can('view', $this->server))->toBeFalse();
});

test('the dashboard does not load servers or private keys for a project member', function () {
    actAsProjectMember();

    Livewire::test(Dashboard::class)
        ->assertSet('servers', fn ($servers) => $servers->isEmpty())
        ->assertSet('privateKeys', fn ($keys) => $keys->isEmpty())
        ->assertSee('Assigned Project')
        ->assertDontSee('Hidden Project');
});

test('a project member cannot open servers on the web or through the api', function () {
    actAsProjectMember();
    $this->get('/servers')->assertForbidden();

    auth()->logout();
    $token = projectMembershipToken($this->projectUser, $this->team, ['read']);

    $this->withToken($token)
        ->getJson('/api/v1/servers')
        ->assertForbidden();

    $this->withToken($token)
        ->getJson('/api/v1/security/keys')
        ->assertForbidden();
});

test('project member api tokens list only assigned projects and appear in team member lists', function () {
    $token = projectMembershipToken($this->projectUser, $this->team, ['read']);

    $this->withToken($token)
        ->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonFragment(['uuid' => $this->project->uuid])
        ->assertJsonMissing(['uuid' => $this->otherProject->uuid]);

    $this->withToken($token)
        ->getJson('/api/v1/team/members')
        ->assertOk()
        ->assertJsonFragment([
            'email' => 'project-user@example.com',
            'membership' => 'project',
            'role' => 'project',
        ]);
});

test('team admins manage project members through the api', function () {
    $token = projectMembershipToken($this->admin, $this->team, ['read', 'write']);

    $created = $this->withToken($token)
        ->postJson('/api/v1/projects/'.$this->project->uuid.'/members', [
            'email' => 'api-invitee@example.com',
            'can_create_projects' => true,
        ])
        ->assertCreated()
        ->json();

    expect($created['can_create_projects'])->toBeTrue();

    $this->withToken($token)
        ->patchJson('/api/v1/projects/'.$this->project->uuid.'/members/'.$created['id'], [
            'can_create_projects' => false,
        ])
        ->assertOk()
        ->assertJsonPath('can_create_projects', false);

    $this->withToken($token)
        ->deleteJson('/api/v1/projects/'.$this->project->uuid.'/members/'.$created['id'])
        ->assertOk();

    expect($this->project->members()->where('users.id', $created['id'])->exists())->toBeFalse();
});

test('a project member token cannot manage members or update the team', function () {
    $token = projectMembershipToken($this->projectUser, $this->team, ['read', 'write']);

    $this->withToken($token)
        ->postJson('/api/v1/projects/'.$this->project->uuid.'/members', [
            'email' => 'nope@example.com',
        ])
        ->assertForbidden();

    $this->withToken($token)
        ->patchJson('/api/v1/team', [
            'is_build_server_fallback_enabled' => false,
        ])
        ->assertForbidden();
});

test('the create projects flag lets a project member create another project they belong to', function () {
    $this->project->members()->updateExistingPivot($this->projectUser->id, ['can_create_projects' => false]);
    $denied = projectMembershipToken($this->projectUser, $this->team, ['write']);

    $this->withToken($denied)
        ->postJson('/api/v1/projects', ['name' => 'Not Allowed'])
        ->assertForbidden();

    expect(Project::query()->where('name', 'Not Allowed')->exists())->toBeFalse();

    $this->project->members()->updateExistingPivot($this->projectUser->id, ['can_create_projects' => true]);
    ProjectAccess::flushUserId($this->projectUser->id);
    $allowed = projectMembershipToken($this->projectUser, $this->team, ['write']);

    $uuid = $this->withToken($allowed)
        ->postJson('/api/v1/projects', ['name' => 'Own Project'])
        ->assertCreated()
        ->json('uuid');

    $created = Project::query()->where('uuid', $uuid)->firstOrFail();
    expect($created->members()->where('users.id', $this->projectUser->id)->exists())->toBeTrue();

    actAsProjectMember();
    Livewire::test(AddEmpty::class)
        ->set('name', 'Second Own Project')
        ->call('submit')
        ->assertRedirect();

    expect(Project::query()->where('name', 'Second Own Project')->where('team_id', $this->team->id)->exists())->toBeTrue();
});

test('a project member can switch to the team that owns their project', function () {
    $personal = $this->projectUser->teams()->first();
    $this->actingAs($this->projectUser);
    session(['currentTeam' => $personal]);

    Livewire::test(SwitchTeam::class)
        ->call('switch_to', $this->team->id)
        ->assertRedirect(route('dashboard'));

    expect(session('currentTeam')->is($this->team))->toBeTrue();
});

test('a project member cannot save a compose file that mounts the docker socket', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'docker_compose_raw' => "services:\n  app:\n    image: nginx\n",
    ]);

    actAsProjectMember();

    expect(fn () => $service->fresh()->update([
        'docker_compose_raw' => "services:\n  app:\n    image: nginx\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n",
    ]))->toThrow(ValidationException::class);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $service->fresh()->update([
        'docker_compose_raw' => "services:\n  app:\n    image: nginx\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n",
    ]);

    expect($service->fresh()->docker_compose_raw)->toContain('docker.sock');
});
