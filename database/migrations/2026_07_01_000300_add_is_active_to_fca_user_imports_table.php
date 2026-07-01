<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fca_user_imports')) {
            return;
        }

        if (!Schema::hasColumn('fca_user_imports', 'is_active')) {
            Schema::table('fca_user_imports', function (Blueprint $table) {
                $table->boolean('is_active')->default(false)->after('rows_count');
            });
        }

        $latestId = DB::table('fca_user_imports')->max('id');
        if ($latestId) {
            DB::table('fca_user_imports')->update(['is_active' => false]);
            DB::table('fca_user_imports')->where('id', $latestId)->update(['is_active' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fca_user_imports') && Schema::hasColumn('fca_user_imports', 'is_active')) {
            Schema::table('fca_user_imports', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }
};
