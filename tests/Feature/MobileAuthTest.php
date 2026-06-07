<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_login_returns_token_and_profile(): void
    {
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['email' => 'tech@sophix.local', 'password' => Hash::make('secret'), 'operator_code' => 'WIK']);
        $user->assignRole('FIELD_TECHNICIAN');

        $res = $this->postJson('/api/auth/token', ['email' => 'tech@sophix.local', 'password' => 'secret', 'device' => 'pwa']);
        $res->assertOk()
            ->assertJsonStructure(['token', 'user' => ['uid', 'roles', 'permissions']])
            ->assertJsonFragment(['workorder.execute']);

        $token = $res->json('token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')->assertOk()->assertJsonPath('email', 'tech@sophix.local');
    }

    public function test_invalid_credentials_rejected(): void
    {
        User::factory()->create(['email' => 'x@sophix.local', 'password' => Hash::make('right')]);
        $this->postJson('/api/auth/token', ['email' => 'x@sophix.local', 'password' => 'wrong'])
            ->assertStatus(401)->assertJsonPath('errorCode', 'UNAUTHENTICATED');
    }

    public function test_mobile_pwa_shell_and_manifest_served(): void
    {
        // Static PWA assets (manifest, sw.js, icons) live in public/ and are served
        // by the web server. The SPA shell route returns the mount point.
        $this->get('/m')->assertOk()->assertSee('sophix-mobile', false)->assertSee('manifest.webmanifest', false);
    }
}
