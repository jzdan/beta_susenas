<?php

namespace App\Http\Controllers;

use App\Models\Answer;
use App\Models\AuditLog;
use App\Models\Block;
use App\Models\DerivedField;
use App\Models\Document;
use App\Models\Parameter;
use App\Models\Question;
use App\Models\QuestionnaireVersion;
use App\Models\ResponseOption;
use App\Models\RuleResult;
use App\Models\SampleHousehold;
use App\Models\SurveyCycle;
use App\Models\User;
use App\Models\ValidationRule;
use App\Models\Wilayah;
use App\Services\ExpressionEvaluator;
use App\Services\RuleEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    private const QTYPES = ['int', 'decimal', 'code', 'text'];
    private const LEVELS = ['kabupaten', 'kecamatan', 'desa'];

    public function __construct(private ExpressionEvaluator $ev, private RuleEngine $engine) {}

    /**
     * Kunci di `block_rows.ref` yang-managed di halaman "Harga & Kalori" (Inc 141).
     * Nilai ini dirujuk aturan lewat `ref_<kunci>` (ref_harga_min, ref_harga_max, ...),
     * jadi TIDAK boleh disunting dari editor baris di Metadata — satu angka, satu tempat.
     */
    private const HARGA_REF_KEYS = ['harga_min', 'harga_max', 'kalori'];

    private function activeQv(): QuestionnaireVersion
    {
        return QuestionnaireVersion::active();
    }

    /**
     * Versi kuesioner yang SEDANG DILIHAT/DISUNTING di Metadata/Aturan/Harga — lewat
     * query/field `qv`, agar admin tidak perlu "Jadikan aktif" cuma untuk menyunting
     * versi lain (Inc 131). Beda dari activeQv(): itu tetap satu-satunya versi yang
     * dipakai PCL untuk entri data baru, tidak berubah oleh selector ini.
     */
    private function selectedQv(Request $request): QuestionnaireVersion
    {
        $id = $request->input('qv');
        if ($id && ($qv = QuestionnaireVersion::find($id))) {
            return $qv;
        }

        return $this->activeQv();
    }

    private function csvRows(string $data, array $required): \Generator
    {
        $lines = preg_split('/\r\n|\r|\n/', trim(ltrim($data, "\u{FEFF}")));
        $header = array_map('trim', str_getcsv(array_shift($lines) ?? ''));
        if (array_diff($required, $header)) {
            abort(400, 'Header CSV minimal: '.implode(', ', $required));
        }
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line);
            $row = [];
            foreach ($header as $j => $h) {
                $row[$h] = trim($cells[$j] ?? '');
            }
            yield [$i + 2, $row];
        }
    }

    // -------------------------------------------------------- Metadata

    public function metadata(Request $request)
    {
        $qv = $this->selectedQv($request);

        // Inc 141: Metadata = HANYA membuat kuesioner (versi, blok, rincian, opsi, baris).
        // Parameter, agregat (NERACA), turunan, aturan, harga & kalori pindah ke menu
        // "Aturan Validasi" — supaya ada satu tempat untuk semua mesin & patokan.
        // total sampel per siklus (sampel yang sudah digantikan tidak menempati kuota — sama
        // seperti Dashboard); versi → siklus lewat cycle_id
        $sampleTotals = SampleHousehold::where('status', '!=', 'ganti_sampel')
            ->selectRaw('cycle_id, count(*) as n')->groupBy('cycle_id')->pluck('n', 'cycle_id');

        return view('admin.metadata', [
            'qv' => $qv,
            'activeId' => $this->activeQv()->id,
            'blocks' => $qv->blocks()->with('questions')->get(),
            'versions' => QuestionnaireVersion::withCount('documents')->orderByDesc('id')->get(),
            'sampleTotals' => $sampleTotals,
        ]);
    }

    /**
     * Rincian sampel satu versi kuesioner: per kecamatan → desa, siapa petugas & PML-nya,
     * berapa yang sudah diisi. Sampel diambil dari siklus versi itu.
     */
    public function versionSamples(QuestionnaireVersion $qv)
    {
        $samples = SampleHousehold::where('cycle_id', $qv->cycle_id)
            ->with(['petugas:id,name', 'pml:id,name', 'documents:id,sample_id,status'])
            ->orderBy('wilayah_code')->orderBy('no_bs')->orderBy('no_ruta')->get();
        $wil = Wilayah::pluck('name', 'code');
        $kecLen = self::WIL_LEN['kecamatan'];

        $kec = [];
        foreach ($samples as $s) {
            $k = substr($s->wilayah_code, 0, $kecLen);
            $kec[$k] ??= ['code' => $k, 'name' => $wil[$k] ?? $k, 'desa' => [],
                'total' => 0, 'belum' => 0, 'proses' => 0, 'selesai' => 0, 'diganti' => 0, 'petugas' => [], 'pml' => []];
            $dc = $s->wilayah_code;
            $kec[$k]['desa'][$dc] ??= ['code' => $dc, 'name' => $wil[$dc] ?? $dc,
                'rows' => [], 'total' => 0, 'belum' => 0, 'proses' => 0, 'selesai' => 0, 'petugas' => []];
            $kec[$k]['desa'][$dc]['rows'][] = $s;
            if ($s->status === 'ganti_sampel') {
                $kec[$k]['diganti']++;
            } else {
                // 3 kategori saling lepas (totalnya selalu pas) — ganti dari "terisi vs selesai" yang
                // tumpang tindih & ambigu: terisi (ada dokumen, termasuk draft) tidak sama dengan
                // selesai dicacah (status sampel = 'selesai', cuma berubah saat petugas finalisasi).
                $selesai = $s->status === 'selesai' ? 1 : 0;
                $proses = (! $selesai && $s->documents->isNotEmpty()) ? 1 : 0;
                $belum = (! $selesai && ! $proses) ? 1 : 0;
                $kec[$k]['total']++;
                $kec[$k]['belum'] += $belum;
                $kec[$k]['proses'] += $proses;
                $kec[$k]['selesai'] += $selesai;
                $kec[$k]['desa'][$dc]['total']++;
                $kec[$k]['desa'][$dc]['belum'] += $belum;
                $kec[$k]['desa'][$dc]['proses'] += $proses;
                $kec[$k]['desa'][$dc]['selesai'] += $selesai;
            }
            if ($s->petugas) {
                $kec[$k]['petugas'][$s->petugas->id] = $s->petugas->name;
                $kec[$k]['desa'][$dc]['petugas'][$s->petugas->id] = $s->petugas->name;
            }
            if ($s->pml) {
                $kec[$k]['pml'][$s->pml->id] = $s->pml->name;
            }
        }
        ksort($kec);

        // ringkasan per petugas
        $perPetugas = $samples->where('status', '!=', 'ganti_sampel')
            ->groupBy(fn ($s) => $s->petugas_id ?? 0)
            ->map(fn ($g, $pid) => [
                'name' => $pid ? ($g->first()->petugas->name ?? '—') : 'Belum ditugaskan',
                'total' => $g->count(),
                'proses' => $g->filter(fn ($s) => $s->status !== 'selesai' && $s->documents->isNotEmpty())->count(),
                'selesai' => $g->where('status', 'selesai')->count(),
                'desa' => $g->pluck('wilayah_code')->unique()->map(fn ($c) => $wil[$c] ?? $c)->values()->all(),
            ])->sortBy('name')->values();

        $aktif = $samples->where('status', '!=', 'ganti_sampel');

        return view('admin.versi_sampel', [
            'qv' => $qv, 'cycle' => $qv->cycle, 'kec' => $kec, 'perPetugas' => $perPetugas,
            'total' => $aktif->count(),
            'proses' => $aktif->filter(fn ($s) => $s->status !== 'selesai' && $s->documents->isNotEmpty())->count(),
            'selesai' => $aktif->where('status', 'selesai')->count(),
            'statusLabels' => \App\Http\Controllers\MonitoringController::STATUS,
        ]);
    }

    public function saveAggregate(Request $request)
    {
        $d = $request->validate([
            'name' => 'required|string', 'block_code' => 'required|string',
            'value_var' => 'required|string', 'op' => 'nullable|string',
            'weight_ref' => 'nullable|string',
            'filter_var' => 'nullable|string', 'filter_op' => 'nullable|string',
            'filter_val' => 'nullable|string', 'aggregate_id' => 'nullable|integer',
            'filter_ref' => 'nullable|string', 'filter_ref_val' => 'nullable|string',
            'order' => 'nullable|integer',
        ]);
        $payload = [
            'name' => trim($d['name']), 'label' => trim($request->input('label', '')),
            'order' => (int) ($d['order'] ?? 0),
            'block_code' => trim($d['block_code']), 'value_var' => trim($d['value_var']),
            'op' => in_array($d['op'] ?? '', ['sum', 'weighted_sum', 'count', 'max', 'min'], true) ? $d['op'] : 'sum',
            'weight_ref' => trim($d['weight_ref'] ?? ''),
            'filter_var' => trim($d['filter_var'] ?? ''),
            'filter_op' => in_array($d['filter_op'] ?? '', ['any', 'eq', 'in', 'ne'], true) ? $d['filter_op'] : 'any',
            'filter_val' => trim($d['filter_val'] ?? ''),
            'filter_ref' => trim($d['filter_ref'] ?? ''),
            'filter_ref_val' => trim($d['filter_ref_val'] ?? ''),
        ];
        $qv = $this->selectedQv($request);
        if ($d['aggregate_id'] ?? null) {
            \App\Models\Aggregate::where('id', $d['aggregate_id'])->update($payload);
        } else {
            \App\Models\Aggregate::create($payload + ['qversion_id' => $qv->id]);
        }
        AuditLog::record('aggregate', $d['aggregate_id'] ?? 'baru', 'simpan', $d['name']);
        return redirect()->route('admin.metadata', ['qv' => $qv->id]);
    }

    public function delAggregate(\App\Models\Aggregate $aggregate)
    {
        $qvId = $aggregate->qversion_id;
        $aggregate->delete();
        return redirect()->route('admin.metadata', ['qv' => $qvId]);
    }

    // -------------------------------------------------------- Blok

    private const BLOCK_REPEAT = Block::REPEATS;

    public function addBlock(Request $request)
    {
        $d = $request->validate([
            'code' => 'required|string', 'title' => 'required|string',
            'repeat' => 'nullable|in:'.implode(',', self::BLOCK_REPEAT),
        ]);
        $qv = $this->selectedQv($request);
        $n = (int) Block::where('qversion_id', $qv->id)->max('order');
        $b = Block::create([
            'qversion_id' => $qv->id, 'code' => trim($d['code']), 'title' => trim($d['title']),
            'repeat' => $d['repeat'] ?? 'ruta', 'order' => $n + 1,
        ]);
        AuditLog::record('block', $b->id, 'tambah', "{$d['code']} ({$b->repeat})");

        return redirect()->route('admin.blok', $b);
    }

    public function reorderBlock(Request $request, Block $block)
    {
        $sibs = Block::where('qversion_id', $block->qversion_id)->orderBy('order')->orderBy('id')->get()->values();
        $i = $sibs->search(fn ($x) => $x->id === $block->id);
        $j = $request->input('arah') === 'naik' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < $sibs->count()) {
            [$a, $b] = [$sibs[$i], $sibs[$j]];
            [$a->order, $b->order] = [$b->order, $a->order];
            $a->save();
            $b->save();
            AuditLog::record('block', $block->id, 'urutan', "{$a->code} <-> {$b->code}");
        }

        return redirect()->route('admin.metadata', ['qv' => $block->qversion_id]);
    }

    public function updateBlock(Request $request, Block $block)
    {
        $d = $request->validate([
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'title' => 'required|string|max:200',
            'repeat' => 'required|in:'.implode(',', self::BLOCK_REPEAT),
            'harga_kalori' => 'nullable|boolean',
        ], [
            'code.regex' => 'Kode blok hanya huruf, angka, titik, garis bawah, atau strip — mis. IV.1, V, IV.1M.',
        ]);
        // ubah tipe berulang hanya aman bila belum ada jawaban
        if ($d['repeat'] !== $block->repeat) {
            $hasAns = \App\Models\Answer::whereIn('question_id', $block->questions()->pluck('id'))->exists();
            abort_if($hasAns, 409, 'Blok sudah punya jawaban — tipe berulang tidak bisa diubah.');
        }
        $oldCode = $block->code;
        $newCode = trim((string) ($d['code'] ?? '')) ?: $oldCode;
        if ($newCode !== $oldCode
            && Block::where('qversion_id', $block->qversion_id)->where('code', $newCode)->exists()) {
            return back()->withErrors(['code' => "Kode blok \"{$newCode}\" sudah dipakai blok lain di versi ini."]);
        }
        // tanda blok Harga & Kalori hanya bermakna untuk blok daftar baris tetap
        $harga = $request->boolean('harga_kalori') && $d['repeat'] === 'list';

        DB::transaction(function () use ($block, $d, $oldCode, $newCode, $harga) {
            $block->update([
                'code' => $newCode, 'title' => trim($d['title']), 'repeat' => $d['repeat'], 'harga_kalori' => $harga,
            ]);
            // Inc 145: aturan per-baris & agregat merujuk blok lewat KODE (teks) — ikut diganti
            // supaya tidak diam-diam putus saat kode blok diubah (mis. IV.1 → V).
            if ($newCode !== $oldCode) {
                ValidationRule::where('qversion_id', $block->qversion_id)->where('row_block', $oldCode)
                    ->update(['row_block' => $newCode]);
                \App\Models\Aggregate::where('qversion_id', $block->qversion_id)->where('block_code', $oldCode)
                    ->update(['block_code' => $newCode]);
            }
        });
        AuditLog::record('block', $block->id, 'ubah',
            ($newCode !== $oldCode ? "{$oldCode} → {$newCode}" : $newCode).": {$d['repeat']}".($harga ? ' · harga&kalori' : ''));

        return redirect()->route('admin.blok', $block)
            ->with('ok', $newCode !== $oldCode
                ? "Kode blok diganti {$oldCode} → {$newCode}; aturan & agregat yang menunjuk blok ini ikut diperbarui."
                : 'Blok disimpan.');
    }

    public function blockEditor(Request $request, Block $block)
    {
        $rows = $block->rows()->orderBy('row_index')->get();
        // Kolom referensi yang dipakai di blok ini. Harga & Kalori disunting di
        // "Aturan Validasi → Harga & Kalori" (Inc 141), jadi dikecualikan di sini —
        // supaya tiap angka hanya punya satu tempat ubah.
        $refKeys = $rows->flatMap(fn ($r) => array_keys($r->ref ?? []))
            ->unique()->reject(fn ($k) => $k === 'group' || in_array($k, self::HARGA_REF_KEYS, true))
            ->values();

        $fQ = trim((string) $request->query('q', ''));
        if ($fQ !== '') {
            $rows = $rows->filter(fn ($r) => stripos($r->code.' '.$r->label, $fQ) !== false)->values();
        }

        return view('admin.block_editor', [
            'b' => $block,
            'questions' => $block->questions,
            'rows' => $rows,
            'refKeys' => $refKeys,
            'fQ' => $fQ,
            'rowTotal' => $block->rows()->count(),
            'qtypes' => self::QTYPES,
        ]);
    }

    public function saveRow(Request $request, Block $block)
    {
        abort_unless($block->isRowBlock(), 400, 'Blok ini bukan tipe daftar.');
        $d = $request->validate([
            'row_id' => 'nullable|integer',
            'row_index' => 'required|integer|min:1',
            'code' => 'nullable|string|max:24',
            'label' => 'required|string|max:300',
            'ref' => 'nullable|array',
        ]);
        $ref = collect($d['ref'] ?? [])->map(fn ($v) => is_string($v) ? trim($v) : $v)
            ->filter(fn ($v) => $v !== '' && $v !== null)
            // Inc 141: harga & Calories hanya boleh diubah lewat tab "Harga & Kalori"
            // (Aturan Validasi) — bukan dari editor baris di Metadata.
            ->reject(fn ($v, $k) => in_array($k, self::HARGA_REF_KEYS, true))
            ->all();

        $existing = \App\Models\BlockRow::where('block_id', $block->id)
            ->where('row_index', $d['row_index'])->first();
        if ($existing && (int) $existing->id !== (int) ($d['row_id'] ?? 0)) {
            return back()->withErrors(['row_index' => "Nomor baris {$d['row_index']} sudah dipakai."]);
        }

        $payload = [
            'code' => trim($d['code'] ?? ''), 'label' => trim($d['label']),
            'row_index' => $d['row_index'], 'ref' => $ref ?: null,
        ];
        if ($d['row_id'] ?? null) {
            \App\Models\BlockRow::where('id', $d['row_id'])->update($payload);
        } else {
            $payload['order'] = (int) \App\Models\BlockRow::where('block_id', $block->id)->max('order') + 1;
            \App\Models\BlockRow::create($payload + ['block_id' => $block->id]);
        }
        AuditLog::record('block_row', $block->code, 'simpan', "baris {$d['row_index']}");

        return back()->with('ok', 'Baris disimpan.');
    }

    public function delRow(\App\Models\BlockRow $row)
    {
        $bid = $row->block_id;
        $row->delete();
        AuditLog::record('block_row', $bid, 'hapus', "baris {$row->row_index}");

        return back()->with('ok', 'Baris dihapus.');
    }

    /** Impor baris tetap (block_rows) untuk blok tipe daftar — CSV atau Excel. */
    public function importRows(Request $request, Block $block)
    {
        abort_unless($block->isRowBlock(), 400, 'Blok ini bukan tipe daftar (list)');
        $d = $request->validate([
            'data' => 'nullable|string', 'mode' => 'nullable|string',
            'file' => 'nullable|file|mimes:xlsx,csv,txt|max:5120', 'sheet' => 'nullable|string|max:64',
        ]);
        try {
            $rows = $request->hasFile('file')
                ? \App\Services\XlsxReader::fromUpload($request->file('file'), ($d['sheet'] ?? null) ?: null)
                : \App\Services\XlsxReader::csvRows((string) ($d['data'] ?? ''));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }
        if (! $rows || ! array_key_exists('row_index', $rows[0] ?? []) || ! array_key_exists('label', $rows[0] ?? [])) {
            return back()->withErrors(['file' => 'Kolom wajib: row_index, label (opsional code, max_kons).']);
        }
        // Inc 141: kolom harga/kalori diabaikan di sini — satu tempatnya tab "Harga & Kalori".
        $kolomHarga = array_values(array_filter(
            array_keys($rows[0] ?? []),
            fn ($k) => in_array($k, self::HARGA_REF_KEYS, true)
        ));
        if (($d['mode'] ?? '') === 'replace') {
            \App\Models\BlockRow::where('block_id', $block->id)->delete();
        }
        $ada = \App\Models\BlockRow::where('block_id', $block->id)->pluck('row_index')->flip();
        $n = (int) \App\Models\BlockRow::where('block_id', $block->id)->max('order');
        $dibuat = 0;
        $dilewati = [];
        foreach ($rows as $i => $row) {
            $ln = $i + 2;
            $idx = (int) ($row['row_index'] ?? 0);
            if ($idx < 1 || ! ($row['label'] ?? '')) {
                $dilewati[] = "baris {$ln}: row_index/label tidak valid";
                continue;
            }
            if ($ada->has($idx)) {
                $dilewati[] = "baris {$ln}: row_index {$idx} sudah ada";
                continue;
            }
            // simpan semua kolom selain row_index/code/label sebagai data referensi
            // (Inc 141: harga & Calories tidak ikut — impor lewat tab "Harga & Kalori")
            $ref = [];
            foreach ($row as $k => $v) {
                if (! in_array($k, ['row_index', 'code', 'label'], true) && $v !== ''
                    && ! in_array($k, self::HARGA_REF_KEYS, true)) {
                    $ref[$k] = $v;
                }
            }
            $n++;
            \App\Models\BlockRow::create([
                'block_id' => $block->id, 'row_index' => $idx,
                'code' => $row['code'] ?? '', 'label' => $row['label'],
                'ref' => $ref ?: null, 'order' => $n,
            ]);
            $ada[$idx] = true;
            $dibuat++;
        }
        AuditLog::record('block_row', $block->code, 'impor', "{$dibuat} baris");
        if ($kolomHarga) {
            $dilewati[] = 'kolom '.implode(', ', $kolomHarga).' diabaikan — impor harga & kalori lewat tab "Harga & Kalori"';
        }

        return back()->with('rowsResult', ['dibuat' => $dibuat, 'dilewati' => $dilewati]);
    }

    public function delBlock(Block $block)
    {
        $qids = $block->questions->pluck('id');
        abort_if(\App\Models\Answer::whereIn('question_id', $qids)->exists(), 400,
            'Blok punya jawaban terisi — tidak bisa dihapus');
        $qvId = $block->qversion_id;
        $block->delete();
        AuditLog::record('block', $block->id, 'hapus');
        return redirect()->route('admin.metadata', ['qv' => $qvId]);
    }

    // -------------------------------------------------------- Rincian

    public function addQuestion(Request $request, Block $block)
    {
        $d = $this->questionData($request, $block);
        $n = Question::where('block_id', $block->id)->count();
        Question::create($d + ['block_id' => $block->id, 'order' => $n + 1]);
        AuditLog::record('question', 'baru', 'tambah', "{$block->code}/{$d['var_name']}");
        return redirect()->route('admin.blok', $block);
    }

    public function questionEditor(Question $question)
    {
        return view('admin.question_editor', [
            'q' => $question,
            'options' => $question->options,
            'qtypes' => self::QTYPES,
        ]);
    }

    public function updateQuestion(Request $request, Question $question)
    {
        $question->update($this->questionData($request, $question->block, $question->id));
        AuditLog::record('question', $question->id, 'ubah', $question->var_name);
        return redirect()->route('admin.blok', $question->block_id);
    }

    /**
     * Nama variabel: huruf kecil/angka/underscore, diawali huruf. Dipakai apa adanya di
     * ekspresi aturan, turunan & kolom ekspor — nama berspasi ("harga rumah") tidak bisa
     * dirujuk mesin aturan sama sekali.
     */
    private const VAR_NAME_RE = '/^[a-z][a-z0-9_]*$/';

    /** var_name sudah dipakai rincian lain di versi yang sama? (semua var satu versi berbagi satu konteks aturan) */
    private function varNameTaken(int $qversionId, string $var, ?int $exceptId = null): bool
    {
        return Question::whereHas('block', fn ($q) => $q->where('qversion_id', $qversionId))
            ->where('var_name', $var)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    private function questionData(Request $request, Block $block, ?int $exceptId = null): array
    {
        $d = $request->validate([
            'code_rincian' => 'required|string',
            'var_name' => ['required', 'string', 'max:64', 'regex:'.self::VAR_NAME_RE],
            'label' => 'required|string', 'qtype' => 'nullable|string',
            'unit' => 'nullable|string', 'skip_expr' => 'nullable|string',
            'help_text' => 'nullable|string',
        ], [
            'var_name.regex' => 'Variabel hanya boleh huruf kecil, angka, dan garis bawah (_), diawali huruf — mis. harga_rumah, bukan "harga rumah".',
        ]);
        if ($this->varNameTaken($block->qversion_id, trim($d['var_name']), $exceptId)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'var_name' => "Variabel \"{$d['var_name']}\" sudah dipakai rincian lain di versi ini — pilih nama lain.",
            ]);
        }
        $skip = trim($d['skip_expr'] ?? '');
        if ($skip && ($err = $this->ev->checkSyntax($skip))) {
            abort(400, "Ekspresi skip tidak valid: {$err}");
        }
        return [
            'code_rincian' => trim($d['code_rincian']),
            'var_name' => trim($d['var_name']),
            'label' => trim($d['label']),
            'qtype' => in_array($d['qtype'] ?? '', self::QTYPES, true) ? $d['qtype'] : 'int',
            'unit' => trim($d['unit'] ?? ''),
            'skip_expr' => $skip,
            'help_text' => trim($d['help_text'] ?? ''),
        ];
    }

    public function delQuestion(Question $question)
    {
        $bid = $question->block_id;
        abort_if(\App\Models\Answer::where('question_id', $question->id)->exists(), 400,
            'Rincian sudah punya jawaban — tidak bisa dihapus');
        $question->delete();
        AuditLog::record('question', $question->id, 'hapus');
        return redirect()->route('admin.blok', $bid);
    }

    public function reorderQuestion(Request $request, Question $question)
    {
        $dir = $request->input('arah');
        $sibs = Question::where('block_id', $question->block_id)->orderBy('order')->get()->values();
        $i = $sibs->search(fn ($q) => $q->id === $question->id);
        $j = $dir === 'naik' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < $sibs->count()) {
            [$a, $b] = [$sibs[$i], $sibs[$j]];
            [$a->order, $b->order] = [$b->order, $a->order];
            $a->save();
            $b->save();
        }
        return redirect()->route('admin.blok', $question->block_id);
    }

    public function addOption(Request $request, Question $question)
    {
        $d = $request->validate(['code' => 'required|string', 'label' => 'required|string']);
        $n = ResponseOption::where('question_id', $question->id)->count();
        ResponseOption::create(['question_id' => $question->id, 'code' => trim($d['code']),
            'label' => trim($d['label']), 'order' => $n + 1]);
        return redirect()->route('admin.rincian', $question);
    }

    public function delOption(ResponseOption $option)
    {
        $qid = $option->question_id;
        $option->delete();
        return redirect()->route('admin.rincian', $qid);
    }

    public function bulkOptions(Request $request, Question $question)
    {
        $d = $request->validate(['data' => 'required|string']);
        $n = (int) ResponseOption::where('question_id', $question->id)->max('order');
        $added = 0;
        $skipped = [];
        foreach (preg_split('/\r?\n/', trim($d['data'])) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$code, $label] = array_pad(array_map('trim', explode(',', $line, 2)), 2, '');
            if ($code === '' || $label === '') {
                $skipped[] = $line;

                continue;
            }
            if (ResponseOption::where('question_id', $question->id)->where('code', $code)->exists()) {
                $skipped[] = "{$code} (sudah ada)";

                continue;
            }
            ResponseOption::create(['question_id' => $question->id, 'code' => $code,
                'label' => $label, 'order' => ++$n]);
            $added++;
        }
        AuditLog::record('question', $question->id, 'opsi-massal', "{$added} pilihan");

        return back()->with('ok', "{$added} pilihan ditambahkan"
            .($skipped ? ' · dilewati: '.implode('; ', $skipped) : '.'));
    }

    public function reorderOption(Request $request, ResponseOption $option)
    {
        $sibs = ResponseOption::where('question_id', $option->question_id)
            ->orderBy('order')->orderBy('id')->get()->values();
        $i = $sibs->search(fn ($o) => $o->id === $option->id);
        $j = $request->input('arah') === 'naik' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < $sibs->count()) {
            [$a, $b] = [$sibs[$i], $sibs[$j]];
            [$a->order, $b->order] = [$b->order, $a->order];
            $a->save();
            $b->save();
        }

        return redirect()->route('admin.rincian', $option->question_id);
    }

    // -------------------------------------------------------- Impor rincian

    public function importForm(Request $request)
    {
        $qv = $this->selectedQv($request);
        return view('admin.import', [
            'qv' => $qv, 'activeId' => $this->activeQv()->id,
            'versions' => QuestionnaireVersion::orderByDesc('id')->get(),
            'blocks' => $qv->blocks, 'hasil' => null,
        ]);
    }

    public function importCsv(Request $request)
    {
        $d = $request->validate(['block_id' => 'required|integer', 'data' => 'required|string']);
        $b = Block::findOrFail($d['block_id']);
        $n = Question::where('block_id', $b->id)->count();
        $ada = $b->questions->pluck('var_name')->flip();
        $dibuat = 0;
        $dilewati = [];
        foreach ($this->csvRows($d['data'], ['kode_rincian', 'var_name', 'label']) as [$ln, $row]) {
            $var = $row['var_name'] ?? '';
            if (! $var || ! ($row['kode_rincian'] ?? '') || ! ($row['label'] ?? '')) {
                $dilewati[] = "baris {$ln}: kolom wajib kosong";
                continue;
            }
            if (! preg_match(self::VAR_NAME_RE, $var)) {
                $dilewati[] = "baris {$ln}: var '{$var}' tidak valid (huruf kecil/angka/_, diawali huruf)";
                continue;
            }
            if ($ada->has($var) || $this->varNameTaken($b->qversion_id, $var)) {
                $dilewati[] = "baris {$ln}: var '{$var}' sudah ada di versi ini";
                continue;
            }
            $skip = $row['skip_expr'] ?? '';
            if ($skip && $this->ev->checkSyntax($skip)) {
                $dilewati[] = "baris {$ln}: skip_expr tidak valid";
                continue;
            }
            $n++;
            Question::create([
                'block_id' => $b->id, 'code_rincian' => $row['kode_rincian'], 'var_name' => $var,
                'label' => $row['label'], 'qtype' => $row['qtype'] ?: 'decimal',
                'unit' => $row['unit'] ?? '', 'skip_expr' => $skip,
                'help_text' => $row['help_text'] ?? '', 'order' => $n,
            ]);
            $ada[$var] = true;
            $dibuat++;
        }
        AuditLog::record('question', $b->code, 'impor-csv', "{$dibuat} rincian");
        return view('admin.import', [
            'qv' => $b->qversion, 'activeId' => $this->activeQv()->id,
            'versions' => QuestionnaireVersion::orderByDesc('id')->get(),
            'blocks' => $b->qversion->blocks,
            'hasil' => ['dibuat' => $dibuat, 'dilewati' => $dilewati, 'blok' => $b],
        ]);
    }

    // -------------------------------------------------------- Parameter

    public function saveDerived(Request $request)
    {
        $d = $request->validate([
            'var_name' => 'required|string|max:60',
            'label' => 'nullable|string|max:200',
            'expr' => 'required|string',
            'order' => 'nullable|integer',
            'derived_id' => 'nullable|integer',
        ]);
        if ($err = $this->ev->checkSyntax($d['expr'], RuleEngine::ROSTER_FUNCS)) {
            return back()->withErrors(['expr' => "Ekspresi tidak valid: {$err}"]);
        }
        $payload = [
            'var_name' => trim($d['var_name']), 'label' => trim($d['label'] ?? ''),
            'expr' => trim($d['expr']), 'order' => (int) ($d['order'] ?? 0),
        ];
        $qv = $this->selectedQv($request);
        if ($d['derived_id'] ?? null) {
            DerivedField::where('id', $d['derived_id'])->update($payload);
        } else {
            DerivedField::create($payload + ['qversion_id' => $qv->id]);
        }
        AuditLog::record('derived', $d['derived_id'] ?? 'baru', 'simpan', $d['var_name']);

        return redirect()->route('admin.metadata', ['qv' => $qv->id]);
    }

    public function delDerived(DerivedField $derived)
    {
        $qvId = $derived->qversion_id;
        AuditLog::record('derived', $derived->id, 'hapus', $derived->var_name);
        $derived->delete();

        return redirect()->route('admin.metadata', ['qv' => $qvId]);
    }

    public function saveParam(Request $request)
    {
        $d = $request->validate([
            'name' => 'required|string', 'value' => 'required|string',
            'note' => 'nullable|string', 'param_id' => 'nullable|integer',
        ]);
        $qv = $this->selectedQv($request);
        if ($d['param_id'] ?? null) {
            Parameter::where('id', $d['param_id'])->update([
                'name' => trim($d['name']), 'value' => trim($d['value']), 'note' => trim($d['note'] ?? ''),
            ]);
        } else {
            Parameter::create(['qversion_id' => $qv->id, 'name' => trim($d['name']),
                'value' => trim($d['value']), 'note' => trim($d['note'] ?? '')]);
        }
        AuditLog::record('parameter', $d['param_id'] ?? 'baru', 'simpan', $d['name']);
        return redirect()->route('admin.metadata', ['qv' => $qv->id]);
    }

    public function delParam(Parameter $parameter)
    {
        $qvId = $parameter->qversion_id;
        $parameter->delete();
        return redirect()->route('admin.metadata', ['qv' => $qvId]);
    }

    // -------------------------------------------------------- Aturan

    public function rulesList(Request $request)
    {
        return $this->rulesView(null, $request);
    }

    public function ruleEditor(Request $request, ValidationRule $rule)
    {
        return $this->rulesView($rule, $request);
    }

    private function rulesView(?ValidationRule $edit, ?Request $request = null)
    {
        // Menyunting aturan tertentu selalu pakai qversion aturan itu sendiri (bukan
        // selector ?qv= yang mungkin basi di URL) — supaya link "Sunting" selalu benar.
        $qv = $edit ? $edit->qversion : ($request ? $this->selectedQv($request) : $this->activeQv());
        $all = $qv->rules;

        $fSev = $request?->query('sev', '') ?? '';
        $fScope = $request?->query('scope', '') ?? '';
        $fActive = $request?->query('active', '') ?? '';
        $fQ = trim((string) ($request?->query('q', '') ?? ''));
        $rules = $all->filter(function ($r) use ($fSev, $fScope, $fActive, $fQ) {
            if ($fSev && $r->severity !== $fSev) {
                return false;
            }
            if ($fScope && $r->scope !== $fScope) {
                return false;
            }
            if ($fActive === 'ya' && ! $r->active) {
                return false;
            }
            if ($fActive === 'tidak' && $r->active) {
                return false;
            }
            if ($fQ !== '' && stripos($r->code.' '.$r->message.' '.$r->expr.' '.$r->target_var, $fQ) === false) {
                return false;
            }

            return true;
        })->values();

        $blockTitles = $qv->blocks->pluck('title', 'code');
        $scopeLabels = [
            'dokumen' => 'Tingkat sampel', 'identitas' => 'Identitas & wilayah',
            'neraca' => 'Neraca rumah tangga', 'kalori' => 'Kalori & gizi',
            'roster' => 'Antar anggota rumah tangga (roster)', 'baris' => 'Per baris',
        ];
        $groups = $rules->groupBy(function ($r) use ($blockTitles, $scopeLabels) {
            if ($r->row_block) {
                return 'Blok '.$r->row_block.($blockTitles[$r->row_block] ?? '' ? ' — '.$blockTitles[$r->row_block] : '');
            }

            return $scopeLabels[$r->scope] ?? ('Lainnya ('.$r->scope.')');
        })->sortKeys();

        $wizardBlocks = $qv->blocks()->with('questions')->orderBy('order')->orderBy('id')->get();
        $refKeys = $wizardBlocks->filter(fn ($bl) => in_array($bl->repeat, ['list', 'list_art'], true))
            ->flatMap(fn ($bl) => \App\Models\BlockRow::where('block_id', $bl->id)->get()
                ->flatMap(fn ($r) => array_keys((array) $r->ref)))
            ->unique()->reject(fn ($k) => $k === 'group')->sort()->values();

        // Inc 141: satu halaman "Aturan Validasi" berisi tiga tab —
        //   aturan  = daftar aturan (seperti sebelumnya)
        //   neraca  = parameter + agregat cek-silang (NERACA) + variabel turunan
        //   harga   = patokan harga wajar & Calories per komoditas
        // Metadata sekarang hanya untuk membuat kuesioner.
        $tab = (string) ($request?->query('tab', 'aturan') ?? 'aturan');
        if (! in_array($tab, ['aturan', 'neraca', 'harga'], true)) {
            $tab = 'aturan';
        }

        return view('admin.rules', [
            'qv' => $qv,
            'activeId' => $this->activeQv()->id,
            'versions' => QuestionnaireVersion::orderByDesc('id')->get(),
            'rules' => $rules,
            'groups' => $groups,
            'wizardBlocks' => $wizardBlocks,
            'derivedFields' => \App\Models\DerivedField::where('qversion_id', $qv->id)->orderBy('var_name')->get(),
            'ruleParams' => Parameter::where('qversion_id', $qv->id)->orderBy('name')->get(),
            'refKeys' => $refKeys,
            'hasRoster' => $wizardBlocks->contains(fn ($bl) => $bl->repeat === 'art'),
            'total' => $all->count(),
            'activeCount' => $all->where('active', true)->count(),
            'ruleErrs' => $rules->mapWithKeys(fn ($r) => [$r->id => $this->ev->checkSyntax($r->expr, RuleEngine::ROSTER_FUNCS)]),
            'docs' => Document::where('qversion_id', $qv->id)->orderBy('id')->get(),
            'edit' => $edit,
            'scopes' => $all->pluck('scope')->unique()->filter()->sort()->values(),
            'filters' => ['sev' => $fSev, 'scope' => $fScope, 'active' => $fActive, 'q' => $fQ],
            // data untuk tab "neraca" & "harga"
            'tab' => $tab,
            'params' => $qv->parameters,
            'derived' => $qv->derived,
            'aggregates' => $qv->aggregates,
            'repeatBlocks' => $qv->blocks->whereIn('repeat', ['art', 'list', 'list_art']),
            'hargaBlocks' => $hargaBlocks = $this->hargaBlocks($qv),
            'komoditas' => \App\Models\BlockRow::whereIn('block_id', $hargaBlocks->pluck('id'))
                ->orderBy('row_index')->get(),
        ]);
    }

    /** Aktif/nonaktif banyak aturan sekaligus. */
    public function bulkToggleRules(Request $request)
    {
        $d = $request->validate([
            'ids' => 'required|array', 'ids.*' => 'integer',
            'aksi' => 'required|in:aktif,nonaktif',
        ]);
        $n = ValidationRule::where('qversion_id', $this->selectedQv($request)->id)
            ->whereIn('id', $d['ids'])
            ->update(['active' => $d['aksi'] === 'aktif']);
        AuditLog::record('rule', '-', 'toggle-massal', "{$n} aturan -> {$d['aksi']}");

        return back()->with('ok', "{$n} aturan di-{$d['aksi']}kan.");
    }

    /** Susun ekspresi dari pilihan terpandu (wizard). */
    private function assembleRuleExpr(string $kind, array $p): string
    {
        $a = trim($p['field_a'] ?? '');
        $b = trim($p['field_b'] ?? '');
        $cmp = function (string $field, string $op, string $val): string {
            $val = trim($val);
            if (in_array($op, ['>', '<', '>=', '<='], true) || ($op === '==' && is_numeric($val)) || ($op === '!=' && is_numeric($val))) {
                return "n({$field}) {$op} ".(is_numeric($val) ? $val : '0');
            }

            return "{$field} ".($op === '!=' ? '!=' : '==').' "'.str_replace('"', '', $val).'"';
        };

        return match ($kind) {
            'wajib' => "blank({$a})",
            'wajib_jika' => (function () use ($a, $b, $p, $cmp) {
                $op = $p['cond_op'] ?? 'terisi';
                $cond = match ($op) {
                    'terisi' => "filled({$a})",
                    'kosong' => "blank({$a})",
                    default => 'filled('.$a.') and '.$cmp($a, $op, $p['cond_val'] ?? ''),
                };

                return "({$cond}) and (blank({$b}) or n({$b}) == 0)";
            })(),
            'rentang' => sprintf('filled(%s) and (n(%s) < %s or n(%s) > %s)',
                $a, $a, (float) ($p['min'] ?? 0), $a, (float) ($p['max'] ?? 0)),
            'batas' => sprintf('filled(%s) and n(%s) %s %s', $a, $a,
                in_array($p['limit_op'] ?? '>', ['>', '<', '>=', '<='], true) ? $p['limit_op'] : '>',
                (float) ($p['limit_val'] ?? 0)),
            'banding' => ($p['banding_op'] ?? 'sama') === 'sama'
                ? "filled({$a}) and filled({$b}) and {$a} != {$b}"
                : "filled({$a}) and filled({$b}) and {$a} == {$b}",
            // 'kombinasi' = query bebas: beberapa syarat digabung DAN/ATAU (mis. aset banyak
            // TAPI belanja makanan dilaporkan rendah — dua sinyal, bukan satu).
            'kombinasi' => (function () use ($p, $cmp) {
                $fields = $p['cond_field'] ?? [];
                $ops = $p['cond_op2'] ?? [];
                $vals = $p['cond_val2'] ?? [];
                $joiner = ($p['join'] ?? 'and') === 'or' ? ' or ' : ' and ';
                $parts = [];
                foreach ($fields as $i => $f) {
                    $f = trim((string) $f);
                    if ($f === '') {
                        continue;
                    }
                    $op = $ops[$i] ?? 'terisi';
                    $val = $vals[$i] ?? '';
                    $cond = match ($op) {
                        'terisi' => "filled({$f})",
                        'kosong' => "blank({$f})",
                        default => $cmp($f, $op, $val),
                    };
                    $parts[] = "({$cond})";
                }

                return count($parts) >= 2 ? implode($joiner, $parts) : '';
            })(),
            default => '',
        };
    }

    /** Buat aturan baru lewat form terpandu — tanpa menulis ekspresi. */
    public function buildRule(Request $request)
    {
        $d = $request->validate([
            'code' => 'required|string|max:40',
            'severity' => 'required|in:warning,error',
            'block' => 'nullable|string',
            'kind' => 'required|in:wajib,wajib_jika,rentang,batas,banding,kombinasi',
            'field_a' => 'required_unless:kind,kombinasi|nullable|string',
            'field_b' => 'nullable|string',
            'cond_op' => 'nullable|string', 'cond_val' => 'nullable|string',
            'min' => 'nullable|numeric', 'max' => 'nullable|numeric',
            'limit_op' => 'nullable|string', 'limit_val' => 'nullable|numeric',
            'banding_op' => 'nullable|string',
            'join' => 'nullable|in:and,or',
            'cond_field' => 'nullable|array', 'cond_field.*' => 'nullable|string',
            'cond_op2' => 'nullable|array', 'cond_op2.*' => 'nullable|string',
            'cond_val2' => 'nullable|array', 'cond_val2.*' => 'nullable|string',
            'message' => 'required|string|max:250',
            'note' => 'nullable|string|max:500',
        ]);
        if (in_array($d['kind'], ['wajib_jika', 'banding'], true) && ! ($d['field_b'] ?? '')) {
            return back()->withErrors(['wizard' => 'Jenis pemeriksaan ini butuh rincian kedua.'])->withInput();
        }
        $qv = $this->selectedQv($request);
        $block = ($d['block'] ?? '') ? $qv->blocks->firstWhere('code', $d['block']) : null;
        $isRow = $block && $block->isRowBlock();
        $expr = $this->assembleRuleExpr($d['kind'], $d);
        if (! $expr || ($err = $this->ev->checkSyntax($expr, RuleEngine::ROSTER_FUNCS))) {
            $why = $d['kind'] === 'kombinasi' && ! $expr ? ' (isi minimal 2 syarat)' : ($err ? ": {$err}" : '');
            return back()->withErrors(['wizard' => 'Tidak bisa menyusun ekspresi'.$why.'.'])->withInput();
        }
        if (ValidationRule::where('qversion_id', $qv->id)->where('code', trim($d['code']))->exists()) {
            return back()->withErrors(['wizard' => 'Kode aturan sudah dipakai.'])->withInput();
        }
        $targetVar = $d['kind'] === 'kombinasi'
            ? (string) collect($d['cond_field'] ?? [])->first(fn ($f) => trim((string) $f) !== '')
            : $d['field_a'];
        ValidationRule::create([
            'qversion_id' => $qv->id, 'code' => trim($d['code']), 'severity' => $d['severity'],
            'scope' => $isRow ? 'baris' : 'dokumen', 'row_block' => $isRow ? $block->code : '',
            'target_var' => $targetVar, 'expr' => $expr,
            'message' => trim($d['message']), 'note' => trim($d['note'] ?? '') ?: null, 'active' => true,
        ]);
        AuditLog::record('rule', 'baru', 'tambah-terpandu', $d['code']);

        return redirect()->route('admin.aturan', ['qv' => $qv->id])
            ->with('ok', "Aturan {$d['code']} dibuat. Ekspresi: {$expr}");
    }

    /**
     * "Uji dulu" di form terpandu — susun ekspresi dari isian wizard (tanpa menyimpan)
     * lalu tampilkan berapa dokumen/baris yang akan menyala, seperti query pratinjau.
     */
    public function wizardImpact(Request $request)
    {
        $kind = (string) $request->input('kind');
        if (! in_array($kind, ['wajib', 'wajib_jika', 'rentang', 'batas', 'banding', 'kombinasi'], true)) {
            return response()->json(['ok' => false, 'error' => 'Jenis pemeriksaan tidak dikenal.']);
        }
        $expr = $this->assembleRuleExpr($kind, $request->except(['_token']));
        if (! $expr) {
            $why = $kind === 'kombinasi' ? 'isi minimal 2 syarat' : 'lengkapi isian dulu';
            return response()->json(['ok' => false, 'error' => "Tidak bisa menyusun ekspresi ({$why})."]);
        }
        if ($err = $this->ev->checkSyntax($expr, RuleEngine::ROSTER_FUNCS)) {
            return response()->json(['ok' => false, 'error' => $err]);
        }
        $qv = $this->selectedQv($request);
        $blockCode = (string) $request->input('block', '');
        $block = $blockCode ? $qv->blocks->firstWhere('code', $blockCode) : null;
        $isRow = $block && $block->isRowBlock();
        $report = $this->engine->previewExpression($expr, $isRow ? $block->code : '', $qv->id);

        return response()->json(['ok' => true, 'expr' => $expr] + $report);
    }

    public function saveRule(Request $request)
    {
        $d = $request->validate([
            'code' => 'required|string', 'expr' => 'required|string', 'message' => 'required|string',
            'severity' => 'nullable|string', 'scope' => 'nullable|string', 'row_block' => 'nullable|string',
            'target_var' => 'nullable|string', 'note' => 'nullable|string', 'rule_id' => 'nullable|integer',
        ]);
        if ($err = $this->ev->checkSyntax($d['expr'], RuleEngine::ROSTER_FUNCS)) {
            abort(400, "Ekspresi tidak valid: {$err}");
        }
        $sev = in_array($d['severity'] ?? '', ['error', 'warning'], true) ? $d['severity'] : 'warning';
        $payload = [
            'code' => trim($d['code']), 'expr' => trim($d['expr']), 'message' => trim($d['message']),
            'severity' => $sev, 'scope' => trim($d['scope'] ?? 'dokumen'),
            'row_block' => trim($d['row_block'] ?? ''),
            'target_var' => trim($d['target_var'] ?? ''),
            'note' => trim($d['note'] ?? '') ?: null,
        ];
        if ($d['rule_id'] ?? null) {
            ValidationRule::where('id', $d['rule_id'])->update($payload);
            AuditLog::record('rule', $d['rule_id'], 'ubah', $d['code']);
        } else {
            ValidationRule::create($payload + ['qversion_id' => $this->selectedQv($request)->id, 'active' => true]);
            AuditLog::record('rule', 'baru', 'tambah', $d['code']);
        }
        return redirect()->route('admin.aturan');
    }

    /** Sunting cepat: keterangan + angka ambang, tanpa menyentuh ekspresi. */
    public function quickRule(Request $request, ValidationRule $rule)
    {
        $d = $request->validate([
            'note' => 'nullable|string|max:500',
            'threshold' => 'nullable|numeric',
        ]);
        $payload = ['note' => trim($d['note'] ?? '') ?: null];
        if (($d['threshold'] ?? null) !== null && $rule->soleThreshold() !== null) {
            $newExpr = $rule->withThreshold((string) $d['threshold']);
            if (! $this->ev->checkSyntax($newExpr, RuleEngine::ROSTER_FUNCS)) {
                $payload['expr'] = $newExpr;
            }
        }
        $rule->update($payload);
        AuditLog::record('rule', $rule->id, 'sunting-cepat', $rule->code);

        return back()->with('ok', "Aturan {$rule->code} diperbarui.");
    }

    public function delRule(ValidationRule $rule)
    {
        $qvId = $rule->qversion_id;
        RuleResult::where('rule_id', $rule->id)->delete();
        $rule->delete();
        AuditLog::record('rule', $rule->id, 'hapus');
        return redirect()->route('admin.aturan', ['qv' => $qvId]);
    }

    public function toggleRule(ValidationRule $rule)
    {
        $rule->update(['active' => ! $rule->active]);
        AuditLog::record('rule', $rule->id, 'toggle', 'active='.($rule->active ? '1' : '0'));
        return redirect()->route('admin.aturan', ['qv' => $rule->qversion_id]);
    }

    public function testRule(Request $request)
    {
        $d = $request->validate(['expr' => 'required|string', 'document_id' => 'required|integer']);
        if ($err = $this->ev->checkSyntax($d['expr'], RuleEngine::ROSTER_FUNCS)) {
            return response()->json(['ok' => false, 'error' => $err]);
        }
        $doc = Document::find($d['document_id']);
        if (! $doc) {
            return response()->json(['ok' => false, 'error' => 'sampel tidak ada']);
        }
        $ctx = $this->engine->buildContext($doc);
        try {
            $val = $this->ev->evaluate($d['expr'], $ctx);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
        ksort($ctx);
        return response()->json([
            'ok' => true,
            'fires' => ExpressionEvaluator::truthy($val),
            'value' => var_export($val, true),
            'context' => array_map(fn ($v) => is_null($v) ? 'null' : (string) $v, $ctx),
        ]);
    }

    public function qversionImportForm()
    {
        return view('admin.impor_versi', [
            'cycles' => \App\Models\SurveyCycle::orderByDesc('id')->get(),
            'hasil' => null,
        ]);
    }

    public function qversionImportRun(Request $request)
    {
        $d = $request->validate([
            'data' => 'nullable|string',
            'file' => 'nullable|file|mimetypes:application/json,text/plain,text/json',
            'cycle_id' => 'required|integer|exists:survey_cycles,id',
            'code' => 'nullable|string|max:60',
            'force' => 'nullable',
            'mode' => 'nullable|in:check,commit',
        ]);

        $raw = $request->hasFile('file')
            ? (string) file_get_contents($request->file('file')->getRealPath())
            : (string) ($d['data'] ?? '');
        $pkg = json_decode($raw, true);

        $svc = app(\App\Services\QuestionnairePackage::class);
        $view = fn ($hasil) => view('admin.impor_versi', [
            'cycles' => \App\Models\SurveyCycle::orderByDesc('id')->get(),
            'hasil' => $hasil, 'data' => $raw,
        ]);

        if (! is_array($pkg)) {
            return $view(['errors' => ['File/teks bukan JSON yang valid.'], 'warnings' => [], 'ok' => false]);
        }
        if (! empty($d['code'])) {
            $pkg['code'] = trim($d['code']);
        }

        $lint = $svc->lint($pkg);
        $commit = ($d['mode'] ?? 'check') === 'commit';
        if (! $commit || $lint['errors'] || ($lint['warnings'] && ! $request->boolean('force'))) {
            return $view($lint + ['ok' => ! $lint['errors'], 'checked' => true]);
        }

        try {
            $qv = $svc->import($pkg, (int) $d['cycle_id'], $d['code'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return $view(['errors' => [$e->getMessage()], 'warnings' => [], 'ok' => false]);
        }
        AuditLog::record('qversion', $qv->id, 'impor-paket', $qv->code);

        return redirect()->route('admin.metadata', ['qv' => $qv->id])->with('ok', "Versi '{$qv->code}' diimpor.");
    }

    /** Buku kode (codebook) satu versi kuesioner — halaman referensi & cetak. */
    public function codebook(QuestionnaireVersion $qv)
    {
        $qv->loadMissing(['blocks.questions.options', 'blocks.rows', 'parameters', 'derived', 'aggregates', 'rules']);

        return view('admin.kodebook', ['qv' => $qv, 'cycle' => $qv->cycle]);
    }

    /** Unduh paket JSON metadata satu versi kuesioner. */
    public function qversionExport(QuestionnaireVersion $qv)
    {
        $data = app(\App\Services\QuestionnairePackage::class)->export($qv);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        AuditLog::record('qversion', $qv->id, 'ekspor-paket', $qv->code);

        return response($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="qversion_'.$qv->code.'.json"',
        ]);
    }

    /** Pratinjau: berapa dokumen/baris akan menyala jika ekspresi ini diaktifkan. */
    public function ruleImpact(Request $request)
    {
        $d = $request->validate([
            'expr' => 'required|string',
            'row_block' => 'nullable|string',
        ]);
        if ($err = $this->ev->checkSyntax($d['expr'], RuleEngine::ROSTER_FUNCS)) {
            return response()->json(['ok' => false, 'error' => $err]);
        }
        $report = $this->engine->previewExpression(
            trim($d['expr']), trim($d['row_block'] ?? ''), $this->selectedQv($request)->id,
        );

        return response()->json(['ok' => true] + $report);
    }

    // -------------------------------------------------------- Duplikasi versi

    public function duplicateVersion(Request $request)
    {
        $d = $request->validate(['new_code' => 'required|string']);
        $src = $this->activeQv();
        $dst = QuestionnaireVersion::create([
            'cycle_id' => $src->cycle_id, 'code' => trim($d['new_code']),
            'effective_date' => $src->effective_date,
        ]);
        foreach ($src->blocks as $b) {
            $nb = Block::create(['qversion_id' => $dst->id, 'code' => $b->code, 'title' => $b->title,
                'order' => $b->order, 'repeat' => $b->repeat, 'harga_kalori' => $b->harga_kalori]);
            foreach ($b->questions as $q) {
                $nq = Question::create([
                    'block_id' => $nb->id, 'code_rincian' => $q->code_rincian, 'var_name' => $q->var_name,
                    'label' => $q->label, 'qtype' => $q->qtype, 'unit' => $q->unit, 'order' => $q->order,
                    'skip_expr' => $q->skip_expr, 'help_text' => $q->help_text,
                ]);
                foreach ($q->options as $o) {
                    ResponseOption::create(['question_id' => $nq->id, 'code' => $o->code,
                        'label' => $o->label, 'order' => $o->order]);
                }
            }
            foreach ($b->rows as $row) {   // baris tetap blok daftar (komoditas dsb.)
                \App\Models\BlockRow::create(['block_id' => $nb->id, 'row_index' => $row->row_index,
                    'code' => $row->code, 'label' => $row->label, 'order' => $row->order, 'ref' => $row->ref]);
            }
        }
        foreach ($src->parameters as $p) {
            Parameter::create(['qversion_id' => $dst->id, 'name' => $p->name, 'value' => $p->value, 'note' => $p->note]);
        }
        foreach ($src->derived as $x) {
            DerivedField::create(['qversion_id' => $dst->id, 'var_name' => $x->var_name,
                'label' => $x->label, 'expr' => $x->expr, 'order' => $x->order]);
        }
        foreach ($src->aggregates as $a) {
            \App\Models\Aggregate::create(array_merge(
                $a->only((new \App\Models\Aggregate)->getFillable()),
                ['qversion_id' => $dst->id],
            ));
        }
        foreach ($src->rules as $r) {
            ValidationRule::create(['qversion_id' => $dst->id, 'code' => $r->code, 'scope' => $r->scope,
                'row_block' => $r->row_block, 'target_var' => $r->target_var, 'expr' => $r->expr,
                'severity' => $r->severity, 'message' => $r->message, 'active' => $r->active]);
        }
        AuditLog::record('qversion', $dst->id, 'duplikat', "{$src->code} -> {$d['new_code']}");
        return redirect()->route('admin.metadata', ['qv' => $dst->id])->with('ok', "Versi \"{$dst->code}\" dibuat.");
    }

    /** Ubah kode & tanggal berlaku versi kuesioner. */
    public function saveVersion(Request $request, QuestionnaireVersion $qv)
    {
        $d = $request->validate([
            'code' => 'required|string|max:40',
            'effective_date' => 'nullable|date',
        ]);
        $code = trim($d['code']);
        if (QuestionnaireVersion::where('code', $code)->where('id', '!=', $qv->id)->exists()) {
            return back()->withErrors(['versi' => "Kode \"{$code}\" sudah dipakai versi lain."]);
        }
        $qv->update(['code' => $code, 'effective_date' => ($d['effective_date'] ?? null) ?: null]);
        AuditLog::record('qversion', $qv->id, 'ubah', $code);

        return redirect()->route('admin.metadata', ['qv' => $qv->id])->with('ok', "Versi \"{$code}\" diperbarui.");
    }

    /** Pilih versi kuesioner yang disunting di menu Admin. */
    /**
     * Aktifkan versi. Inc 145: versi diperiksa dulu (VersionChecker) — bila ada rujukan
     * putus (error), versi TIDAK diaktifkan dan alasannya ditampilkan; admin bisa memaksa
     * lewat "Aktifkan tetap" (force=1, tercatat di log audit).
     */
    public function activateVersion(Request $request, QuestionnaireVersion $qv)
    {
        $check = app(\App\Services\VersionChecker::class)->check($qv);
        $force = $request->boolean('force');
        if ($check['errors'] && ! $force) {
            return redirect()->route('admin.metadata', ['qv' => $qv->id])
                ->with('versiCheck', $check + ['qv_id' => $qv->id, 'code' => $qv->code, 'blocked' => true]);
        }

        QuestionnaireVersion::where('is_active', true)->update(['is_active' => false]);
        $qv->update(['is_active' => true]);
        AuditLog::record('qversion', $qv->id, 'aktifkan',
            $qv->code.($check['errors'] ? ' (DIPAKSA, '.count($check['errors']).' error pengecekan)' : ''));

        $redirect = redirect()->route('admin.metadata', ['qv' => $qv->id])->with('ok', "Versi aktif: {$qv->code}.");

        return $check['warnings'] || $check['errors']
            ? $redirect->with('versiCheck', $check + ['qv_id' => $qv->id, 'code' => $qv->code, 'blocked' => false])
            : $redirect;
    }

    /** Periksa keutuhan versi tanpa mengaktifkan (Inc 145). */
    public function checkVersion(QuestionnaireVersion $qv)
    {
        $check = app(\App\Services\VersionChecker::class)->check($qv);

        return redirect()->route('admin.metadata', ['qv' => $qv->id])
            ->with('versiCheck', $check + ['qv_id' => $qv->id, 'code' => $qv->code, 'blocked' => false]);
    }

    public function deleteVersion(QuestionnaireVersion $qv)
    {
        if (Document::where('qversion_id', $qv->id)->exists()) {
            return back()->withErrors(['versi' => "Versi \"{$qv->code}\" sudah punya isian — tidak bisa dihapus."]);
        }
        if (QuestionnaireVersion::count() <= 1) {
            return back()->withErrors(['versi' => 'Tidak bisa menghapus versi terakhir.']);
        }
        $wasActive = $qv->is_active;
        $code = $qv->code;
        $qv->delete();   // cascade: blok, rincian, opsi, aturan, parameter, turunan, agregat, baris
        if ($wasActive) {
            QuestionnaireVersion::orderByDesc('id')->first()?->update(['is_active' => true]);
        }
        AuditLog::record('qversion', '-', 'hapus', $code);

        return redirect()->route('admin.metadata')->with('ok', "Versi \"{$code}\" dihapus.");
    }

    // -------------------------------------------------------- Manajemen pengguna

    public function users()
    {
        return view('admin.users', [
            'users' => User::orderBy('role')->orderBy('username')->get(),
            'roles' => User::ROLE_LABELS,
            'kecamatans' => Wilayah::where('level', 'kecamatan')->orderBy('code')->get(['code', 'name']),
        ]);
    }

    public function saveUser(Request $request)
    {
        $id = $request->input('user_id');
        $d = $request->validate([
            'username' => 'required|string|max:64|regex:/^[a-z0-9_.]+$/'
                . ($id ? "|unique:users,username,{$id}" : '|unique:users,username'),
            'name' => 'required|string|max:128',
            'role' => 'required|in:'.implode(',', array_keys(User::ROLE_LABELS)),
            'wilayah_scope' => 'nullable|string|max:16',
            'password' => ($id ? 'nullable' : 'required').'|string|min:8',
            'user_id' => 'nullable|integer',
        ]);
        $scope = trim($d['wilayah_scope'] ?? '');

        // Wilayah scope selalu berupa kode KECAMATAN (bukan desa/kabupaten) — dipilih dari
        // dropdown, bukan diketik bebas. Kosong = tidak dibatasi (lihat seluruh kabupaten;
        // aplikasi ini 1 kabupaten, jadi scope kabupaten toh sama saja dengan kosong).
        if ($scope !== '' && (strlen($scope) !== self::WIL_LEN['kecamatan']
            || ! Wilayah::where('code', $scope)->where('level', 'kecamatan')->exists())) {
            return back()->withErrors(['wilayah_scope' => "Kode kecamatan '{$scope}' tidak ditemukan di master wilayah."])->withInput();
        }
        // PJ: satu per kecamatan — scope wajib diisi & belum dipakai PJ aktif lain.
        if ($d['role'] === 'pj') {
            if ($scope === '') {
                return back()->withErrors(['wilayah_scope' => 'Penanggung Jawab wajib diisi wilayah (kecamatan).'])->withInput();
            }
            $dupe = User::where('role', 'pj')->where('wilayah_scope', $scope)->where('active', true);
            if ($id) {
                $dupe->where('id', '!=', $id);
            }
            if ($dupe->exists()) {
                return back()->withErrors(['wilayah_scope' => "Sudah ada Penanggung Jawab aktif untuk kecamatan {$scope} — satu kecamatan hanya boleh satu Penanggung Jawab."])->withInput();
            }
        }

        $payload = [
            'username' => $d['username'], 'name' => $d['name'], 'role' => $d['role'],
            'wilayah_scope' => $scope,
        ];
        if (! empty($d['password'])) {
            $payload['password'] = \Illuminate\Support\Facades\Hash::make($d['password']);
        }
        if ($id) {
            $u = User::findOrFail($id);
            $u->update($payload);
        } else {
            $u = User::create($payload + ['email' => $d['username'].'@bps.go.id', 'active' => true]);
        }
        AuditLog::record('user', $u->id, $id ? 'ubah' : 'tambah', $d['username']);
        return redirect()->route('admin.pengguna')->with('ok', 'Pengguna disimpan.');
    }

    public function toggleUser(Request $request, User $user)
    {
        abort_if($user->id === $request->user()->id, 400, 'Tidak bisa menonaktifkan akun sendiri.');
        $user->update(['active' => ! $user->active]);
        AuditLog::record('user', $user->id, 'toggle-aktif', 'active='.($user->active ? '1' : '0'));
        return back()->with('ok', 'Status pengguna diubah.');
    }

    // -------------------------------------------------------- Impor dokumen massal (format lebar)

    public function docImportForm()
    {
        $qv = $this->activeQv();
        $vars = Question::whereIn('block_id', $qv->blocks->pluck('id'))
            ->get(['var_name', 'block_id'])
            ->groupBy('block_id')
            ->map(fn ($g) => $g->pluck('var_name'));
        return view('admin.import_dokumen', [
            'blocks' => $qv->blocks, 'varsByBlock' => $vars, 'hasil' => null,
            'aliases' => \App\Models\ColumnAlias::with('qversion:id,code')
                ->orderBy('qversion_id')->orderBy('source_col')->get()
                ->groupBy(fn ($a) => $a->qversion?->code ?? $a->qversion_id),
        ]);
    }

    /**
     * Kolom format-lebar untuk versi kuesioner: blok `ruta` (var polos) + blok
     * `art` (var__1..var__N). Blok `list` (daftar komoditas) TIDAK termasuk —
     * jumlah barisnya tetap & besar; diekspor terpisah bila perlu.
     */
    private function docColumns(QuestionnaireVersion $qv, int $art): array
    {
        $cols = ['wilayah_code', 'no_bs', 'no_ruta', 'krt_name'];
        foreach ($qv->blocks()->orderBy('order')->get() as $b) {
            if ($b->isRowBlock()) {
                continue;
            }
            foreach ($b->questions()->orderBy('order')->get() as $q) {
                if ($b->repeat === 'ruta') {
                    $cols[] = $q->var_name;
                } else {
                    for ($i = 1; $i <= $art; $i++) {
                        $cols[] = "{$q->var_name}__{$i}";
                    }
                }
            }
        }
        return $cols;
    }

    /** Ekspor jawaban semua dokumen suatu siklus dalam format lebar (round-trip dengan impor). */
    public function docExport(SurveyCycle $cycle)
    {
        $qv = QuestionnaireVersion::where('cycle_id', $cycle->id)->orderByDesc('id')->firstOrFail();
        $useClean = request()->boolean('bersih');   // pakai nilai bersih bila ada

        $docs = Document::query()
            ->join('sample_households as s', 's.id', '=', 'documents.sample_id')
            ->where('documents.qversion_id', $qv->id)
            ->select('documents.id', 's.wilayah_code', 's.no_bs', 's.no_ruta', 's.krt_name')
            ->orderBy('s.wilayah_code')->orderBy('s.no_bs')->orderBy('s.no_ruta')->get();

        // art_index tertinggi pada blok roster ART → jumlah kolom __n (blok list dikecualikan)
        $rosterQids = Question::whereIn('block_id',
            Block::where('qversion_id', $qv->id)->where('repeat', 'art')->pluck('id'))->pluck('id');
        $maxArt = $rosterQids->isEmpty() ? 1 : (int) Answer::whereIn('question_id', $rosterQids)
            ->whereIn('document_id', $docs->pluck('id'))->max('art_index');
        $maxArt = max(1, min($maxArt, 30));

        $cols = $this->docColumns($qv, $maxArt);
        $varToCol = [];   // "qid:artIndex" => nama kolom
        foreach ($qv->blocks()->with('questions')->get() as $b) {
            if ($b->isRowBlock()) {
                continue;
            }
            foreach ($b->questions as $q) {
                if ($b->repeat === 'ruta') {
                    $varToCol["{$q->id}:0"] = $q->var_name;
                } else {
                    for ($i = 1; $i <= $maxArt; $i++) {
                        $varToCol["{$q->id}:{$i}"] = "{$q->var_name}__{$i}";
                    }
                }
            }
        }

        $answers = Answer::whereIn('document_id', $docs->pluck('id'))->get()
            ->groupBy('document_id');

        $name = "jawaban_{$qv->code}_".now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($docs, $cols, $varToCol, $answers, $useClean) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $cols);
            $blank = array_fill_keys($cols, '');
            foreach ($docs as $d) {
                $row = $blank;
                $row['wilayah_code'] = $d->wilayah_code;
                $row['no_bs'] = $d->no_bs;
                $row['no_ruta'] = $d->no_ruta;
                $row['krt_name'] = $d->krt_name;
                foreach ($answers[$d->id] ?? [] as $a) {
                    $col = $varToCol["{$a->question_id}:{$a->art_index}"] ?? null;
                    if ($col === null) {
                        continue;
                    }
                    $val = ($useClean && $a->clean_value !== null && $a->clean_value !== '')
                        ? $a->clean_value : $a->raw_value;
                    $row[$col] = $val;
                }
                fputcsv($out, array_values($row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    // -------------------------------------------------------- Harga & kalori komoditas

    private function hargaBlocks(QuestionnaireVersion $qv): \Illuminate\Support\Collection
    {
        // Inc 145: blok bertanda harga_kalori (diatur di Sunting blok), bukan kode 'IV.1'
        return $qv->blocks()->where('harga_kalori', true)->orderBy('order')->get();
    }

    public function hargaForm(Request $request)
    {
        // Inc 141: menu "Harga & Kalori" tidak lagi terpisah — jadi tab di halaman
        // Aturan Validasi. URL lama /admin/harga diarahkan ke sana (bookmark lama tetap jalan).
        $qv = $this->selectedQv($request);

        return redirect()->route('admin.aturan', ['qv' => $qv->id, 'tab' => 'harga']);
    }

    /** Simpan satu komoditas (kode, nama, rentang harga, kalori) — merge ke ref. */
    public function hargaSave(Request $request)
    {
        $d = $request->validate([
            'row_id' => 'required|integer',
            'code' => 'nullable|string|max:24',
            'label' => 'required|string|max:300',
            'harga_min' => 'nullable|numeric|min:0',
            'harga_max' => 'nullable|numeric|min:0',
            'kalori' => 'nullable|numeric|min:0',
        ]);
        if (($d['harga_min'] ?? null) !== null && ($d['harga_max'] ?? null) !== null
            && (float) $d['harga_min'] > (float) $d['harga_max']) {
            return back()->withErrors(['harga' => 'Harga minimal lebih besar dari maksimal.']);
        }
        $row = \App\Models\BlockRow::findOrFail($d['row_id']);
        $ref = (array) $row->ref;
        foreach (['harga_min', 'harga_max', 'kalori'] as $k) {
            if (($d[$k] ?? null) === null || $d[$k] === '') {
                unset($ref[$k]);
            } else {
                $ref[$k] = (string) (0 + $d[$k]);
            }
        }
        $row->update(['code' => trim($d['code'] ?? ''), 'label' => trim($d['label']), 'ref' => $ref ?: null]);
        AuditLog::record('block_row', $row->code, 'harga', "{$row->label}: {$row->ref['harga_min']}–{$row->ref['harga_max']}");

        return back()->with('ok', "Komoditas \"{$row->label}\" diperbarui.");
    }

    /** Impor massal harga (+kalori) dari Excel/CSV — cocokkan lewat kolom code. */
    public function hargaImport(Request $request)
    {
        $d = $request->validate([
            'data' => 'nullable|string',
            'file' => 'nullable|file|mimes:xlsx,csv,txt|max:5120',
            'sheet' => 'nullable|string|max:64',
        ]);
        try {
            $rows = $request->hasFile('file')
                ? \App\Services\XlsxReader::fromUpload($request->file('file'), ($d['sheet'] ?? null) ?: null)
                : \App\Services\XlsxReader::csvRows((string) ($d['data'] ?? ''));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }
        if (! $rows || ! array_key_exists('code', $rows[0] ?? []) || ! array_key_exists('harga_min', $rows[0] ?? [])) {
            return back()->withErrors(['file' => 'Kolom wajib: code, harga_min, harga_max (opsional kalori).']);
        }
        $qv = $this->selectedQv($request);
        $byCode = \App\Models\BlockRow::whereIn('block_id', $this->hargaBlocks($qv)->pluck('id'))->get()->keyBy('code');

        $n = 0;
        $skip = [];
        foreach ($rows as $i => $row) {
            $ln = $i + 2;
            $code = trim($row['code'] ?? '');
            $min = $row['harga_min'] ?? '';
            $max = $row['harga_max'] ?? '';
            if ($code === '' || ! is_numeric($min) || ! is_numeric($max)) {
                $skip[] = "baris {$ln}: code/harga tidak valid";

                continue;
            }
            if ((float) $min > (float) $max) {
                $skip[] = "baris {$ln}: harga_min > harga_max";

                continue;
            }
            if (! ($target = $byCode[$code] ?? null)) {
                $skip[] = "baris {$ln}: kode {$code} tidak ada di daftar komoditas";

                continue;
            }
            $ref = array_merge((array) $target->ref, ['harga_min' => (string) (0 + $min), 'harga_max' => (string) (0 + $max)]);
            if (isset($row['kalori']) && is_numeric($row['kalori'])) {
                $ref['kalori'] = (string) (0 + $row['kalori']);
            }
            $target->update(['ref' => $ref]);
            $n++;
        }
        AuditLog::record('block_row', $this->hargaBlocks($qv)->pluck('code')->implode(','), 'impor-harga', "{$n} komoditas");

        return redirect()->route('admin.harga', ['qv' => $qv->id])->with('ok', "{$n} komoditas diperbarui."
            .($skip ? ' Dilewati: '.implode('; ', $skip) : ''));
    }

    public function aliasSave(Request $request)
    {
        $d = $request->validate([
            'cycle_id' => 'required|integer',
            'source_col' => 'nullable|string|max:128',
            'target_col' => 'nullable|string|max:128',
            'bulk' => 'nullable|string',   // baris "sumber,target"
        ]);
        $qv = QuestionnaireVersion::where('cycle_id', $d['cycle_id'])->orderByDesc('id')->firstOrFail();
        $n = 0;

        $pairs = [];
        if (! empty($d['source_col']) && ! empty($d['target_col'])) {
            $pairs[] = [$d['source_col'], $d['target_col']];
        }
        foreach (preg_split('/\r\n|\r|\n/', trim($d['bulk'] ?? '')) as $line) {
            $p = array_map('trim', explode(',', $line, 2));
            if (count($p) === 2 && $p[0] !== '' && $p[1] !== '') {
                $pairs[] = $p;
            }
        }
        foreach ($pairs as [$src, $tgt]) {
            \App\Models\ColumnAlias::updateOrCreate(
                ['qversion_id' => $qv->id, 'source_col' => $src],
                ['target_col' => $tgt],
            );
            $n++;
        }
        AuditLog::record('column_alias', $qv->code, 'simpan', "{$n} alias");

        return back()->with('ok', "{$n} alias kolom disimpan untuk {$qv->code}.");
    }

    public function aliasDelete(\App\Models\ColumnAlias $alias)
    {
        $alias->delete();
        AuditLog::record('column_alias', $alias->id, 'hapus', $alias->source_col);

        return back();
    }

    public function docTemplate(SurveyCycle $cycle)
    {
        $qv = QuestionnaireVersion::where('cycle_id', $cycle->id)->orderByDesc('id')->firstOrFail();
        $art = max(1, min((int) request('art', 8), 30));
        $cols = $this->docColumns($qv, $art);
        $name = "template_impor_{$qv->code}_art{$art}.csv";

        return response()->streamDownload(function () use ($cols) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $cols);
            $ex = array_fill(0, count($cols), '');
            $ex[0] = '3526101001';
            $ex[1] = '001B';
            $ex[2] = '001';
            $ex[3] = 'Nama Kepala Rumah Tangga';
            fputcsv($out, $ex);
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    public function docDictionary(SurveyCycle $cycle)
    {
        $qv = QuestionnaireVersion::where('cycle_id', $cycle->id)->orderByDesc('id')->firstOrFail();
        $name = "kamus_data_{$qv->code}.csv";

        return response()->streamDownload(function () use ($qv) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['blok', 'tipe_blok', 'kode_rincian', 'var_name', 'kolom_impor', 'label', 'tipe', 'opsi']);
            foreach ($qv->blocks()->orderBy('order')->get() as $b) {
                foreach ($b->questions()->orderBy('order')->get() as $q) {
                    $opts = $q->options->map(fn ($o) => "{$o->code}={$o->label}")->implode(' | ');
                    $kolom = match ($b->repeat) {
                        'ruta' => $q->var_name,
                        'art' => "{$q->var_name}__<n>",
                        default => '(blok daftar — di luar format lebar)',
                    };
                    fputcsv($out, [
                        $b->code, $b->repeat, $q->code_rincian, $q->var_name, $kolom,
                        $q->label, $q->qtype, $opts,
                    ]);
                }
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    public function docImport(Request $request, RuleEngine $engine)
    {
        $d = $request->validate([
            'data' => 'required|string', 'cycle_id' => 'required|integer',
            'mode' => 'nullable|in:preview,commit',
        ]);
        $preview = ($d['mode'] ?? 'preview') === 'preview';
        $cycle = SurveyCycle::findOrFail($d['cycle_id']);
        $qv = QuestionnaireVersion::where('cycle_id', $cycle->id)->orderByDesc('id')->firstOrFail();

        $qByVar = Question::whereIn('block_id', Block::where('qversion_id', $qv->id)->pluck('id'))
            ->get()->keyBy('var_name');
        $desa = Wilayah::where('level', 'desa')->pluck('code')->flip();
        $alias = \App\Models\ColumnAlias::mapFor($qv->id);   // [kolom_capi => kolom_sistem]
        $aliasUsed = [];

        $dibuat = 0;
        $diperbarui = 0;
        $dilewati = [];
        $totErr = $totWarn = 0;
        $unknownCols = [];   // kolom => berapa baris memuat nilai
        $usedVars = [];      // var dikenal yang benar-benar terpakai
        $nRows = 0;

        DB::beginTransaction();
        try {
            foreach ($this->csvRows($d['data'], ['wilayah_code', 'no_bs', 'no_ruta']) as [$ln, $row]) {
                $nRows++;
                $wc = $row['wilayah_code'] ?? '';
                $bs = $row['no_bs'] ?? '';
                $ruta = $row['no_ruta'] ?? '';
                if (! $wc || ! $bs || ! $ruta) {
                    $dilewati[] = "baris {$ln}: kolom kunci kosong";
                    continue;
                }
                if ($desa->isNotEmpty() && ! $desa->has($wc)) {
                    $dilewati[] = "baris {$ln}: kode desa '{$wc}' tidak ada di master wilayah";
                    continue;
                }

                $sample = SampleHousehold::firstOrCreate(
                    ['cycle_id' => $cycle->id, 'wilayah_code' => $wc, 'no_bs' => $bs, 'no_ruta' => $ruta],
                    ['krt_name' => $row['krt_name'] ?? '', 'status' => 'selesai'],
                );
                $doc = Document::firstOrNew(['sample_id' => $sample->id]);
                $isNew = ! $doc->exists;
                $doc->fill(['qversion_id' => $qv->id, 'status' => 'selesai',
                    'created_by' => $request->user()->id])->save();

                $seenAny = false;
                foreach ($row as $col0 => $val) {
                    if (in_array($col0, ['wilayah_code', 'no_bs', 'no_ruta', 'krt_name'], true) || $val === '') {
                        continue;
                    }
                    // terjemahkan lewat alias kolom bila ada
                    $col = $alias[$col0] ?? $col0;
                    if (isset($alias[$col0])) {
                        $aliasUsed[$col0] = ($aliasUsed[$col0] ?? 0) + 1;
                    }
                    // kolom "var" atau "var__<rowindex>" untuk blok berulang
                    $art = 0;
                    $var = $col;
                    if (str_contains($col, '__') && preg_match('/^(.+)__(\d+)$/', $col, $m)) {
                        $var = $m[1];
                        $art = (int) $m[2];
                    }
                    $q = $qByVar[$var] ?? null;
                    if (! $q) {
                        $unknownCols[$col0] = ($unknownCols[$col0] ?? 0) + 1;
                        continue;
                    }
                    $usedVars[$var] = true;
                    Answer::updateOrCreate(
                        ['document_id' => $doc->id, 'question_id' => $q->id, 'art_index' => $art],
                        ['raw_value' => (string) $val],
                    );
                    $seenAny = true;
                }

                if (! $seenAny && $isNew) {
                    $dilewati[] = "baris {$ln}: tidak ada kolom variabel yang dikenal";
                    $doc->delete();
                    continue;
                }

                $sum = $engine->run($doc);
                $totErr += $sum['errors'];
                $totWarn += $sum['warnings'];
                $isNew ? $dibuat++ : $diperbarui++;
            }

            if ($preview) {
                DB::rollBack();
            } else {
                DB::commit();
                AuditLog::record('document', $cycle->period, 'impor-massal',
                    "{$dibuat} baru, {$diperbarui} diperbarui", $request->user()->id);
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $vars = Question::whereIn('block_id', $qv->blocks->pluck('id'))
            ->get(['var_name', 'block_id'])->groupBy('block_id')->map(fn ($g) => $g->pluck('var_name'));

        return view('admin.import_dokumen', [
            'blocks' => $qv->blocks, 'varsByBlock' => $vars,
            'aliases' => \App\Models\ColumnAlias::with('qversion:id,code')
                ->orderBy('qversion_id')->orderBy('source_col')->get()
                ->groupBy(fn ($a) => $a->qversion?->code ?? $a->qversion_id),
            'hasil' => compact('dibuat', 'diperbarui', 'dilewati', 'totErr', 'totWarn', 'preview', 'nRows')
                + ['unknown' => $unknownCols, 'used' => array_keys($usedVars), 'alias_used' => $aliasUsed,
                    'cycle' => $cycle->name, 'data' => $d['data'], 'cycle_id' => $cycle->id],
        ]);
    }

    // -------------------------------------------------------- Impor wilkerstat

    /**
     * Selalu tampilkan semua wilayah (dikelompokkan per kecamatan, bukan cuma muncul
     * saat dicari) — daftar desa bisa ratusan baris, jadi dikelompokkan spy tetap
     * telusur-mudah. `q` menyaring: kecamatan yang cocok menampilkan semua desanya,
     * kecamatan yang tidak cocok cuma menampilkan desa yang cocok saja. Dipakai
     * bersama oleh wilayahForm() dan wilayahImport() (supaya hasil impor langsung
     * kelihatan di halaman yang sama, bukan cuma pesan ringkasan).
     *
     * @return array{groups: \Illuminate\Support\Collection, kabupaten: ?Wilayah}
     */
    private function wilayahGroups(string $fQ): array
    {
        $hit = fn (Wilayah $w) => $fQ === '' || str_starts_with($w->code, $fQ) || stripos($w->name, $fQ) !== false;

        $desaByKec = Wilayah::where('level', 'desa')->orderBy('code')->get()->groupBy('parent_code');
        $groups = Wilayah::where('level', 'kecamatan')->orderBy('code')->get()
            ->map(function (Wilayah $k) use ($desaByKec, $fQ, $hit) {
                $desa = $desaByKec->get($k->code, collect());
                if ($fQ !== '' && ! $hit($k)) {
                    $desa = $desa->filter($hit)->values();
                }
                return ['kec' => $k, 'desa' => $desa];
            })
            ->filter(fn ($g) => $fQ === '' || $hit($g['kec']) || $g['desa']->isNotEmpty())
            ->values();

        return ['groups' => $groups, 'kabupaten' => Wilayah::where('level', 'kabupaten')->first()];
    }

    public function wilayahForm(Request $request)
    {
        $fQ = trim((string) $request->query('q', ''));

        return view('admin.import_wilayah', [
            'n' => Wilayah::count(),
            'byLevel' => Wilayah::selectRaw('level, count(*) as n')->groupBy('level')->pluck('n', 'level'),
            ...$this->wilayahGroups($fQ),
            'fQ' => $fQ, 'hasil' => null,
        ]);
    }

    public function wilayahRename(Request $request, Wilayah $wilayah)
    {
        $d = $request->validate(['name' => 'required|string|max:120']);
        $name = trim($d['name']);
        $old = $wilayah->name;
        $changed = $name !== $old;
        if ($changed) {
            $wilayah->update(['name' => $name]);
        }
        AuditLog::record('wilayah', $wilayah->code, 'ubah-nama',
            $changed ? "nama: {$old} -> {$name}" : 'tanpa perubahan');

        return back()->with('ok', "Wilayah {$wilayah->code} diperbarui.");
    }

    /** panjang kode per jenjang wilayah (konvensi sistem ini) — kabupaten adalah jenjang teratas (aplikasi 1 kabupaten). */
    // kode wilayah statistik BPS: provinsi(2)+kabupaten(2)=4, +kecamatan(3)=7, +desa(3)=10.
    private const WIL_LEN = ['kabupaten' => 4, 'kecamatan' => 7, 'desa' => 10];
    private const WIL_PARENT_LEVEL = ['kecamatan' => 'kabupaten', 'desa' => 'kecamatan'];

    /**
     * Kode induk selalu bisa diturunkan dari kode itu sendiri (awalan sepanjang jenjang di
     * atasnya) — tidak perlu diisi manual (Inc 134). Kosong untuk 'kabupaten' (jenjang teratas).
     */
    private function deriveWilayahParent(string $code, string $level): string
    {
        $parentLevel = self::WIL_PARENT_LEVEL[$level] ?? null;

        return $parentLevel ? substr($code, 0, self::WIL_LEN[$parentLevel]) : '';
    }

    public function wilayahStore(Request $request)
    {
        // 'kabupaten' sengaja tidak diterima di sini — cuma ada satu (Bangkalan/3526, sudah
        // ada sejak seed/setup awal), bukan sesuatu yang admin tambahkan lewat CRUD harian.
        $d = $request->validate([
            'code' => 'required|string|max:10',
            'name' => 'required|string|max:120',
            'level' => 'required|in:kecamatan,desa',
        ]);
        $code = trim($d['code']);
        $name = trim($d['name']);
        $level = $d['level'];

        if (strlen($code) !== self::WIL_LEN[$level]) {
            return back()->withErrors(['code' => "Panjang kode untuk jenjang {$level} harus ".self::WIL_LEN[$level].' digit.'])->withInput();
        }
        if (Wilayah::where('code', $code)->exists()) {
            return back()->withErrors(['code' => "Kode '{$code}' sudah ada — gunakan Sunting di daftar telusuri."])->withInput();
        }
        $parent = $this->deriveWilayahParent($code, $level);
        if (! Wilayah::where('code', $parent)->exists()) {
            return back()->withErrors(['code' => "Induk '{$parent}' belum ada di sistem — tambahkan itu dulu."])->withInput();
        }

        Wilayah::create(['code' => $code, 'name' => $name, 'level' => $level, 'parent_code' => $parent]);
        AuditLog::record('wilayah', $code, 'tambah', $name);

        // arahkan balik dengan pencarian sudah terisi kode baru ini, supaya langsung
        // kelihatan di daftar Telusuri — bukan cuma pesan sukses tanpa bukti di layar.
        return redirect()->route('admin.impor.wilayah', ['q' => $code])
            ->with('ok', "Wilayah {$code} — {$name} ditambahkan.");
    }

    public function wilayahDestroy(Wilayah $wilayah)
    {
        if (Wilayah::where('parent_code', $wilayah->code)->exists()) {
            return back()->withErrors(['hapus' => "Tidak bisa dihapus — {$wilayah->code} masih punya wilayah anak di bawahnya."]);
        }
        if (SampleHousehold::where('wilayah_code', $wilayah->code)->exists()) {
            return back()->withErrors(['hapus' => "Tidak bisa dihapus — {$wilayah->code} masih dipakai oleh sampel rumah tangga."]);
        }
        [$code, $name] = [$wilayah->code, $wilayah->name];
        $wilayah->delete();
        AuditLog::record('wilayah', $code, 'hapus', $name);

        return back()->with('ok', "Wilayah {$code} — {$name} dihapus.");
    }

    public function wilayahImport(Request $request)
    {
        $d = $request->validate(['data' => 'required|string', 'mode' => 'nullable|in:preview,commit']);
        $preview = ($d['mode'] ?? 'preview') === 'preview';

        // kumpulkan & urutkan: kode pendek (induk) dulu
        $rows = [];
        foreach ($this->csvRows($d['data'], ['code', 'name', 'level']) as [$ln, $row]) {
            $rows[] = [$ln, [
                'code' => trim($row['code'] ?? ''),
                'name' => trim($row['name'] ?? ''),
                'level' => strtolower(trim($row['level'] ?? '')),
            ]];
        }
        usort($rows, fn ($a, $b) => strlen($a[1]['code']) <=> strlen($b[1]['code']));

        $ada = Wilayah::pluck('level', 'code')->all();
        $baru = $diperbarui = 0;
        $dilewati = [];

        DB::beginTransaction();
        foreach ($rows as [$ln, $r]) {
            [$code, $name, $level] = [$r['code'], $r['name'], $r['level']];
            if (! $code || ! $name) {
                $dilewati[] = "baris {$ln}: code/name kosong";
                continue;
            }
            if (! isset(self::WIL_LEN[$level])) {
                $dilewati[] = "baris {$ln}: level '{$level}' tidak dikenal";
                continue;
            }
            if (strlen($code) !== self::WIL_LEN[$level]) {
                $dilewati[] = "baris {$ln}: panjang kode '{$code}' tidak sesuai jenjang {$level} (butuh ".self::WIL_LEN[$level]." digit)";
                continue;
            }
            $parent = $this->deriveWilayahParent($code, $level);
            if ($level !== 'kabupaten' && ! isset($ada[$parent])) {
                $dilewati[] = "baris {$ln}: induk '{$parent}' belum ada (di sistem maupun di berkas ini)";
                continue;
            }
            if (isset($ada[$code])) {
                Wilayah::where('code', $code)->update(['name' => $name, 'level' => $level, 'parent_code' => $parent]);
                $diperbarui++;
            } else {
                Wilayah::create(['code' => $code, 'name' => $name, 'level' => $level, 'parent_code' => $parent]);
                $baru++;
            }
            $ada[$code] = $level;
        }

        if ($preview) {
            DB::rollBack();
        } else {
            DB::commit();
            AuditLog::record('wilayah', '-', 'impor', "{$baru} baru, {$diperbarui} diperbarui");
        }

        return view('admin.import_wilayah', [
            'n' => Wilayah::count(),
            'byLevel' => Wilayah::selectRaw('level, count(*) as n')->groupBy('level')->pluck('n', 'level'),
            ...$this->wilayahGroups(''),
            'fQ' => '',
            'hasil' => ['baru' => $baru, 'diperbarui' => $diperbarui, 'dilewati' => $dilewati,
                'preview' => $preview, 'data' => $d['data']],
        ]);
    }

    // -------------------------------------------------------- Impor sampel

    /**
     * Data dropdown form tambah-satu-sampel (dipakai sampelForm & sampelImport).
     * `operators` dikirim lengkap dgn `scope` (bukan cuma id=>name) supaya JS di
     * halaman bisa menyaring pilihan Petugas begitu Kode desa dipilih — operator
     * dgn wilayah_scope kosong dianggap "bisa kemana saja", muncul di semua kecamatan.
     */
    private function sampelFormExtras(): array
    {
        return [
            'cycles' => SurveyCycle::orderByDesc('id')->get(),
            'operators' => User::where('role', 'operator')->where('active', true)->orderBy('name')
                ->get(['id', 'name', 'wilayah_scope'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'scope' => $u->wilayah_scope ?: null])
                ->values(),
            'pmls' => User::whereIn('role', ['pml', 'pj'])->where('active', true)->orderBy('name')->pluck('name', 'id'),
            'kecGroups' => $this->wilayahGroups('')['groups'],
        ];
    }

    public function sampelForm()
    {
        return view('admin.import_sampel', [...$this->sampelFormExtras(), 'hasil' => null]);
    }

    /** Tambah satu sampel langsung lewat form — CSV di bawah tetap ada utk banyak sekaligus. */
    public function sampelStore(Request $request)
    {
        $d = $request->validate([
            'cycle_id' => 'required|integer|exists:survey_cycles,id',
            'wilayah_code' => 'required|string|max:10',
            'no_bs' => 'required|string|max:20',
            'no_ruta' => 'required|string|max:12',
            'krt_name' => 'nullable|string|max:120',
            'petugas_id' => 'nullable|integer|exists:users,id',
            'pml_id' => 'nullable|integer|exists:users,id',
        ]);
        $wc = trim($d['wilayah_code']);
        $desa = Wilayah::where('code', $wc)->where('level', 'desa')->first();
        if (! $desa) {
            return back()->withErrors(['wilayah_code' => "Kode desa '{$wc}' tidak ada di master wilayah."])->withInput();
        }
        if (SampleHousehold::where('cycle_id', $d['cycle_id'])->where('wilayah_code', $wc)
            ->where('no_bs', $d['no_bs'])->where('no_ruta', $d['no_ruta'])->exists()) {
            return back()->withErrors(['no_ruta' => 'Sampel dengan wilayah / blok sensus / nomor rumah tangga ini sudah ada.'])->withInput();
        }
        // pertahanan di balik penyaringan JS (dropdown Petugas) — operator dgn wilayah_scope
        // terisi tidak boleh ditugaskan ke desa di luar kecamatannya, meski request dipaksa manual.
        if (! empty($d['petugas_id'])) {
            $scope = User::find($d['petugas_id'])->wilayah_scope ?: null;
            if ($scope && $scope !== $desa->parent_code) {
                return back()->withErrors(['petugas_id' => "Petugas ini terbatas ke kecamatan {$scope}, tidak cocok dengan kecamatan desa yang dipilih ({$desa->parent_code})."])->withInput();
            }
        }

        $sample = SampleHousehold::create([
            'cycle_id' => $d['cycle_id'], 'wilayah_code' => $wc, 'no_bs' => $d['no_bs'], 'no_ruta' => $d['no_ruta'],
            'krt_name' => $d['krt_name'] ?? '', 'petugas_id' => $d['petugas_id'] ?? null,
            'pml_id' => $d['pml_id'] ?? null, 'status' => 'belum',
        ]);
        AuditLog::record('sample', (string) $sample->id, 'tambah', "{$wc}/{$d['no_bs']}/{$d['no_ruta']}", $request->user()->id);

        return redirect()->route('monitoring.sample', $sample)->with('ok', 'Sampel ditambahkan.');
    }

    public function sampelImport(Request $request)
    {
        $d = $request->validate(['cycle_id' => 'required|integer', 'data' => 'required|string']);
        $cycle = SurveyCycle::findOrFail($d['cycle_id']);
        $users = User::pluck('id', 'username');
        $desa = Wilayah::where('level', 'desa')->pluck('code')->flip();
        $ada = SampleHousehold::where('cycle_id', $cycle->id)->get()
            ->mapWithKeys(fn ($s) => ["{$s->wilayah_code}|{$s->no_bs}|{$s->no_ruta}" => true]);
        $dibuat = 0;
        $dilewati = [];
        foreach ($this->csvRows($d['data'], ['wilayah_code', 'no_bs', 'no_ruta']) as [$ln, $row]) {
            $wc = $row['wilayah_code'] ?? '';
            $bs = $row['no_bs'] ?? '';
            $ruta = $row['no_ruta'] ?? '';
            if (! $wc || ! $bs || ! $ruta) {
                $dilewati[] = "baris {$ln}: kolom kunci kosong";
                continue;
            }
            if ($desa->isNotEmpty() && ! $desa->has($wc)) {
                $dilewati[] = "baris {$ln}: kode desa '{$wc}' tidak ada di master wilayah";
                continue;
            }
            if ($ada->has("{$wc}|{$bs}|{$ruta}")) {
                $dilewati[] = "baris {$ln}: sampel {$wc}/{$bs}/{$ruta} sudah ada";
                continue;
            }
            $pet = $users[$row['petugas'] ?? ''] ?? null;
            if (($row['petugas'] ?? '') && ! $pet) {
                $dilewati[] = "baris {$ln}: username petugas '{$row['petugas']}' tidak ada";
                continue;
            }
            $w = trim((string) ($row['weight'] ?? $row['penimbang'] ?? ''));
            SampleHousehold::create([
                'cycle_id' => $cycle->id, 'wilayah_code' => $wc, 'no_bs' => $bs, 'no_ruta' => $ruta,
                'krt_name' => $row['krt_name'] ?? '', 'petugas_id' => $pet,
                'pml_id' => $users[$row['pml'] ?? ''] ?? null, 'status' => 'belum',
                'weight' => is_numeric($w) ? (float) $w : null,
            ]);
            $ada["{$wc}|{$bs}|{$ruta}"] = true;
            $dibuat++;
        }
        AuditLog::record('sample', $cycle->period, 'impor', "{$dibuat} sampel");
        return view('admin.import_sampel', [
            ...$this->sampelFormExtras(),
            'hasil' => ['dibuat' => $dibuat, 'dilewati' => $dilewati, 'cycle' => $cycle],
        ]);
    }

    // -------------------------------------------------------- Jadwal lapangan

    private const CYCLE_STATUS = ['persiapan', 'lapangan', 'pengolahan', 'selesai'];

    public function jadwalForm()
    {
        // dibungkus per tahun (dari awal `period`, format YYYY-MM) supaya tidak menumpuk jadi
        // satu daftar panjang begitu siklus makin banyak (mis. 4 triwulan × beberapa instrumen/tahun).
        $cycles = SurveyCycle::withCount(['samples', 'versions'])->orderByDesc('id')->get();
        $byYear = $cycles
            ->groupBy(fn ($c) => $c->period ? substr($c->period, 0, 4) : 'Lainnya')
            ->sortKeysDesc()
            ->map(fn ($group) => $group->sortBy('period')->values());

        return view('admin.jadwal', [
            'cyclesByYear' => $byYear,
            'statuses' => self::CYCLE_STATUS,
        ]);
    }

    public function cycleCreate(Request $request)
    {
        $d = $request->validate([
            'name' => 'required|string|max:120',
            'period' => 'required|string|max:16',   // mis. 2027-03
            'status' => 'required|in:'.implode(',', self::CYCLE_STATUS),
            'field_start' => 'nullable|date',
            'field_end' => 'nullable|date|after_or_equal:field_start',
        ]);
        $c = SurveyCycle::create([
            'name' => trim($d['name']), 'period' => trim($d['period']), 'status' => $d['status'],
            'field_start' => ($d['field_start'] ?? null) ?: null,
            'field_end' => ($d['field_end'] ?? null) ?: null,
        ]);
        AuditLog::record('cycle', $c->id, 'buat', $c->name);

        return redirect()->route('admin.jadwal')->with('ok', "Siklus \"{$c->name}\" dibuat. Berikutnya: buat versi kuesioner & impor sampel.");
    }

    /** Detail kesiapan penutupan satu siklus. */
    public function cycleShow(SurveyCycle $cycle)
    {
        $samples = $cycle->samples()->get();
        $sampleIds = $samples->pluck('id');
        $docs = Document::whereIn('sample_id', $sampleIds)->get(['id', 'status', 'sample_id']);

        $active = $samples->where('status', '!=', 'ganti_sampel');
        $unresolved = $active->whereIn('status', ['belum', 'proses'])->count();
        $notBersih = $docs->where('status', '!=', 'bersih')->count();
        $errDocs = $docs->isEmpty() ? 0 : RuleResult::whereIn('document_id', $docs->pluck('id'))
            ->where('status', 'error')->distinct('document_id')->count('document_id');

        // angka respons (kasus tidak-eligible dikeluarkan dari penyebut)
        $done = $active->where('status', 'selesai')->count();
        $ineligible = $active->whereIn('nonresponse_reason', ['bangunan_kosong', 'ruta_ganda'])->count();
        $eligible = max(0, $active->count() - $ineligible);
        $response = [
            'eligible' => $eligible, 'responded' => $done,
            'nonresponse' => $active->where('status', 'non_respons')->count(),
            'replaced' => $samples->where('status', 'ganti_sampel')->count(),
            'ineligible' => $ineligible,
            'rate' => $eligible ? round(100 * $done / $eligible, 1) : 0.0,
        ];

        // corong pengolahan
        $funnel = [];
        $byS = $docs->countBy('status');
        foreach (['draft' => 'Entri/draft', 'selesai' => 'Menunggu cleaning',
            'dikembalikan' => 'Dikembalikan', 'bersih' => 'Bersih'] as $k => $lbl) {
            $funnel[] = ['label' => $lbl, 'n' => (int) ($byS[$k] ?? 0)];
        }

        // temuan tersering
        $topRules = $docs->isEmpty() ? collect() : RuleResult::query()
            ->whereIn('rule_results.document_id', $docs->pluck('id'))
            ->whereIn('rule_results.status', ['error', 'warning'])
            ->join('validation_rules as vr', 'vr.id', '=', 'rule_results.rule_id')
            ->selectRaw('vr.code, vr.severity, vr.message, count(distinct rule_results.document_id) as n_doc')
            ->groupBy('vr.code', 'vr.severity', 'vr.message')
            ->orderByRaw('vr.severity = "error" desc, n_doc desc')->limit(10)->get();

        return view('admin.siklus', [
            'cycle' => $cycle,
            'sTotal' => $samples->count(),
            'sByStatus' => $samples->countBy('status'),
            'unresolved' => $unresolved,
            'dTotal' => $docs->count(),
            'dByStatus' => $docs->countBy('status'),
            'notBersih' => $notBersih,
            'errDocs' => $errDocs,
            'response' => $response, 'funnel' => $funnel, 'topRules' => $topRules,
            'versions' => $cycle->versions()->pluck('code'),
            'versionObjs' => $cycle->versions()->orderBy('id')->get(['id', 'code']),
            'ready' => $unresolved === 0 && $notBersih === 0 && $errDocs === 0,
            'statusLabels' => \App\Http\Controllers\MonitoringController::STATUS,
        ]);
    }

    public function cycleRevalidate(Request $request, SurveyCycle $cycle)
    {
        $engine = app(RuleEngine::class);
        $docs = Document::whereIn('sample_id', $cycle->samples()->select('id'))->orderBy('id')->get();
        foreach ($docs as $doc) {
            $engine->run($doc);
        }
        AuditLog::record('cycle', $cycle->id, 'revalidasi', "{$docs->count()} sampel");

        return redirect()->route('admin.siklus', $cycle)
            ->with('ok', "{$docs->count()} sampel direvalidasi (hasil aturan & snapshot diperbarui).");
    }

    public function cycleLock(Request $request, SurveyCycle $cycle)
    {
        $cycle->update(['status' => 'selesai']);
        AuditLog::record('cycle', $cycle->id, 'kunci', 'status -> selesai');

        return redirect()->route('admin.siklus', $cycle)->with('ok', 'Siklus dikunci (selesai). Entri & pengolahan untuk siklus ini terkunci.');
    }

    public function cycleUnlock(Request $request, SurveyCycle $cycle)
    {
        $cycle->update(['status' => 'pengolahan']);
        AuditLog::record('cycle', $cycle->id, 'buka-kunci', 'status -> pengolahan');

        return redirect()->route('admin.siklus', $cycle)->with('ok', 'Kunci siklus dibuka.');
    }

    public function jadwalSave(Request $request, SurveyCycle $cycle)
    {
        $d = $request->validate([
            'name' => 'required|string|max:120',
            'field_start' => 'nullable|date',
            'field_end' => 'nullable|date|after_or_equal:field_start',
            'status' => 'required|in:'.implode(',', self::CYCLE_STATUS),
        ]);
        $cycle->update([
            'name' => trim($d['name']),
            'field_start' => ($d['field_start'] ?? null) ?: null,
            'field_end' => ($d['field_end'] ?? null) ?: null,
            'status' => $d['status'],
        ]);
        AuditLog::record('cycle', $cycle->id, 'ubah', $d['status'].' · '.($d['field_start'] ?? '-').' s.d. '.($d['field_end'] ?? '-'));

        return redirect()->route('admin.jadwal')->with('ok', 'Siklus diperbarui.');
    }
}
