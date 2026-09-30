# Horários (Fase 5)

Tudo o que define **quando** a barbearia e cada profissional atendem. A regra que usa esses dados está em
[disponibilidade.md](disponibilidade.md).

## 1. Fuso horário e datas

| Camada | Política |
|---|---|
| Banco | Instantes em **UTC** (`appointments.starts_at/ends_at`, `blocked_slots.starts_at/ends_at`, `*_at`) |
| PHP / Laravel | `config('app.timezone') = UTC`. Nunca depende de `date_default_timezone_get()` nem do fuso da máquina |
| Barbearia | `BARBEARIA_TIMEZONE` (padrão `America/Sao_Paulo`). Horas de parede (funcionamento, expediente, pausas) e datas civis (folgas, "o dia 15") são **desse fuso** |
| Navegador | Envia só data (`AAAA-MM-DD`) e hora (`HH:MM`) **da barbearia**. Nenhum cálculo de horário acontece em JavaScript |
| E-mails e telas | Exibem na hora da barbearia, pelo mesmo conversor |
| Testes | `TimezoneTest`: UTC no banco, fuso da máquina trocado (Tóquio) sem efeito, virada de dia pelo calendário da barbearia, horário de verão (Nova York) sem deslocar a grade, datas inválidas recusadas |

A conversão acontece **só** em `App\Modules\Scheduling\Support\BusinessTime`: parede → UTC na entrada
(`BusinessTime::at(data, hora)`) e UTC → parede na saída (`BusinessTime::local` / `formatLocal`). Nenhum outro
lugar converte horário de agenda, o que evita converter duas vezes. O Brasil não tem horário de verão desde
2019, mas o código funciona em fusos com horário de verão (testado).

## 2. Horário de funcionamento da barbearia

Tabela `business_hours`: dia da semana (0 = domingo … 6 = sábado), abertura e fechamento.

- **Mais de um período no mesmo dia** é permitido (ex.: 09:00–12:00 e 13:00–19:00, quando fecha para almoço).
  A tela oferece até dois períodos por dia; os períodos de um dia não podem se sobrepor.
- **Dia sem nenhum período = fechado.**
- Tela: `/painel/agenda/configuracoes` (permissão `schedule.settings`). Salvar substitui a semana inteira numa
  transação e registra na auditoria (`agenda.business_hours_changed`, com o resumo da semana).

## 3. Expediente do profissional

Tabela `working_hours`: **um** intervalo por dia da semana.

- Profissional **sem nenhum** expediente cadastrado **segue o horário da barbearia** (o caso comum numa
  barbearia pequena).
- Com expediente próprio: dia sem intervalo = não trabalha (ex.: folga fixa no domingo).
- A disponibilidade real é a **interseção** entre o funcionamento da barbearia e o expediente dele. Exemplo:
  barbearia 09:00–20:00, profissional A 09:00–18:00, B 12:00–22:00 → A atende 09:00–18:00 e B 12:00–20:00.
- Tela: `/painel/profissionais/{id}/expediente` (permissão `schedule.working_hours`).

### Pausas

Tabela `schedule_breaks`: pausa recorrente (ex.: almoço 12:00–13:00), num dia ou em todos (`weekday` nulo),
ativa ou não.

**Decisão (pendência da Fase 4):** o almoço tem **uma forma só** de ser representado. O expediente tem um
intervalo por dia; pausas ficam em `schedule_breaks`, que é o que o importador já usava. Nunca como dois
intervalos de expediente.

## 4. Folgas

Tabela `time_off`: dias **inteiros** (datas civis, inclusivas) em que o profissional não atende. Tipos: folga,
férias, atestado, treinamento, outro. Guarda quem lançou.

- **Folga recorrente** (todo domingo) = dia sem expediente, não uma folga.
- **Ausência de parte do dia** = bloqueio (seção 5).
- Criar folga **não mexe em agendamento existente**: a tela avisa quantos agendamentos ficam dentro do período,
  para a equipe remarcar ou cancelar.
- Folga que já terminou não pode ser removida (histórico).
- Tela: `/painel/folgas` (permissão `schedule.time_off`).

## 5. Bloqueios

Tabela `blocked_slots`: intervalo (instantes UTC) que ninguém pode reservar, com motivo e quem criou.

- **Com profissional**: só a agenda dele (reunião, consulta médica, treinamento de meio período).
- **Sem profissional**: a **barbearia inteira** (feriado, evento, manutenção). Um feriado é um bloqueio de dia
  inteiro da barbearia.
- Não apaga nem move agendamentos: a tela avisa quantos há no período.
- Bloqueio que já terminou não pode ser removido (histórico).
- Tela: `/painel/bloqueios` (permissão `schedule.blocks`).

## 6. Regras configuráveis da agenda

`BookingPolicy` (tabela `settings`, chave `agenda.policy`), editada na mesma tela do funcionamento. Tipos e
limites são validados; não há número mágico em controller.

| Regra | Padrão | Vale para |
|---|---|---|
| Antecedência mínima para agendar | 120 min (como o sistema antigo) | Cliente |
| Até quantos dias à frente | 30 dias (como o sistema antigo) | Cliente |
| Até quantos dias à frente (equipe) | 365 dias | Equipe |
| Intervalo entre os horários oferecidos | 15 min (múltiplo de 5) | Todos |
| Cliente cancela até | 120 min antes (D-13) | Cliente |
| Cliente remarca até | 120 min antes (D-13) | Cliente |
| Máximo de remarcações pelo cliente | 2 por agendamento (D-13) | Cliente |
| Agendamento do site precisa de confirmação | não (D-06: confirmação automática, como hoje) | Cliente |

Toda alteração vai para a auditoria (`agenda.policy_changed`, com o antes e o depois).
