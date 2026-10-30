<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function insertSession(string $id, int $userId, string $ip = '10.0.0.1'): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $userId, 'ip_address' => $ip, 'user_agent' => 'PHPUnit',
            'payload' => 'x', 'last_activity' => time(),
        ]);
    }

    public function test_user_lists_only_own_sessions_without_exposing_real_ids(): void
    {
        [, $user] = $this->makeTenant();
        [, $other] = $this->makeTenant();
        $this->insertSession('mine-session-id-aaaaaaaaaaaaaaaaaaaaaaaaaaaa', $user->id);
        $this->insertSession('other-session-id-bbbbbbbbbbbbbbbbbbbbbbbbbbbb', $other->id);

        $response = $this->spa()->actingAs($user)->getJson('/api/v1/auth/sessions')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertStringNotContainsString('mine-session-id', $response->getContent());
        $this->assertStringNotContainsString('other-session-id', $response->getContent());
    }

    public function test_user_can_revoke_another_device_session(): void
    {
        [, $user] = $this->makeTenant();
        $this->insertSession('device-two-session-idcccccccccccccccccccccc', $user->id);

        $handle = $this->spa()->actingAs($user)->getJson('/api/v1/auth/sessions')->json('data.0.id');

        $this->spa()->actingAs($user)->deleteJson('/api/v1/auth/sessions/'.$handle)->assertNoContent();
        $this->assertDatabaseMissing('sessions', ['id' => 'device-two-session-idcccccccccccccccccccccc']);
    }

    public function test_user_cannot_revoke_someone_elses_session(): void
    {
        [, $user] = $this->makeTenant();
        [, $other] = $this->makeTenant();
        $this->insertSession('victim-session-iddddddddddddddddddddddddd', $other->id);
        $handle = substr(hash('sha256', 'victim-session-iddddddddddddddddddddddddd'), 0, 32);

        $this->spa()->actingAs($user)->deleteJson('/api/v1/auth/sessions/'.$handle)->assertNotFound();
        $this->assertDatabaseHas('sessions', ['id' => 'victim-session-iddddddddddddddddddddddddd']);
    }

    public function test_user_can_sign_out_all_other_devices(): void
    {
        [, $user] = $this->makeTenant();
        $this->insertSession('device-a-session-ideeeeeeeeeeeeeeeeeeeeeeeee', $user->id);
        $this->insertSession('device-b-session-idfffffffffffffffffffffffff', $user->id);

        $this->spa()->actingAs($user)->deleteJson('/api/v1/auth/sessions')->assertNoContent();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }
}
