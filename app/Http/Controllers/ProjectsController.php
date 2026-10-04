<?php

namespace App\Http\Controllers;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\ProjectHistory;
use App\Models\User;
use App\Services\ProjectManagerReplacementService;
use App\Services\ProjectMembershipService;
use App\Services\ProjectWriterLocks;
use App\Support\InputContracts;
use App\Support\UserPayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectsController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        $user = auth()->user();
        $projects = $this->visibleProjects()
            ->with(['projectManager', 'members.role'])
            ->withCount([
                'tasks as active_tasks_count' => fn ($query) => $query->whereNotIn('status', [TaskState::Completed->value, TaskState::Cancelled->value]),
                'tasks as completed_tasks_count' => fn ($query) => $query->where('status', TaskState::Completed->value),
            ])
            ->latest()
            ->orderByDesc('id')
            ->paginate(12);

        $projectManagers = User::query()
            ->whereHas('role', fn ($query) => $query->whereIn('name', ['manager', 'project_manager']))
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $teamMembers = User::query()
            ->with('role')
            ->whereHas('role', fn ($query) => $query->whereIn('name', ['project_manager', 'team_member']))
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return view('projects', compact('projects', 'projectManagers', 'teamMembers', 'user'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Project::class);

        $data = $this->validatedProjectData($request);
        if (auth()->user()->hasRole('project_manager') && ! auth()->user()->hasRole('manager')) {
            $data['project_manager_id'] = auth()->id();
        }
        $project = DB::transaction(function () use ($data) {
            $project = Project::query()->create($data);

            if ($project->project_manager_id) {
                $project->members()->syncWithoutDetaching([
                    $project->project_manager_id => ['added_by' => auth()->id()],
                ]);
            }

            $this->recordHistory($project, 'created', $data);

            return $project;
        }, 3);

        return response()->json([
            'success' => true,
            'project' => $this->projectPayload($project),
        ]);
    }

    public function update(Request $request, Project $project, ProjectManagerReplacementService $pmReplacementService)
    {
        $this->authorize('update', $project);

        DB::transaction(function () use ($project, $request, $pmReplacementService) {
            $actor = app(ProjectWriterLocks::class)->actor(auth()->user());
            $lockedProject = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $lockedProject);
            $data = $this->validatedProjectData($request, $lockedProject);
            if ($actor->hasRole('project_manager') && ! $actor->hasRole('manager')) {
                $data['project_manager_id'] = $actor->id;
            }
            $oldPmId = $lockedProject->project_manager_id ? (int) $lockedProject->project_manager_id : null;
            $newPmId = array_key_exists('project_manager_id', $data) && $data['project_manager_id'] !== null
                ? (int) $data['project_manager_id']
                : null;

            if (array_key_exists('project_manager_id', $data) && $oldPmId !== $newPmId) {
                $pmReplacementService->reconcile(
                    project: $lockedProject,
                    oldPmId: $oldPmId,
                    newPmId: $newPmId,
                    actor: $actor,
                );
            }

            $old = $lockedProject->toArray();
            $lockedProject->update($data);

            if ($lockedProject->project_manager_id) {
                $lockedProject->members()->syncWithoutDetaching([
                    $lockedProject->project_manager_id => ['added_by' => auth()->id()],
                ]);
            }

            $this->recordHistory($lockedProject, 'updated', ['old' => $old, 'new' => $data]);
        });

        return response()->json([
            'success' => true,
            'project' => $this->projectPayload($project->fresh()),
        ]);
    }

    public function destroy(Project $project)
    {
        $this->authorize('delete', $project);

        DB::transaction(function () use ($project) {
            $actor = app(ProjectWriterLocks::class)->actor(auth()->user());
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $locked);
            if ($locked->tasks()->lockForUpdate()->exists()) {
                abort(409, 'Cannot delete a project with assigned tasks. Complete, delete, or reassign its tasks first.');
            }
            $this->recordHistory($locked, 'deleted', $locked->toArray());
            $locked->delete();
        }, 3);

        return response()->json(['success' => true]);
    }

    public function members(Project $project)
    {
        $this->authorize('view', $project);

        return response()->json([
            'success' => true,
            'members' => $this->memberPayloads($project),
        ]);
    }

    public function addMember(Request $request, Project $project)
    {
        $this->authorize('manageMembers', $project);

        $data = $request->validate([
            'user_id' => InputContracts::id('required', Rule::exists('users', 'id')->where('active', true)->whereNull('deleted_at')),
        ]);

        app(ProjectMembershipService::class)->change($project, (int) $data['user_id'], $request->user(), true);

        return response()->json([
            'success' => true,
            'members' => $this->memberPayloads($project),
        ]);
    }

    public function removeMember(Request $request, Project $project)
    {
        $this->authorize('manageMembers', $project);

        $data = $request->validate([
            'user_id' => InputContracts::id('required', 'exists:users,id'),
        ]);

        app(ProjectMembershipService::class)->change($project, (int) $data['user_id'], $request->user(), false);

        return response()->json([
            'success' => true,
            'members' => $this->memberPayloads($project),
        ]);
    }

    private function visibleProjects()
    {
        $user = auth()->user();

        if ($user->hasRole('manager')) {
            return Project::query();
        }

        if ($user->hasRole('project_manager')) {
            return Project::query()->where('project_manager_id', $user->id);
        }

        return Project::query()->whereHas('members', fn ($query) => $query->whereKey($user->id));
    }

    private function memberPayloads(Project $project)
    {
        return $project->members()->with('role')->orderBy('name')->get()
            ->map(fn (User $user) => UserPayload::roster($user));
    }

    private function projectPayload(Project $project): array
    {
        return $project->only(['id', 'name', 'description', 'project_manager_id', 'color', 'status', 'start_date', 'end_date']) + [
            'project_manager' => $project->projectManager ? UserPayload::roster($project->projectManager) : null,
            'members' => $this->memberPayloads($project),
        ];
    }

    private function validatedProjectData(Request $request, ?Project $project = null): array
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('projects', 'name')->ignore($project?->id),
            ],
            'description' => InputContracts::text(),
            'project_manager_id' => InputContracts::id('nullable', Rule::exists('users', 'id')->where('active', true)->whereNull('deleted_at')),
            'color' => ['nullable', 'string', 'max:20'],
            'status' => ['required', Rule::in(['active', 'on_hold', 'completed', 'archived'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        if (! empty($data['project_manager_id'])) {
            $manager = User::query()->with('role')->find($data['project_manager_id']);
            if (! $manager?->hasAnyRole(['manager', 'project_manager']) || ! $manager->isActive()) {
                abort(422, 'Project manager must be an active manager or project manager.');
            }
        }

        return $data;
    }

    private function recordHistory(Project $project, string $action, array $changes): void
    {
        ProjectHistory::query()->create([
            'project_id' => $project->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'changes' => $changes,
        ]);
    }
}
