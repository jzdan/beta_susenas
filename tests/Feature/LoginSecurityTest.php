<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        // penguncian akun (aplikasi) diuji terpisah dari throttle IP (framework)
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    public function test_failed_login_is_audited(): void
    {
        $this->post('/login', ['username' => 'admin', 'password' => 'salah'])
            ->assertSessionHasErrors('username');

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'auth', 'entity_id' => 'admin', 'action' => 'login-gagal',
        ]);
        $this->assertGuest();
    }

    public function test_account_locks_after_five_failures_even_with_correct_password(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'admin', 'password' => 'salah'.$i]);
        }

        // sandi benar, tapi akun terkunci sementara
        $res = $this->post('/login', ['username' => 'admin', 'password' => 'admin123']);
        $res->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['entity' => 'auth', 'action' => 'login-terkunci']);
    }

    public function test_successful_login_resets_the_failure_count(): void
    {
        // 4 gagal, lalu sukses
        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['username' => 'rahmawati', 'password' => 'x']);
        }
        $this->post('/login', ['username' => 'rahmawati', 'password' => 'pengawas123'])
            ->assertRedirect();
        auth()->logout();

        // 4 gagal lagi — belum terkunci karena hitungan sudah ter-reset oleh login sukses
        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['username' => 'rahmawati', 'password' => 'y']);
        }
        $this->post('/login', ['username' => 'rahmawati', 'password' => 'pengawas123'])
            ->assertRedirect();
    }

    public function test_audit_security_filter_shows_only_auth_failures(): void
    {
        $this->post('/login', ['username' => 'ppl_ani', 'password' => 'salah']);
        AuditLog::record('document', 1, 'finalisasi', 'x');

        $admin = User::where('username', 'admin')->firstOrFail();
        $rows = collect($this->actingAs($admin)->get('/monitoring/audit?entity=keamanan')->viewData('rows'));
        $this->assertTrue($rows->every(fn ($r) => $r->entity === 'auth'
            && in_array($r->action, ['login-gagal', 'login-terkunci'])));
    }
}
