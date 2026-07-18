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
tr.reverted, tr.reverted td { opacity: 0.5; pointer-events: none; }
</style>
@endpush
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const messageContainer = document.getElementById('messageContainer');
    function showMessage(msg, success = true) {
        if (!messageContainer) return;
        messageContainer.textContent = msg;
        messageContainer.style.display = 'block';
        messageContainer.className = 'message-container ' + (success ? 'success' : 'error');
        setTimeout(() => { messageContainer.style.display = 'none'; }, 3000);
    }
    const revertBtns = document.querySelectorAll('.revert-btn');
    revertBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const row = this.closest('tr');
            const originalText = this.textContent;
            const button = this;
            Swal.fire({
                title: 'Revert this task?',
                text: 'This will move the task back to active tasks.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#aaa',
                confirmButtonText: 'Yes, revert!'
            }).then((result) => {
                if (!result.isConfirmed) return;
                button.disabled = true;
                button.textContent = '⏳ Reverting...';
            fetch(`/history/revert/${id}`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
            })
                .then(async r => {
                    let data;
                    try {
                        data = await r.json();
                    } catch (e) {
                        const text = await r.text();
                        Swal.fire('Error', `Server error (status ${r.status}): ${text.substring(0, 200)}`, 'error');
                        button.disabled = false;
                        button.textContent = originalText;
                        return;
                    }
                    if (r.ok && data.success) {
                        row.classList.add('reverted');
                        button.textContent = 'Reverted';
                        button.classList.remove('revert-btn');
                        button.disabled = true;
                        Swal.fire('Reverted!', 'Task reverted successfully.', 'success');
                    } else if (data && data.message) {
                        Swal.fire('Error', data.message, 'error');
                        button.disabled = false;
                        button.textContent = originalText;
                    } else {
                        Swal.fire('Error', 'Failed to revert task.', 'error');
                        button.disabled = false;
                        button.textContent = originalText;
                    }
                })
                .catch(error => {
                    Swal.fire('Error', 'Network or server error: ' + (error.message || error), 'error');
                    button.disabled = false;
                    button.textContent = originalText;
                });
            });
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
                <tr @if($task->reverted) class="reverted" @endif
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
                        @if(!$task->reverted)
                            @can('revert', $task)
                                <button class="btn-small btn-primary revert-btn" data-id="{{ $task->id }}">Revert</button>
                            @else
                                <span aria-label="Revert unavailable">&mdash;</span>
                            @endcan
                        @else
                            <button class="btn-small btn-primary" disabled>Reverted</button>
                        @endif
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
