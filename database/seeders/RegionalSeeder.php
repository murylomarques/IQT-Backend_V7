<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RegionalSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('regionais')->delete();
        DB::table('regionais')->insert([
            ['nome' => 'SUDESTE', 'uf' => 'SP'],
            ['nome' => 'CENTRAL', 'uf' => 'SP'],
            ['nome' => 'CENTRO OESTE', 'uf' => 'SP'],
        ]);
    }
}