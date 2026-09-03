<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_user_changes_own_password(): void
    {
        $u = User::where('username', 'ppl_ani')->firstOrFail();

        $this->actingAs($u)->post('/akun/sandi', [
            'current_password' => 'operator123',
            'password' => 'RahasiaBaru9',
            'password_confirmation' => 'RahasiaBaru9',
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertTrue(Hash::check('RahasiaBaru9', $u->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'ganti-sandi-sendiri', 'by_user' => $u->id]);
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $u = User::where('username', 'ppl_ani')->firstOrFail();
        $this->actingAs($u)->post('/akun/sandi', [
            'current_password' => 'salah',
            'password' => 'RahasiaBaru9',
            'password_confirmation' => 'RahasiaBaru9',
        ])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('operator123', $u->fresh()->password));
    }

    public function test_new_password_cannot_contain_username_or_be_weak(): void
    {
        $u = User::where('username', 'ppl_ani')->firstOrFail();

        $this->actingAs($u)->post('/akun/sandi', [
            'current_password' => 'operator123',
            'password' => 'ppl_ani12345',
            'password_confirmation' => 'ppl_ani12345',
        ])->assertSessionHasErrors('password');

        $this->actingAs($u)->post('/akun/sandi', [
            'current_password' => 'operator123',
            'password' => 'pendekaja',
            'password_confirmation' => 'pendekaja',
        ])->assertSessionHasErrors('password');   // tanpa angka
    }
}
