<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder produksi: hanya struktur kuesioner + 1 admin, tanpa data contoh.
 *
 *   php artisan migrate --force
 *   php artisan db:seed --class=MetadataSeeder --force
 */
class MetadataSeeder extends Seeder
{
    public function run(): void
    {
        $ds = new DatabaseSeeder();
        $ds->setContainer($this->container)->setCommand($this->command);
        $ds->metadataOnly();
    }
}
