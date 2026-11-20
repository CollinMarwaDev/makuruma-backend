<?php

return [
    // UAT 20 Nov 2026: an Offloaded leg stays on the Tracking Active list this many full days after its
    // offloading date, so the client still sees it (it is in the Offloaded tab from the start).
    'offloaded_visible_days' => (int) env('TRACKING_OFFLOADED_VISIBLE_DAYS', 1),
];
