<?php

namespace Tests\Unit;

use App\Services\Notifications\Alerts;
use PHPUnit\Framework\TestCase;

/** Mock defense: offers that fit (or nearly fit) the client's budget get a clearer alert. */
class BudgetFitTest extends TestCase
{
    public function test_offers_are_matched_against_the_budget(): void
    {
        $this->assertSame('within', Alerts::budgetFit(50000, null, 50000));       // exactly the budget
        $this->assertSame('within', Alerts::budgetFit(120000, 80000, 150000));
        $this->assertSame('close', Alerts::budgetFit(54000, null, 50000));         // 8% over
        $this->assertSame('close', Alerts::budgetFit(75000, 80000, 150000));       // a little under the minimum
        $this->assertNull(Alerts::budgetFit(60000, null, 50000));                  // 20% over
        $this->assertNull(Alerts::budgetFit(60000, null, null));                   // no budget given
        $this->assertSame('within', Alerts::budgetFit(900000, 800000, null));      // "Over ₱800,000"
    }
}
