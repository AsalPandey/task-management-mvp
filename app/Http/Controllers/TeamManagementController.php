<?php

namespace App\Http\Controllers;

use App\Models\CompletedTask;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AccountStatusChangedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamManagementController extends Controller
{
    public function index(Request $request)
    {
        $authUser = auth()->user();
        abort_unless($authUser->hasAnyRole(['manager', 'project_manager']), 403);

        $users = $this->manageableUsers()
            ->with(['role', 'projects:id,name'])
            ->when($request->input('search'), function ($query, string $search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $roles = $authUser->hasRole('manager')
            ? Role::query()->orderBy('id')->get()
            : collect();

        return view('team-management', compact('users', 'roles'));
    }

    public function store(Request $request)
    {
        $authUser = auth()->user();
        abort_unless($authUser->hasRole('manager'), 403);
        $this->assertOnlyFields($request, ['name', 'email', 'password', 'role_id']);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        $data['password'] = Hash::make($data['password']);
        $data['active'] = true;
        $user = User::query()->create($data);

        return response()->json($user->load('role'));
    }

    public function update(Request $request, User $user)
    {
        $authUser = auth()->user();
        $this->assertCanManageGlobalAccount($authUser, $user);
        $this->assertOnlyFields($request, ['name', 'email', 'password', 'role_id']);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'role_id' => ['sometimes', 'required', 'exists:roles,id'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json($user->load('role'));
    }

    public function destroy(User $user)
    {
        $authUser = auth()->user();
        $this->assertCanManageGlobalAccount($authUser, $user);

        $activeTasks = Task::query()->where('assignee_id', $user->id)->exists();
        $completedTasks = CompletedTask::query()->where('assignee_id', $user->id)->exists();
        if ($activeTasks || $completedTasks) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a user with assigned tasks. Deactivate the user or reassign their tasks first.',
            ], 409);
        }

        $user->delete();

        return response()->json(['success' => true]);
    }

    public function activate(User $user)
    {
        $authUser = auth()->user();
        $this->assertCanManageGlobalAccount($authUser, $user);
        $user->forceFill(['active' => true])->save();
        $user->notify(new AccountStatusChangedNotification(true, $authUser));

        return response()->json(['success' => true]);
    }

    public function deactivate(User $user)
    {
        $authUser = auth()->user();
        $this->assertCanManageGlobalAccount($authUser, $user);
        abort_if($user->is($authUser), 422, 'You cannot deactivate your own account.');

        $user->forceFill(['active' => false])->save();
        $user->notify(new AccountStatusChangedNotification(false, $authUser));

        return response()->json(['success' => true]);
    }

    public function analytics(User $user)
    {
        $authUser = auth()->user();
        if (! $authUser->hasRole('manager') && ! $authUser->hasRole('project_manager') && ! $authUser->is($user)) {
            abort(403, 'Unauthorized.');
        }

        if ($authUser->hasRole('project_manager')) {
            $allowed = $authUser->is($user)
                || $user->projects()->where('project_manager_id', $authUser->id)->exists();
            abort_unless($allowed || $authUser->is($user), 403);
        }

        $allTasksQuery = Task::query()->where('assignee_id', $user->id);
        $allCompletedTasksQuery = CompletedTask::query()->where('assignee_id', $user->id);

        if ($authUser->hasRole('project_manager') && ! $authUser->is($user)) {
            $allTasksQuery->whereHas('project', fn ($query) => $query->where('project_manager_id', $authUser->id));
            $allCompletedTasksQuery->whereHas('project', fn ($query) => $query->where('project_manager_id', $authUser->id));
        }

        $allTasks = $allTasksQuery->get();
        $allCompletedTasks = $allCompletedTasksQuery->get();
        $totalTasks = $allTasks->count();
        $totalCompletedTasks = $allCompletedTasks->count();
        $totalTasksForRate = $totalTasks + $totalCompletedTasks;
        $overallCompletionRate = $totalTasksForRate ? round($totalCompletedTasks / $totalTasksForRate * 100) : 0;
        $currentActiveTasks = $allTasks->where('status', '!=', 'Completed');
        $currentInProgressTasks = $currentActiveTasks->where('status', 'In Progress')->count();
        $currentOverdueTasks = $currentActiveTasks->where('due_date', '<', now())->count();
        $currentAvgProgress = $currentActiveTasks->count() ? round($currentActiveTasks->avg('progress')) : 0;
        $priorityCounts = [
            'High' => $allTasks->where('priority', 'High')->count(),
            'Medium' => $allTasks->where('priority', 'Medium')->count(),
            'Low' => $allTasks->where('priority', 'Low')->count(),
        ];
        $statusCounts = [
            'Not Started' => $allTasks->where('status', 'Not Started')->count(),
            'In Progress' => $allTasks->where('status', 'In Progress')->count(),
            'Completed' => $allCompletedTasks->count(),
        ];
        $dailyCompletionTrend = collect(range(0, 29))->mapWithKeys(function ($i) use ($allCompletedTasksQuery) {
            $date = now()->subDays(29 - $i);

            return [$date->format('M d') => (clone $allCompletedTasksQuery)
                ->whereDate('completed_at', $date)
                ->count()];
        });
        $dailyTaskCreationTrend = collect(range(0, 29))->mapWithKeys(function ($i) use ($allTasksQuery) {
            $date = now()->subDays(29 - $i);

            return [$date->format('M d') => (clone $allTasksQuery)
                ->whereDate('created_at', $date)
                ->count()];
        });
        $monthlyPerformance = collect(range(0, 5))->mapWithKeys(function ($i) use ($allTasksQuery, $allCompletedTasksQuery) {
            $start = now()->subMonths(5 - $i)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $created = (clone $allTasksQuery)->whereBetween('created_at', [$start, $end])->count();
            $completed = (clone $allCompletedTasksQuery)->whereBetween('completed_at', [$start, $end])->count();

            return [$start->format('M Y') => [
                'created' => $created,
                'completed' => $completed,
                'rate' => ($created + $completed) ? round($completed / ($created + $completed) * 100) : 0,
            ]];
        });
        $recentActivity = $allTasks->sortByDesc('created_at')->take(5)
            ->concat($allCompletedTasks->sortByDesc('completed_at')->take(5))
            ->sortByDesc(fn ($task) => $task->completed_at ?? $task->created_at)
            ->take(10);
        $achievements = [
            'Completed '.$totalCompletedTasks.' tasks overall',
            'Current completion rate: '.$overallCompletionRate.'%',
            'Average progress on active tasks: '.$currentAvgProgress.'%',
        ];
        $improvements = [
            'Address '.$currentOverdueTasks.' overdue task(s)',
            'Focus on high-priority tasks',
            'Maintain consistent daily progress',
        ];
        $teamComparison = null;

        return view('team-member-analytics', compact(
            'user',
            'allTasks',
            'allCompletedTasks',
            'totalTasks',
            'totalCompletedTasks',
            'overallCompletionRate',
            'currentActiveTasks',
            'currentInProgressTasks',
            'currentOverdueTasks',
            'currentAvgProgress',
            'priorityCounts',
            'statusCounts',
            'dailyCompletionTrend',
            'dailyTaskCreationTrend',
            'monthlyPerformance',
            'recentActivity',
            'achievements',
            'improvements',
            'teamComparison',
        ));
    }

    private function manageableUsers()
    {
        $authUser = auth()->user();

        if ($authUser->hasRole('manager')) {
            return User::query()->whereKeyNot($authUser->id);
        }

        return User::query()
            ->whereHas('role', fn ($query) => $query->where('name', 'team_member'))
            ->whereHas('projects', fn ($query) => $query->where('project_manager_id', $authUser->id));
    }

    private function assertCanManageGlobalAccount(User $authUser, User $user): void
    {
        abort_unless($authUser->hasRole('manager'), 403);
        abort_if($authUser->is($user), 403, 'Use the self-service profile and password routes for your own account.');
    }

    private function assertOnlyFields(Request $request, array $allowed): void
    {
        $unexpected = collect($request->keys())
            ->reject(fn (string $field) => in_array($field, ['_token', '_method'], true))
            ->diff($allowed)
            ->values()
            ->all();

        if ($unexpected === []) {
            return;
        }

        throw ValidationException::withMessages([
            'fields' => 'Unsupported fields: '.implode(', ', $unexpected).'. Project membership changes must use the project membership endpoints.',
        ]);
    }
}
