<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use App\Models\PushDeerUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdentityTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey = '';
    private array $claims;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $base64 = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        Http::preventStrayRequests();
        Http::fake(['https://appleid.apple.com/auth/keys' => Http::response(['keys' => [[
            'kty' => 'RSA', 'kid' => 'fixture', 'alg' => 'RS256', 'use' => 'sig',
            'n' => $base64($rsa['n']), 'e' => $base64($rsa['e']),
        ]]])]);
        $this->claims = ['iss' => 'https://appleid.apple.com', 'aud' => 'com.pushdeer.app.ios',
            'sub' => 'apple-fixture', 'email' => 'apple@example.test', 'iat' => time(), 'exp' => time() + 3600];
    }

    public function test_apple_login_uses_the_maintained_verifier(): void
    {
        $jwt = JWT::encode($this->claims, $this->privateKey, 'RS256', 'fixture');
        $token = $this->postJson('/login/idtoken', ['idToken' => $jwt])
            ->assertOk()->assertJsonPath('code', 0)->json('content.token');
        $this->assertDatabaseHas('push_deer_users', ['apple_id' => 'apple-fixture', 'email' => 'apple@example.test']);
        $this->resetNativeSession();
        $this->postJson('/user/info', ['token' => $token])->assertJsonPath('content.apple_id', 'apple-fixture');
        Http::assertSent(fn ($request) => $request->url() === 'https://appleid.apple.com/auth/keys');
    }

    public function test_apple_verification_rejects_untrusted_claims_signatures_and_keys(): void
    {
        foreach ([['aud' => 'another.app'], ['iss' => 'https://attacker.invalid'], ['exp' => time() - 60],
            ['nbf' => time() + 3600], ['sub' => '']] as $changes) {
            $jwt = JWT::encode(array_replace($this->claims, $changes), $this->privateKey, 'RS256', 'fixture');
            $this->assertFalse(getUserDataFromIdentityToken($jwt));
        }
        $this->assertFalse(getUserDataFromIdentityToken(JWT::encode($this->claims, $this->privateKey, 'RS256', 'unknown')));
        $this->assertFalse(getUserDataFromIdentityToken(JWT::encode($this->claims, str_repeat('x', 32), 'HS256', 'fixture')));
        $jwt = JWT::encode($this->claims, $this->privateKey, 'RS256', 'fixture');
        $parts = explode('.', $jwt);
        $parts[1] = rtrim(strtr(base64_encode(json_encode(array_replace($this->claims, ['sub' => 'tampered']))), '+/', '-_'), '=');
        $this->assertFalse(getUserDataFromIdentityToken(implode('.', $parts)));
        $this->postJson('/login/idtoken', ['idToken' => 'invalid'])->assertJsonPath('code', 80501);
        $this->assertDatabaseCount('push_deer_users', 0);
    }

    public function test_apple_key_fetch_failures_fail_closed(): void
    {
        $jwt = JWT::encode($this->claims, $this->privateKey, 'RS256', 'fixture');
        Http::swap(new Factory());
        Http::fakeSequence()->push('unavailable', 503)->push(['keys' => []]);
        $this->assertFalse(getUserDataFromIdentityToken($jwt));
        $this->assertFalse(getUserDataFromIdentityToken($jwt));
    }

    public function test_wechat_and_self_hosted_login_response_contracts(): void
    {
        Http::fake([
            'https://api.weixin.qq.com/*' => Http::response(['access_token' => 'fixture', 'unionid' => 'wechat-fixture']),
            'https://api2.pushdeer.com/*' => Http::response(['content' => ['unionid' => 'hosted-fixture']]),
        ]);
        $token = $this->postJson('/login/wecode', ['code' => 'fixture'])
            ->assertJsonPath('code', 0)->json('content.token');
        $this->resetNativeSession();
        $this->postJson('/user/info', ['token' => $token])->assertJsonPath('content.wechat_id', 'wechat-fixture');
        $this->resetNativeSession();
        $this->postJson('/login/wecode', ['code' => 'fixture', 'self_hosted' => 1])->assertJsonPath('code', 0);
        $this->assertDatabaseHas('push_deer_users', ['wechat_id' => 'hosted-fixture']);
        Http::swap(new Factory());
        Http::fake(['*' => Http::response(['errcode' => 40029])]);
        $this->resetNativeSession();
        $this->postJson('/login/wecode', ['code' => 'invalid'])->assertJsonPath('code', 80502);
    }

    public function test_account_merge_uses_the_same_apple_verification(): void
    {
        $user = PushDeerUser::create(['name' => 'merge-target', 'level' => 1]);
        $user->simple_token = 'SP-merge-fixture';
        $user->save();
        $token = $this->postJson('/login/simple_token', ['stoken' => $user->simple_token])->json('content.token');
        $this->resetNativeSession();
        $jwt = JWT::encode($this->claims, $this->privateKey, 'RS256', 'fixture');
        $this->postJson('/user/merge', ['token' => $token, 'type' => 'apple', 'tokenorcode' => $jwt])
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('push_deer_users', ['id' => $user->id, 'apple_id' => 'apple-fixture']);
        $this->resetNativeSession();
        $bad = JWT::encode(array_replace($this->claims, ['aud' => 'wrong.app']), $this->privateKey, 'RS256', 'fixture');
        $this->postJson('/user/merge', ['token' => $token, 'type' => 'apple', 'tokenorcode' => $bad])
            ->assertJsonPath('code', 80501);
        $this->assertDatabaseHas('push_deer_users', ['id' => $user->id, 'apple_id' => 'apple-fixture']);
    }
}
