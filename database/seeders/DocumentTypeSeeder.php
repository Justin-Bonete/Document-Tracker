<?php
namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['LETTER', 'Letter / Correspondence', null],
            ['MEMO', 'Memorandum', null],
            ['CERT_REQUEST', 'Request for Certification', null],
            ['REPORT', 'Report', null],
            ['FORM', 'Form / Application', null],
            ['CONTRACT', 'Contract / Agreement', 3650],
            ['OTHER', 'Other', null],
        ] as [$code, $name, $days]) {
            DocumentType::updateOrCreate(['code' => $code], ['name' => $name, 'retention_days' => $days]);
        }
    }
}
