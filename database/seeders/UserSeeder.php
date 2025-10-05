<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('users')->delete();

        DB::table('users')->insert([
            // Usuário Administrador da empresa Desktop
            [
                'nome' => 'Admin Desktop',
                'email' => 'admin@desktop.com.br',
                'password' => Hash::make('senha123'),
                'numero' => '19999999999',
                'cpf' => '111.111.111-11',
                'cadastro_completo' => true,
                'status' => 'ativo',
                'empresa_id' => 38, // ID da empresa 'Desktop'
                'cargo_id' => 1,    // ID do cargo 'Administrador'
                'regional_id' => 1,
                'supervisor_id' => null,
            ],
            // Usuário Fiscal da empresa Desktop, supervisionado pelo Admin
            [
                'nome' => 'Fiscal Desktop',
                'email' => 'fiscal@desktop.com.br',
                'password' => Hash::make('senha123'),
                'numero' => '19888888888',
                'cpf' => '222.222.222-22',
                'cadastro_completo' => true,
                'status' => 'ativo',
                'empresa_id' => 38, // ID da empresa 'Desktop'
                'cargo_id' => 3,    // ID do cargo 'Fiscal'
                'regional_id' => 1,
                'supervisor_id' => 1, // Supervisionado pelo usuário de ID 1 (Admin Desktop)
            ],
            // Usuário de uma empresa Terceirizada
            [
                'nome' => 'Funcionario FM Manutenções',
                'email' => 'contato@fmmanutencoes.com.br',
                'password' => Hash::make('senha123'),
                'numero' => '11777777777',
                'cpf' => '333.333.333-33',
                'cadastro_completo' => true,
                'status' => 'ativo',
                'empresa_id' => 1, // ID da empresa 'FM Manutenções'
                'cargo_id' => 2,   // ID do cargo 'Terceirizado'
                'regional_id' => 2,
                'supervisor_id' => 2, // Supervisionado pelo Fiscal da Desktop
            ],
        ]);
    }
}