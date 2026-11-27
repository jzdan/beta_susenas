<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Satu langkah panduan bergambar (Inc 167). Satu halaman (page_key) = beberapa langkah berurutan,
 * ditampilkan sebagai carousel lewat komponen <x-panduan>. Gambar di disk 'local' (tidak publik),
 * disajikan lewat route panduan.gambar supaya tidak perlu storage:link di server.
 */
class GuideSlide extends Model
{
    protected $fillable = ['page_key', 'order', 'section', 'title', 'body', 'image_path', 'link', 'active'];

    protected $casts = ['active' => 'boolean'];

    /** Langkah aktif satu halaman, urut. */
    public static function forPage(string $key): Collection
    {
        return static::where('page_key', $key)->where('active', true)->orderBy('order')->orderBy('id')->get();
    }

    /** Penanda versi isi panduan: berubah bila langkah ditambah/diubah → muncul sendiri sekali lagi. */
    public static function versionOf(Collection $slides): string
    {
        return substr(md5($slides->map(fn ($s) => $s->id.'@'.$s->updated_at?->timestamp)->implode('|')), 0, 10);
    }

    /** Panduan halaman ini (versi ini) sudah pernah muncul sendiri untuk pengguna itu? */
    public static function seenBy(?int $userId, string $key, string $version): bool
    {
        return $userId === null || \Illuminate\Support\Facades\DB::table('guide_views')
            ->where('user_id', $userId)->where('page_key', $key)->where('version', $version)->exists();
    }

    public static function markSeen(int $userId, string $key, string $version): void
    {
        \Illuminate\Support\Facades\DB::table('guide_views')->upsert(
            [['user_id' => $userId, 'page_key' => $key, 'version' => $version, 'seen_at' => now()]],
            ['user_id', 'page_key'], ['version', 'seen_at']);
    }

    public function hasImage(): bool
    {
        return $this->image_path && Storage::disk('local')->exists($this->image_path);
    }

    public function deleteImage(): void
    {
        if ($this->image_path) {
            Storage::disk('local')->delete($this->image_path);
        }
    }
}
