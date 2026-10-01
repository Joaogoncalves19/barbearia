# Agendamento (Fase 5)

## 1. Uma regra de criação

`App\Modules\Scheduling\Services\BookingService` é a **única** forma de criar, remarcar, cancelar, confirmar e
marcar falta. Site, conta do cliente e painel montam um `BookingRequest` e chamam o serviço; nenhum grava
agendamento por conta própria. O sistema antigo tinha 3 formas de agendar e 4 de remarcar; o novo tem uma.

```text
Tela (site / conta / painel)
  └─ lê a intenção e valida a entrada
       └─ BookingService::book(BookingRequest)
            ├─ transação: bloqueia a agenda do profissional
            ├─ Availability::check()      (a mesma regra da lista de horários)
            ├─ grava o agendamento + item com o preço/duração do momento
            ├─ totais (AppointmentPricing)
            └─ evento "created" no histórico
```

## 2. Canais

| Canal | Quem | Regras de tempo |
|---|---|---|
| `Channel::Customer` | Cliente pelo site/conta | Antecedência mínima, alcance máximo, prazos de cancelamento/remarcação, limite de remarcações ([horarios.md §6](horarios.md#6-regras-configuráveis-da-agenda)) |
| `Channel::Staff` | Equipe pelo painel | Só não pode no passado e até 365 dias; pode encaixar em cima da hora |

A disponibilidade em si (expediente, folga, bloqueio, conflito) é a **mesma** para os dois.

### Origem (`appointments.source`)

O canal decide as regras de tempo; a **origem** diz de onde veio o agendamento. Todas ocupam a agenda do mesmo
jeito e passam pelo mesmo `BookingService::book`.

| Origem | Rótulo | Canal | De onde |
|---|---|---|---|
| `online` | Site | `Customer` | Cliente pelo site/conta |
| `staff` | Equipe | `Staff` | Equipe pela agenda |
| `walk_in` | Encaixe | `Staff` | Cliente chegou sem hora marcada: tela de encaixe do atendimento. Começa no próximo ponto da grade de 5 min, dura o tempo do serviço, e o atendimento nasce dele na mesma transação ([atendimento.md §2](atendimento.md#2-relação-com-o-agendamento)) |
| `legacy` | Sistema antigo | — | Importador |

**Com atendimento em vigor** (o cliente chegou), o agendamento não é cancelado, remarcado nem marcado como
falta por fora do atendimento (`in_attendance`): o horário continua ocupado enquanto o cliente está lá. A
troca de profissional e o cancelamento do encaixe passam pelo atendimento, que remarca/cancela pelo
`BookingService`.

## 3. Snapshot: o que é congelado e o que é referência

| Dado | Onde | Tipo | Por quê |
|---|---|---|---|
| Preço cobrado | `appointment_items.unit_price_cents`, `total_cents` (+ `price_source = catalog_at_booking`) | **Snapshot** | Mudar o preço do serviço não muda o agendamento ([precos.md](precos.md)) |
| Totais | `appointments.subtotal_cents`, `total_cents` | **Snapshot** | Relatórios usam o que foi registrado |
| Nome do serviço | `appointment_items.name` | **Snapshot** | O serviço pode ser renomeado |
| Duração | `appointment_items.duration_minutes` e o próprio intervalo `starts_at`–`ends_at` | **Snapshot** | Mudar a duração do serviço não encolhe nem estica o horário |
| Nome do profissional | `appointments.professional_name` | **Snapshot** | Renomear/desligar não reescreve o passado |
| Nome, e-mail e telefone do cliente | `appointments.customer_*` | **Snapshot** | Contato do momento; cliente sem cadastro (balcão) só tem isso |
| Serviço | `appointment_items.service_id` | **Referência** | Relatórios por serviço; o serviço nunca é apagado se foi usado |
| Profissional | `appointments.professional_id` | **Referência** | Agenda, comissão (Fase 6); nunca apagado (FK `restrict`) |
| Cliente | `appointments.customer_id` | **Referência** (anulável) | Área do cliente; balcão pode não ter cadastro |

Não se copia o resto (descrição do serviço, foto, bio, categoria): não é necessário para o histórico.

## 4. Status

Definidos uma vez em `AppointmentStatus` (desde a Fase 2), com transições permitidas no próprio enum e
garantidas no model (transição proibida = exceção):

```text
PENDENTE ─┬─> CONFIRMADO ─┬─> CONCLUÍDO
          │               ├─> NÃO COMPARECEU ──> CONCLUÍDO (correção da equipe)
          │               └─> CANCELADO
          ├─> AGUARDANDO PAGAMENTO ─┬─> CONFIRMADO   (assinaturas, Fase 9)
          │                         └─> CANCELADO
          └─> CANCELADO
```

| Status | Ocupa horário? | Nesta fase |
|---|---|---|
| Pendente | sim | Agendamento do site quando a barbearia exige confirmação (D-06). Padrão: não exige |
| Confirmado | sim | Estado normal de um agendamento criado |
| Aguardando pagamento | sim | Reservado para assinaturas (Fase 9) |
| Concluído | sim (histórico) | Acontece no **fechamento do atendimento** (caixa, Fase 6). Não há botão "concluir" nesta fase |
| Não compareceu | não | Equipe registra **depois** do horário de início |
| Cancelado | não | [regras-cancelamento.md](regras-cancelamento.md) |

## 5. Concorrência (dupla reserva)

"Verificar → mostrar → salvar" tem condição de corrida. A proteção é **estrutural**:

1. Toda gravação que ocupa horário (reservar, remarcar, cancelar) abre uma transação cuja **primeira escrita** é
   `UPDATE professionals SET schedule_version = schedule_version + 1 WHERE id = ?` (a "linha de bloqueio" da
   agenda do profissional).
   - **MySQL/MariaDB (InnoDB):** trava a linha até o fim da transação.
   - **SQLite:** trava o banco para escrita. As transações são `IMMEDIATE`, com `busy_timeout` de 10 s e WAL
     (`config/database.php`).
   - Nos dois casos, a segunda reserva para o mesmo profissional **espera** a primeira terminar.
2. **Só depois** do bloqueio a disponibilidade é revalidada (`Availability::check`), com o que já foi gravado.
3. Se o horário foi ocupado nesse meio tempo, a resposta é `SlotUnavailable('conflict')` e a transação é
   desfeita **inteira**, inclusive a escrita de bloqueio. Nada fica pela metade.
4. Remarcação entre dois profissionais bloqueia as duas agendas sempre em ordem crescente de id, o que evita
   impasse entre duas remarcações cruzadas.

**Teste de concorrência de verdade** (`BookingConcurrencyTest`):

- Quatro **processos PHP independentes**, cada um com a sua conexão a um SQLite **em arquivo**, tentam reservar
  o mesmo horário do mesmo profissional.
- Eles largam juntos a partir de um arquivo-sinal, e cada um segura a transação aberta 400 ms entre a
  verificação e a gravação.
- Resultado: **exatamente 1 reserva**, 3 conflitos, 1 item gravado, versão de agenda = 1 (as perdedoras foram
  desfeitas).
- **Contraprova:** com as proteções desligadas, o mesmo teste falha, porque as tentativas se atropelam. O teste
  detecta a corrida.

Além disso, o mesmo **cliente** não pode ter dois agendamentos que se sobrepõem (`customer_conflict`).

## 6. Cliente

Jornada (sem login até a confirmação):

```text
/agendar → serviço → profissional (ou "sem preferência") → dia → horário
  → /minha-conta/agendar/confirmar (login; CPF obrigatório) → Confirmar
```

- Os horários vêm do servidor como links. Não há lista gerada em JavaScript, e tudo funciona sem JavaScript.
- A escolha chega pela URL e é **revalidada**. Manipular a URL (serviço inativo, profissional que não faz o
  serviço, horário ocupado, fora da grade, no passado) nunca reserva nada: dá 404 ou volta com a mensagem.
- Na conta: ver os próprios horários, remarcar e cancelar dentro das regras
  ([regras-reagendamento.md](regras-reagendamento.md), [regras-cancelamento.md](regras-cancelamento.md)).
  Agendamento de outra pessoa responde **404** em qualquer URL.
- Cliente sem CPF completa o cadastro antes (regra da Fase 3/4).

## 7. Equipe

[agenda.md](agenda.md) descreve as telas. Criar pelo painel aceita cliente cadastrado (busca por nome, e-mail
ou telefone, para quem tem `customers.view`) ou **cliente sem cadastro**, só com nome e telefone (balcão).
Criar cliente novo continua exigindo CPF (a tela de clientes é de fase futura).

## 8. Auditoria

| O quê | Onde |
|---|---|
| Criação, remarcação (horário e/ou profissional, com antes → depois), cancelamento (quem, motivo), confirmação, falta | `appointment_events` (histórico do agendamento, mostrado na tela) |
| Toda alteração de campos (`starts_at`, `ends_at`, `professional_id`, `status`, `notes`...) com antes/depois, autor e IP | `audit_logs` (trait `Auditable`). Sem e-mail e telefone do cliente (dado pessoal desnecessário na trilha) |
| Funcionamento, expediente, regras da agenda | `audit_logs` (`agenda.*`) |
| Folgas, bloqueios, pausas | `audit_logs` (`Auditable` nos models) |
