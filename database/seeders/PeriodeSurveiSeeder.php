<?php

namespace Database\Seeders;

use App\Models\Answer;
use App\Models\Block;
use App\Models\Document;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\SampleHousehold;
use App\Models\SurveyCycle;
use App\Models\User;
use App\Models\Wilayah;
use App\Services\QuestionnairePackage;
use App\Services\RuleEngine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Inc 164 — kerangka Periode Survei 2026 & 2027 + sampel contoh. Menggantikan Susenas2026CycleSeeder.
 *
 * Pola tiap tahun (1 siklus = 1 instrumen):
 *   TW1 Maret     SUSENAS KOR, SUSENAS KP, SERUTI INTI
 *   TW2 Juni      SERUTI INTI, SERUTI MAK
 *   TW3 September SUSENAS Modul, SUSENAS KP, SERUTI INTI
 *   TW4 November  SERUTI INTI, SERUTI MAK
 *
 * - Jadwal lapangan selalu di dalam triwulan periodenya (SERUTI Maret boleh s.d. 15 April).
 * - Status dihitung dari KALENDER saat seeding (calendarStatus): sebelum jadwal → persiapan,
 *   di dalam jadwal → lapangan, lewat ≤ 2 bulan → pengolahan, lewat > 2 bulan → selesai.
 *   Pengecualian: tiga siklus Maret 2026 (KOR/KP/SERUTI) dibuat DatabaseSeeder & SerutiSeeder dan
 *   memegang data contoh utama (isian draf, dikembalikan, dsb.) — statusnya tidak diubah.
 * - Versi kuesioner disalin lewat paket metadata (sama dengan tombol "Siapkan triwulan") dari versi
 *   instrumen yang sama sebelumnya. SUSENAS Modul & SERUTI MAK tetap kosong (dokumen belum ada).
 * - Sampel contoh hanya untuk siklus yang sudah mulai lapangan dan kuesionernya berisi. Isian disalin
 *   dari isian Maret 2026 instrumen yang sama, nilai rupiah dikali satu faktor per isian (jumlah &
 *   rinciannya tetap cocok). Acak tetap (mt_srand) → hasil sama setiap seeding.
 *
 * Dipanggil DatabaseSeeder di luar tes. Manual: php artisan db:seed --class=Database\\Seeders\\PeriodeSurveiSeeder
 * Idempoten: siklus yang namanya sudah ada dilewati.
 */
class PeriodeSurveiSeeder extends Seeder
{
    public const YEARS = [2026, 2027];

    /** [bulan, survei, instrumen] */
    public const PATTERN = [
        [3, 'SUSENAS', 'KOR'], [3, 'SUSENAS', 'KP'], [3, 'SERUTI', 'INTI'],
        [6, 'SERUTI', 'INTI'], [6, 'SERUTI', 'MAK'],
        [9, 'SUSENAS', 'MODUL'], [9, 'SUSENAS', 'KP'], [9, 'SERUTI', 'INTI'],
        [11, 'SERUTI', 'INTI'], [11, 'SERUTI', 'MAK'],
    ];

    /** Siklus bawaan DatabaseSeeder / SerutiSeeder (nama lama dipertahankan). */
    private const EXISTING = [
        '2026-03|SUSENAS|KOR' => 'SUSENAS Maret 2026 (KOR)',
        '2026-03|SUSENAS|KP' => 'SUSENAS Maret 2026 (KP)',
        '2026-03|SERUTI|INTI' => 'SERUTI Maret 2026',
    ];

    /** Banyaknya sampel per siklus = kecamatan × blok sensus × RUTA_PER_BS (18 × 2 × 10 = 360 SUSENAS). */
    private const BS_SUSENAS = 2;

    private const BS_SERUTI = 1;

    private const RUTA_PER_BS = 10;

    private const KRT = ['Sugianto', 'Hj. Maryam', 'Abdul Rahman', 'Suparno', 'Nur Hidayah', 'Slamet Riyadi',
        'Endang Sulastri', 'Muhaimin', 'Rukiyah', 'Fauzan', 'Painem', 'Hartono', 'Siti Aisyah', 'Bambang Wijaya',
        'Ngatinah', 'Yusuf', 'Kholifah', 'Moh. Toyyib', 'Marsini', 'Achmad Fauzi', 'Sulastri', 'Mat Rawi',
        'Halimah', 'Supriyadi', 'Musyarofah', 'Abd. Ghofur', 'Sumiati', 'Rofiqi', 'Juwariyah', 'Hasan Basri'];

    /** Faktor rupiah per isian sebagai pecahan supaya bilangan bulat tetap bulat. */
    private const FACTORS = [[4, 5], [9, 10], [1, 1], [11, 10], [6, 5], [5, 4]];

    public function run(): void
    {
        // pastikan SERUTI Maret 2026 (+ blok INTI lengkap) ada — idempoten
        $this->call(SerutiSeeder::class);

        mt_srand(2026);
        $oldDocMax = (int) Document::max('id');
        $pkg = app(QuestionnairePackage::class);
        $made = 0;
        foreach (self::YEARS as $year) {
            foreach (self::PATTERN as [$month, $survey, $instrument]) {
                $period = sprintf('%04d-%02d', $year, $month);
                $key = "{$period}|{$survey}|{$instrument}";
                if (isset(self::EXISTING[$key])) {
                    continue; // siklus Maret 2026 bawaan — dipakai sebagai sumber, tidak diubah
                }
                $name = self::cycleName($survey, $instrument, $month, $year);
                if (SurveyCycle::where('name', $name)->exists()) {
                    continue;
                }
                [$start, $end] = self::schedule($survey, $year, $month);
                $cycle = SurveyCycle::create([
                    'name' => $name, 'period' => $period, 'status' => 'persiapan',
                    'survey' => $survey, 'instrument' => $instrument,
                    'field_start' => $start, 'field_end' => $end,
                ]);
                $qv = $this->version($pkg, $cycle, $survey, $instrument, $year, $month);
                $status = self::calendarStatus($cycle);
                if ($status !== 'persiapan' && $qv->blocks()->exists()) {
                    $this->samples($cycle, $qv, $status);
                }
                $cycle->update(['status' => $status]); // terakhir: 'selesai' mengunci metadata versinya
                $made++;
            }
        }
        $this->completeExisting($oldDocMax);
        $this->command?->info("Periode Survei: {$made} siklus dibuat (".implode(', ', self::YEARS).').');
    }

    public static function cycleName(string $survey, string $instrument, int $month, int $year): string
    {
        $label = $instrument === 'MODUL' ? 'Modul' : $instrument;

        return "{$survey} ".SurveyCycle::MONTHS[$month]." {$year} ({$label})";
    }

    /** [mulai, selesai] lapangan: sebulan penuh; SERUTI Maret s.d. 15 April (seperti 2026). */
    public static function schedule(string $survey, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1);
        $end = $survey === 'SERUTI' && $month === 3
            ? Carbon::create($year, 4, 15)
            : $start->copy()->endOfMonth();

        return [$start->toDateString(), $end->toDateString()];
    }

    /** Status yang wajar menurut kalender hari ini. */
    public static function calendarStatus(SurveyCycle $c): string
    {
        $today = today();
        if (! $c->field_start || $c->field_start->gt($today)) {
            return 'persiapan';
        }
        if ($c->field_end && $c->field_end->gte($today)) {
            return 'lapangan';
        }

        return $c->field_end && $c->field_end->copy()->addMonthsNoOverflow(SurveyCycle::FIELD_END_GRACE_MONTHS)->lt($today)
            ? 'selesai' : 'pengolahan';
    }

    /**
     * Versi kuesioner siklus baru: salinan paket dari versi instrumen yang sama dengan periode
     * terakhir sebelum siklus ini. Belum ada sumber berisi (Modul, MAK) → versi kosong.
     */
    private function version(QuestionnairePackage $pkg, SurveyCycle $cycle, string $survey, string $instrument, int $year, int $month): QuestionnaireVersion
    {
        $q = (int) ceil($month / 3);
        $code = self::versionCode($survey, $instrument, $year, $q);
        $src = QuestionnaireVersion::query()
            ->whereHas('cycle', fn ($c) => $c->where('survey', $survey)->where('instrument', $instrument)
                ->where('period', '<', $cycle->period)->where('name', 'not like', '%(contoh)%'))
            ->whereHas('blocks')
            ->get()->sortByDesc(fn ($v) => $v->cycle->period.'|'.$v->id)->first();
        $effective = Carbon::create($year, $month, 1)->subMonthNoOverflow()->addDays(4)->toDateString();
        if (! $src) {
            return QuestionnaireVersion::create(['cycle_id' => $cycle->id, 'code' => $code, 'effective_date' => $effective]);
        }
        $data = $pkg->export($src);
        $data['effective_date'] = $effective;

        return $pkg->import($data, $cycle->id, $code);
    }

    /** VSEN26.K, VSEN27.KP-TW3, VSERUTI27.INTI-TW2, VSERUTI26.MAK-TW4, VSEN27.M-TW3 … */
    public static function versionCode(string $survey, string $instrument, int $year, int $q): string
    {
        $base = ($survey === 'SERUTI' ? 'VSERUTI' : 'VSEN').substr((string) $year, 2).'.'
            .(['KOR' => 'K', 'MODUL' => 'M'][$instrument] ?? $instrument);
        // nama lama 2026: Modul September = VSEN26.M (tanpa -TW3)
        $code = $q === 1 || $instrument === 'MODUL' ? $base : "{$base}-TW{$q}";
        $try = $code;
        for ($i = 2; QuestionnaireVersion::where('code', $try)->exists(); $i++) {
            $try = "{$code}-{$i}";
        }

        return $try;
    }

    /**
     * Sampel contoh + isian. Kerangka per kecamatan (yang punya desa): SUSENAS BS_SUSENAS blok sensus,
     * SERUTI BS_SERUTI blok sensus, masing-masing RUTA_PER_BS rumah tangga. Satu blok sensus = satu
     * Pencacah; Pengawas mengikuti kecamatannya (PetugasBangkalanSeeder::assignment(); dataset tes →
     * 3 petugas & 2 pengawas bawaan). SEMUA sampel berisi isian kecuali ±3% non-respons.
     */
    private function samples(SurveyCycle $cycle, QuestionnaireVersion $qv, string $status): void
    {
        $sources = $this->sourceDocuments($this->sourceVersionIds($cycle->survey, $cycle->instrument));
        $kecs = Wilayah::where('level', 'desa')->where('code', 'like', '3526%')->orderBy('code')->get()
            ->groupBy('parent_code');
        if ($kecs->isEmpty() || ! $sources) {
            return;
        }
        $team = PetugasBangkalanSeeder::assignment();
        $fallback = User::whereIn('username', ['ppl_ani', 'ppl_dodi', 'ppl_eka', 'rahmawati', 'santoso'])->pluck('id', 'username');
        $nBs = $cycle->survey === 'SERUTI' ? self::BS_SERUTI : self::BS_SUSENAS;
        $month = $cycle->month() ?? 1;
        $frame = [];
        $k = 0;
        foreach ($kecs as $kec => $desa) {
            for ($b = 0; $b < $nBs; $b++, $k++) {
                $wil = $desa[($b * 7 + $month * 3 + $k) % $desa->count()]->code;
                $bs = sprintf('%03dB', ($cycle->survey === 'SERUTI' ? 100 : 0) + $month + $b * 20);
                $ppl = isset($team[$kec]) ? $team[$kec]['ppl'][($b + intdiv($month, 3)) % 2]
                    : ($fallback[['ppl_ani', 'ppl_dodi', 'ppl_eka'][$k % 3]] ?? null);
                $pml = $team[$kec]['pml'] ?? ($fallback[$kec === '3526010' ? 'rahmawati' : 'santoso'] ?? null);
                for ($r = 1; $r <= self::RUTA_PER_BS; $r++) {
                    $frame[] = [$wil, $bs, sprintf('%03d', $r * 3 - 1 + ($k % 2)), $ppl, $pml];
                }
            }
        }
        $plan = self::stagePlan($status, count($frame));
        $made = [];
        foreach ($frame as $i => [$wil, $bs, $ruta, $ppl, $pml]) {
            $made[] = [SampleHousehold::create([
                'cycle_id' => $cycle->id, 'wilayah_code' => $wil, 'no_bs' => $bs, 'no_ruta' => $ruta,
                'krt_name' => self::KRT[($i * 7 + $month) % count(self::KRT)],
                'petugas_id' => $ppl, 'pml_id' => $pml, 'weight' => 120 + ($i * 37 + $month) % 260,
                'status' => 'belum',
            ]), $plan[$i]];
        }
        $this->fillSamples($cycle, $qv, $made, $sources);
    }

    /**
     * Buat isian untuk [[sampel, tahap], …]: jawaban disalin dari isian sumber (rupiah × faktor),
     * blok yang tetap kosong dilengkapi IsianSintetis. Status sampel ikut tahapnya.
     */
    private function fillSamples(SurveyCycle $cycle, QuestionnaireVersion $qv, array $pairs, array $sources): void
    {
        $editor = User::where('username', 'siti_editor')->value('id');
        $targetQ = Question::whereIn('block_id', Block::where('qversion_id', $qv->id)->pluck('id'))
            ->get(['id', 'var_name', 'qtype'])->keyBy('var_name');
        $engine = app(RuleEngine::class);
        $filler = new IsianSintetis($engine);
        $runRules = app()->runningUnitTests() || filter_var(env('SEED_ATURAN', true), FILTER_VALIDATE_BOOL);
        $start = $cycle->field_start->copy();
        $end = $cycle->field_end->copy()->min(today());
        $span = max(1, (int) $start->diffInDays($end));
        $n = count($pairs);

        foreach ($pairs as $i => [$sample, $stage]) {
            $sample->update([
                'status' => match ($stage) {
                    'non_respons' => 'non_respons', 'draft', 'dikembalikan' => 'proses', default => 'selesai',
                },
                'nonresponse_reason' => $stage === 'non_respons'
                    ? ['Rumah tangga menolak diwawancarai', 'Responden tidak ditemui setelah 3 kali kunjungan'][$i % 2] : null,
            ]);
            if ($stage === 'non_respons') {
                continue;
            }
            $opened = $start->copy()->addDays((int) floor($span * ($i / max(1, $n))))->setTime(8 + $i % 8, 15)->min(now());
            $submitted = $opened->copy()->addDays(1 + $i % 3)->min(now());
            $doc = new Document;
            $doc->forceFill([
                'sample_id' => $sample->id, 'qversion_id' => $qv->id, 'status' => $stage,
                'created_by' => $sample->petugas_id, 'created_at' => $opened, 'updated_at' => $submitted,
                'submitted_at' => $stage === 'draft' ? null : $submitted,
            ]);
            if ($stage === 'bersih') {
                $doc->forceFill(['cleaned_by' => $editor, 'cleaned_at' => $submitted->copy()->addDays(2)->min(now())]);
            }
            if ($stage === 'dikembalikan') {
                $doc->forceFill(['return_reason' => 'Mohon konfirmasi ulang pengeluaran ke responden — nilainya jauh di atas rumah tangga lain di blok sensus ini.',
                    'returned_by' => $sample->pml_id, 'returned_at' => $submitted->copy()->addDay()->min(now())]);
            }
            $doc->save();
            $this->copyAnswers($sources[$i % count($sources)], $doc, $targetQ);
            $filler->fill($doc, $opened->toDateString());
            if ($runRules) {
                $engine->run($doc);
            }
        }
    }

    /**
     * Siklus contoh (DatabaseSeeder::questionnaire) punya sampel "belum dikunjungi" — isi juga,
     * sumber = isiannya sendiri. Isian lama yang blok-bloknya kosong (mis. SERUTI Maret) dilengkapi.
     */
    private function completeExisting(int $oldDocMax): void
    {
        foreach (SurveyCycle::where('name', 'like', '%(contoh)%')->get() as $cycle) {
            $qv = $cycle->versions()->orderByDesc('id')->first();
            $docSamples = Document::pluck('sample_id');
            $empty = $cycle->samples()->whereNotIn('id', $docSamples)->orderBy('id')->get();
            if (! $qv || $empty->isEmpty()) {
                continue;
            }
            $plan = self::stagePlan($cycle->status === 'persiapan' ? 'lapangan' : $cycle->status, $empty->count());
            $this->fillSamples($cycle, $qv, $empty->values()->map(fn ($s, $i) => [$s, $plan[$i]])->all(),
                $this->sourceDocuments(collect([$qv->id])));
        }
        $engine = app(RuleEngine::class);
        $filler = new IsianSintetis($engine);
        $runRules = app()->runningUnitTests() || filter_var(env('SEED_ATURAN', true), FILTER_VALIDATE_BOOL);
        foreach (Document::where('id', '<=', $oldDocMax)->orderBy('id')->get() as $doc) {
            if ($filler->fill($doc, $doc->created_at?->toDateString()) && $runRules) {
                $engine->run($doc);
            }
        }
    }

    /** Urutan tahap untuk $n sampel (deterministik, tercampur antar-kecamatan); non-respons ±3%. */
    public static function stagePlan(string $status, int $n): array
    {
        $mix = match ($status) {
            'lapangan' => array_merge(array_fill(0, 13, 'selesai'), array_fill(0, 8, 'draft'),
                array_fill(0, 8, 'bersih'), ['dikembalikan', 'dikembalikan', 'non_respons']),
            'pengolahan' => array_merge(array_fill(0, 18, 'bersih'), array_fill(0, 11, 'selesai'),
                ['dikembalikan', 'dikembalikan', 'non_respons']),
            default => array_merge(array_fill(0, 31, 'bersih'), ['non_respons']),
        };
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $mix[($i * 13) % count($mix)]; // 13 & 32 koprima → semua tahap muncul merata
        }

        return $out;
    }

    /** Versi sumber isian: siklus Maret 2026 instrumen yang sama (data contoh utama). */
    private function sourceVersionIds(string $survey, ?string $instrument)
    {
        return QuestionnaireVersion::whereHas('cycle', fn ($c) => $c->where('survey', $survey)
            ->where('instrument', $instrument)->where('period', 'like', '2026-03%'))->pluck('id');
    }

    /** Isian versi-versi itu → [[var_name, art_index, nilai, qtype], …] per isian. */
    private function sourceDocuments($qvIds): array
    {
        $out = [];
        foreach (Document::whereIn('qversion_id', $qvIds)->orderBy('id')->get() as $d) {
            $rows = Answer::where('document_id', $d->id)->join('questions', 'questions.id', '=', 'answers.question_id')
                ->get(['questions.var_name', 'questions.qtype', 'answers.art_index', 'answers.raw_value', 'answers.clean_value'])
                ->map(fn ($a) => [$a->var_name, (int) $a->art_index, $a->clean_value ?? $a->raw_value, $a->qtype])->all();
            if (count($rows) >= 3) {
                $out[] = $rows;
            }
        }

        return $out;
    }

    private function copyAnswers(array $rows, Document $doc, $targetQ): void
    {
        [$num, $den] = self::FACTORS[mt_rand(0, count(self::FACTORS) - 1)];
        $now = $doc->updated_at ?? now();
        $insert = [];
        foreach ($rows as [$var, $art, $val, $qtype]) {
            $q = $targetQ->get($var);
            if (! $q || $val === null || $val === '') {
                continue;
            }
            if (in_array($qtype, ['int', 'decimal'], true) && is_numeric($val) && abs((float) $val) >= 1000
                && (string) (int) $val === (string) $val) {
                $val = (string) intdiv((int) $val * $num + intdiv($den, 2), $den);
            }
            $insert[] = ['document_id' => $doc->id, 'question_id' => $q->id, 'art_index' => $art,
                'raw_value' => (string) $val, 'clean_value' => null, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($insert, 400) as $chunk) {
            DB::table('answers')->insert($chunk);
        }
    }
}
