# Disponibilidade (Fase 5)

## 1. Uma regra só

`App\Modules\Scheduling\Services\Availability` é a **única** resposta para "este horário pode ser reservado?".

| Método | Uso |
|---|---|
| `check(serviço, profissional, início, canal, [ignorar], [duração])` | Verificar um horário: confirmação do site, gravação (`BookingService`), remarcação, **encaixe** e troca de profissional no atendimento (ambos pelo `BookingService`) |
| `slots(serviço, profissional ou nulo, dia, canal, [ignorar], [duração])` | Listar horários livres: site, conta do cliente, painel |
| `bookableDates(canal)` | Dias que aparecem para escolha (barbearia aberta, dentro do alcance do canal) |

`check()` e `slots()` usam **a mesma função** (`evaluate`) sobre **o mesmo retrato do dia** (`planFor`). O
que aparece como livre é exatamente o que a gravação aceita. O teste
`AvailabilityTest::test_lista_de_horarios_e_verificacao_concordam` percorre a grade inteira do dia e confere
os dois métodos, ponto a ponto. Controllers, Blade e JavaScript não decidem disponibilidade: só mostram o
resultado.

O **encaixe** (cliente chegou sem hora marcada) não tem regra própria: é um agendamento de origem `walk_in`
reservado pelo `BookingService::book`, logo passa por esta mesma regra
([agendamento.md §2](agendamento.md#2-canais), [atendimento.md §2](atendimento.md#2-relação-com-o-agendamento)).

## 2. O que torna um horário livre

Horário candidato = intervalo **[início, início + duração)**, com a duração do serviço (ou a fotografada, na
remarcação). Está livre quando, nesta ordem:

| # | Critério | Motivo quando falha |
|---|---|---|
| 1 | Serviço agendável: ativo e com categoria ativa (ou sem categoria) | `service_unavailable` |
| 2 | Profissional ativo e recebendo agendamentos | `professional_unavailable` |
| 3 | O profissional executa o serviço (`professional_service`) | `professional_not_qualified` |
| 4 | Início em múltiplo de 5 min | `invalid_time` |
| 5 | Início no futuro | `past` |
| 6 | Antecedência mínima (canal cliente) | `too_soon` |
| 7 | Alcance máximo (cliente: 30 dias; equipe: 365) | `too_far` |
| 8 | O intervalo **inteiro** cabe num período de funcionamento da barbearia que também esteja no expediente do profissional (interseção) | `outside_hours` |
| 9 | O profissional não está de folga no dia | `time_off` |
| 10 | Não encosta em pausa, bloqueio (dele ou da barbearia) nem em agendamento que ocupa horário (pendente, aguardando pagamento, confirmado, concluído) | `break`, `blocked`, `conflict` |

Os textos exibidos para cada motivo ficam em `AvailabilityResult::MESSAGES`.

## 3. Conflitos: intervalos meio-abertos

`App\Modules\Scheduling\Support\Interval` é a única regra de sobreposição: `[a, b)` e `[c, d)` se sobrepõem
quando `a < d` e `c < b`. O fim **não** pertence ao intervalo.

| A | B | Resultado |
|---|---|---|
| 10:00–10:30 | 10:30–11:00 | **permite** (adjacente) |
| 10:00–10:30 | 10:29–10:59 | **bloqueia** |
| 10:00–10:30 | 09:45–10:15 (começa antes, termina dentro) | bloqueia |
| 10:00–10:30 | 10:15–10:45 (começa dentro, termina depois) | bloqueia |
| 10:00–10:30 | 10:10–10:20 (dentro) | bloqueia |
| 10:00–10:30 | 09:00–11:00 (envolve) | bloqueia |

Testes: `IntervalTest::test_todos_os_tipos_de_sobreposicao` (cada caso nos dois sentidos) e
`AvailabilityTest::test_conflito_com_outro_agendamento`.

## 4. Duração

- A duração do **serviço** (`services.duration_minutes`, regra `Duration` da Fase 4) é a fonte de verdade para
  agendamentos novos.
- O fim é calculado num lugar só: `Interval::starting(início, minutos)`. Exemplo: Corte de 40 min às 14:00 →
  14:00–14:40.
- Serviço que atravessaria o fechamento **não** é oferecido nem aceito (ex.: 19:45 para um serviço de 30 min com
  fechamento às 20:00).
- Na remarcação, vale a duração **fotografada** no agendamento, não a atual do serviço.

## 5. Grade de horários oferecidos

Os horários oferecidos começam em múltiplos do intervalo configurado (padrão 15 min), contados da meia-noite
local, dentro de cada período de trabalho. O último horário oferecido é o último que ainda termina dentro do
período. A equipe pode pedir qualquer múltiplo de 5 min, mas o que aparece na tela segue a grade.

## 6. "Sem preferência" de profissional

Sem profissional escolhido, `slots()` junta os horários de todos os profissionais que podem fazer o serviço
(`ProfessionalDirectory::bookableFor`), e cada horário diz **quem** está livre nele, na ordem da equipe. Na
reserva, o `BookingService` tenta os profissionais nessa ordem até um aceitar (cada tentativa com a proteção
da seção 7). O cliente **não** é obrigado a escolher profissional.

## 7. Verificação final no servidor e concorrência

Mostrar um horário como livre não reserva nada. A reserva (`BookingService::book`) **revalida** a
disponibilidade dentro de uma transação que bloqueia a agenda do profissional antes. Detalhes e o teste de
concorrência estão em [agendamento.md](agendamento.md#5-concorrência-dupla-reserva).
