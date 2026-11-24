<?php

namespace App\Models\Concerns;

use App\Models\QuestionnaireVersion;

/**
 * Setiap perubahan pada bahan pemeriksaan (aturan, parameter, agregat, turunan) menandai
 * versinya `rules_changed_at = now()`, supaya halaman Aturan Validasi bisa menunjukkan
 * sampel yang hasil pemeriksaannya sudah basi (Inc 153). Model memakai kolom `qversion_id`.
 * Perubahan lewat query builder (update/delete massal) tidak memicu event — panggil
 * QuestionnaireVersion::markRulesChanged() sendiri di tempat itu.
 */
trait MarksRulesChanged
{
    protected static function bootMarksRulesChanged(): void
    {
        $mark = fn ($m) => QuestionnaireVersion::markRulesChanged($m->qversion_id);
        static::saved($mark);
        static::deleted($mark);
    }
}
