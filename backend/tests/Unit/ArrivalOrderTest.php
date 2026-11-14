<?php

namespace Tests\Unit;

use App\Services\Reports\AttendanceReport;
use PHPUnit\Framework\TestCase;

class ArrivalOrderTest extends TestCase
{
    public function test_reports_shared_before_saved_check_in_times_sort_by_the_shown_time(): void
    {
        $rows = [
            ['name' => 'Rina', 'status' => 'no_show', 'first_check_in' => null],
            ['name' => 'Jun', 'status' => 'attended', 'first_check_in' => 'Oct 17, 1:54 PM'],
            ['name' => 'Tita', 'status' => 'attended', 'first_check_in' => 'Oct 17, 12:05 PM'],
            ['name' => 'Lara', 'status' => 'attended', 'first_check_in' => 'Oct 17, 1:47 PM'],
            ['name' => 'Cara', 'status' => 'cancelled', 'first_check_in' => null],
        ];
        usort($rows, AttendanceReport::arrivalOrder(...));

        $this->assertSame(['Tita', 'Lara', 'Jun', 'Rina', 'Cara'], array_column($rows, 'name'));
    }
}
