<?php

namespace Tests;

use App\Foundation\Support\Context;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Context holds the operator/correlation in static state; clear it so a forced operator
        // from a prior test never leaks into the next (which relies on lazy auth-derived scope).
        Context::reset();
    }
}
