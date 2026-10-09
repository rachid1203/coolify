<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Support\ProjectAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class ProjectMembersController extends Controller
{
    #[OA\Get(
        summary: 'List project members',
        description: 'List users who can access one project without joining the whole team.',
        path: '/projects/{uuid}/members',
        operationId: 'list-project-members',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Project members.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 404, description: 'Project not found.'),
        ]
    )]
    public function index(Request $request, string $uuid): JsonResponse
    {
        $project = $this->project($uuid);
        $this->authorize('manageMembers', $project->team);

        return response()->json($this->payload($project));
    }

    #[OA\Post(
        summary: 'Add project member',
        description: 'Give an existing or new user access to one project.',
        path: '/projects/{uuid}/members',
        operationId: 'add-project-member',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'can_create_projects', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Project member added.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 404, description: 'Project not found.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function store(Request $request, string $uuid): JsonResponse
    {
        $project = $this->project($uuid);
        $this->authorize('manageMembers', $project->team);

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'can_create_projects' => ['sometimes', 'boolean'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $member = ProjectAccess::addMember(
            $project,
            $validator->validated()['email'],
            (bool) ($validator->validated()['can_create_projects'] ?? false),
            $request->user(),
        );

        return response()->json([
            'id' => $member->id,
            'email' => $member->email,
            'name' => $member->name,
            'can_create_projects' => (bool) ($validator->validated()['can_create_projects'] ?? false),
        ], 201);
    }

    #[OA\Patch(
        summary: 'Update project member',
        description: 'Allow or revoke the ability to create further projects on this team.',
        path: '/projects/{uuid}/members/{user_id}',
        operationId: 'update-project-member',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['can_create_projects'],
                properties: [
                    new OA\Property(property: 'can_create_projects', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Project member updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 404, description: 'Project member not found.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update(Request $request, string $uuid, int $user_id): JsonResponse
    {
        $project = $this->project($uuid);
        $this->authorize('manageMembers', $project->team);
        $membership = $this->membership($project, $user_id);

        $validator = Validator::make($request->all(), [
            'can_create_projects' => ['required', 'boolean'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $membership->update([
            'can_create_projects' => $validator->validated()['can_create_projects'],
        ]);

        return response()->json([
            'id' => $membership->user_id,
            'can_create_projects' => (bool) $membership->can_create_projects,
        ]);
    }

    #[OA\Delete(
        summary: 'Remove project member',
        description: 'Remove a user from one project. Team membership is unchanged.',
        path: '/projects/{uuid}/members/{user_id}',
        operationId: 'remove-project-member',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Project member removed.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 404, description: 'Project member not found.'),
        ]
    )]
    public function destroy(Request $request, string $uuid, int $user_id): JsonResponse
    {
        $project = $this->project($uuid);
        $this->authorize('manageMembers', $project->team);
        ProjectAccess::removeMember($this->membership($project, $user_id));

        return response()->json(['message' => 'Project member removed.']);
    }

    private function project(string $uuid): Project
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            abort(response()->json(['message' => 'Invalid token.'], 400));
        }

        $project = Project::query()->where('team_id', $teamId)->where('uuid', $uuid)->first();
        if ($project === null) {
            abort(response()->json(['message' => 'Project not found.'], 404));
        }

        return $project;
    }

    private function membership(Project $project, int $userId): ProjectMember
    {
        $membership = ProjectMember::query()
            ->where('project_id', $project->id)
            ->where('user_id', $userId)
            ->first();

        if ($membership === null) {
            abort(response()->json(['message' => 'Project member not found.'], 404));
        }

        return $membership;
    }

    /**
     * @return list<array{id: int, name: string|null, email: string, can_create_projects: bool}>
     */
    private function payload(Project $project): array
    {
        return $project->projectMemberships()->with('user:id,name,email')->get()->map(fn (ProjectMember $membership): array => [
            'id' => $membership->user_id,
            'name' => $membership->user?->name,
            'email' => $membership->user?->email,
            'can_create_projects' => (bool) $membership->can_create_projects,
        ])->all();
    }
}
