<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Most feature tests exercise staff-only pages and queue endpoints, so by
     * default they run as a signed-in staff member. Auth tests opt out.
     */
    protected bool $actingAsByDefault = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->actingAsByDefault) {
            $this->actingAs(new User([
                'id' => 1,
                'name' => 'موظف الاختبار',
                'username' => 'test-staff',
                'email' => 'test-staff@staff.local',
            ]));
        }
    }
}
