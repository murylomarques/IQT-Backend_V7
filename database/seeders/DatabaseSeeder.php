<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // A ordem de execução é importante para as chaves estrangeiras
        $this->call([
            EmpresaSeeder::class,
            CargoSeeder::class,
            RegionalSeeder::class, // Mantido do exemplo anterior para a tabela users funcionar
            UserSeeder::class,
            AgendaSeeder::class,    // Seeder de usuários para popular com exemplos
        ]);
    }
}
