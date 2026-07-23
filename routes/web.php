<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\CompletedTasksController;
use App\Http\Controllers\ManagerDashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\TasksController;
use App\Http\Controllers\TeamDashboardController;
use App\Http\Controllers\TeamManagementController;
use App\Http\Middleware\EnsureTaskCorrelationId;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/setup', [SetupController::class, 'create'])->name('setup.create');
Route::post('/setup', [SetupController::class, 'store'])->name('setup.store');

Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }

    return view('auth.login');
});

Route::middleware(['auth', 'active', 'verified'])->group(function () {
    Route::get('/dashboard', function () {
        $user = auth()->user();

        return match ($user->role?->name) {
            'manager' => redirect()->route('manager.dashboard'),
            'project_manager' => redirect()->route('manager.dashboard'),
            'team_member' => redirect()->route('team-dashboard'),
            default => abort(403, 'Unauthorized.'),
        };
    })->name('dashboard');

    Route::get('/manager', ManagerDashboardController::class)->name('manager.dashboard');
    Route::get('/team-dashboard', [TeamDashboardController::class, 'index'])->name('team-dashboard');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');
    Route::get('/analytics/export/csv', [AnalyticsController::class, 'exportCsv'])->name('analytics.export.csv');
    Route::get('/analytics/export/pdf', [AnalyticsController::class, 'exportPdf'])->name('analytics.export.pdf');

    Route::get('/projects', [ProjectsController::class, 'index'])->name('projects');
    Route::post('/projects', [ProjectsController::class, 'store'])->name('projects.store');
    Route::put('/projects/{project}', [ProjectsController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectsController::class, 'destroy'])->name('projects.destroy');
    Route::get('/projects/{project}/members', [ProjectsController::class, 'members'])->name('projects.members');
    Route::post('/projects/{project}/add-member', [ProjectsController::class, 'addMember'])->name('projects.members.add');
    Route::delete('/projects/{project}/remove-member', [ProjectsController::class, 'removeMember'])->name('projects.members.remove');

    Route::get('/tasks', [TasksController::class, 'index'])->name('tasks');
    Route::middleware(EnsureTaskCorrelationId::class)->group(function () {
        Route::post('/tasks', [TasksController::class, 'store'])->middleware('throttle:30,1')->name('tasks.store');
        Route::put('/tasks/{task}', [TasksController::class, 'update'])->name('tasks.update');
        Route::delete('/tasks/{task}', [TasksController::class, 'destroy'])->name('tasks.destroy');
        Route::post('/tasks/bulk-delete', [TasksController::class, 'bulkDelete'])->name('tasks.bulk-delete');
        Route::post('/tasks/bulk-complete', [TasksController::class, 'bulkComplete'])->name('tasks.bulk-complete');
        Route::post('/tasks/{task}/reopen', [TasksController::class, 'reopen'])->name('tasks.reopen');
        Route::post('/tasks/{task}/start', [TasksController::class, 'start'])->whereNumber('task')->name('tasks.start');
        Route::post('/tasks/{task}/hold', [TasksController::class, 'hold'])->whereNumber('task')->name('tasks.hold');
        Route::post('/tasks/{task}/resume', [TasksController::class, 'resume'])->whereNumber('task')->name('tasks.resume');
        Route::post('/tasks/{task}/submit', [TasksController::class, 'submit'])->whereNumber('task')->name('tasks.submit');
        Route::post('/tasks/{task}/review/start', [TasksController::class, 'startReview'])->whereNumber('task')->name('tasks.review.start');
    });
    Route::get('/tasks/{task}/edit', [TasksController::class, 'edit'])->name('tasks.edit');

    Route::get('/history', [CompletedTasksController::class, 'index'])->name('completed-tasks');
    Route::post('/history/revert/{completedTask}', [CompletedTasksController::class, 'retiredRevert'])
        ->whereNumber('completedTask')
        ->name('completed-tasks.revert');

    Route::get('/team-management', [TeamManagementController::class, 'index'])->name('team-management');
    Route::post('/team-management', [TeamManagementController::class, 'store'])->name('team-management.store');
    Route::put('/team-management/{user}', [TeamManagementController::class, 'update'])->name('team-management.update');
    Route::delete('/team-management/{user}', [TeamManagementController::class, 'destroy'])->name('team-management.destroy');
    Route::get('/team-management/{user}/analytics', [TeamManagementController::class, 'analytics'])->name('team-management.analytics');
    Route::post('/team-management/{user}/activate', [TeamManagementController::class, 'activate'])->name('team-management.activate');
    Route::post('/team-management/{user}/deactivate', [TeamManagementController::class, 'deactivate'])->name('team-management.deactivate');

    Route::post('/settings/profile', [ProfileController::class, 'updateJson'])->name('settings.profile');
    Route::post('/settings/preferences', [ProfileController::class, 'updatePreferences'])->name('settings.preferences');

    Route::post('/notifications/read/{id}', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
    Route::get('/notifications/all', [NotificationController::class, 'all'])->name('notifications.all');
});

require __DIR__.'/auth.php';
