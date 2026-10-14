<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Rental;
use App\Models\RentalRequest;
use App\Models\Room;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class ReleaseExpiredReservations extends Command
{
    protected $signature = 'reservations:release-expired';

    protected $description = 'Release rooms whose reservation (due-diligence) window has expired, and warn tenants whose window is ending';

    public function __construct(
        protected NotificationService $notifications,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = now();

        $rooms = Room::query()
            ->where('status', 'reserved')
            // A null deadline is a hold left over from before this feature
            // shipped, so it is treated as already expired.
            ->where(function ($q) use ($now) {
                $q->whereNull('reserved_until')->orWhere('reserved_until', '<', $now);
            })
            ->get();

        $released = 0;

        foreach ($rooms as $room) {
            $pendingRentals = Rental::query()
                ->where('room_id', $room->id)
                ->where('status', 'pending')
                ->get();

            foreach ($pendingRentals as $rental) {
                $rental->releaseIfUnstarted('Reservation window expired.');

                // Surface the outcome on the tenant's request list.
                RentalRequest::query()
                    ->where('room_id', $room->id)
                    ->where('tenant_id', $rental->tenant_id)
                    ->where('status', RentalRequest::STATUS_ACCEPTED)
                    ->update([
                        'status' => RentalRequest::STATUS_CANCELLED,
                        'response_message' => 'Reservation window expired before the contract was signed.',
                        'response_at' => $now,
                    ]);

                $this->notifications->notifyReservationReleased($rental);
            }

            // releaseIfUnstarted already frees the room; a hold with no matching
            // rental is simply cleared here.
            $room->refresh();
            $room->update([
                'status' => $room->status === 'reserved' ? 'available' : $room->status,
                'reserved_until' => null,
            ]);

            $released++;
        }

        $warned = $this->warnExpiring($now);

        $this->info("Released {$released} expired reservation(s); warned {$warned} tenant(s).");

        return self::SUCCESS;
    }

    /**
     * Notify tenants once that their reservation window is about to end.
     */
    private function warnExpiring($now): int
    {
        $warningHours = (int) config('rental.reservation_warning_hours', 12);
        $threshold = $now->copy()->addHours($warningHours);

        $rooms = Room::query()
            ->where('status', 'reserved')
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '>', $now)
            ->where('reserved_until', '<=', $threshold)
            ->get();

        $warned = 0;

        foreach ($rooms as $room) {
            $rental = Rental::query()
                ->where('room_id', $room->id)
                ->where('status', 'pending')
                ->first();

            if (! $rental) {
                continue;
            }

            $alreadyWarned = Notification::query()
                ->where('type', 'reservation_expiring')
                ->where('related_type', $rental->getMorphClass())
                ->where('related_id', $rental->id)
                ->exists();

            if ($alreadyWarned) {
                continue;
            }

            $hoursLeft = max(1, (int) ceil($now->diffInHours($room->reserved_until)));
            $this->notifications->notifyReservationExpiring($rental, $hoursLeft);
            $warned++;
        }

        return $warned;
    }
}
