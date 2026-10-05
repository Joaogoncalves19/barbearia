# Lembretes e confirmação de presença (Fase 10, D-51)

> **D-51 (dono): "como hoje"** — lembrete na véspera, a partir das 9h, e outro 2 h antes; os dois
> configuráveis; link para confirmar presença; e-mail + aviso na conta.

## 1. Quando sai

| Lembrete | Regra | Configuração (painel → Comunicação → Lembretes e avisos) |
|---|---|---|
| Véspera | agendamentos **confirmados** de amanhã, a partir da hora configurada | ligado; a partir das 9h (6 a 21) |
| Horas antes | agendamentos **confirmados** que começam entre agora e agora + H | ligado; 2 h (1 a 24) |

A rotina `app:communication reminders` roda a cada 5 minutos. Agendamento **aguardando confirmação** da
barbearia não recebe lembrete (como no sistema antigo, que só lembrava os confirmados).

Confirmação, remarcação e cancelamento por e-mail: [emails.md §2](emails.md#2-o-que-o-domínio-comunica).

## 2. Um lembrete por horário

`appointment_reminders` é **único por (agendamento, tipo, horário lembrado)**. A rotina tenta criar a linha
dentro de uma transação junto com o pedido do e-mail e do aviso; se a linha já existe (outra execução, outro
servidor), a transação é desfeita e o lembrete conta como "já lembrado". O agendador rodando duas vezes não
duplica (teste com 4 processos reais em [fila.md §4](fila.md#4-concorrência-testada)).

## 3. Cancelado e remarcado

Conferido **na hora do envio** pelo modelo:

- **Cancelado** (ou concluído, falta) depois de o lembrete entrar na fila: o e-mail **não sai** ("não se
  aplica mais"). A rotina também não pega cancelados.
- **Remarcado**: o lembrete guarda o horário lembrado; se o agendamento mudou de horário, o e-mail antigo
  **não sai**. O novo horário ganha o próprio lembrete (outra linha, outra chave) na próxima rotina.
- Horário que já passou: não sai.

## 4. Confirmação de presença

O lembrete leva **"Confirmar presença"**: link assinado (`/presenca/{código}?expires=…&signature=…`) que
vale **até o horário do agendamento**.

- Abrir o link **só mostra** a tela (horário, profissional, código; só o primeiro nome). Leitores de e-mail e
  antivírus abrem links sozinhos, por isso confirmar é um botão (POST com CSRF).
- Confirmar grava `appointments.presence_confirmed_at` e um evento `presence_confirmed` no histórico do
  agendamento (`BookingService::confirmPresence`). Confirmar de novo não muda nada.
- Link adulterado, de outro agendamento ou vencido: 403. Agendamento cancelado: a tela avisa; nada muda.
- Confirmar presença **não** é o "confirmado" da agenda (aprovação da barbearia, D-06): só informa a equipe.
- Limite próprio por IP (`throttle:email-links`).

## 5. Preferência do cliente

O cliente pode desligar **só os lembretes por e-mail** em "Meus dados" (registrado em `consent_records`,
finalidade `reminder_email`). Com o lembrete desligado, o aviso na conta continua; confirmação, remarcação,
cancelamento e comprovantes continuam ([consentimento.md](consentimento.md)).

## 6. Importação

Os lembretes já enviados pelo sistema antigo entram presos ao horário importado (`scheduled_for`): a rotina
não lembra de novo um agendamento futuro que o antigo já lembrou. A migration da Fase 10 preencheu o horário
das linhas existentes.
