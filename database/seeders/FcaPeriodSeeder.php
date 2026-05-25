<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FcaPeriod;
use App\Models\FcaPeriodTecnico;
use App\Models\FcaUser;

class FcaPeriodSeeder extends Seeder
{
    public function run(): void
    {
        // Desativa períodos anteriores
        FcaPeriod::where('is_active', true)->update(['is_active' => false]);

        $admin = FcaUser::where('usuario', 'admin.fca')->first();

        $period = FcaPeriod::create([
            'mes'         => 'mai/26',
            'uploaded_by' => $admin?->id,
            'expires_at'  => now()->addDays(25),
            'is_active'   => true,
        ]);

        // O nome aqui deve ser IDÊNTICO (case-insensitive) ao fca_users.name do técnico
        // "Tecnico Teste" → UPPER = "TECNICO TESTE" → match com "TECNICO TESTE" abaixo
        $tecnicos = [
            ['nome' => 'TECNICO TESTE',    'prod_bruta' => 2.50, 'revisita' => 0.14, 'tec1' => 0.83, 'certificado' => 'Não'],
            ['nome' => 'JOAO DA SILVA',    'prod_bruta' => 3.10, 'revisita' => 0.08, 'tec1' => 0.90, 'certificado' => 'Sim'],
            ['nome' => 'MARIA SANTOS',     'prod_bruta' => 1.80, 'revisita' => 0.20, 'tec1' => 0.75, 'certificado' => 'Não'],
            ['nome' => 'PEDRO OLIVEIRA',   'prod_bruta' => 2.70, 'revisita' => 0.05, 'tec1' => 0.88, 'certificado' => 'Sim'],
            ['nome' => 'ANA PAULA ROCHA',  'prod_bruta' => 1.50, 'revisita' => 0.12, 'tec1' => 0.70, 'certificado' => 'Não'],
        ];

        foreach ($tecnicos as $t) {
            FcaPeriodTecnico::create(array_merge($t, ['fca_period_id' => $period->id]));
        }

        $this->command->info('✅ Período FCA de teste criado!');
        $this->command->table(
            ['Técnico na base', 'Certificado', 'Obs'],
            [
                ['TECNICO TESTE',   'Não', '← match com GH user "Tecnico Teste"'],
                ['JOAO DA SILVA',   'Sim', ''],
                ['MARIA SANTOS',    'Não', ''],
                ['PEDRO OLIVEIRA',  'Sim', ''],
                ['ANA PAULA ROCHA', 'Não', ''],
            ]
        );
        $this->command->info('Período expira em: ' . now()->addDays(25)->format('d/m/Y'));
    }
}
