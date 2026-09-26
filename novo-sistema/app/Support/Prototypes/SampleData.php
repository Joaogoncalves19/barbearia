<?php

namespace App\Support\Prototypes;

use App\Modules\Shared\Support\Money;
use Illuminate\Support\Carbon;

/**
 * DADOS DE EXEMPLO para as telas de referencia visual (Fase 1).
 *
 * Nada aqui e dado real nem regra de negocio. Nomes, precos e horarios so
 * existem para avaliar layout, hierarquia e densidade. As telas mostram a
 * marca "Exemplo" sempre que um destes valores aparece.
 *
 * Este arquivo e o controller de prototipos serao removidos quando as telas
 * reais existirem (Fases 5, 7 e 11).
 */
class SampleData
{
    public const BRAND = 'Barbearia Exemplo';

    /**
     * @return array<int, array{category: string, items: array<int, array<string, mixed>>}>
     */
    public static function services(): array
    {
        $s = fn (string $id, string $nome, string $desc, int $min, int $centavos) => [
            'id' => $id, 'name' => $nome, 'description' => $desc, 'minutes' => $min,
            'price' => Money::fromCents($centavos), 'price_cents' => $centavos,
        ];

        return [
            ['category' => 'Cabelo', 'items' => [
                $s('corte', 'Corte clássico', 'Tesoura e máquina, lavagem e finalização com pomada.', 45, 5500),
                $s('degrade', 'Degradê', 'Transição na máquina com navalha no acabamento.', 45, 6000),
                $s('tesoura', 'Corte na tesoura', 'Para cabelos médios e longos, com textura.', 60, 7000),
            ]],
            ['category' => 'Barba', 'items' => [
                $s('barba', 'Barba com toalha quente', 'Toalha quente, navalha e óleo pós-barba.', 30, 4500),
                $s('acabamento', 'Acabamento de barba', 'Contorno e alinhamento rápidos.', 15, 2500),
            ]],
            ['category' => 'Combos', 'items' => [
                $s('combo', 'Corte + barba', 'O ritual completo, com toalha quente.', 75, 9500),
            ]],
            ['category' => 'Cuidados', 'items' => [
                $s('sobrancelha', 'Sobrancelha', 'Na navalha ou pinça.', 15, 2000),
                $s('hidratacao', 'Hidratação capilar', 'Máscara e massagem no couro cabeludo.', 20, 3500),
            ]],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function flatServices(): array
    {
        return array_merge(...array_column(self::services(), 'items'));
    }

    /** @return array<int, array{id: string, name: string, role: string}> */
    public static function professionals(): array
    {
        return [
            ['id' => 'rafael', 'name' => 'Rafael Souza', 'role' => 'Degradê e desenhos'],
            ['id' => 'diego', 'name' => 'Diego Martins', 'role' => 'Barba e navalha'],
            ['id' => 'marcos', 'name' => 'Marcos Lima', 'role' => 'Cortes clássicos'],
            ['id' => 'thiago', 'name' => 'Thiago Alves', 'role' => 'Cabelos longos e textura'],
        ];
    }

    /** @return array<int, array{text: string, author: string, service: string}> */
    public static function testimonials(): array
    {
        return [
            ['text' => 'Saí com o corte que eu tinha na cabeça e ainda tomei um café bom esperando.', 'author' => 'Cliente exemplo 1', 'service' => 'Degradê'],
            ['text' => 'A toalha quente na barba virou meu momento da semana.', 'author' => 'Cliente exemplo 2', 'service' => 'Barba com toalha quente'],
            ['text' => 'Agendei pelo celular em um minuto e fui atendido no horário.', 'author' => 'Cliente exemplo 3', 'service' => 'Corte clássico'],
        ];
    }

    /** @return array<int, array{day: string, hours: string, today: bool}> */
    public static function openingHours(): array
    {
        $hoje = (int) now(config('barbearia.display_timezone'))->dayOfWeekIso;
        $dias = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        $horas = [1 => '10h – 20h', 2 => '9h – 20h', 3 => '9h – 20h', 4 => '9h – 20h', 5 => '9h – 21h', 6 => '8h – 18h', 7 => 'Fechado'];

        return array_map(fn ($n) => ['day' => $dias[$n], 'hours' => $horas[$n], 'today' => $n === $hoje], array_keys($dias));
    }

    /**
     * Agenda de exemplo do dia (inicio em minutos desde 00:00).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function agenda(): array
    {
        $e = fn (int $h, int $m, int $dur, string $cliente, string $servico, string $status = 'confirmed') => [
            'start' => $h * 60 + $m, 'minutes' => $dur, 'client' => $cliente, 'service' => $servico, 'status' => $status,
        ];

        return [
            'rafael' => [
                $e(9, 0, 45, 'João P.', 'Degradê', 'done'),
                $e(10, 0, 75, 'Lucas M.', 'Corte + barba', 'done'),
                $e(12, 0, 60, '', 'Almoço', 'break'),
                $e(13, 30, 45, 'André S.', 'Degradê'),
                $e(15, 0, 45, 'Pedro H.', 'Corte clássico', 'pending'),
                $e(16, 30, 30, 'Caio R.', 'Barba com toalha quente'),
            ],
            'diego' => [
                $e(9, 30, 30, 'Bruno T.', 'Barba com toalha quente', 'done'),
                $e(10, 15, 15, 'Felipe A.', 'Acabamento de barba', 'done'),
                $e(11, 0, 75, 'Gustavo L.', 'Corte + barba'),
                $e(13, 0, 60, '', 'Almoço', 'break'),
                $e(14, 30, 45, 'Renato C.', 'Corte clássico'),
                $e(17, 0, 30, 'Samuel V.', 'Barba com toalha quente', 'pending'),
            ],
            'marcos' => [
                $e(9, 0, 45, 'Eduardo F.', 'Corte clássico', 'done'),
                $e(11, 30, 45, 'Vitor N.', 'Degradê'),
                $e(12, 30, 60, '', 'Almoço', 'break'),
                $e(14, 0, 60, 'Daniel K.', 'Corte na tesoura'),
                $e(18, 0, 45, 'Mateus O.', 'Corte clássico'),
            ],
            'thiago' => [
                $e(10, 0, 60, 'Rodrigo B.', 'Corte na tesoura', 'done'),
                $e(11, 15, 20, 'Igor D.', 'Hidratação capilar'),
                $e(13, 30, 60, '', 'Almoço', 'break'),
                $e(15, 0, 75, 'Leonardo G.', 'Corte + barba'),
                $e(16, 30, 45, 'Otávio Q.', 'Degradê', 'pending'),
            ],
        ];
    }

    /**
     * Proximos dias com vaga (exemplo) para o seletor do agendamento.
     *
     * @return array<int, array{value: string, weekday: string, day: string, long: string, disabled: bool}>
     */
    public static function bookingDays(): array
    {
        $semana = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
        $semanaLonga = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
        $dias = [];
        $data = Carbon::now(config('barbearia.display_timezone'))->startOfDay();
        for ($i = 0; $i < 14; $i++) {
            $d = $data->copy()->addDays($i);
            $dias[] = [
                'value' => $d->toDateString(),
                'weekday' => $i === 0 ? 'hoje' : $semana[$d->dayOfWeek],
                'day' => $d->format('d'),
                'long' => ucfirst($semanaLonga[$d->dayOfWeek]).', '.$d->format('d/m'),
                'disabled' => $d->dayOfWeek === 0, // domingo fechado (exemplo)
            ];
        }

        return $dias;
    }

    /** @return array<string, array<int, string>> */
    public static function bookingSlots(): array
    {
        return [
            'Manhã' => ['09:00', '09:45', '10:30', '11:15'],
            'Tarde' => ['13:30', '14:15', '15:00', '16:30', '17:15'],
            'Noite' => ['18:00', '18:45', '19:30'],
        ];
    }
}
