<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reservation (due-diligence) window
    |--------------------------------------------------------------------------
    |
    | When an owner accepts a rental request the room is held for this many
    | hours so a first-time tenant (or their trusted agent) can confirm the room
    | exists and the owner is genuine. If nothing progresses before the deadline
    | the hold is released and the room returns to "available".
    |
    */

    'reservation_hours' => (int) env('RENTAL_RESERVATION_HOURS', 72),

    // How close to the deadline (in hours) the tenant is warned that the hold is
    // about to expire.
    'reservation_warning_hours' => (int) env('RENTAL_RESERVATION_WARNING_HOURS', 12),

];
