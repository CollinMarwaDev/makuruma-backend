<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Client;

class BookingNumberGenerator
{
    /** $date = the booking date (average truck ETA, UAT 20 Nov); its month and year go into the number. */
    public static function generate(Client $client, int $truckCount, ?\Carbon\Carbon $date = null): string
    {
        $date ??= now();
        $shortCode = $client->short_code ?: strtoupper(substr($client->company_name, 0, 3));
        $trucks = str_pad($truckCount, 2, '0', STR_PAD_LEFT);
        $month = $date->format('m');
        $year = $date->format('Y');

        $base = "{$shortCode}{$trucks}{$month}{$year}";

        // If this exact code already exists (e.g. the Return booking for
        // the same client/truck-count/month as its Go), append the next
        // available letter to keep it unique while staying readable.
        if (!Booking::where('booking_number', $base)->exists()) {
            return $base;
        }

        $suffix = 'B';
        while (Booking::where('booking_number', $base . $suffix)->exists()) {
            $suffix++;
        }

        return $base . $suffix;
    }
}