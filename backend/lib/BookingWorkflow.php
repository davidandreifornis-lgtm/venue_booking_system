<?php

/**
 * Explicit booking state transitions. Default deny.
 * Call assertTransition() at the start of every mutating action.
 */
class BookingWorkflow
{
    /** @var array<string, list<string>> from => allowed to */
    public const TRANSITIONS = [
        'Pending Manager Validation' => [
            'Pending HR Decision',
            'Rejected by Manager',
            'Revision Requested',
            'Cancelled',
            'Expired',
        ],
        'Revision Requested' => [
            'Pending Manager Validation',
            'Pending HR Decision',
            'Cancelled',
            'Expired',
        ],
        'Manager Validated' => [
            'Pending HR Decision',
            'Approved',
            'Declined',
            'Reschedule Proposed',
            'Revision Requested',
            'Cancelled',
            'Expired',
        ],
        'Pending HR Decision' => [
            'Approved',
            'Declined',
            'Reschedule Proposed',
            'Revision Requested',
            'Cancelled',
            'Expired',
        ],
        'Reschedule Proposed' => [
            'Approved',          // requester accepts (or keeps an approved booking)
            'Declined',
            'Pending HR Decision', // requester keeps original, back to the HR queue
            'Rescheduled',       // emergency override moves it immediately
            'Cancelled',
            'Expired',
        ],
        'Approved' => [
            'Cancelled',
            'Completed',
            'Rescheduled',
            'Reschedule Proposed',
            'No-Show',
        ],
        'Rescheduled' => [
            'Cancelled',
            'Completed',
            'Reschedule Proposed',
            'No-Show',
        ],
        'Declined' => [],
        'Rejected by Manager' => [],
        'Cancelled' => [],
        'Completed' => ['No-Show'],
        'No-Show' => [],
        'Expired' => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        $allowed = self::TRANSITIONS[$from] ?? [];
        return in_array($to, $allowed, true);
    }

    public static function assertTransition(string $from, string $to, string $action = 'change status'): void
    {
        if (!self::canTransition($from, $to)) {
            require_once __DIR__ . '/Response.php';
            Response::error("Cannot {$action}: transition from \"{$from}\" to \"{$to}\" is not allowed.", 409);
        }
    }

    /** Who is the current process owner for notifications. */
    public static function currentApproverRole(string $status): ?string
    {
        return match ($status) {
            'Pending Manager Validation', 'Revision Requested' => 'Manager',
            'Pending HR Decision', 'Manager Validated' => 'HR',
            'Reschedule Proposed' => 'Requester',
            default => null,
        };
    }

    /** Waiting states that participate in SLA / expiry jobs. */
    public static function waitingStatuses(): array
    {
        return [
            'Pending Manager Validation',
            'Pending HR Decision',
            'Manager Validated',
            'Revision Requested',
            'Reschedule Proposed',
        ];
    }
}
