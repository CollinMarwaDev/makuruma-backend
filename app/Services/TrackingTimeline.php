<?php

namespace App\Services;

use App\Models\BookingTruck;
use App\Models\Checkpoint;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Route and date-order rules for one tracking leg (contract 2.2 D-2/D-3).
 * A leg's timeline is: loading-point arrival → loading → dispatch → each checkpoint on the leg's
 * route (arrival, dispatch) → offloading-point arrival → offloading. No point may be dated
 * earlier than a point before it.
 */
class TrackingTimeline
{
    public const LEG_DATES = [
        'loading_point_arrival_date' => 'Arrival at loading point',
        'loading_date' => 'Loading',
        'loading_dispatch_date' => 'Dispatch from loading point',
        'offloading_point_arrival_date' => 'Arrival at offloading point',
        'offloading_date' => 'Offloading',
    ];

    /** Checkpoints on the leg's border route, in travel order (reversed on Return legs). */
    public static function checkpointsFor(BookingTruck $leg): Collection
    {
        $checkpoints = Checkpoint::query()
            ->where(fn ($q) => $q->whereNull('route')->orWhere('route', $leg->route))
            ->orderBy('sequence_order')
            ->orderBy('id')
            ->get();

        return $leg->booking->direction === 'return' ? $checkpoints->reverse()->values() : $checkpoints;
    }

    /**
     * @param array $dates     leg date fields being saved (key => value|null)
     * @param array|null $milestone checkpoint being saved: ['checkpoint_id', 'arrival_at', 'dispatch_at']
     */
    public static function assertInOrder(BookingTruck $leg, array $dates = [], ?array $milestone = null): void
    {
        $leg->loadMissing('milestones', 'booking');
        $points = [];
        $pick = fn (string $field) => array_key_exists($field, $dates) ? $dates[$field] : $leg->{$field};

        foreach (['loading_point_arrival_date', 'loading_date', 'loading_dispatch_date'] as $field) {
            $points[] = [self::LEG_DATES[$field], $pick($field), true, $field];
        }

        foreach (self::checkpointsFor($leg) as $checkpoint) {
            $saved = $leg->milestones->firstWhere('checkpoint_id', $checkpoint->id);
            $isEdited = $milestone && (int) $milestone['checkpoint_id'] === $checkpoint->id;
            foreach (['arrival_at' => 'arrival', 'dispatch_at' => 'dispatch'] as $field => $word) {
                $value = $isEdited ? ($milestone[$field] ?? null) : $saved?->{$field};
                $points[] = ["{$checkpoint->name} {$word}", $value, false, $field];
            }
        }

        foreach (['offloading_point_arrival_date', 'offloading_date'] as $field) {
            $points[] = [self::LEG_DATES[$field], $pick($field), true, $field];
        }

        $latest = null;
        foreach ($points as [$label, $value, $dateOnly, $field]) {
            if (!$value) {
                continue;
            }
            $at = Carbon::parse($value);
            if ($latest && self::isBefore($at, $dateOnly, $latest['at'], $latest['dateOnly'])) {
                throw ValidationException::withMessages([
                    $field => sprintf(
                        '%s (%s) cannot be earlier than %s (%s).',
                        $label,
                        $at->format($dateOnly ? 'd M Y' : 'd M Y H:i'),
                        $latest['label'],
                        $latest['at']->format($latest['dateOnly'] ? 'd M Y' : 'd M Y H:i'),
                    ),
                ]);
            }
            $latest = ['at' => $at, 'dateOnly' => $dateOnly, 'label' => $label];
        }
    }

    /** Compare by day when either side has no time, otherwise by exact time. */
    private static function isBefore(Carbon $a, bool $aDateOnly, Carbon $b, bool $bDateOnly): bool
    {
        return ($aDateOnly || $bDateOnly) ? $a->toDateString() < $b->toDateString() : $a->lt($b);
    }
}
