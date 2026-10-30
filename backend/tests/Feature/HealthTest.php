<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_liveness_returns_ok(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_readiness_reports_database_ok(): void
    {
        $this->getJson('/api/v1/health/ready')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'checks' => ['database' => 'ok']]);
    }
}
