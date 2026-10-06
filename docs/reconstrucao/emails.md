# E-mails — sistema central (Fase 10)

> Como o sistema novo manda e-mail. Um caminho só para todo e-mail do negócio: modelo, registro, fila,
> novas tentativas, falha, log, preferências e descadastro. Detalhes em [templates.md](templates.md),
> [fila.md](fila.md), [lembretes.md](lembretes.md), [campanhas.md](campanhas.md),
> [avaliacoes.md](avaliacoes.md) e [consentimento.md](consentimento.md).

## 1. O caminho de um e-mail

```text
Acontecimento do domínio (agendamento, lembrete, assinatura, avaliação, comprovante, campanha)
   └─ CustomerMessages / Reminders / Campaigns / Receipts  (dentro da transação do acontecimento)
        └─ Outbox::queue()  → registro em email_messages (modelo + parâmetros, chave de unicidade)
             └─ job SendEmailMessage, disparado DEPOIS do commit (fila "emails")
                  └─ Outbox::deliver()
                       1. reivindica o registro (só um processo envia)
                       2. confere consentimento e supressão NA HORA
                       3. monta o e-mail com o estado ATUAL (o modelo pode desistir)
                       4. entrega pelo provedor (CommunicationMail, layout da identidade)
                       5. registra: enviado / não se aplica / bloqueado / erro → nova tentativa
```

- **Ninguém chama `Mail::` por conta própria** para e-mail do negócio. O único Mailable é
  `CommunicationMail` (não fica na fila sozinho: quem está na fila é o job, que controla tentativas).
- **Nenhuma requisição HTTP espera o provedor**: o e-mail só é gravado na requisição; o envio é do worker.
  A conexão SMTP tem tempo limite (`MAIL_TIMEOUT`, padrão 10 s) e o job tem `timeout` de 60 s.
- **Se o acontecimento é desfeito** (transação revertida), o registro some junto e o job nunca sai.

## 2. O que o domínio comunica

| Acontecimento | E-mail | Aviso na conta | Chave de unicidade |
|---|---|---|---|
| Agendamento criado pelo cliente ou pela equipe (não encaixe, não importado) | Confirmado (ou "aguardando a barbearia") | — | `booking_confirmed:{agendamento}` |
| Agendamento pendente confirmado pela barbearia | Confirmado | — | `booking_confirmed:{agendamento}:approved` |
| Remarcação | Remarcado (novo horário) | — | `booking_rescheduled:{agendamento}:{horário}` |
| Cancelamento | Cancelado | — | `booking_cancelled:{agendamento}` |
| Lembrete da véspera / horas antes (D-51) | Lembrete com link de presença | Sim | `reminder:{tipo}:{agendamento}:{horário}` |
| Atendimento concluído há 3 h (D-49) | Pedido de avaliação | Sim | `review_request:{atendimento}` |
| Assinatura ativada (D-50) | Ativada | Sim | `subscription:{id}:activated:first` |
| Cobrança da renovação recusada (D-50) | Falha de pagamento | Sim | `subscription:{id}:payment_failed:{fim pago}` |
| Renovação cancelada (D-50) | Cancelamento agendado | Sim | `subscription:{id}:cancel_scheduled:{pedido}` |
| Assinatura encerrada (D-50) | Encerrada | Sim | `subscription:{id}:cancelled:final` |
| Renovação paga (D-50) | **Não** (só aviso) | Sim | `subscription:{id}:renewed:{fim pago}` |
| Comprovante pedido (Fase 8) | Comprovante | — | `receipt:{chave do pedido}` |
| Campanha (marketing) | Campanha | — | `campaign:{campanha}:{cliente}` |
| Teste de campanha (equipe) | Campanha [TESTE] | — | sem chave (cada clique é um teste) |

E-mails de **conta e segurança** (confirmar e-mail, link mágico, redefinir senha, convite da equipe) continuam
como notificações em fila próprias da Fase 3: levam tokens, que **nunca** vão para o registro central.

## 3. Assinatura: estado consolidado, nunca o webhook

O aviso de assinatura é pedido por `SubscriptionLifecycle` **depois** que a transição foi aplicada (o mesmo
ponto que grava o histórico), e não quando o webhook chega. Webhook repetido, fora de ordem ou com fotografia
antiga não gera aviso (a transição não acontece). Na hora do envio o modelo confere de novo o estado
**atual**: se a cobrança já foi recuperada, o aviso de falha não sai; se o cancelamento foi desfeito, o aviso
de cancelamento não sai. Link de pagamento que nunca foi pago não gera nada.

## 4. Transacional × marketing

| | Transacional | Marketing |
|---|---|---|
| Exemplos | confirmação, remarcação, cancelamento, lembrete, avaliação, assinatura, comprovante | campanhas, promoções, novidades |
| Precisa de consentimento | Não (é necessário ao serviço) | **Sim: concedido**. "Desconhecido" não recebe |
| Descadastro de marketing bloqueia? | **Não** | Sim |
| Devolução (bounce) / reclamação bloqueia? | Sim | Sim |
| Link de descadastro e `List-Unsubscribe` | Não | Sim (página e "um clique") |
| Fila | `emails` | `emails`, mas o lote sai aos poucos (limite por minuto) |

O lembrete é transacional **opcional**: o cliente pode desligar só os lembretes por e-mail; o aviso na conta
continua ([consentimento.md](consentimento.md)).

## 5. Avisos na conta

`customer_notifications` (tipo, texto, link interno, chave de unicidade): lembretes, pedido de avaliação e
assinatura. Tela **Minha conta → Avisos** (contador de não lidos no menu); marcar como lido vale só para os
do próprio cliente. O link do aviso só é mostrado se for do próprio site.
Avisos são sempre **transacionais**: marketing nunca vira aviso (só e-mail, com consentimento), e a tela diz
isso (Fase 12). Conta excluída: nada mais é enviado, nem o que estava na fila (`Outbox::blockReason`).

## 6. Registro e painel

**Painel → Comunicação → E-mails enviados** (`communications.view`): situação, modelo, tipo, tentativas,
motivo de não envio e erro técnico. O endereço aparece **mascarado** (`jo***@dominio`) e o texto do e-mail
**não é guardado** (é montado na hora a partir dos parâmetros). Pré-visualização de cada modelo com dados
fictícios. Reenviar só o que falhou (`communications.retry`, auditado `email.retried`).

| Situação | Significado |
|---|---|
| Na fila | Esperando o worker (ou nova tentativa) |
| Enviando | Reivindicado por um processo |
| Enviado | O provedor aceitou |
| Falhou | Tentativas esgotadas (erro guardado sem credenciais); pode ser reenviado |
| Bloqueado | Consentimento, descadastro, devolução ou reclamação, conferidos na hora |
| Não se aplica mais | O modelo desistiu: agendamento cancelado/remarcado, assinatura mudou, já avaliado… |

O registro é histórico: só a situação muda; nunca é apagado (o model recusa).

## 7. Provedor (D-05) e segredos

**D-05 decidido (aprovação da Fase 10): Resend como primeiro provedor, atrás de uma abstração.** O domínio
não conhece o fornecedor: a fila, os modelos, as regras de envio, as preferências, as novas tentativas e o
histórico falam só com a interface `EmailProvider` (`app/Modules/Communication/Delivery`).

| `EMAIL_PROVIDER` | Implementação | Uso |
|---|---|---|
| `mailer` (padrão) | `MailerProvider`: mailer do Laravel (`MAIL_MAILER`: `log` em desenvolvimento, `array` nos testes, `smtp` se preciso) | Desenvolvimento, testes, CI |
| `resend` | `ResendProvider`: API HTTP do Resend (`POST /emails`), sem SDK | Homologação e produção |

Regras do adaptador do Resend: chave **só** em `RESEND_API_KEY` (vazia = nada sai e o registro guarda "provedor
não configurado"); `Idempotency-Key` = identificador público do registro (o mesmo registro nunca vira dois
e-mails no provedor); o HTML é o mesmo do layout da identidade; cabeçalhos de descadastro de um clique no
marketing; tempo limite de 10 s (`RESEND_TIMEOUT`); erro devolvido como `EmailDeliveryFailed` **sem a chave**;
o identificador do e-mail no Resend fica em `email_messages.provider_message_id`. Trocar de fornecedor = nova
classe que implementa a interface + uma linha em `EmailProviders::AVAILABLE` + `EMAIL_PROVIDER`.

Nenhuma credencial no código, no banco, no painel ou no log: o erro guardado passa por `Outbox::safeError`,
que mascara `password=`, `token=`, `secret=`, `key=`, e o log global de falhas de fila também.

**Pendente para a homologação (sem credenciais reais aqui):** entrega real com o Resend a uma lista-semente,
aprovação visual dos e-mails reais, SPF/DKIM/DMARC do domínio e devolução/reclamação automáticas (webhook do
Resend alimentando `email_suppressions`).

## 8. Rotinas

| Agendamento | Comando | O que faz |
|---|---|---|
| a cada minuto | `queue:work --queue=emails,default` (via agendador) | entrega os e-mails |
| a cada 5 min | `app:communication reminders` | lembretes ([lembretes.md](lembretes.md)) |
| a cada 10 min | `app:communication review-requests` | pedidos de avaliação ([avaliacoes.md](avaliacoes.md)) |
| a cada minuto | `app:communication campaigns` | próximo lote das campanhas ([campanhas.md](campanhas.md)) |
| diário, 03:30 | `app:communication retention` | retenção dos eventos do Stripe (P9-10, [assinaturas.md §11](assinaturas.md#11-retenção-dos-eventos-do-stripe-decisão-do-dono-p9-10)) |
| manual | `app:communication retry [--id=]` | reenvia os que falharam |

Todas idempotentes: rodar duas vezes (ou em dois servidores) não duplica nada ([fila.md](fila.md)).
