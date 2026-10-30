<?php

namespace Tests\Feature;

use App\Models\PushDeerKey;
use App\Models\PushDeerMessage;
use App\Models\PushDeerUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private function api(string $uri, array $data = [], string $method = 'POST'): TestResponse
    {
        $this->resetNativeSession();
        return $this->json($method, $uri, $data);
    }

    private function member(string $name = 'one', int $level = 1): PushDeerUser
    {
        $user = PushDeerUser::create(['name' => $name, 'email' => $name.'@example.test', 'level' => $level]);
        $user->simple_token = 'SP-test-'.$name;
        $user->save();
        return $user;
    }

    private function login(PushDeerUser $user): string
    {
        return $this->api('/login/simple_token', ['stoken' => $user->simple_token])
            ->assertOk()->assertJsonPath('code', 0)->json('content.token');
    }

    public function test_native_session_login_and_invalid_tokens(): void
    {
        $user = $this->member();
        $token = $this->login($user);
        $this->api('/user/info', ['token' => $token])->assertOk()->assertJsonPath('content.id', $user->id);
        foreach ([[], ['token' => null], ['token' => ['invalid']], ['token' => 'nonexistent']] as $input) {
            $this->api('/user/info', $input)->assertOk()->assertJsonPath('code', 80403);
        }
        $this->api('/user/info', ['token' => $token])->assertJsonPath('content.id', $user->id);
        $this->api('/login/simple_token', ['stoken' => $this->member('disabled', 0)->simple_token])
            ->assertJsonPath('code', 80501);
    }

    public function test_debug_login_stays_disabled_and_errors_keep_the_api_contract(): void
    {
        $this->api('/login/fake', [], 'GET')->assertOk()->assertJsonPath('error', 'Debug only');
        $this->assertDatabaseCount('push_deer_users', 0);
        $this->api('/message/push')->assertOk()->assertJsonStructure(['code', 'error'])->assertJsonPath('code', 80501);
        $this->api('/login/simple_token', ['stoken' => ['invalid']])->assertJsonPath('code', 80501);
    }

    public function test_device_lifecycle_and_ownership(): void
    {
        $token = $this->login($this->member());
        $otherToken = $this->login($this->member('other'));
        $device = $this->api('/device/reg', ['token' => $token, 'name' => 'iPhone', 'device_id' => 'device-one'])
            ->assertJsonPath('code', 0)->assertJsonPath('content.devices.0.is_clip', 0)->json('content.devices.0.id');
        $this->api('/device/list', ['token' => $otherToken])->assertJsonCount(0, 'content.devices');
        $this->api('/device/remove', ['token' => $otherToken, 'id' => $device])->assertJsonPath('code', 80501);
        $this->api('/device/rename', ['token' => $token, 'id' => $device, 'name' => 'renamed'])->assertJsonPath('code', 0);
        $this->api('/device/list', ['token' => $token])->assertJsonPath('content.devices.0.name', 'renamed');
        $this->api('/device/remove', ['token' => $token, 'id' => $device])->assertJsonPath('code', 0);
        $this->api('/device/list', ['token' => $token])->assertJsonCount(0, 'content.devices');
    }

    public function test_key_lifecycle_and_ownership(): void
    {
        $token = $this->login($this->member());
        $otherToken = $this->login($this->member('other'));
        $key = $this->api('/key/gen', ['token' => $token])->assertJsonPath('code', 0)->json('content.keys.0');
        $this->api('/key/remove', ['token' => $otherToken, 'id' => $key['id']])->assertJsonPath('code', 80501);
        $this->api('/key/rename', ['token' => $token, 'id' => $key['id'], 'name' => 'renamed'])->assertJsonPath('code', 0);
        $this->api('/key/regen', ['token' => $token, 'id' => $key['id']])->assertJsonPath('code', 0);
        $updated = $this->api('/key/list', ['token' => $token])->json('content.keys.0');
        $this->assertNotSame($key['key'], $updated['key']);
        $this->assertSame('renamed', $updated['name']);
        $this->api('/key/remove', ['token' => $token, 'id' => $key['id']])->assertJsonPath('code', 0);
        $this->api('/key/list', ['token' => $token])->assertJsonCount(0, 'content.keys');
    }

    public function test_push_accepts_json_forms_and_get_and_messages_remain_isolated(): void
    {
        $user = $this->member();
        $token = $this->login($user);
        $other = $this->member('other');
        $otherToken = $this->login($other);
        $key = PushDeerKey::create(['uid' => $user->id, 'name' => 'fixture', 'key' => 'PDU-test']);
        $data = ['pushkey' => $key->key, 'text' => 'hello', 'desp' => '**markdown**', 'type' => 'markdown'];
        $this->api('/message/push', $data)->assertJsonPath('code', 0);
        $this->resetNativeSession();
        $this->post('/message/push', $data)->assertOk()->assertJsonPath('code', 0);
        $this->api('/message/push', $data, 'GET')->assertJsonPath('code', 0);
        $this->api('/message/list', ['token' => $token])->assertJsonCount(3, 'content.messages');
        $this->api('/message/list', ['token' => $otherToken])->assertJsonCount(0, 'content.messages');
        $id = PushDeerMessage::first()->id;
        $this->api('/message/remove', ['token' => $otherToken, 'id' => $id])->assertJsonPath('code', 80501);
        $this->api('/message/remove', ['token' => $token, 'id' => $id])->assertJsonPath('code', 0);
        $this->api('/message/clean', ['token' => $token])->assertJsonPath('code', 0);
        $this->assertDatabaseCount('push_deer_messages', 0);
        $this->api('/message/push', ['pushkey' => 'invalid', 'text' => 'hello'])->assertJsonPath('code', 80501);
    }

    public function test_cors_preflight_and_unprefixed_routes(): void
    {
        $this->options('/message/push', [], ['Origin' => 'https://example.test', 'Access-Control-Request-Method' => 'POST'])
            ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->getJson('/api/user/info')->assertNotFound();
    }

    public function test_retention_removes_only_expired_messages(): void
    {
        foreach ([8, 2] as $days) {
            $message = PushDeerMessage::create(['uid' => '1', 'readkey' => 'fixture', 'pushkey_name' => 'fixture', 'text' => 'hello', 'desp' => '', 'type' => 'text']);
            $message->created_at = Carbon::now()->subDays($days);
            $message->save();
        }
        $this->artisan('app:cleanpush')->assertExitCode(0);
        $this->assertDatabaseCount('push_deer_messages', 1);
        $this->assertTrue(PushDeerMessage::first()->created_at->isAfter(Carbon::now()->subDays(7)));
    }
}
