<?php

namespace App\Notifications\Concerns;

use App\Models\Task;
use App\Models\WorkflowNotificationIntent;
use App\Support\WorkflowNoticeSemantics;

trait InterpretsRequiredIntent
{
    private ?WorkflowNotificationIntent $workflowIntent = null;

    public function forIntent(WorkflowNotificationIntent $intent): static
    {
        $this->workflowIntent = $intent;

        return $this;
    }

    protected function coherentPayload(array $payload, Task $task): array
    {
        $intent = $this->workflowIntent;
        if (! $intent) {
            return $payload;
        }
        $payload += ['required_action' => $intent->action];

        return WorkflowNoticeSemantics::interpret($payload, $task, (int) $intent->task_version, $intent->transition, $intent->created_at?->toAtomString());
    }
}
