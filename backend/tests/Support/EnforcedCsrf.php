<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/**
 * Laravel skips CSRF verification while running tests. This subclass turns it
 * back on so the real protection can be asserted.
 */
class EnforcedCsrf extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}
