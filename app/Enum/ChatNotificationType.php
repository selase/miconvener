<?php

declare(strict_types=1);

namespace App\Enum;

enum ChatNotificationType: string
{
    case MinutesSummary = 'minutes_summary';
    case TaskAssignment = 'task_assignment';
    case MeetingReminder = 'meeting_reminder';
    case TaskStatusUpdate = 'task_status_update';

    public function label(): string
    {
        return match ($this) {
            self::MinutesSummary => 'Minutes Summary',
            self::TaskAssignment => 'Task Assignment',
            self::MeetingReminder => 'Meeting Reminder',
            self::TaskStatusUpdate => 'Task Status Update',
        };
    }
}
