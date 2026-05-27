<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\FcaUser;

class CreateNataliaBiarSeeder extends Seeder
{
    public function run(): void
    {
        // ── Sistema IQT (admin, cargo_id = 1) ──────────────────────────────
        $iqtUser = User::updateOrCreate(
            ['email' => 'natalia.biar@desktop.tec.br'],
            [
                'nome'     => 'Natalia Biar',
                'password' => Hash::make('270625Bi@r'),
                'cargo_id' => 1,
                'status'   => 'ativo',
            ]
        );

        $this->command->info("IQT  → id {$iqtUser->id} | {$iqtUser->email} | cargo_id 1 (admin)");

        // ── Sistema FCA / GH (role = admin) ────────────────────────────────
        $fcaUser = FcaUser::updateOrCreate(
            ['email' => 'natalia.biar@desktop.tec.br'],
            [
                'name'    => 'Natalia Biar',
                'usuario' => 'natalia.biar',
                'password' => Hash::make('270625Bi@r'),
                'role'    => 'admin',
            ]
        );

        $this->command->info("FCA  → id {$fcaUser->id} | {$fcaUser->email} | role admin");
    }
}
