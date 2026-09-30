# Regras de reagendamento (Fase 5)

Uma regra só: `BookingService::reschedule(agendamento, novo início, novo profissional ou nulo, canal, quem)`.

## 1. Passos (sempre nesta ordem, numa transação)

1. **Autorização** (antes de chegar ao serviço): `AppointmentPolicy@reschedule`. Cliente: só os próprios
   (alheio = 404) e ainda não começados. Equipe: `appointments.manage`, ou `appointments.manage_own` na própria
   agenda. Para outro profissional, também `createFor` no destino (o profissional só move para a própria
   agenda).
2. **Bloqueio** das agendas envolvidas (a atual e a de destino), em ordem crescente de id.
3. **Estado**: só pendente, aguardando pagamento ou confirmado.
4. **Prazo e limite (cliente)**: até `customer_reschedule_notice_minutes` antes do horário atual (padrão
   120 min) e no máximo `customer_max_reschedules` remarcações pelo cliente (padrão 2). A equipe não tem esses
   limites.
5. **Nova data**: tem de ser diferente da atual (`no_change`).
6. **Disponibilidade**: `Availability::check` com o **mesmo serviço** e a **duração fotografada**, ignorando o
   próprio agendamento. Pode, por exemplo, adiantar 15 min sobre o próprio horário.
7. **Conflito do cliente**: o cliente não fica em dois lugares ao mesmo tempo.
8. **Gravação**: novo `starts_at`/`ends_at` (mesmo cálculo de fim), `professional_id` e `professional_name` do
   destino, e `customer_reschedules + 1` quando foi o cliente.

## 2. O que NÃO muda

Serviço, preço, totais e duração continuam os do momento do agendamento. Remarcar não é um agendamento novo:
é o **mesmo** registro, com o mesmo código.

## 3. Histórico

Nada é sobrescrito em silêncio:

- evento `rescheduled` com **de** (data, hora, profissional) → **para**, canal e autor;
- o `Auditable` guarda o antes e o depois de `starts_at`, `ends_at`, `professional_id` e `professional_name`.

**Alterar só o profissional** (mesmo horário) é uma remarcação para outro profissional. Trocar o **serviço**
de um agendamento não é permitido: cancela-se e cria-se outro (preço e duração seriam outros).

## 4. Testes

`BookingServiceTest`:

- `test_remarcar_move_horario_mantem_preco_e_registra_historico`;
- `test_remarcar_para_outro_profissional`;
- `test_remarcar_valida_disponibilidade_ignorando_o_proprio`;
- `test_limites_do_cliente_na_remarcacao`;
- `test_prazo_de_remarcacao_do_cliente`;
- `test_cancelado_nao_pode_ser_remarcado`.

Outros arquivos:

- `CustomerBookingFlowTest::test_cancela_e_remarca_o_proprio_pela_conta`;
- `StaffAgendaTest::test_recepcao_remarca_cancela_e_registra_falta`;
- E2E (cliente e equipe).
