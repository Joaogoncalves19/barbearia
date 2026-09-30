# Relatório da Fase 5 — Motor de agenda e agendamento

> **Status: concluída em 2026-09-30, aguardando aprovação explícita do dono para iniciar a Fase 6.**
> Branch `claude/fase-5-agenda`, criada a partir de `claude/fase-4-catalogo-equipe` (as Fases 3 e 4 ainda não
> estão na `main`). Só dados fictícios; nenhum banco de produção acessado; nenhuma migração real executada;
> sistema antigo não alterado; nenhum segredo no repositório.
>
> Legenda: **PASSOU** · **NÃO EXECUTADO** · **PENDENTE** · **FALHOU**.

## 1. Critérios de aceite

| Critério | Situação | Evidência |
|---|---|---|
| Horário de funcionamento (vários períodos por dia) | PASSOU | `business_hours`, tela "Funcionamento e regras", `StaffAgendaTest` |
| Expediente e pausas por profissional | PASSOU | tela de expediente, `AvailabilityTest` (pausa, fora do expediente) |
| Folgas e bloqueios (do profissional ou da barbearia inteira) | PASSOU | `StaffAgendaTest`, `AvailabilityTest` |
| **Uma** regra de disponibilidade | PASSOU | `Availability::evaluate`, usada por `check` e por `slots`; `test_lista_de_horarios_e_verificacao_concordam` |
| **Uma** regra de criação, remarcação e cancelamento | PASSOU | `BookingService`; nenhum controller grava agendamento direto |
| Nenhuma dupla reserva | PASSOU | `BookingConcurrencyTest`: 4 processos PHP reais disputando o mesmo horário, só 1 grava; contraprova com a proteção desligada falha |
| Sobreposição correta (adjacente permite, 1 min bloqueia) | PASSOU | `IntervalTest`, `AvailabilityTest` |
| Duração respeitada (o intervalo inteiro cabe) | PASSOU | `AvailabilityTest` (fim do expediente, antes da pausa) |
| Preço, nome e duração congelados | PASSOU | `test_mudar_o_catalogo_depois_nao_altera_o_agendamento` |
| Fuso horário (UTC no banco, `America/Sao_Paulo` na tela) | PASSOU | `BusinessTime`, `TimezoneTest` (5) |
| Regras configuráveis (antecedência, alcance, grade, prazos, confirmação manual) | PASSOU | `BookingPolicy`, tela "Funcionamento e regras", auditoria `agenda.policy_changed` |
| Agendamento pelo site (login só na confirmação) | PASSOU | `CustomerBookingFlowTest` (11), E2E |
| Cliente vê, remarca e cancela só os próprios, dentro do prazo | PASSOU | `HorizontalAccessTest`, `CustomerBookingFlowTest`, `BookingServiceTest` (prazos e limite) |
| Agenda da equipe (dia por profissional) | PASSOU | `StaffAgendaTest` (17), E2E |
| Profissional vê e mexe só na própria agenda | PASSOU | `test_profissional_ve_so_a_propria_agenda_mesmo_manipulando_o_filtro`, E2E |
| Permissões no servidor / IDOR | PASSOU | 5 habilidades novas, policy por registro (alheio = 404), `RouteAuthorizationTest` |
| Auditoria e histórico do agendamento | PASSOU | `appointment_events` + `Auditable` (antes/depois) |
| Folga e bloqueio não apagam nem movem agendamentos | PASSOU | `test_folga_e_bloqueio_nao_mexem_...` |
| Design System / celular / acessibilidade / sem rolagem lateral | PASSOU | axe sem violação séria/crítica nas telas novas, celular e desktop |
| Testes passando / PHPStan sem erros | PASSOU | §7 |
| CI verde | PASSOU | run nº 24, os dois jobs (§9) |
| E-mail de confirmação e lembretes | NÃO EXECUTADO | Fase 10 (Comunicação), depende do provedor de e-mail (D-05) |
| Teste com 5 pessoas (< 90 s para agendar) | NÃO EXECUTADO | exige pessoas reais e ambiente de homologação (D-01) |
| Teste de carga | NÃO EXECUTADO | sem ambiente de homologação (D-01); a concorrência foi provada com processos reais |

## 2. O que foi implementado

**Modelo** (migration `2026_10_01_000100_create_agenda_tables`, sem editar as anteriores):

- `business_hours` (nova): funcionamento por dia da semana, vários períodos por dia.
- `blocked_slots.professional_id` anulável (nulo = barbearia inteira), `created_by_user_id` e índice.
- `time_off.created_by_user_id`.
- `professionals.schedule_version`: a linha que serializa gravações na agenda de um profissional.
- `appointments.customer_reschedules` e `created_by_user_id`.
- SQLite em WAL, `busy_timeout` e transações `IMMEDIATE` (`config/database.php`, `.env.example`).

**Domínio** (`app/Modules/Scheduling`):

- `Support/BusinessTime`: fuso; a única conversão entre UTC e hora de parede.
- `Support/Interval`: intervalo meio-aberto; o fim é calculado num lugar só.
- `Support/BookingPolicy`: regras configuráveis (settings `agenda.policy`), com auditoria.
- `Services/Availability`: a regra de disponibilidade (`check`, `slots`, `bookableDates`).
- `Services/BookingService`: criar, remarcar, cancelar, confirmar, marcar falta, observações.
- `Services/ScheduleAdmin`: funcionamento, expediente, pausas, folgas e bloqueios.
- `Policies/AppointmentPolicy`: `view`, `createFor`, `update`, `reschedule`, `cancel`.

**Telas:**

- Site: `/agendar` (serviço), profissional ou "sem preferência", dia e horário.
- Conta do cliente: confirmação (com login), "Agendar horário", detalhe, remarcar, cancelar.
- Painel: agenda do dia, novo agendamento, detalhe com histórico, remarcar, cancelar (modal com motivo),
  confirmar, falta, observações; "Funcionamento e regras", expediente e pausas, folgas, bloqueios.

## 3. Estratégias

**Uma regra por conceito:**

| Conceito | Onde vive |
|---|---|
| "Este horário está livre?" e "quais horários estão livres?" | `Availability::evaluate` |
| Criar, remarcar, cancelar, mudar status | `BookingService` |
| Fim do atendimento e sobreposição | `Interval` |
| Fuso e hora de parede | `BusinessTime` |
| Prazos e limites | `BookingPolicy` |

**Dupla reserva:** a primeira escrita da transação é `UPDATE professionals SET schedule_version = schedule_version + 1`
na agenda envolvida. Isso serializa quem grava na mesma agenda (no SQLite, a transação `IMMEDIATE` já trava a
escrita; no MySQL, é um bloqueio de linha). Depois do bloqueio, a disponibilidade é **verificada de novo**,
dentro da transação. Na remarcação entre profissionais, as duas agendas são travadas em ordem crescente de id,
para não haver impasse. Ver [agendamento.md §5](agendamento.md#5-concorrência-dupla-reserva).

**Almoço (pendência da Fase 4):** uma forma só. O expediente tem um intervalo por dia; pausas ficam em
`schedule_breaks` ([horarios.md](horarios.md)).

## 4. Decisões

| # | Decisão | Motivo |
|---|---|---|
| D-24 | **Opção A** (decisão do dono): "Corte + Barba" é um serviço comum | Uma definição só de serviço, preço e duração |
| D-06 | Confirmação automática, com opção configurável de exigir confirmação da equipe para agendamentos do site | Mantém o comportamento atual e dá a escolha ao dono |
| D-13 | Cliente cancela/remarca até 2 h antes, no máximo 2 remarcações; depois, só pela barbearia. Valores configuráveis | Valores recomendados; o dono ajusta em "Funcionamento e regras" |
| T5-01 | Padrões: antecedência mínima 2 h, alcance 30 dias (equipe 365), grade de 15 min | Configuráveis; a equipe pode agendar mais longe |
| T5-02 | Remarcar mantém serviço, preço e duração; trocar o serviço = cancelar e criar outro | Preço e duração seriam outros |
| T5-03 | Agenda da equipe em visão de dia (sem semana) | Barbearia de uma unidade; pode vir depois sobre a mesma consulta |
| T5-04 | Consultar a agenda (`agenda.view`) é separado de configurá-la (`schedule.*`) | Menor privilégio |
| T5-05 | Cliente em agendamento alheio recebe 404, não 403 | Não confirma que o código existe |
| T5-06 | "Concluir atendimento" fica com o caixa (Fase 6) | Concluir envolve pagamento |

## 5. Permissões

Novas: `agenda.view`, `schedule.settings`, `schedule.working_hours`, `schedule.time_off`, `schedule.blocks`.
Reutilizadas da Fase 3: `appointments.view_all`, `view_own`, `manage`, `manage_own`, `cancel`. Matriz em
[papeis-permissoes.md](papeis-permissoes.md).

| Papel | Agenda |
|---|---|
| Proprietário, gerente | tudo, inclusive configurar |
| Recepção | todas as agendas; folgas e bloqueios; não mexe no funcionamento, nas regras nem no expediente |
| Profissional | só a própria agenda (o filtro na URL é ignorado); cria e remarca só nela; não configura |
| Financeiro | nenhuma |
| Cliente | só os próprios agendamentos futuros, dentro dos prazos |

## 6. Auditoria

- `appointment_events`: linha do tempo do agendamento (criado, confirmado, remarcado de → para, cancelado com
  motivo, falta), com canal e autor.
- `Auditable` em agendamento, expediente, pausas, folgas e bloqueios, com antes/depois.
- `agenda.policy_changed` ao alterar as regras.

## 7. Testes

| Verificação | Resultado |
|---|---|
| PHPUnit | **PASSOU** — 382 testes (eram 310), 0 falhas, 0 pulados |
| — novos na Fase 5 | `Unit/IntervalTest` (4), `Scheduling/AvailabilityTest` (14), `BookingServiceTest` (20), `BookingConcurrencyTest` (1, 4 processos reais), `TimezoneTest` (5), `CustomerBookingFlowTest` (11), `StaffAgendaTest` (17) |
| — ajustados | `PermissionMatrixTest` (matriz nova); `RouteAuthorizationTest` (rotas públicas de agendamento e limite `booking`); `HorizontalAccessTest` (o cliente passou a remarcar e cancelar os próprios; alheio continua bloqueado). Nenhum teste desativado |
| Larastan nível 6 | **PASSOU** — 0 erros |
| Pint | **PASSOU** |
| Build (Vite) | **PASSOU** |
| Playwright + axe | **PASSOU** — 79 passando, 3 ignorados de propósito (os mesmos da Fase 4), em duas execuções seguidas |
| — novos na Fase 5 | `agenda.spec.js`: cliente agenda pelo site, vê, remarca e cancela; equipe cria, remarca e cancela; profissional vê só a própria agenda e não configura. Axe e rolagem lateral nas telas novas |

## 8. Problemas encontrados

1. **`createFor` caía na policy errada.** O Gate escolhe a policy pelo primeiro argumento (o profissional).
   Corrigido para `[Appointment::class, $profissional]`.
2. **Select de serviço com o valor errado** no novo agendamento da equipe: a lista era renumerada e o
   `value` virava a posição, o que agendaria **outro serviço**. Pego pelo E2E; corrigido e coberto por teste de
   regressão.
3. **Cliente em agendamento alheio recebia 403.** Passou a receber 404.
4. **Data inválida na URL gerava erro 500.** A validação passou a ser feita antes da conversão.
5. **Teste de concorrência:** a versão de agenda esperada é 1, porque as transações perdedoras são desfeitas.
   A contraprova (proteção desligada) falha como esperado, então o teste prova de fato a proteção.
6. **Estabilidade dos testes de navegador:** nomes repetidos entre projetos em paralelo e modal aberto antes do
   fim do carregamento. Corrigidos na causa; nenhum teste ignorado para passar.

## 9. CI

**PASSOU.** Run nº 24 (commit `9b629bb`,
https://github.com/Joaogoncalves19/barbearia/actions/runs/36786155213), PHP 8.4, os dois jobs verdes em todos
os passos:

- **Novo sistema (Laravel):** dependências, Pint, Larastan nível 6, auditoria de dependências, build, testes
  PHP (inclusive o de concorrência com processos reais), importador com banco fictício e Playwright + axe.
- **Sistema atual:** regressão de segurança S-01 a S-04.

O commit seguinte só atualiza este relatório (documentação).

## 10. Pendências

1. **E-mail de confirmação e lembretes:** Fase 10 (depende de D-05). A tabela `appointment_reminders` já
   existe.
2. **Concluir atendimento e pagamento:** Fase 6 (caixa).
3. **Teste com 5 pessoas e teste de carga:** dependem de ambiente de homologação (D-01).
4. **Visão de semana** e **lista de espera (D-11):** não pedidas nesta fase.
5. **Assinaturas:** Fase 9.
6. **Hospedagem (D-01)** e **provedor de e-mail (D-05):** sem mudança.

## 11. Riscos

| Risco | Mitigação |
|---|---|
| Servidor com fuso diferente do da barbearia | Conversão só no `BusinessTime`; `TimezoneTest` cobre virada de dia |
| Banco em produção diferente do SQLite (MySQL) | A proteção usa um `UPDATE` na linha do profissional, que funciona nos dois; repetir o teste de concorrência no banco escolhido (D-01) |
| Regras padrão (2 h, 2 remarcações) diferentes do desejado | Configuráveis pelo dono, com auditoria |
| Cliente sem aviso por e-mail até a Fase 10 | A confirmação aparece na tela e na conta do cliente |

## 12. Recomendações para a Fase 6 (caixa)

1. **Concluir atendimento pelo `BookingService`** (nova transição `completed`), sem gravar status fora dele.
2. **Cobrar pelo snapshot** (`appointment_items`), nunca pelo preço atual do catálogo.
3. **Descontos como `appointment_adjustments`**, recalculando os totais pelo `AppointmentPricing`.
4. **Produtos e estoque** com habilidade própria.
5. **Manter o dinheiro em centavos** (`Money`), sem float.

---

**Aguardando aprovação explícita para iniciar a Fase 6.** Silêncio não é autorização.
