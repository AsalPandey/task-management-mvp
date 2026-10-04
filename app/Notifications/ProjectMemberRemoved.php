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
            ->line('Your membership and access to a project were removed.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'project_member_removed',
            'message' => 'Your membership and access to a project were removed.',
        ];
    }
}
