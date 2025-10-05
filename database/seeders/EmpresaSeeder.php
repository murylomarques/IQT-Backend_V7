<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmpresaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Limpa a tabela antes de inserir novos dados para evitar duplicatas
        DB::table('empresas')->delete();

        DB::table('empresas')->insert([
            ['nome' => 'FM Manutenções'],
            ['nome' => '5.5 Soluções'],
            ['nome' => 'Anderson Divalcir Melchior Ltda'],
            ['nome' => 'Carlos Alberto Rangel'],
            ['nome' => 'DP Serviços'],
            ['nome' => 'DW Telecomunicações'],
            ['nome' => 'Gontel'],
            ['nome' => 'Grupo HMS - HM Soluções'],
            ['nome' => 'HRS Telecom'],
            ['nome' => 'Jeferson Danilo Telecom'],
            ['nome' => 'JGV Instalações'],
            ['nome' => 'KNM Telecom Ltda'],
            ['nome' => 'L Viana'],
            ['nome' => 'LAS Telecom'],
            ['nome' => 'LE Telecom'],
            ['nome' => 'Max.Play Serviços'],
            ['nome' => 'MHZ Telecom'],
            ['nome' => 'MM Opressi'],
            ['nome' => 'Mrs Telecom'],
            ['nome' => 'Otimização Serviços'],
            ['nome' => 'Prosyscom'],
            ['nome' => 'R&L Telefonia'],
            ['nome' => 'RP Telecom'],
            ['nome' => 'Saymon Souza Sales'],
            ['nome' => 'Silvatel'],
            ['nome' => 'Smart Field Serviços Tecnológicos Ltda'],
            ['nome' => 'Souza Rangel Telecom'],
            ['nome' => 'Start'],
            ['nome' => 'Svoboda Telecom - Azul Telecom'],
            ['nome' => 'Thais Rizzieri de Souza'],
            ['nome' => 'THL Telecom'],
            ['nome' => 'TKL e Russo Telecom'],
            ['nome' => 'TSP'],
            ['nome' => 'TYM Telecom Ltda'],
            ['nome' => 'Vortex'],
            ['nome' => 'Start Pro'],
            ['nome' => 'Sou Mais'],
            ['nome' => 'Desktop'], // Empresa principal
        ]);
    }
}