<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // nama pendek untuk Blade: {{ Istilah::severity($x) }}, {{ Tahap::label($t) }}
        $loader = \Illuminate\Foundation\AliasLoader::getInstance();
        $loader->alias('Istilah', \App\Support\Istilah::class);
        $loader->alias('Tahap', \App\Support\Tahap::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // waktu relatif ("1 menit yang lalu") & nama bulan Carbon dalam bahasa Indonesia
        \Illuminate\Support\Carbon::setLocale('id');

        // Kotak "Triwulan berjalan" di menu samping (Inc 154; Inc 156: mengikuti KALENDER hari ini,
        // bukan siklus lapangan berperiode terbaru — data contoh "Maret 2027" sempat tampil di Nov 2026).
        // Siklus lain yang masih berstatus lapangan ditampilkan sebagai catatan (+ tanda jadwal lewat).
        \Illuminate\Support\Facades\View::composer('layouts.app', function ($view) {
            if (! auth()->check() || ! in_array(auth()->user()->role, ['pml', 'pj', 'admin'], true)) {
                return;
            }
            $y = (int) now()->year;
            $q = (int) ceil(now()->month / 3);
            $cur = \App\Models\SurveyCycle::inQuarter($y, $q)->get();
            // Inc 163: catatan bila status siklus tidak cocok dengan kalender
            $late = $cur->first(fn ($c) => $c->fieldShouldHaveStarted());
            $notes = [];
            if ($late) {
                $notes[] = 'Jadwal lapangan sudah mulai '.$late->field_start->day.' '.\App\Models\SurveyCycle::MONTHS[$late->field_start->month]
                    .', '.($cur->filter(fn ($c) => $c->fieldShouldHaveStarted())->count() > 1 ? 'beberapa periode' : $late->label()).' masih Persiapan';
            }
            if ($n = $cur->filter(fn ($c) => $c->fieldOverdue())->count()) {
                $notes[] = "{$n} periode jadwalnya selesai, belum ditutup";
            }
            $view->with('runningQuarter', ['year' => $y, 'q' => $q, 'notes' => $notes,
                'status' => $cur->isEmpty() ? null : \App\Http\Controllers\PeriodeController::combinedStatus($cur)]);
            $groups = [];
            foreach (\App\Models\SurveyCycle::where('status', 'lapangan')->orderBy('period')->get()
                ->reject(fn ($c) => $c->year() === $y && $c->quarter() === $q)
                ->groupBy(fn ($c) => $c->year().'-'.$c->quarter()) as $grp) {
                $c0 = $grp->first();
                $bits = [];
                if ($grp->count() > 1) {
                    $bits[] = $grp->count().' survei';
                }
                if ($c0->quarterNotStarted()) {
                    $bits[] = 'belum waktunya';
                } elseif ($grp->contains(fn ($c) => $c->fieldOverdue())) {
                    $bits[] = 'jadwal selesai, belum ditutup';
                }
                $groups[] = ['year' => $c0->year(), 'q' => $c0->quarter(), 'note' => implode(', ', $bits),
                    'warn' => (bool) array_intersect($bits, ['belum waktunya', 'jadwal selesai, belum ditutup'])];
            }
            $view->with('otherField', $groups);
        });
    }
}
