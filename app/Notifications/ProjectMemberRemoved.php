<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectMemberRemoved extends Notification
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
            ->subject('Removed from project')
            ->line("You were removed from {$this->project->name}.");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'project_member_removed',
            'project_id' => $this->project->id,
            'message' => 'You were removed from project: '.$this->project->name,
            'actor_id' => $this->actor?->id,
        ];
    }
}
