<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fca_user_imports') || !Schema::hasTable('fca_user_import_rows')) {
            return;
        }

        DB::table('fca_user_imports')
            ->select('id', 'label', 'created_at')
            ->orderBy('id')
            ->chunkById(100, function ($imports) {
                foreach ($imports as $import) {
                    $row = DB::table('fca_user_import_rows')
                        ->where('fca_user_import_id', $import->id)
                        ->whereNotNull('usuario_created_at')
                        ->where('usuario_created_at', '<>', '')
                        ->orderBy('source_row')
                        ->orderBy('id')
                        ->first(['usuario_created_at']);

                    $label = $this->monthLabelFromValue($row->usuario_created_at ?? null);
                    if (!$label) continue;

                    $currentLabel = trim((string) $import->label);
                    $uploadLabel = $this->monthLabelFromValue($import->created_at);

                    if ($currentLabel !== '' && $currentLabel !== $uploadLabel) {
                        continue;
                    }

                    DB::table('fca_user_imports')
                        ->where('id', $import->id)
                        ->update([
                            'label' => $label,
                            'updated_at' => now(),
                        ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        // Nao desfaz ajuste de rotulo historico.
    }

    private function monthLabelFromValue($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;

        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})\b/', $value, $m)) {
            $year = (int) $m[3];
            if ($year < 100) $year += 2000;
            $month = (int) $m[2];
            if ($month >= 1 && $month <= 12) return sprintf('%02d/%04d', $month, $year);
        }

        if (preg_match('/\b(\d{1,2})[\/\-](\d{2,4})\b/', $value, $m)) {
            $year = (int) $m[2];
            if ($year < 100) $year += 2000;
            $month = (int) $m[1];
            if ($month >= 1 && $month <= 12) return sprintf('%02d/%04d', $month, $year);
        }

        $months = [
            'jan' => 1, 'fev' => 2, 'mar' => 3, 'abr' => 4,
            'mai' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8,
            'set' => 9, 'out' => 10, 'nov' => 11, 'dez' => 12,
        ];
        if (preg_match('/\b(jan|fev|mar|abr|mai|jun|jul|ago|set|out|nov|dez)[a-z]*[\/\- ]?(\d{2,4})\b/i', $value, $m)) {
            $year = (int) $m[2];
            if ($year < 100) $year += 2000;
            return sprintf('%02d/%04d', $months[strtolower(substr($m[1], 0, 3))], $year);
        }

        try {
            return \Carbon\Carbon::parse($value)->format('m/Y');
        } catch (\Throwable $e) {
            return null;
        }
    }
};
