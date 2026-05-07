<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ManagerDashboardController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }
    // Show the login page (Blade view)
    return view('auth.login');
});

// Redirect /dashboard to the manager dashboard (or change as needed)
Route::get('/dashboard', function () {
    $user = auth()->user();
    if ($user && $user->role) {
        if ($user->role->name === 'manager') {
            return redirect()->route('manager.dashboard');
        } elseif ($user->role->name === 'team_member') {
            return redirect()->route('team-dashboard');
        }
    }
    return abort(403, 'Unauthorized.');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Manager dashboard route with manual role check
Route::get('/manager', function () {
    $user = auth()->user();
    if (!$user || $user->role->name !== 'manager') {
        abort(403, 'Unauthorized.');
    }
    return app(\App\Http\Controllers\ManagerDashboardController::class)();
})->middleware(['auth', 'verified'])->name('manager.dashboard');

// Team Member dashboard route with manual role check
Route::get('/teammember', function () {
    $user = auth()->user();
    if (!$user || $user->role->name !== 'team_member') {
        abort(403, 'Unauthorized.');
    }
    return 'Team Member Dashboard';
})->middleware(['auth', 'verified'])->name('teammember.dashboard');

// AJAX endpoints for tasks and widgets
Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/dashboard/tasks', [App\Http\Controllers\ManagerDashboardController::class, 'storeTask']);
    Route::put('/dashboard/tasks/{id}', [App\Http\Controllers\ManagerDashboardController::class, 'updateTask']);
    Route::delete('/dashboard/tasks/{id}', [App\Http\Controllers\ManagerDashboardController::class, 'deleteTask']);
    Route::get('/dashboard/widgets', [App\Http\Controllers\ManagerDashboardController::class, 'dashboardWidgets']);
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/analytics', [App\Http\Controllers\AnalyticsController::class, 'index'])->name('analytics');
    Route::get('/tasks', [App\Http\Controllers\TasksController::class, 'index'])->name('tasks');
    Route::post('/tasks', [App\Http\Controllers\TasksController::class, 'store'])->middleware('throttle:20,1');
    Route::put('/tasks/{id}', [App\Http\Controllers\TasksController::class, 'update']);
    Route::delete('/tasks/{id}', [App\Http\Controllers\TasksController::class, 'destroy']);
    Route::post('/tasks/bulk-delete', [App\Http\Controllers\TasksController::class, 'bulkDelete']);
    Route::post('/tasks/bulk-complete', [App\Http\Controllers\TasksController::class, 'bulkComplete']);
    Route::get('/tasks/{id}/edit', [App\Http\Controllers\TasksController::class, 'edit'])->name('tasks.edit');
    Route::get('/team-dashboard', [App\Http\Controllers\TeamDashboardController::class, 'index'])->name('team-dashboard');
    Route::get('/team-management', [App\Http\Controllers\TeamManagementController::class, 'index'])->name('team-management');
    Route::post('/team-management', [App\Http\Controllers\TeamManagementController::class, 'store']);
    Route::put('/team-management/{id}', [App\Http\Controllers\TeamManagementController::class, 'update']);
    Route::delete('/team-management/{id}', [App\Http\Controllers\TeamManagementController::class, 'destroy']);
    Route::get('/team-management/{id}/analytics', [App\Http\Controllers\TeamManagementController::class, 'analytics'])->name('team-management.analytics');
    Route::post('/notifications/read/{id}', [App\Http\Controllers\NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [App\Http\Controllers\NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
    Route::get('/notifications/all', [App\Http\Controllers\NotificationController::class, 'all'])->name('notifications.all');
    Route::post('/team-management/{id}/activate', [App\Http\Controllers\TeamManagementController::class, 'activate'])->name('team-management.activate');
    Route::post('/team-management/{id}/deactivate', [App\Http\Controllers\TeamManagementController::class, 'deactivate'])->name('team-management.deactivate');
});

// Team Dashboard route with manual role check
Route::get('/team-dashboard', function () {
    $user = auth()->user();
    if (!$user || $user->role->name !== 'team_member') {
        abort(403, 'Unauthorized.');
    }
    return app(\App\Http\Controllers\TeamDashboardController::class)->index();
})->middleware(['auth', 'verified'])->name('team-dashboard');

Route::get('/history', [App\Http\Controllers\CompletedTasksController::class, 'index'])->name('completed-tasks');
Route::post('/history/revert/{id}', [App\Http\Controllers\CompletedTasksController::class, 'revert'])->name('completed-tasks.revert');

require __DIR__.'/auth.php';
