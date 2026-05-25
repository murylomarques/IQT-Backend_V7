<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\FcaUser;

class FcaUsersSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name'        => 'Admin FCA',
                'usuario'     => 'admin.fca',
                'email'       => 'admin@fca.desktop.com.br',
                'password'    => Hash::make('Admin@123'),
                'role'        => 'admin',
                'employee_id' => '0001',
                'territory'   => 'CENTRAL',
                'regional'    => 'SUDESTE',
                'title'       => 'Administrador do Sistema',
                'manager_id'  => null,
            ],
            [
                'name'        => 'Coordenador FCA',
                'usuario'     => 'coord.fca',
                'email'       => 'coordenador@fca.desktop.com.br',
                'password'    => Hash::make('Coord@123'),
                'role'        => 'coordenacao',
                'employee_id' => '0002',
                'territory'   => 'CAMPINAS',
                'regional'    => 'SUDESTE',
                'title'       => 'Coordenador de Campo',
                'manager_id'  => null,
            ],
            [
                'name'        => 'Supervisor FCA',
                'usuario'     => 'super.fca',
                'email'       => 'supervisor@fca.desktop.com.br',
                'password'    => Hash::make('Super@123'),
                'role'        => 'supervisao',
                'employee_id' => '0003',
                'territory'   => 'CAMPINAS',
                'regional'    => 'SUDESTE',
                'title'       => 'Supervisor de Equipe',
                'manager_id'  => null,
            ],
        ];

        foreach ($users as $data) {
            FcaUser::updateOrCreate(
                ['usuario' => $data['usuario']],
                $data
            );
        }

        $this->command->info('✅ Usuários FCA criados com sucesso!');
        $this->command->table(
            ['Usuário', 'Senha', 'Perfil'],
            [
                ['admin.fca',  'Admin@123', 'Administrador'],
                ['coord.fca',  'Coord@123', 'Coordenação'],
                ['super.fca',  'Super@123', 'Supervisão'],
            ]
        );
    }
}
