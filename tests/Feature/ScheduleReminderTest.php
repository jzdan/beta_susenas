<?php

namespace Tests\Feature;

use App\Models\SurveyCycle;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Inc 163 — pengingat jadwal lapangan vs status siklus; data contoh di triwulan berjalan. */
class ScheduleReminderTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    public function test_demo_cycle_sits_in_the_running_quarter(): void
    {
        $c = SurveyCycle::where('name', 'like', '%(contoh)')->firstOrFail();
        $this->assertSame(now()->format('Y-m'), $c->period);
        $this->assertNull($c->scheduleIssue());
        $this->assertFalse($c->fieldOverdue());
    }

    public function test_reminder_notifies_admin_once_and_does_not_change_status(): void
    {
        $late = SurveyCycle::create(['name' => 'SERUTI Juni 2026 (INTI)', 'period' => '2026-06', 'status' => 'lapangan',
            'field_start' => now()->subMonths(2)->toDateString(), 'field_end' => now()->subDays(3)->toDateString()]);
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->artisan('susenas:ingat-jadwal')->assertSuccessful();
        $this->artisan('susenas:ingat-jadwal')->assertSuccessful();

        $n = $admin->unreadNotifications()->where('data->cycle_id', $late->id)->get();
        $this->assertCount(1, $n, 'tidak dobel selama belum dibaca');
        $this->assertStringContainsString('sudah selesai', $n[0]->data['title']);
        $this->assertSame('lapangan', $late->fresh()->status, 'status tidak diubah otomatis');
    }
}
