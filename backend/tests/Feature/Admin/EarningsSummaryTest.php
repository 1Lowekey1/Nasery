<?php

namespace Tests\Feature\Admin;

use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

/** Admin Overview: what NaSeRy earns from plans and the ticket fee (admins only). */
class EarningsSummaryTest extends TestCase
{
    use InteractsWithOrganizations;

    public function test_admins_see_plan_run_rate_and_money_in(): void
    {
        // A paying organizer (test organizers start on Pro, monthly).
        [, $owner] = $this->listedOrganizer('Earnings Co');

        $data = $this->actingAs($this->adminUser())->spaJson('GET', '/api/v1/admin/earnings')->assertOk()->json('data');

        $this->assertGreaterThan(0, $data['mrr']);
        $this->assertEqualsWithDelta($data['mrr'] * 12, $data['arr'], 0.01);
        $this->assertGreaterThanOrEqual(1, $data['organizations']['paying']);
        $this->assertSame($data['organizations']['listed'], $data['organizations']['paying'] + $data['organizations']['free']);
        $this->assertEqualsWithDelta($data['last_12_months']['plans'] + $data['last_12_months']['ticket_fee'], $data['last_12_months']['total'], 0.01);
        $this->assertContains('Pro', array_column($data['plans'], 'plan'));

        $this->actingAs($owner)->spaJson('GET', '/api/v1/admin/earnings')->assertForbidden();
    }
}
