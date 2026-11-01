<?php

namespace Tests\Unit;

use App\Models\Inquiry;
use PHPUnit\Framework\TestCase;

/** The event name Post an Event keeps as an "Event: …" line, shown as the request card's title. */
class InquiryEventNameTest extends TestCase
{
    public function test_the_event_line_is_read_back(): void
    {
        $inquiry = new Inquiry;

        $inquiry->additional_requirements = "Event: Marlo & Rena Wedding\nTime: 16:00 – 21:00\nGarden setup.";
        $this->assertSame('Marlo & Rena Wedding', $inquiry->eventName());

        $inquiry->additional_requirements = "Time: 16:00 – 21:00\n  event:   Lola's 80th  \n";
        $this->assertSame("Lola's 80th", $inquiry->eventName());

        $inquiry->additional_requirements = 'Garden setup, rain plan needed.';
        $this->assertNull($inquiry->eventName());

        $inquiry->additional_requirements = null;
        $this->assertNull($inquiry->eventName());
    }
}
