<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\TaskAssignmentCandidateService;
use App\Services\TaskReadService;
use App\Support\UserPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ProjectRosterController extends Controller
{
    public function candidates(Request $request, Project $project)
    {
        Gate::authorize('manageMembers', $project);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'selected' => ['nullable', 'integer', 'min:1'],
            'kind' => ['nullable', 'in:assignment,member,reviewer']]);
        $kind = $data['kind'] ?? 'assignment';
        $query = User::query()->where('active', true)->with('role');
        if ($kind === 'reviewer') {
            $query->where(fn ($users) => $users->whereHas('role', fn ($roles) => $roles->where('name', 'manager'))
                ->orWhere(fn ($pm) => $pm->whereKey($project->project_manager_id)->whereHas('role', fn ($roles) => $roles->where('name', 'project_manager'))));
        } elseif ($kind === 'member') {
            $query->whereHas('role', fn ($roles) => $roles->whereIn('name', ['project_manager', 'team_member']));
        } else {
            app(TaskAssignmentCandidateService::class)->eligibleInProject($query, $project);
        }
        // PMs see their own operational roster by default; adding other staff requires
        // an explicit name search within this authorized membership-management action.
        if ($kind !== 'reviewer' && ! $request->user()->hasRole('manager') && strlen(trim($data['search'] ?? '')) < 2) {
            $query->whereHas('projects', fn ($projects) => $projects->where('project_manager_id', $request->user()->id));
        }
        $selected = isset($data['selected']) ? (clone $query)->whereKey($data['selected'])->first(['users.id', 'users.name', 'users.role_id', 'users.active']) : null;
        $this->search($query, $data['search'] ?? '');
        $users = $query->withExists(['projects as project_member' => fn ($projects) => $projects->where('projects.id', $project->id)])
            ->orderBy('name')->orderBy('id')->limit(25)->get(['users.id', 'users.name', 'users.role_id', 'users.active']);
        if ($selected && ! $users->contains('id', $selected->id)) {
            $selected->setAttribute('project_member', $project->members()->whereKey($selected->id)->exists());
            $users->push($selected);
        }

        return response()->json(['candidates' => $users->map(fn (User $user) => UserPayload::roster($user) + ['project_member' => (bool) $user->getAttribute('project_member')]),
            'limit' => 25, 'search' => $data['search'] ?? '']);
    }

    public function managers(Request $request)
    {
        abort_unless($request->user()->hasAnyRole(['manager', 'project_manager']), 403);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'selected' => ['nullable', 'integer', 'min:1']]);
        $query = User::query()->where('active', true)->whereHas('role', fn ($roles) => $roles->whereIn('name', ['manager', 'project_manager']));
        if (! $request->user()->hasRole('manager')) {
            $query->whereKey($request->user()->id);
        }
        $selected = isset($data['selected']) ? (clone $query)->whereKey($data['selected'])->first(['id', 'name']) : null;
        $this->search($query, $data['search'] ?? '');
        $users = $query->orderBy('name')->orderBy('id')->limit(25)->get(['id', 'name']);
        if ($selected && ! $users->contains('id', $selected->id)) {
            $users->push($selected);
        }

        return response()->json(['candidates' => $users]);
    }

    public function taskFilters(Request $request)
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'kind' => ['nullable', 'in:assignee,reviewer']]);
        $column = ($data['kind'] ?? 'assignee').'_id';
        $query = User::withTrashed()->whereIn('id', app(TaskReadService::class)->visibleTo($request->user())
            ->whereNotNull($column)->select($column));
        $this->search($query, $data['search'] ?? '');

        return response()->json(['candidates' => $query->orderBy('name')->orderBy('id')->limit(25)->get(['id', 'name'])]);
    }

    public function members(Request $request, Project $project)
    {
        Gate::authorize('view', $project);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = $project->members()->with('role');
        $this->search($query, $data['search'] ?? '');
        $page = $query->orderBy('name')->orderBy('users.id')->paginate(20, ['users.id', 'users.name', 'users.role_id', 'users.active']);

        return response()->json(['success' => true, 'members' => $page->getCollection()->map(fn (User $user) => UserPayload::roster($user)),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
            'can_manage' => $request->user()->can('manageMembers', $project)]);
    }

    private function search($query, string $search): void
    {
        if (trim($search) !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search)).'%';
            $query->whereRaw("users.name LIKE ? ESCAPE '!'", [$pattern]);
        }
    }
}
