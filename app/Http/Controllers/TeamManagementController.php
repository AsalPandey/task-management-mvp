<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TeamManagementController extends Controller
{
    public function index()
    {
        $authUser = auth()->user();
        // Only show team members (not managers)
        $users = \App\Models\User::with('role')
            ->whereHas('role', function($q) { $q->where('name', 'team_member'); })
            ->orderBy('created_at', 'desc')->paginate(12);
        $roles = \App\Models\Role::all();
        return view('team-management', compact('users', 'roles'));
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        if (!$user || $user->role->name !== 'manager') {
            return response()->json(['success' => false, 'message' => 'Only managers can add members.'], 403);
        }
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role_id' => 'required|exists:roles,id',
        ]);
        $role = \App\Models\Role::find($data['role_id']);
        // Only managers can assign manager roles
        if ($role->name === 'manager' && $user->role->name !== 'manager') {
            return response()->json(['success' => false, 'message' => 'Only managers can assign manager roles.'], 403);
        }
        $data['password'] = bcrypt($data['password']);
        $user = \App\Models\User::create($data);
        return response()->json($user->load('role'));
    }

    public function update(Request $request, $id)
    {
        $authUser = auth()->user();
        if (!$authUser || $authUser->role->name !== 'manager') {
            return response()->json(['success' => false, 'message' => 'Only managers can edit members.'], 403);
        }
        $user = \App\Models\User::findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $id,
        ]);
        $user->update($data);
        return response()->json($user->load('role'));
    }

    public function destroy($id)
    {
        $authUser = auth()->user();
        if (!$authUser || $authUser->role->name !== 'manager') {
            return response()->json(['success' => false, 'message' => 'Only managers can delete members.'], 403);
        }
        $user = \App\Models\User::findOrFail($id);
        // Only managers can delete other managers
        if ($user->role && $user->role->name === 'manager' && $authUser->role->name !== 'manager') {
            return response()->json(['success' => false, 'message' => 'Only managers can delete other managers.'], 403);
        }
        $activeTasks = \App\Models\Task::where('assignee_id', $id)->count();
        $completedTasks = \App\Models\CompletedTask::where('assignee_id', $id)->count();
        if ($activeTasks > 0 || $completedTasks > 0) {
            return response()->json(['success' => false, 'message' => 'Cannot delete user with assigned tasks. Please reassign or delete all tasks first.'], 409);
        }
        $user->delete();
        return response()->json(['success' => true]);
    }

    public function activate($id)
    {
        $authUser = auth()->user();
        if (!$authUser || $authUser->role->name !== 'manager') {
            return response()->json(['success' => false, 'message' => 'Only managers can activate members.'], 403);
        }
        $user = \App\Models\User::findOrFail($id);
        if ($user->role && $user->role->name === 'manager' && $authUser->role->name !== 'manager') {
            return response()->json(['success' => false, 'message' => 'Only managers can activate other managers.'], 403);
        }
        $user->active = true;
        $user->save();
        return response()->json(['success' => true]);
    }
    public function deactivate($id)
    {
        $authUser = auth()->user();
        if (!$authUser || $authUser->role->name !== 'manager') {
            return response()->json(['success' => false, 'message' => 'Only managers can deactivate members.'], 403);
        }
        $user = \App\Models\User::findOrFail($id);
        if ($user->role && $user->role->name === 'manager' && $authUser->role->name !== 'manager') {
            return response()->json(['success' => false, 'message' => 'Only managers can deactivate other managers.'], 403);
        }
        $user->active = false;
        $user->save();
        return response()->json(['success' => true]);
    }

    public function analytics($id)
    {
        $authUser = auth()->user();
        // Only allow self or manager
        if ($authUser->id != $id && $authUser->role->name !== 'manager') {
            abort(403, 'Unauthorized.');
        }
        
        $user = \App\Models\User::with('role')->findOrFail($id);
        
        // Individual performance analytics (comprehensive)
        $allTasks = \App\Models\Task::where('assignee_id', $user->id)->get();
        $allCompletedTasks = \App\Models\CompletedTask::where('assignee_id', $user->id)->get();
        
        // Overall performance metrics
        $totalTasks = $allTasks->count();
        $totalCompletedTasks = $allCompletedTasks->count();
        $totalTasksForRate = $totalTasks + $totalCompletedTasks;
        $overallCompletionRate = $totalTasksForRate ? round($totalCompletedTasks / $totalTasksForRate * 100) : 0;
        
        // Current active tasks
        $currentActiveTasks = $allTasks->where('status', '!=', 'Completed');
        $currentInProgressTasks = $currentActiveTasks->where('status', 'In Progress')->count();
        $currentOverdueTasks = $currentActiveTasks->where('due_date', '<', now())->count();
        $currentAvgProgress = $currentActiveTasks->count() ? round($currentActiveTasks->avg('progress')) : 0;
        
        // Priority distribution
        $priorityCounts = [
            'High' => $allTasks->where('priority', 'High')->count(),
            'Medium' => $allTasks->where('priority', 'Medium')->count(),
            'Low' => $allTasks->where('priority', 'Low')->count(),
        ];
        
        // Status distribution
        $statusCounts = [
            'Not Started' => $allTasks->where('status', 'Not Started')->count(),
            'In Progress' => $allTasks->where('status', 'In Progress')->count(),
            'Completed' => $allTasks->where('status', 'Completed')->count(),
        ];
        
        // Performance trends (last 30 days)
        $days = collect(range(0, 29))->map(function($i) {
            return now()->subDays(29 - $i)->format('Y-m-d');
        });
        
        // Daily completion trend
        $dailyCompletionTrend = $days->mapWithKeys(function($date) use ($user) {
            $count = \App\Models\CompletedTask::where('assignee_id', $user->id)
                ->whereDate('completed_at', $date)
                ->count();
            return [\Carbon\Carbon::parse($date)->format('M d') => $count];
        });
        
        // Daily task creation trend
        $dailyTaskCreationTrend = $days->mapWithKeys(function($date) use ($user) {
            $count = \App\Models\Task::where('assignee_id', $user->id)
                ->whereDate('created_at', $date)
                ->count();
            return [\Carbon\Carbon::parse($date)->format('M d') => $count];
        });
        
        // Monthly performance (last 6 months)
        $months = collect(range(0, 5))->map(function($i) {
            return now()->subMonths(5 - $i)->format('Y-m');
        });
        
        $monthlyPerformance = $months->mapWithKeys(function($month) use ($user) {
            $startOfMonth = \Carbon\Carbon::parse($month . '-01');
            $endOfMonth = $startOfMonth->copy()->endOfMonth();
            
            $created = \App\Models\Task::where('assignee_id', $user->id)
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->count();
                
            $completed = \App\Models\CompletedTask::where('assignee_id', $user->id)
                ->whereBetween('completed_at', [$startOfMonth, $endOfMonth])
                ->count();
                
            return [$startOfMonth->format('M Y') => [
                'created' => $created,
                'completed' => $completed,
                'rate' => ($created + $completed) ? round($completed / ($created + $completed) * 100) : 0
            ]];
        });
        
        // Recent activity (last 10 tasks)
        $recentActivity = $allTasks->sortByDesc('created_at')->take(5)
            ->concat($allCompletedTasks->sortByDesc('completed_at')->take(5))
            ->sortByDesc(function($task) {
                return $task->created_at ?? $task->completed_at;
            })->take(10);
            
        // Performance insights
        $achievements = [
            'Completed ' . $totalCompletedTasks . ' tasks overall',
            'Current completion rate: ' . $overallCompletionRate . '%',
            'Average progress on active tasks: ' . $currentAvgProgress . '%',
        ];
        
        $improvements = [
            'Address ' . $currentOverdueTasks . ' overdue task(s)',
            'Focus on high-priority tasks',
            'Maintain consistent daily progress',
        ];
        
        // Performance comparison (if manager viewing team member)
        $teamComparison = null;
        if ($authUser->role->name === 'manager' && $authUser->id !== $user->id) {
            $allTeamMembers = \App\Models\User::whereHas('role', function($q) {
                $q->where('name', 'team_member');
            })->get();
            
            $teamComparison = $allTeamMembers->map(function($member) {
                $memberTasks = \App\Models\Task::where('assignee_id', $member->id)->count();
                $memberCompleted = \App\Models\CompletedTask::where('assignee_id', $member->id)->count();
                $memberRate = ($memberTasks + $memberCompleted) ? round($memberCompleted / ($memberTasks + $memberCompleted) * 100) : 0;
                
                return [
                    'name' => $member->name,
                    'total_tasks' => $memberTasks + $memberCompleted,
                    'completion_rate' => $memberRate,
                ];
            })->sortByDesc('completion_rate');
        }
        
        return view('team-member-analytics', compact(
            'user', 'allTasks', 'allCompletedTasks', 'totalTasks', 'totalCompletedTasks', 
            'overallCompletionRate', 'currentActiveTasks', 'currentInProgressTasks', 
            'currentOverdueTasks', 'currentAvgProgress', 'priorityCounts', 'statusCounts',
            'dailyCompletionTrend', 'dailyTaskCreationTrend', 'monthlyPerformance',
            'recentActivity', 'achievements', 'improvements', 'teamComparison'
        ));
    }
}
