# Regras de cancelamento (Fase 5)

Uma regra só: `BookingService::cancel(agendamento, canal, quem, motivo)`.

## 1. Quem pode

| Quem | Pode cancelar | Autorização |
|---|---|---|
| Cliente | Os **próprios** agendamentos que ainda não começaram | `AppointmentPolicy@cancel` (alheio = 404) |
| Recepção, gerente, proprietário | Qualquer agendamento | `appointments.cancel` |
| Profissional | Os da **própria** agenda | `appointments.manage_own` + agenda própria |
| Financeiro | Nenhum | — |

## 2. Quando

| Regra | Cliente | Equipe |
|---|---|---|
| Estado | Pendente, aguardando pagamento ou confirmado | Idem |
| Antecedência | Até `customer_cancel_notice_minutes` antes do início (padrão 120 min, D-13). Depois disso: "fale com a barbearia" | Sem prazo |
| Já concluído, cancelado ou falta | Não | Não (estados finais, ou falta → só concluído) |

## 3. O que acontece

- O registro **não é apagado**: `status = cancelado`, `cancelled_at`, `cancelled_by` (cliente/barbearia) e
  `cancellation_reason` (até 64 caracteres).
- Valores, itens e histórico continuam como estavam.
- O horário fica livre na hora (cancelado não ocupa agenda).
- Evento `cancelled` no histórico do agendamento (quem, motivo) e alteração na auditoria.
- Na tela, cancelar sempre pede **confirmação** em modal. Na equipe, o modal pede também o motivo.

## 4. Testes

`BookingServiceTest`:

- `test_cancelar_preserva_o_registro_e_libera_o_horario`;
- `test_prazo_de_cancelamento_do_cliente` (o cliente fora do prazo é recusado; a equipe ainda cancela);
- `test_cancelado_nao_cancela_de_novo`;
- `test_transicoes_de_status` (falta não volta para cancelado).

Outros arquivos:

- `CustomerBookingFlowTest::test_prazo_vencido_mostra_a_regra_e_nao_altera`;
- `test_nao_mexe_no_agendamento_de_outro_cliente`;
- `StaffAgendaTest::test_profissional_ve_so_a_propria_agenda_mesmo_manipulando_o_filtro`;
- E2E (cliente e equipe, com modal).
