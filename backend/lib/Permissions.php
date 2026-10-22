<?php

/**
 * Central role → actions. Default deny.
 */
class Permissions
{
    private const MAP = [
        'Employee' => [
            'booking.create', 'booking.view_own', 'booking.edit_own_pending', 'booking.cancel_own',
            'venue.list', 'venue.view',
            'notification.own',
        ],
        'Manager' => [
            'booking.create', 'booking.view_own', 'booking.edit_own_pending', 'booking.cancel_own',
            'booking.view_department', 'booking.validate',
            'venue.list', 'venue.view',
            'notification.own',
            'user.list_managers',
        ],
        'HR' => [
            'booking.create', 'booking.view_all', 'booking.decide', // approve / decline / reschedule
            'booking.override', // emergency: move or cancel an already-approved booking
            'venue.list', 'venue.view', 'venue.availability',
            'notification.own',
            'reports.view',
        ],
        'Administrator' => [
            'booking.create', 'booking.view_all', 'booking.decide', 'booking.validate', 'booking.cancel_any',
            'booking.override',
            'venue.list', 'venue.view', 'venue.manage',
            'user.manage', 'department.manage',
            'notification.own', 'activity.view', 'settings.manage', 'reports.view',
        ],
    ];

    public static function can(array $user, string $action): bool
    {
        $role = $user['role'] ?? '';
        $allowed = self::MAP[$role] ?? [];
        return in_array($action, $allowed, true);
    }

    public static function require(array $user, string $action): void
    {
        if (!self::can($user, $action)) {
            require_once __DIR__ . '/ActivityLog.php';
            require_once __DIR__ . '/Response.php';
            ActivityLog::write($user['id'] ?? null, $user['email'] ?? null, 'permission_denied', 'permission', $action, null);
            Response::error('Forbidden.', 403);
        }
    }
}
