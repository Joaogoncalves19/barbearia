# Fila, novas tentativas e idempotência (Fase 10)

> Como os e-mails saem sem travar o sistema e sem sair duas vezes. Ver [emails.md](emails.md).

## 1. Fila

- Conexão `QUEUE_CONNECTION=database` (tabela `jobs`); filas `emails` (primeiro) e `default`.
- Sem supervisor de processos na hospedagem: o agendador roda `queue:work --queue=emails,default
  --stop-when-empty --max-time=50` a cada minuto (`QUEUE_WORK_VIA_SCHEDULER=true`). Com supervisor, um worker
  permanente com as mesmas filas.
- O job é disparado **depois do commit** (`afterCommit`): se o acontecimento for desfeito, nada sai.
- O job leva **só o id do registro**; o e-mail é montado no worker com o estado atual.

## 2. Novas tentativas e falha definitiva

| | Valor |
|---|---|
| Tentativas | 4 |
| Espera entre elas | 1 min, 5 min, 15 min, 60 min |
| Tempo limite do job | 60 s |
| Tempo limite da conexão SMTP | `MAIL_TIMEOUT` (padrão 10 s) |

Erro do provedor: o registro volta para "na fila" com o erro (sem credenciais) e o job lança a exceção para o
worker tentar de novo. Esgotou: o registro vira **"falhou"** (`failed_at`, `last_error`) e o log `jobs`
recebe o aviso (também sem credenciais). Reenvio: painel ("Reenviar", `communications.retry`) ou
`php artisan app:communication retry [--id=<registro>]`; só o que está "falhou" volta para a fila.

## 3. Idempotência (nada sai duas vezes)

| Camada | Garantia |
|---|---|
| Pedido | `email_messages.dedupe_key` **único no banco**: o mesmo acontecimento pedido duas vezes (ou por dois processos) devolve o mesmo registro |
| Entrega | O worker **reivindica** o registro (`queued → sending` numa única atualização condicional). Job repetido, worker duplicado ou reenvio enquanto envia: só um entrega; os outros encontram "enviado" e param |
| Lembrete | `appointment_reminders` único por (agendamento, tipo, **horário**) ([lembretes.md](lembretes.md)) |
| Pedido de avaliação | chave `review_request:{atendimento}` + aviso na conta com a mesma chave |
| Campanha | destinatário único por (campanha, cliente); reivindicação do destinatário; chave `campaign:{campanha}:{cliente}` |
| Aviso na conta | `customer_notifications.dedupe_key` único |

Limite conhecido: se o provedor aceitar o e-mail e o processo morrer **antes** de gravar "enviado", o
registro fica em "enviando" e não é reenviado sozinho (preferimos não duplicar). Aparece no painel; a equipe
decide. Não há como garantir "exatamente uma vez" com SMTP; o sistema garante **no máximo uma vez** por
registro, mais o reenvio explícito.

## 4. Concorrência testada

`CommunicationConcurrencyTest` (processos PHP reais, SQLite em arquivo):

- 4 agendadores rodando os lembretes ao mesmo tempo: **um** lembrete, **um** e-mail e **um** aviso por
  horário;
- 4 processos entregando o lote da mesma campanha: **um** e-mail por destinatário, campanha concluída.

Contraprova no MySQL: **NÃO EXECUTADO** (D-02).

## 5. Comandos

`app:communication {reminders|review-requests|campaigns|retry|retention}` (agendados em
`routes/console.php`); `app:communication-probe` existe só para o teste de concorrência (recusa rodar fora de
local/testing).
