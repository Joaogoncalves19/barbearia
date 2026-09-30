# Agenda (Fase 5)

Visão geral de como a agenda funciona. Detalhes em [horarios.md](horarios.md) (funcionamento, expediente,
folgas, bloqueios, fuso), [disponibilidade.md](disponibilidade.md) (a regra),
[agendamento.md](agendamento.md) (criação, snapshot, status, concorrência),
[regras-cancelamento.md](regras-cancelamento.md) e [regras-reagendamento.md](regras-reagendamento.md).

## 1. Arquitetura

```text
Horários (configuração)                 Regra                         Gravação
business_hours ─┐                  ┌─────────────────────┐     ┌──────────────────────┐
working_hours ──┤                  │ Availability        │     │ BookingService       │
schedule_breaks ┼─ retrato do dia ─▶ check() / slots()   ◀─────│ book / reschedule /  │
time_off ───────┤   (planFor)      │ (mesma evaluate)    │     │ cancel / confirm /   │
blocked_slots ──┤                  └─────────────────────┘     │ markNoShow           │
appointments ───┘                   BookingPolicy (regras)     └──────────────────────┘
                                    BusinessTime (fuso)          transação + bloqueio
                                    Interval (sobreposição/fim)  da agenda do profissional

Interfaces: site (/agendar), conta do cliente (/minha-conta), painel (/painel/agenda...).
Todas só leem a intenção, validam a entrada e chamam os serviços acima.
```

| Peça | Arquivo |
|---|---|
| Regra de disponibilidade | `app/Modules/Scheduling/Services/Availability.php` |
| Criar/remarcar/cancelar/status | `app/Modules/Scheduling/Services/BookingService.php` |
| Configuração de horários | `app/Modules/Scheduling/Services/ScheduleAdmin.php` |
| Fuso, sobreposição/fim, regras, canal | `app/Modules/Scheduling/Support/{BusinessTime,Interval,BookingPolicy,Channel}.php` |
| Autorização por registro | `app/Modules/Scheduling/Policies/AppointmentPolicy.php` |
| Telas | `app/Http/Controllers/{Site/BookingController, Account/*, Panel/Agenda/*}` |

## 2. Telas da equipe

| Tela | URL | Permissão |
|---|---|---|
| Agenda do dia (por profissional: hora, fim, cliente, serviço, duração, status, bloqueios, folga) | `/painel/agenda?data=&profissional=` | `agenda.view` (todos com `appointments.view_all`; profissional só a própria, mesmo com filtro na URL) |
| Novo agendamento (serviço → profissional → dia → horário livre → cliente) | `/painel/agenda/novo` | `appointments.manage` (qualquer agenda) ou `manage_own` (a própria) |
| Detalhe (valores, ações, histórico) | `/painel/agendamentos/{código}` | `view` da policy |
| Confirmar, falta, observações | ações no detalhe | `update` da policy |
| Remarcar | `/painel/agendamentos/{código}/remarcar` | `reschedule` da policy |
| Cancelar (modal com motivo) | ação no detalhe | `cancel` da policy |
| Funcionamento e regras | `/painel/agenda/configuracoes` | `schedule.settings` |
| Expediente e pausas | `/painel/profissionais/{id}/expediente` | `schedule.working_hours` |
| Folgas | `/painel/folgas` | `schedule.time_off` |
| Bloqueios | `/painel/bloqueios` | `schedule.blocks` |

Decisão de interface: a agenda da equipe é uma **visão de dia**, com um cartão por profissional (lista no
celular, colunas no desktop) e navegação por dia e filtro de profissional. A "semana" não entrou: para uma
barbearia de uma unidade, o dia e a navegação dia a dia atendem à leitura rápida pedida. Pode ser acrescentada
depois sobre a mesma consulta.

## 3. Telas do cliente

`/agendar` (serviço), `/agendar/{serviço}` (profissional ou sem preferência),
`/agendar/{serviço}/horarios` (dia e horário), `/minha-conta/agendar/confirmar` (login + confirmação),
`/minha-conta` (meus horários), `/minha-conta/agendamentos/{código}` (ver, remarcar, cancelar).

## 4. Permissões (Fase 5)

Novas: `agenda.view` (consultar), `schedule.settings`, `schedule.working_hours`, `schedule.time_off`,
`schedule.blocks` (configurar). Reutilizadas da Fase 3, com o significado de agenda: `appointments.view_all`,
`view_own`, `manage`, `manage_own` e `cancel`. Consultar a agenda **não** dá direito a configurá-la. Matriz
completa em [papeis-permissoes.md](papeis-permissoes.md).

## 5. Fora desta fase

- Concluir atendimento (vai para o caixa, Fase 6).
- Lembretes e e-mails de confirmação (Comunicação, Fase 10; depende do provedor de e-mail D-05).
- Visão de semana.
- Lista de espera (D-11).
- Assinaturas (Fase 9).
