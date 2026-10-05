# Modelos de e-mail (Fase 10)

> Os modelos ficam no código (`app/Modules/Communication/Templates`), num registro único
> (`TemplateRegistry`). Cada modelo monta o e-mail **na hora do envio**, a partir do estado atual, e pode
> desistir ("não se aplica mais"). Ver [emails.md](emails.md).

## 1. Contrato

| Método | Para quê |
|---|---|
| `key()` | Nome gravado no registro (`booking_confirmed`, `reminder_day_before`…) |
| `category()` | Transacional ou marketing ([emails.md §4](emails.md#4-transacional--marketing)) |
| `label()` | Nome na tela do painel |
| `render(EmailMessage)` | Devolve o e-mail montado (`RenderedEmail`) ou o **motivo** para não enviar |
| `preview()` | Exemplo com dados fictícios (painel → E-mails enviados → Ver prévia) |

O registro guarda só o nome do modelo e **parâmetros não sensíveis** (identificadores, horário lembrado,
tipo do comprovante). Nunca o corpo pronto, nunca senha, token ou link de acesso.

## 2. Modelos

| Chave | Tipo | Desiste quando |
|---|---|---|
| `booking_confirmed` | Transacional | agendamento não está mais aberto |
| `booking_rescheduled` | Transacional | agendamento mudou de novo ou não está aberto (só a remarcação mais nova sai) |
| `booking_cancelled` | Transacional | agendamento não está cancelado |
| `reminder_day_before`, `reminder_hours_before` | Transacional (opcional) | cancelado/encerrado, **remarcado** (outro horário), horário passou, cliente desligou lembretes |
| `review_request` | Transacional | já avaliado, fora do prazo, atendimento não concluído |
| `subscription_activated` | Transacional | assinatura não está ativa/em dia |
| `subscription_payment_failed` | Transacional | assinatura não está mais em atraso (cobrança recuperada) |
| `subscription_cancel_scheduled` | Transacional | cancelamento desfeito |
| `subscription_cancelled` | Transacional | assinatura não está encerrada |
| `receipt` | Transacional | tipo de comprovante desconhecido |
| `campaign` | **Marketing** | campanha cancelada, cliente não existe mais |
| `campaign_test` | Transacional (só para a equipe) | campanha cancelada |

## 3. Layout e identidade

`resources/views/mail/communication/layout.blade.php`: cores e tipografia da identidade (direção A: tinta,
papel e cobre; faixa escura com o nome da barbearia, cartão claro, botão cobre), largura máxima de 600 px,
estilo no próprio e-mail (leitores de e-mail não carregam CSS externo) e nenhuma imagem: abre bem em leitores
que bloqueiam imagens. Rodapé com nome, endereço e telefone da barbearia (configuração geral); no marketing,
o motivo do envio e o link **"Não quero mais receber"**; no transacional, "E-mail sobre o seu atendimento".

`message.blade.php` (corpo genérico): título, parágrafos, quadro de detalhes (quando, profissional,
serviços, valor, código), botão principal, notas e um link secundário. `receipt.blade.php`: a mesma parte
usada na impressão do comprovante (Fase 8).

**Tudo é escapado** (`{{ }}`): nome do cliente, comentário, texto de campanha. Texto de campanha é **texto
simples** (parágrafos separados por linha em branco) com marcadores `{primeiro_nome}`, `{nome_cliente}`,
`{nome_barbearia}`, `{link_agendamento}`; nunca vira HTML.

## 4. Aprovação visual

**PENDENTE (D-05):** os modelos podem ser revistos pela pré-visualização do painel. A aprovação visual "no
provedor" (como chegam no Gmail/Outlook/celular) depende do provedor escolhido e de um envio a uma
lista-semente.

## 5. Acrescentar um modelo

1. Classe em `Templates/` que estende `BaseTemplate` (ou implementa `EmailTemplate`).
2. Registrar em `TemplateRegistry`.
3. Pedir pelo `CustomerMessages` (ou serviço do domínio) com uma chave de unicidade.
4. Teste: envio, desistência na hora do envio e `preview()` (o teste de prévias passa por todos).
