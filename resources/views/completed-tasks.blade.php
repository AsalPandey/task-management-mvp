@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/tasks.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
<style>
.completed-table { width: 100%; border-collapse: collapse; margin-top: 2rem; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 6px 0 rgba(60,72,88,0.06); }
.completed-table th, .completed-table td { padding: 0.7rem 1rem; border-bottom: 1px solid #e5e7eb; text-align: left; }
.completed-table th { background: #f8fafc; font-weight: 600; color: #22223b; }
.completed-table tr:last-child td { border-bottom: none; }
.btn-small { font-size: 0.95rem; padding: 0.2rem 0.7rem; border-radius: 6px; border: none; cursor: pointer; margin-right: 0.3rem; }
.btn-small.btn-primary { background: #4f8cff; color: #fff; }
.btn-small.btn-danger { background: #ff6b6b; color: #fff; }
</style>
@endpush
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.timeline-btn').forEach(button => {
        button.addEventListener('click', async function() {
            button.disabled = true;
            try {
                const response = await fetch(button.dataset.url, {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'The timeline could not be loaded.');
                }
                const text = data.entries.length
                    ? data.entries.map(entry => {
                        const when = entry.occurred_at ? new Date(entry.occurred_at).toLocaleString() : '';

                        return `${when} — ${entry.label} — ${entry.actor}`;
                    }).join('\n')
                    : 'No timeline entries are available.';
                await Swal.fire({ title: 'Task timeline', text, width: 720 });
            } catch (error) {
                Swal.fire('Error', error.message || 'The timeline could not be loaded.', 'error');
            } finally {
                button.disabled = false;
            }
        });
    });

    document.querySelectorAll('.reopen-revision-btn').forEach(button => {
        button.addEventListener('click', async function() {
            const originalText = button.textContent;
            const reason = await Swal.fire({
                title: 'Reopen for revision?',
                input: 'textarea',
                inputLabel: 'Why does the approved result require more work?',
                inputAttributes: { maxlength: '5000' },
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Continue',
                inputValidator: value => value.trim() ? undefined : 'A reopen reason is required.',
            });
            if (!reason.isConfirmed) return;

            const deadline = await Swal.fire({
                title: 'Set revision deadline',
                input: 'date',
                inputLabel: 'The deadline must be in the future.',
                showCancelButton: true,
                confirmButtonText: 'Reopen for Revision',
                inputValidator: value => value ? undefined : 'A revision deadline is required.',
            });
            if (!deadline.isConfirmed) return;

            button.disabled = true;
            button.textContent = 'Reopening...';

            try {
                const response = await fetch(button.dataset.url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        reopen_reason: reason.value.trim(),
                        revision_due_date: deadline.value,
                    }),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || !data.success) {
                    throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Failed to reopen the task.');
                }

                button.closest('tr')?.remove();
                Swal.fire('Reopened!', 'The task now requires revision.', 'success');
            } catch (error) {
                Swal.fire('Error', error.message || 'Failed to reopen the task.', 'error');
                button.disabled = false;
                button.textContent = originalText;
            }
        });
    });
});
</script>
@endpush
@section('content')
<div class="tasks-container">
    <div id="messageContainer" class="message-container" style="display: none;"></div>
    <h1>Completed Tasks History</h1>
    <table class="completed-table">
        <thead>
            <tr>
                <th>Title</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Assignee</th>
                <th>Project</th>
                <th>Due Date</th>
                <th>Completed At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($completed as $task)
                <tr
                    data-description="{{ htmlspecialchars($task->description ?? '', ENT_QUOTES) }}"
                    data-comments="{{ htmlspecialchars($task->comments ?? '', ENT_QUOTES) }}"
                >
                    <td>{{ $task->title }}</td>
                    <td><span class="status-badge status-completed">Completed</span></td>
                    <td><span class="priority-badge priority-{{ strtolower($task->priority) }}">{{ $task->priority }}</span></td>
                    <td>{{ $task->assignee ? $task->assignee->name : '-' }}</td>
                    <td>{{ $task->project ? $task->project->name : '-' }}</td>
                    <td>{{ $task->due_date ? \Carbon\Carbon::parse($task->due_date)->format('m/d/Y') : '-' }}</td>
                    <td>{{ $task->completed_at ? \Carbon\Carbon::parse($task->completed_at)->format('m/d/Y') : '-' }}</td>
                    <td>
                        <button type="button" class="btn-small btn-secondary timeline-btn"
                            data-url="{{ route('tasks.timeline', $task) }}">Timeline</button>
                        @can('reopen', $task)
                            <button class="btn-small btn-primary reopen-revision-btn" data-url="{{ route('tasks.reopen', $task) }}">Reopen for Revision</button>
                        @else
                            <span aria-label="Reopen unavailable">&mdash;</span>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center; color:#888;">No completed tasks yet</td></tr>
            @endforelse
        </tbody>
    </table>
    @if(method_exists($completed, 'links'))
        <div class="pagination-wrapper" style="margin-top:2rem; text-align:center;">
            {{ $completed->links() }}
        </div>
    @endif
</div>
@endsection
