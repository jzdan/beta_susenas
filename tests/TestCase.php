<?php

namespace Tests;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Siapkan isian supaya petugas boleh mengisinya: periode lapangan siklusnya dibuka &
     * sampelnya ditugaskan ke petugas itu (lihat Document::editableByPetugas).
     */
    protected function openForPetugas(Document $doc, User $petugas): Document
    {
        $doc->sample->cycle->update(['status' => 'lapangan']);
        $doc->sample->update(['petugas_id' => $petugas->id]);

        return $doc->fresh();
    }
}
