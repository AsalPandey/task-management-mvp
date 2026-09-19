<?php

namespace App\Http\Controllers;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\ProjectHistory;
use App\Models\User;
use App\Notifications\ProjectMemberAdded;
use App\Notifications\ProjectMemberRemoved;
use App\Services\ProjectManagerReplacementService;
use App\Support\UserPayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjectsController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        $user = auth()->user();
        $projects = $this->visibleProjects()
            ->with(['projectManager', 'members', 'tasks'])
            ->latest()
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
        $project = Project::query()->create($data);

        if ($project->project_manager_id) {
            $project->members()->syncWithoutDetaching([
                $project->project_manager_id => ['added_by' => auth()->id()],
            ]);
        }

        $this->recordHistory($project, 'created', $data);

        return response()->json([
            'success' => true,
            'project' => $this->projectPayload($project),
        ]);
    }

    public function update(Request $request, Project $project, ProjectManagerReplacementService $pmReplacementService)
    {
        $this->authorize('update', $project);

        $data = $this->validatedProjectData($request, $project);
        if (auth()->user()->hasRole('project_manager') && ! auth()->user()->hasRole('manager')) {
            $data['project_manager_id'] = auth()->id();
        }

        DB::transaction(function () use ($project, $data, $pmReplacementService) {
            $lockedProject = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            $oldPmId = $lockedProject->project_manager_id ? (int) $lockedProject->project_manager_id : null;
            $newPmId = array_key_exists('project_manager_id', $data) && $data['project_manager_id'] !== null
                ? (int) $data['project_manager_id']
                : null;

            if (array_key_exists('project_manager_id', $data) && $oldPmId !== $newPmId) {
                $pmReplacementService->reconcile(
                    project: $lockedProject,
                    oldPmId: $oldPmId,
                    newPmId: $newPmId,
                    actor: auth()->user(),
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

        if ($project->tasks()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a project with assigned tasks. Complete, delete, or reassign its tasks first.',
            ], 409);
        }

        $this->recordHistory($project, 'deleted', $project->toArray());
        $project->delete();

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
            'user_id' => [
                'required',
                Rule::exists('users', 'id')->where('active', true)->whereNull('deleted_at'),
            ],
        ]);

        $member = DB::transaction(function () use ($data, $project) {
            $lockedProject = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $member = User::query()->with('role')->whereKey($data['user_id'])->lockForUpdate()->firstOrFail();

            if (! $member->isActive() || ! $member->hasAnyRole(['project_manager', 'team_member'])) {
                abort(422, 'Only active project managers and team members can be added as project members.');
            }

            $lockedProject->members()->syncWithoutDetaching([
                $member->id => ['added_by' => auth()->id()],
            ]);
            $this->recordHistory($lockedProject, 'member_added', ['user_id' => $member->id]);

            return $member;
        }, 3);

        $member->notify(new ProjectMemberAdded($project, auth()->user()));

        return response()->json([
            'success' => true,
            'members' => $this->memberPayloads($project),
        ]);
    }

    public function removeMember(Request $request, Project $project)
    {
        $this->authorize('manageMembers', $project);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $member = DB::transaction(function () use ($data, $project) {
            $lockedProject = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $member = User::query()->whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
            $activeTasks = $lockedProject->tasks()
                ->where(function ($query) use ($member) {
                    $query->where('assignee_id', $member->id)
                        ->orWhere('reviewer_id', $member->id);
                })
                ->whereNotIn('status', [TaskState::Completed->value, TaskState::Cancelled->value])
                ->lockForUpdate()
                ->exists();

            if ($activeTasks) {
                abort(422, 'Cannot remove a member with active tasks in this project.');
            }

            $lockedProject->members()->detach($member->id);
            $this->recordHistory($lockedProject, 'member_removed', ['user_id' => $member->id]);

            return $member;
        }, 3);

        $member->notify(new ProjectMemberRemoved($project, auth()->user()));

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
            'description' => ['nullable', 'string'],
            'project_manager_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('active', true)->whereNull('deleted_at'),
            ],
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
