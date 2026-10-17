<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_reports_ok_when_database_is_migrated(): void
    {
        config(['app.release' => 'abc1234']);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'ok')
            ->assertJsonPath('release', 'abc1234');
    }

    public function test_health_reports_unavailable_without_leaking_details_when_database_fails(): void
    {
        config(['database.connections.broken' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'missing',
            'username' => 'nobody',
            'password' => 'secret-value',
        ]]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('broken');

        try {
            $response = $this->getJson('/api/health');
        } finally {
            DB::setDefaultConnection($original);
        }

        $response->assertStatus(503)->assertExactJson(['status' => 'error', 'database' => 'unreachable']);
        $this->assertStringNotContainsString('secret-value', $response->getContent());
        $this->assertStringNotContainsString('127.0.0.1', $response->getContent());
    }
}
