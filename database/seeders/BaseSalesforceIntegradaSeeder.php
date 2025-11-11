<?php
namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\BaseSalesforceIntegrada; // Não se esqueça de importar o Model

class BaseSalesforceIntegradaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Limpa a tabela antes de popular para evitar dados duplicados a cada execução
        BaseSalesforceIntegrada::truncate();

        // Cria 50 registros falsos usando a Factory que definimos
        BaseSalesforceIntegrada::factory()->count(50)->create();
    }
}