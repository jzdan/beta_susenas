<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\BlockRow;
use App\Models\QuestionnaireVersion;
use App\Models\User;
use App\Services\XlsxReader;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class XlsxImportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = DatabaseSeeder::class;

    /** Bangun berkas .xlsx minimal (tanpa dependency) berisi baris yang diberikan. */
    private function makeXlsx(array $rows, string $sheet = 'Sheet1'): string
    {
        $dir = sys_get_temp_dir();
        $path = tempnam($dir, 'xls').'.xlsx';
        @unlink($path);
        $col = function (int $n): string {
            $s = '';
            while ($n > 0) {
                $m = ($n - 1) % 26;
                $s = chr(65 + $m).$s;
                $n = intdiv($n - 1 - $m, 26);
            }

            return $s;
        };
        $z = new \ZipArchive;
        $z->open($path, \ZipArchive::CREATE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$sheet.'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $sd = '';
        $rn = 0;
        foreach ($rows as $r) {
            $rn++;
            $cn = 0;
            $cells = '';
            foreach ($r as $v) {
                $cn++;
                $ref = $col($cn).$rn;
                if (is_numeric($v) && $v !== '') {
                    $cells .= '<c r="'.$ref.'"><v>'.$v.'</v></c>';
                } else {
                    $cells .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $v, ENT_XML1).'</t></is></c>';
                }
            }
            $sd .= '<row r="'.$rn.'">'.$cells.'</row>';
        }
        $z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sd.'</sheetData></worksheet>');
        $z->close();

        return $path;
    }

    private function upload(string $path, string $name): UploadedFile
    {
        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_reader_parses_xlsx_by_header(): void
    {
        $path = $this->makeXlsx([
            ['code', 'harga_min', 'harga_max'],
            ['002', '12000', '18000'],
            ['', '', ''],
            ['075', '1800', '2600'],
        ]);
        $rows = XlsxReader::rows($path);
        $this->assertCount(2, $rows);
        $this->assertSame(['code' => '002', 'harga_min' => '12000', 'harga_max' => '18000'], $rows[0]);
        @unlink($path);
    }

    public function test_admin_imports_commodity_prices_from_xlsx(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $b = Block::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))
            ->where('code', 'IV.1')->firstOrFail();
        $path = $this->makeXlsx([
            ['code', 'harga_min', 'harga_max'],
            ['002', '12000', '18000'],
            ['075', '1800', '2600'],
        ], 'HARGA');

        $this->actingAs($admin)->post('/admin/harga', [
            'sheet' => 'HARGA',
            'file' => $this->upload($path, 'harga.xlsx'),
        ])->assertRedirect()->assertSessionHas('ok');

        $r002 = BlockRow::where('block_id', $b->id)->where('code', '002')->firstOrFail();
        $this->assertSame('12000', $r002->ref['harga_min']);
        $this->assertSame('18000', $r002->ref['harga_max']);
        @unlink($path);
    }

    public function test_admin_imports_block_rows_with_calorie_data_from_xlsx(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $b = Block::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))
            ->where('code', 'IV.1')->firstOrFail();
        $free = ((int) BlockRow::where('block_id', $b->id)->max('row_index')) + 50;

        $path = $this->makeXlsx([
            ['row_index', 'code', 'label', 'kalori', 'harga_min', 'harga_max', 'satuan', 'max_kons'],
            [(string) $free, '900', 'Komoditas Uji', '350', '5000', '9000', 'ons', '4'],
        ], 'KALORI');

        $this->actingAs($admin)->post("/admin/blok/{$b->id}/baris", [
            'sheet' => 'KALORI',
            'file' => $this->upload($path, 'kalori.xlsx'),
        ])->assertRedirect();

        $row = BlockRow::where('block_id', $b->id)->where('row_index', $free)->firstOrFail();
        $this->assertSame('Komoditas Uji', $row->label);
        $this->assertSame('350', $row->ref['kalori'] ?? null);
        $this->assertSame('4', $row->ref['max_kons'] ?? null);
        $this->assertSame('ons', $row->ref['satuan'] ?? null);
        @unlink($path);
    }

    public function test_admin_imports_block_rows_from_csv_upload(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $b = Block::whereHas('qversion', fn ($q) => $q->where('code', 'VSEN26.KP'))
            ->where('code', 'IV.1')->firstOrFail();
        $free = ((int) BlockRow::where('block_id', $b->id)->max('row_index')) + 70;

        $csv = "row_index,code,label,kalori\n{$free},901,Komoditas CSV,120\n";
        $path = tempnam(sys_get_temp_dir(), 'csv').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($admin)->post("/admin/blok/{$b->id}/baris", [
            'file' => $this->upload($path, 'baris.csv'),
        ])->assertRedirect();

        $this->assertDatabaseHas('block_rows', [
            'block_id' => $b->id, 'row_index' => $free, 'label' => 'Komoditas CSV',
        ]);
        @unlink($path);
    }
}
