<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\ValueObjects\NotificationDeliveryDecision;
use Illuminate\Notifications\Notification;

final class NotificationPreferencePolicy
{
    /**
     * @var array<string, NotificationCategory>
     */
    private const TYPES = [
        'task_assigned' => NotificationCategory::Mandatory,
        'task_deadline_reminder' => NotificationCategory::Mandatory,
        'task_overdue' => NotificationCategory::Mandatory,
        'task_held' => NotificationCategory::Mandatory,
        'task_resumed' => NotificationCategory::Mandatory,
        'task_submitted' => NotificationCategory::Mandatory,
        'task_revision_requested' => NotificationCategory::Mandatory,
        'task_resubmitted' => NotificationCategory::Mandatory,
        'task_reopened_revision_required' => NotificationCategory::Mandatory,
        'task_cancelled' => NotificationCategory::Mandatory,
        'task_reviewer_reassigned' => NotificationCategory::Mandatory,
        'task_deadline_changed' => NotificationCategory::Mandatory,

        'task_approved_completed' => NotificationCategory::TaskCompletion,

        'task_updated' => NotificationCategory::TeamUpdates,
        'task_started' => NotificationCategory::TeamUpdates,
        'task_review_started' => NotificationCategory::TeamUpdates,
        'task_revision_started' => NotificationCategory::TeamUpdates,
        'project_member_added' => NotificationCategory::TeamUpdates,
        'project_member_removed' => NotificationCategory::TeamUpdates,

        'account_activated' => NotificationCategory::System,
        'account_deactivated' => NotificationCategory::System,
        'browser_push_test' => NotificationCategory::ExplicitUserAction,
    ];

    public function decideForNotification(
        User $user,
        Notification $notification,
        string $channel,
    ): NotificationDeliveryDecision {
        $payload = method_exists($notification, 'toArray')
            ? $notification->toArray($user)
            : [];

        return $this->decideForType($user, (string) ($payload['type'] ?? ''), $channel);
    }

    public function decideForType(
        User $user,
        string $type,
        string $channel,
    ): NotificationDeliveryDecision {
        $category = self::TYPES[$type] ?? NotificationCategory::Mandatory;
        $preferenceKey = $category->preferenceKey();
        $allowed = $preferenceKey === null || $this->preferenceEnabled($user, $preferenceKey);

        return new NotificationDeliveryDecision($category, $preferenceKey, $allowed);
    }

    private function preferenceEnabled(User $user, string $key): bool
    {
        $preferences = $user->notification_preferences;
        if (! is_array($preferences) || ! array_key_exists($key, $preferences)) {
            return true;
        }

        $value = $preferences[$key];

        return is_bool($value) ? $value : true;
    }
}
