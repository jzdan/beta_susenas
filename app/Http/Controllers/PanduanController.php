<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\GuideSlide;
use App\Support\Panduan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Inc 167 — panduan bergambar per halaman admin. Admin mengelola langkah (judul, teks, gambar,
 * urutan, tampil/sembunyi) di /admin/panduan; pengguna melihatnya sebagai carousel (<x-panduan>).
 */
class PanduanController extends Controller
{
    private const IMAGE_RULE = 'nullable|image|mimes:png,jpg,jpeg,webp|max:4096';

    /** label bagian & tautan "Buka halaman ini" (hanya alamat di dalam aplikasi, diawali "/") */
    private const EXTRA_RULES = ['section' => 'nullable|string|max:120', 'link' => ['nullable', 'string', 'max:255', 'regex:#^/(?!/)#']];

    public function index()
    {
        $counts = GuideSlide::selectRaw('page_key, count(*) n, sum(case when active then 1 else 0 end) aktif')
            ->groupBy('page_key')->get()->keyBy('page_key');

        return view('admin.panduan.index', ['pages' => Panduan::pages(), 'counts' => $counts, 'nav' => 'panduan']);
    }

    public function edit(string $key)
    {
        abort_unless(Panduan::exists($key), 404);

        return view('admin.panduan.edit', [
            'key' => $key, 'label' => Panduan::label($key), 'pageUrl' => Panduan::url($key),
            'slides' => GuideSlide::where('page_key', $key)->orderBy('order')->orderBy('id')->get(),
            'nav' => 'panduan',
        ]);
    }

    public function store(Request $request, string $key)
    {
        abort_unless(Panduan::exists($key), 404);
        $d = $request->validate(['title' => 'required|string|max:150', 'body' => 'nullable|string|max:2000',
            'image' => self::IMAGE_RULE] + self::EXTRA_RULES);
        $slide = GuideSlide::create([
            'page_key' => $key, 'title' => $d['title'], 'body' => $d['body'] ?? null,
            'section' => ($d['section'] ?? null) ?: null, 'link' => ($d['link'] ?? null) ?: null,
            'order' => (int) GuideSlide::where('page_key', $key)->max('order') + 1,
            'image_path' => $request->hasFile('image') ? $this->saveImage($request) : null,
        ]);
        AuditLog::record('panduan', $slide->id, 'buat', Panduan::label($key).': '.$slide->title);

        return redirect()->route('admin.panduan.edit', $key)->with('ok', "Langkah \"{$slide->title}\" ditambahkan.");
    }

    public function update(Request $request, GuideSlide $slide)
    {
        $d = $request->validate(['title' => 'required|string|max:150', 'body' => 'nullable|string|max:2000',
            'image' => self::IMAGE_RULE, 'remove_image' => 'nullable|boolean', 'active' => 'nullable|boolean'] + self::EXTRA_RULES);
        $data = ['title' => $d['title'], 'body' => $d['body'] ?? null, 'active' => $request->boolean('active'),
            'section' => ($d['section'] ?? null) ?: null, 'link' => ($d['link'] ?? null) ?: null];
        if ($request->hasFile('image') || $request->boolean('remove_image')) {
            $slide->deleteImage();
            $data['image_path'] = $request->hasFile('image') ? $this->saveImage($request) : null;
        }
        $slide->update($data);
        AuditLog::record('panduan', $slide->id, 'ubah', Panduan::label($slide->page_key).': '.$slide->title);

        return redirect()->to(route('admin.panduan.edit', $slide->page_key).'#langkah-'.$slide->id)
            ->with('ok', "Langkah \"{$slide->title}\" disimpan.");
    }

    public function move(Request $request, GuideSlide $slide)
    {
        $dir = $request->input('dir') === 'up' ? -1 : 1;
        DB::transaction(function () use ($slide, $dir) {
            $list = GuideSlide::where('page_key', $slide->page_key)->orderBy('order')->orderBy('id')->get()->values();
            $i = $list->search(fn ($s) => $s->id === $slide->id);
            $j = $i + $dir;
            if ($i === false || $j < 0 || $j >= $list->count()) {
                return;
            }
            $ids = $list->pluck('id')->all();
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $n => $id) {
                GuideSlide::whereKey($id)->update(['order' => $n + 1]);
            }
        });

        return redirect()->to(route('admin.panduan.edit', $slide->page_key).'#langkah-'.$slide->id);
    }

    public function destroy(GuideSlide $slide)
    {
        $key = $slide->page_key;
        $slide->deleteImage();
        $slide->delete();
        AuditLog::record('panduan', $slide->id, 'hapus', Panduan::label($key).': '.$slide->title);

        return redirect()->route('admin.panduan.edit', $key)->with('ok', "Langkah \"{$slide->title}\" dihapus.");
    }

    /** Halaman "Alur kerja" (menu Bantuan): carousel urutan kerja admin + daftar isi per bagian. */
    public function alur()
    {
        return view('panduan.alur', ['slides' => GuideSlide::forPage('alur-kerja'), 'nav' => 'alur']);
    }

    /** Panduan sudah muncul sendiri untuk pengguna ini — jangan muncul sendiri lagi (sampai isinya berubah). */
    public function seen(Request $request, string $key)
    {
        abort_unless(Panduan::exists($key), 404);
        $v = (string) $request->validate(['version' => 'required|string|max:20'])['version'];
        GuideSlide::markSeen($request->user()->id, $key, $v);

        return response()->noContent();
    }

    /** Gambar langkah — untuk semua pengguna yang sudah masuk (panduan tampil di halaman mereka). */
    public function image(GuideSlide $slide)
    {
        abort_unless($slide->hasImage(), 404);

        return response()->file(Storage::disk('local')->path($slide->image_path), [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function saveImage(Request $request): string
    {
        $file = $request->file('image');

        return $file->storeAs('panduan', Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'png'), 'local');
    }
}
