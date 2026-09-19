@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
<style>
body { background: #f7f8fa; }
.app-container { max-width: 900px; margin: 0 auto; padding: 2rem 1rem; background: #fff; border-radius: 16px; box-shadow: 0 2px 16px 0 rgba(60,72,88,0.05); }
.page-header h2 { font-size: 1.7rem; font-weight: 700; color: #22223b; margin-bottom: 0.2rem; }
.page-header p { color: #888; font-size: 1.05rem; }
.analytics-filters { background: #f8fafc; border-radius: 10px; padding: 1rem 1.2rem; margin-bottom: 2rem; box-shadow: none; }
.analytics-filters label { color: #888; font-size: 0.98rem; margin-right: 0.3rem; }
.analytics-filters input, .analytics-filters select { border: 1px solid #e5e7eb; border-radius: 8px; font-size: 1rem; padding: 0.5rem 1rem; margin-right: 0.7rem; background: #f8fafc; }
.btn-small { font-size: 0.97rem; padding: 0.3rem 1.1rem; border-radius: 7px; border: none; cursor: pointer; margin-right: 0.5rem; transition: background 0.2s; }
.btn-small.btn-primary { background: #4f8cff; color: #fff; }
.btn-small.btn-primary:hover { background: #2563eb; }
.metrics-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.2rem; margin-bottom: 2rem; }
.metric-card { background: #f8fafc; border-radius: 12px; box-shadow: none; padding: 1.1rem 1rem; display: flex; flex-direction: column; align-items: center; min-width: 0; }
.metric-icon { font-size: 1.5rem; margin-bottom: 0.4rem; }
.metric-value { font-size: 1.7rem; font-weight: 600; color: #22223b; }
.metric-label { font-size: 1rem; color: #6c757d; margin-bottom: 0.2rem; }
.charts-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.2rem; margin-bottom: 2rem; }
.chart-card { background: #f8fafc; border-radius: 12px; padding: 1rem 1rem 0.5rem 1rem; box-shadow: none; min-width: 0; }
.completed-history-card { background: #f8fafc; border-radius: 12px; box-shadow: none; padding: 1.2rem 1rem; margin: 2rem auto 0 auto; max-width: 700px; }
.completed-history-card h3 { color: #4f8cff; font-weight: 600; font-size: 1.1rem; margin-bottom: 1rem; }
.tasks-table { width: 100%; border-collapse: collapse; background: none; }
.tasks-table th, .tasks-table td { padding: 0.6rem 1rem; border-bottom: 1px solid #e5e7eb; text-align: left; }
.tasks-table th { background: #f8fafc; font-weight: 600; color: #4f8cff; }
.tasks-table tr:last-child td { border-bottom: none; }
.insights-card { background: #f8fafc; border-radius: 12px; box-shadow: none; padding: 1.2rem 1rem; margin-top: 2rem; }
.insights-card h3 { font-size: 1.1rem; font-weight: 600; color: #22223b; margin-bottom: 0.7rem; }
.insights-grid { display: flex; gap: 2rem; }
.insight-section { flex: 1; }
.insight-section h4 { font-size: 1rem; color: #4f8cff; margin-bottom: 0.5rem; }
.insight-section ul { padding-left: 1.1rem; color: #555; font-size: 0.97rem; }
@media (max-width: 900px) { .metrics-grid, .charts-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 600px) { .metrics-grid, .charts-grid { grid-template-columns: 1fr; } .app-container { padding: 1rem 0.2rem; } .insights-grid { flex-direction: column; gap: 1rem; } }
</style>
@endpush
@section('content')
<div class="app-container" id="analyticsApp">
    <form class="analytics-filters" id="analyticsFilters" method="get" style="margin-bottom:2rem; display:flex; gap:1rem; align-items:center; flex-wrap:wrap;">
        <label for="dateFrom">From:</label>
        <input type="date" id="dateFrom" name="dateFrom" value="{{ request('dateFrom', now()->subDays(7)->format('Y-m-d')) }}">
        <label for="dateTo">To:</label>
        <input type="date" id="dateTo" name="dateTo" value="{{ request('dateTo', now()->format('Y-m-d')) }}">
        <button type="submit" class="btn-small btn-primary">Apply</button>
        <button type="button" class="btn-small" id="resetFilters">Reset</button>
    </form>
    <div class="page-header">
        <h2>Performance: {{ $user->name }}</h2>
        <p>Role: {{ $user->role ? ucfirst($user->role->name) : '-' }}</p>
    </div>
    <div class="metrics-grid">
        <div class="metric-card blue">
            <div class="metric-icon">📊</div>
            <div class="metric-value">{{ $totalTasks }}</div>
            <div class="metric-label">Total Tasks</div>
        </div>
        <div class="metric-card green">
            <div class="metric-icon">✅</div>
            <div class="metric-value">{{ $totalCompletedTasks }}</div>
            <div class="metric-label">Completed Tasks</div>
        </div>
        <div class="metric-card purple">
            <div class="metric-icon">📈</div>
            <div class="metric-value">{{ $currentInProgressTasks }}</div>
            <div class="metric-label">In Progress</div>
        </div>
        <div class="metric-card red">
            <div class="metric-icon">⚠️</div>
            <div class="metric-value">{{ $currentOverdueTasks }}</div>
            <div class="metric-label">Overdue</div>
        </div>
    </div>
    <div class="charts-grid">
        <div class="chart-card">
            <h3>📈 Completion Trend (Last 7 Days)</h3>
            <canvas id="completionTrendChart" height="120"></canvas>
        </div>
        <div class="chart-card">
            <h3>📊 Status Breakdown</h3>
            <canvas id="statusBreakdownChart" height="120"></canvas>
        </div>
    </div>
    <div class="completed-history-card" style="margin: 2rem auto 0 auto; max-width: 700px; background: #fff; border-radius: 14px; box-shadow: 0 1px 6px 0 rgba(60,72,88,0.06); padding: 1.5rem 1.2rem;">
        <h3 style="font-weight:600; color:#4f8cff; display:flex; align-items:center; gap:0.5rem; margin-bottom:1rem;">
            <span style="font-size:1.3rem;">✅</span> Completed Tasks (Last 7 Days)
        </h3>
        <table class="tasks-table" style="margin-bottom:0;">
            <thead style="background:#f4f8ff;">
                <tr>
                    <th style="color:#4f8cff;">Title</th>
                    <th style="color:#4f8cff;">Completed At</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentActivity as $task)
                    @if($task->status === 'Completed')
                    <tr>
                        <td><span style="font-size:1.1rem;">{{ $task->title }}</span></td>
                        <td><span style="color:#38b6ff; font-weight:500;">{{ $task->completed_at ? \Carbon\Carbon::parse($task->completed_at)->format('m/d/Y') : '-' }}</span></td>
                    </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="insights-card">
        <h3>✨ Insights</h3>
        <div class="insights-grid">
            <div class="insight-section">
                <h4>Key Achievements</h4>
                <ul>
                    @foreach($achievements as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="insight-section">
                <h4>Areas for Improvement</h4>
                <ul>
                    @foreach($improvements as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Completion Trend Chart
    const completionTrendData = @json($dailyCompletionTrend);
    const ctx1 = document.getElementById('completionTrendChart');
    if (ctx1) {
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: Object.keys(completionTrendData),
                datasets: [{
                    label: 'Tasks Completed',
                    data: Object.values(completionTrendData),
                    borderColor: '#4f8cff',
                    backgroundColor: 'rgba(79,140,255,0.1)',
                    fill: true,
                    tension: 0.3,
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }
    
    // Status Breakdown Doughnut
    const statusData = @json($statusCounts);
    const ctx3 = document.getElementById('statusBreakdownChart');
    if (ctx3) {
        new Chart(ctx3, {
            type: 'doughnut',
            data: {
                labels: Object.keys(statusData),
                datasets: [{
                    data: Object.values(statusData),
                    backgroundColor: ['#4f8cff', '#ffb347', '#ff6b6b'],
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }
});

document.getElementById('resetFilters')?.addEventListener('click', function() {
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    document.getElementById('analyticsFilters').submit();
});
</script>
@endpush
@endsection
