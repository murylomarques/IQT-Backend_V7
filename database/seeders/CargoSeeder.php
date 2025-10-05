<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CargoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Limpa a tabela antes de inserir novos dados
        DB::table('cargos')->delete();

        DB::table('cargos')->insert([
            ['nome' => 'Administrador'], // Corrigido de "Adiministrador"
            ['nome' => 'Terceirizado'],  // Alterado para um termo mais comum
            ['nome' => 'Fiscal'],
        ]);
    }
}