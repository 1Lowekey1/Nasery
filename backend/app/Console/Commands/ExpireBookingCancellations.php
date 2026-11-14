<?php

namespace App\Console\Commands;

use App\Services\Inquiries\BookingCancellations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nasery:bookings:expire')]
#[Description('Cancel bookings whose cancellation request was not answered in time')]
class ExpireBookingCancellations extends Command
{
    public function handle(BookingCancellations $cancellations): int
    {
        $this->info($cancellations->expire().' request(s) passed their time limit.');

        return self::SUCCESS;
    }
}
