<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fca_user_imports')) {
            return;
        }

        $duplicateLabels = DB::table('fca_user_imports')
            ->select('label')
            ->whereNotNull('label')
            ->where('label', '<>', '')
            ->groupBy('label')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('label');

        foreach ($duplicateLabels as $label) {
            $ids = DB::table('fca_user_imports')
                ->where('label', $label)
                ->orderByDesc('id')
                ->pluck('id');

            $dropIds = $ids->skip(1);

            if ($dropIds->isNotEmpty()) {
                DB::table('fca_user_imports')->whereIn('id', $dropIds)->delete();
            }
        }
    }

    public function down(): void
    {
        // Deduplicação de dados não é reversível.
    }
};
