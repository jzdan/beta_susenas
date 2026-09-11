<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inc 117 — aturan harga Blok IV.1 dipecah jadi KEMAHALAN & KEMURAHAN, dan
 * pesannya menampilkan harga satuan terhitung + rentang wajar komoditas
 * (placeholder {..} / {=..} diisi RuleEngine saat evaluasi).
 */
return new class extends Migration
{
    public function up(): void
    {
        $mahalExpr = 'filled(iv1_nilai_beli) and n(iv1_kuantitas) > 0 and filled(ref_harga_max) '
            .'and n(iv1_nilai_beli) / n(iv1_kuantitas) > n(ref_harga_max)';
        $murahExpr = 'filled(iv1_nilai_beli) and n(iv1_kuantitas) > 0 and filled(ref_harga_min) '
            .'and n(iv1_nilai_beli) / n(iv1_kuantitas) < n(ref_harga_min)';
        $mahalMsg = 'Harga satuan Rp{=n(iv1_nilai_beli)/n(iv1_kuantitas)} kemahalan — batas wajar Rp{ref_harga_min}–Rp{ref_harga_max} per satuan komoditas.';
        $murahMsg = 'Harga satuan Rp{=n(iv1_nilai_beli)/n(iv1_kuantitas)} kemurahan — batas wajar Rp{ref_harga_min}–Rp{ref_harga_max} per satuan komoditas.';

        foreach (DB::table('validation_rules')->where('code', 'IV1-HARGA')->get() as $r) {
            DB::table('validation_rules')->where('id', $r->id)->update([
                'code' => 'IV1-HARGA-MAHAL',
                'expr' => $mahalExpr,
                'message' => $mahalMsg,
            ]);
            if (! DB::table('validation_rules')->where('qversion_id', $r->qversion_id)
                ->where('code', 'IV1-HARGA-MURAH')->exists()) {
                DB::table('validation_rules')->insert([
                    'qversion_id' => $r->qversion_id,
                    'code' => 'IV1-HARGA-MURAH',
                    'scope' => $r->scope,
                    'row_block' => $r->row_block,
                    'target_var' => $r->target_var,
                    'expr' => $murahExpr,
                    'severity' => 'warning',
                    'message' => $murahMsg,
                    'note' => $r->note ?? null,
                    'active' => $r->active,
                ]);
            }
        }

        DB::table('validation_rules')->where('code', 'IV1-KUANTITAS-MAX')->update([
            'message' => 'Kuantitas {=n(iv1_kuantitas)} melebihi batas maksimum wajar ({ref_max_kons} per rumah tangga per minggu).',
        ]);

        foreach ([
            'IV1-HARGA-MAHAL' => 'Harga satuan hasil bagi nilai ÷ kuantitas lebih tinggi dari batas atas harga wajar komoditas (kolom harga_max). Cek salah ketik nol atau salah satuan.',
            'IV1-HARGA-MURAH' => 'Harga satuan hasil bagi nilai ÷ kuantitas lebih rendah dari batas bawah harga wajar komoditas (kolom harga_min). Cek nilai/kuantitas tertukar atau kurang nol.',
            'IV1-KUANTITAS-MAX' => 'Kuantitas konsumsi seminggu melebihi batas maksimum wajar per rumah tangga (kolom max_kons).',
        ] as $code => $note) {
            DB::table('validation_rules')->where('code', $code)->whereNull('note')->update(['note' => $note]);
        }
    }

    public function down(): void
    {
        DB::table('validation_rules')->where('code', 'IV1-HARGA-MURAH')->delete();
        DB::table('validation_rules')->where('code', 'IV1-HARGA-MAHAL')->update([
            'code' => 'IV1-HARGA',
            'expr' => 'filled(iv1_nilai_beli) and n(iv1_kuantitas) > 0 and filled(ref_harga_min) and filled(ref_harga_max) '
                .'and (n(iv1_nilai_beli) / n(iv1_kuantitas) < n(ref_harga_min) or n(iv1_nilai_beli) / n(iv1_kuantitas) > n(ref_harga_max))',
            'message' => 'Harga satuan (nilai ÷ kuantitas) di luar rentang wajar komoditas ini.',
        ]);
        DB::table('validation_rules')->where('code', 'IV1-KUANTITAS-MAX')->update([
            'message' => 'Kuantitas komoditas melebihi batas maksimum wajar per rumah tangga per minggu.',
        ]);
    }
};
