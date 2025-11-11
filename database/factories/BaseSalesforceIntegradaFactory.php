<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class BaseSalesforceIntegradaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Vamos usar o Faker para gerar dados realistas
            'caso' => 'CASO-' . $this->faker->unique()->numberBetween(100000, 999999),
            'numero_compromisso' => $this->faker->unique()->ean8(),
            'city' => $this->faker->city(),
            'data_sa_concluida' => $this->faker->dateTimeThisMonth()->format('d/m/Y'),
            'nome_tecnico' => $this->faker->name(),
            'empresa_tecnico' => $this->faker->company() . ' Soluções',
            'tipo_trabalho' => $this->faker->randomElement(['Instalação Fibra', 'Reparo Externo', 'Manutenção Preventiva']),
            'status_caso' => $this->faker->randomElement(['Concluído', 'Em Aberto', 'Pendente de Material']),
            'cto' => 'CTO-' . $this->faker->bothify('??##'), // Ex: CTO-AB12
            'porta' => $this->faker->numberBetween(1, 16),
            'nome_conta' => $this->faker->name(),
            'endereco' => $this->faker->streetAddress(),
            'telefone' => $this->faker->phoneNumber(),
        ];
    }
}