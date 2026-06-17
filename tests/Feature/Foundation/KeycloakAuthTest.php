<?php

namespace Tests\Feature\Foundation;

use App\Foundation\Auth\KeycloakJwt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FOUNDATION_AUTH seam: flipping SOPHIX_AUTH_DRIVER to 'keycloak' makes every
 * `auth:sanctum` route authenticate Keycloak OIDC tokens — env-only, no route or
 * code change. Modules verify the RS256 JWT locally and bridge sub -> users.uid.
 */
class KeycloakAuthTest extends TestCase
{
    use RefreshDatabase;

    /** @var string */
    private string $privateKey;

    /** @var string */
    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $priv);
        $this->privateKey = $priv;
        $this->publicKey = openssl_pkey_get_details($res)['key'];
    }

    public function test_default_driver_is_sanctum(): void
    {
        $this->assertSame('sanctum', config('sophix.auth.driver'));
    }

    public function test_keycloak_token_authenticates_an_auth_route_without_route_change(): void
    {
        $user = $this->makeUser('kc_subject_1');
        $this->useKeycloak();

        $jwt = $this->mintJwt(['sub' => 'kc_subject_1', 'iss' => 'https://id.yas.sn/realms/sophix', 'exp' => time() + 3600]);

        $this->getJson('/api/user', ['Authorization' => "Bearer {$jwt}"])
            ->assertOk()
            ->assertJsonFragment(['uid' => 'kc_subject_1']);
    }

    public function test_invalid_and_expired_tokens_are_rejected(): void
    {
        $this->makeUser('kc_subject_1');
        $this->useKeycloak();

        // garbage token
        $this->getJson('/api/user', ['Authorization' => 'Bearer not.a.jwt'])->assertUnauthorized();

        // expired token (exp in the past, beyond leeway)
        $expired = $this->mintJwt(['sub' => 'kc_subject_1', 'iss' => 'https://id.yas.sn/realms/sophix', 'exp' => time() - 600]);
        $this->getJson('/api/user', ['Authorization' => "Bearer {$expired}"])->assertUnauthorized();

        // unknown subject (valid signature, no matching users.uid)
        $unknown = $this->mintJwt(['sub' => 'kc_ghost', 'iss' => 'https://id.yas.sn/realms/sophix', 'exp' => time() + 3600]);
        $this->getJson('/api/user', ['Authorization' => "Bearer {$unknown}"])->assertUnauthorized();
    }

    public function test_verifier_rejects_wrong_issuer_and_non_rs256(): void
    {
        $cfg = ['realm_public_key' => $this->publicKey, 'issuer' => 'https://id.yas.sn/realms/sophix', 'leeway' => 30];

        $wrongIss = $this->mintJwt(['sub' => 'x', 'iss' => 'https://evil/realms/x', 'exp' => time() + 60]);
        $this->assertNull(KeycloakJwt::verify($wrongIss, $cfg));

        $good = $this->mintJwt(['sub' => 'x', 'iss' => 'https://id.yas.sn/realms/sophix', 'exp' => time() + 60]);
        $this->assertNotNull(KeycloakJwt::verify($good, $cfg));

        // alg "none" must never validate
        $header = $this->b64url(json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $payload = $this->b64url(json_encode(['sub' => 'x', 'exp' => time() + 60]));
        $this->assertNull(KeycloakJwt::verify("{$header}.{$payload}.", $cfg));
    }

    private function useKeycloak(): void
    {
        config([
            'sophix.auth.driver' => 'keycloak',
            'auth.guards.sanctum.driver' => 'keycloak',
            'sophix.auth.keycloak.realm_public_key' => $this->publicKey,
            'sophix.auth.keycloak.issuer' => 'https://id.yas.sn/realms/sophix',
            'sophix.auth.keycloak.leeway' => 30,
        ]);
        $this->app['auth']->forgetGuards();
    }

    private function makeUser(string $uid): User
    {
        return User::query()->create([
            'uid' => $uid,
            'name' => 'KC User',
            'email' => $uid.'@yas.sn',
            'password' => bcrypt('secret'),
            'operator_code' => 'WIK',
            'status' => 'ACTIVE',
        ]);
    }

    /** @param array<string,mixed> $claims */
    private function mintJwt(array $claims): string
    {
        $header = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->b64url(json_encode($claims));
        openssl_sign("{$header}.{$payload}", $sig, $this->privateKey, OPENSSL_ALGO_SHA256);

        return "{$header}.{$payload}.".$this->b64url($sig);
    }

    private function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
