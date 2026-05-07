@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/analytics.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
<style>
body { background: #f7f8fa; }
.analytics-container { max-width: 1100px; margin: 0 auto; padding: 2rem 1rem; background: #fff; border-radius: 16px; box-shadow: 0 2px 16px 0 rgba(60,72,88,0.05); }
.analytics-header h1 { font-size: 1.7rem; font-weight: 700; color: #22223b; margin-bottom: 0.2rem; }
.analytics-header p { color: #888; font-size: 1.05rem; }
.metrics-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.2rem; margin-bottom: 2rem; }
.metric-card { background: #f8fafc; border-radius: 12px; box-shadow: none; padding: 1.1rem 1rem; display: flex; flex-direction: column; align-items: center; min-width: 0; }
.metric-icon { font-size: 1.5rem; margin-bottom: 0.4rem; }
.metric-value { font-size: 1.7rem; font-weight: 600; color: #22223b; }
.metric-label { font-size: 1rem; color: #6c757d; margin-bottom: 0.2rem; }
.chart-section, .project-performance, .team-performance { background: #f8fafc; border-radius: 12px; box-shadow: none; padding: 1.2rem 1rem; margin-bottom: 2rem; }
@media (max-width: 900px) { .metrics-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 600px) { .metrics-grid { grid-template-columns: 1fr; } .analytics-container { padding: 1rem 0.2rem; } }
</style>
@endpush
@section('content')
<div class="analytics-container">
    <!-- Filters -->
    <form class="analytics-filters" id="analyticsFilters">
        <label for="dateFrom">From:</label>
        <input type="date" id="dateFrom" name="dateFrom" aria-label="Date from" value="{{ request('dateFrom', now()->subDays(7)->format('Y-m-d')) }}">
        <label for="dateTo">To:</label>
        <input type="date" id="dateTo" name="dateTo" aria-label="Date to" value="{{ request('dateTo', now()->format('Y-m-d')) }}">
        <label for="assigneeFilter">Assignee:</label>
        <select id="assigneeFilter" name="assignee" aria-label="Assignee filter">
            <option value="">All Team Members</option>
            @foreach(\App\Models\User::all() as $user)
                <option value="{{ $user->id }}" @if(request('assignee') == $user->id) selected @endif>{{ $user->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn-small btn-primary">Apply</button>
        <button type="button" class="btn-small" id="resetFilters">Reset</button>
        <button type="button" class="export-btn" id="exportCSV">Export CSV</button>
        <button type="button" class="export-btn" id="exportPDF">Export PDF</button>
    </form>
    <!-- No data message -->
    <div id="noDataMsg" style="display:none; color:#888; text-align:center; margin:2rem 0; font-size:1.1rem;">No data for selected range.</div>
    <!-- Header -->
    <div class="analytics-header">
        <div class="header-content">
            <h1>Team Analytics</h1>
            <p>Comprehensive team performance insights</p>
        </div>
        <div class="header-meta">
            <div class="last-updated">
                <span class="date-icon">📅</span>
                <span>Updated: <span>{{ $lastUpdated ? $lastUpdated->format('M d, Y H:i') : 'Never' }}</span></span>
            </div>
        </div>
    </div>
    <!-- Key Performance Metrics -->
    <div class="metrics-grid">
        <div class="metric-card blue">
            <div class="metric-header">
                <div class="metric-icon">📊</div>
                <div class="metric-trend">+12%</div>
            </div>
            <div class="metric-value">{{ $totalActiveTasks }}</div>
            <div class="metric-label">Total Tasks</div>
            <div class="metric-subtitle">All Tasks</div>
        </div>
        <div class="metric-card green">
            <div class="metric-header">
                <div class="metric-icon">🎯</div>
                <div class="metric-trend">+8%</div>
            </div>
            <div class="metric-value">{{ $completionRate }}%</div>
            <div class="metric-label">Completion Rate</div>
            <div class="metric-subtitle">{{ $totalCompletedTasks }} of {{ $totalActiveTasks + $totalCompletedTasks }} completed</div>
        </div>
        <div class="metric-card purple">
            <div class="metric-header">
                <div class="metric-icon">📈</div>
                <div class="metric-trend">+5%</div>
            </div>
            <div class="metric-value">{{ $avgProgress }}%</div>
            <div class="metric-label">Average Progress</div>
            <div class="metric-subtitle">Overall task completion</div>
        </div>
        <div class="metric-card red">
            <div class="metric-header">
                <div class="metric-icon">⚠️</div>
                <div class="metric-trend">-2%</div>
            </div>
            <div class="metric-value">{{ $overdueTasks }}</div>
            <div class="metric-label">Overdue Tasks</div>
            <div class="metric-subtitle">Needs attention</div>
        </div>
    </div>
    <!-- Detailed Analytics -->
    <div class="analytics-grid">
        <div class="chart-card">
            <h3>📊 Tasks by Priority</h3>
            <canvas id="priorityChartCanvas" height="180" aria-label="Tasks by Priority" role="img"></canvas>
        </div>
        <div class="chart-card">
            <h3>📈 7-Day Productivity Trend</h3>
            <canvas id="productivityChartCanvas" height="180" aria-label="7-Day Productivity Trend" role="img"></canvas>
        </div>
        <div class="chart-card">
            <h3>🟠 Status Breakdown</h3>
            <canvas id="statusChartCanvas" height="180" aria-label="Status Breakdown" role="img"></canvas>
        </div>
        <div class="chart-card">
            <h3>⚠️ Overdue Trend</h3>
            <canvas id="overdueTrendChartCanvas" height="180" aria-label="Overdue Trend" role="img"></canvas>
        </div>
    </div>
    <!-- Team Performance Overview -->
    <div id="teamPerformanceSection" class="team-performance-card">
        <h3>👥 Team Performance Overview</h3>
        <div class="team-performance-grid" id="teamPerformanceGrid">
            @foreach ($teamPerformance as $member)
                <div class="team-member-performance">
                    <div class="member-header">
                        <div class="member-avatar">{{ $member['avatar'] }}</div>
                        <div class="member-info">
                            <h4>{{ $member['name'] }}</h4>
                            <p>{{ $member['completionRate'] }}% completion rate</p>
                        </div>
                    </div>
                    <div class="performance-stats">
                        <div class="stat-row">
                            <span class="stat-label">Total Tasks:</span>
                            <span class="stat-value">{{ $member['total'] }}</span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Completed:</span>
                            <span class="stat-value completed">{{ $member['completed'] }}</span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Overdue:</span>
                            <span class="stat-value overdue">{{ $member['overdue'] }}</span>
                        </div>
                    </div>
                    <div class="completion-bar">
                        <div class="completion-fill" style="width: {{ $member['completionRate'] }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    <!-- Performance Insights -->
    <div class="insights-card">
        <h3>✨ Performance Insights</h3>
        <div class="insights-grid">
            <div class="insight-section">
                <h4>Key Achievements</h4>
                <div class="achievements" id="achievements">
                    @foreach ($achievements as $item)
                        <div class="achievement-item">{{ $item }}</div>
                    @endforeach
                </div>
            </div>
            <div class="insight-section">
                <h4>Areas for Improvement</h4>
                <div class="improvements" id="improvements">
                    @foreach ($improvements as $item)
                        <div class="improvement-item">{{ $item }}</div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const priorityData = @json($priorityCounts);
const productivityData = @json($productivity);
const statusData = @json($statusCounts);
const overdueTrendData = @json($overdueTrend);
// Check for empty data
const allEmpty = [priorityData, productivityData, statusData, overdueTrendData].every(obj => Object.values(obj).every(v => v === 0 || v === null || v === undefined));
if (allEmpty) {
    document.getElementById('noDataMsg').style.display = '';
    document.querySelectorAll('.analytics-grid .chart-card').forEach(card => card.style.display = 'none');
} else {
    document.getElementById('noDataMsg').style.display = 'none';
    document.querySelectorAll('.analytics-grid .chart-card').forEach(card => card.style.display = '');
}
// Reset filters
const resetBtn = document.getElementById('resetFilters');
if (resetBtn) {
    resetBtn.onclick = function() {
        document.getElementById('dateFrom').value = '';
        document.getElementById('dateTo').value = '';
        document.getElementById('assigneeFilter').selectedIndex = 0;
        document.getElementById('analyticsFilters').submit();
    };
}
// Priority Chart
const ctxPriority = document.getElementById('priorityChartCanvas').getContext('2d');
new Chart(ctxPriority, {
    type: 'bar',
    data: {
        labels: Object.keys(priorityData),
        datasets: [{
            label: 'Tasks',
            data: Object.values(priorityData),
            backgroundColor: ['#4f8cff', '#ffb347', '#ff6b6b'],
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } } }
});
// Productivity Chart
const ctxProd = document.getElementById('productivityChartCanvas').getContext('2d');
new Chart(ctxProd, {
    type: 'line',
    data: {
        labels: Object.keys(productivityData),
        datasets: [{
            label: 'Completed Tasks',
            data: Object.values(productivityData),
            borderColor: '#4f8cff',
            backgroundColor: 'rgba(79,140,255,0.1)',
            fill: true,
            tension: 0.3,
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } } }
});
// Status Breakdown Pie Chart
const ctxStatus = document.getElementById('statusChartCanvas').getContext('2d');
new Chart(ctxStatus, {
    type: 'doughnut',
    data: {
        labels: Object.keys(statusData),
        datasets: [{
            label: 'Tasks',
            data: Object.values(statusData),
            backgroundColor: ['#b0b3b8', '#4f8cff', '#ffb347', '#ff6b6b', '#38b6ff'],
        }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});
// Overdue Trend Chart
const ctxOverdue = document.getElementById('overdueTrendChartCanvas').getContext('2d');
new Chart(ctxOverdue, {
    type: 'line',
    data: {
        labels: Object.keys(overdueTrendData),
        datasets: [{
            label: 'Overdue Tasks',
            data: Object.values(overdueTrendData),
            borderColor: '#ff6b6b',
            backgroundColor: 'rgba(255,107,107,0.1)',
            fill: true,
            tension: 0.3,
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } } }
});
// Export CSV (now includes all charts)
document.getElementById('exportCSV').onclick = function() {
    let csv = 'Priority,Count\n';
    Object.entries(priorityData).forEach(([k,v]) => { csv += `${k},${v}\n`; });
    csv += '\nStatus,Count\n';
    Object.entries(statusData).forEach(([k,v]) => { csv += `${k},${v}\n`; });
    csv += '\nDay,Completed\n';
    Object.entries(productivityData).forEach(([k,v]) => { csv += `${k},${v}\n`; });
    csv += '\nDay,Overdue\n';
    Object.entries(overdueTrendData).forEach(([k,v]) => { csv += `${k},${v}\n`; });
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'analytics.csv'; a.click();
    URL.revokeObjectURL(url);
};
// Export PDF (basic, using browser print)
document.getElementById('exportPDF').onclick = function() {
    window.print();
};
</script>
@endpush 