<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectMemberAdded extends Notification
{
    use Queueable;

    public function __construct(
        public Project $project,
        public ?User $actor = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Added to project')
            ->line("You were added to {$this->project->name}.")
            ->action('Open project tasks', route('tasks'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'project_member_added',
            'project_id' => $this->project->id,
            'message' => 'You were added to project: '.$this->project->name,
            'actor_id' => $this->actor?->id,
        ];
    }
}
