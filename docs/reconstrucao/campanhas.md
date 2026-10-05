# Campanhas de e-mail (Fase 10)

> Marketing: novidades e promoções para clientes que **aceitaram** receber. Separado dos e-mails do
> atendimento em tudo: categoria, consentimento, ritmo e permissões. Ver [emails.md](emails.md) e
> [consentimento.md](consentimento.md).

## 1. Etapas

| Etapa | Quem | O que acontece |
|---|---|---|
| Rascunho | `campaigns.manage` | Nome interno, assunto, texto (simples, com marcadores) e público. Editável enquanto for rascunho |
| Teste | `campaigns.manage` | Envia só para o e-mail de quem pediu (prefixo `[TESTE]`); nunca para clientes |
| Disparo | `campaigns.send` | Confirmação explícita ("revisei texto, assunto e público"); o público é **fotografado** (um destinatário por cliente, único no banco); auditado (`campaign.started`). Duplo clique: mesma chave, mesmo disparo |
| Envio | rotina a cada minuto | Lote de N destinatários (N = limite por minuto, padrão 30): cada um conferido **de novo** (ativo, mesmo e-mail, consentimento concedido, fora da supressão) e entregue à fila central |
| Cancelar | `campaigns.send` | O que não saiu não sai mais (auditado `campaign.cancelled`) |
| Conclusão | rotina | Quando não sobra destinatário; contagens a partir do registro central |

Nada é enviado na requisição do disparo: o disparo só fotografa o público.

## 2. Públicos

Os do sistema antigo, sempre **restritos a quem pode receber marketing** (cliente ativo, não anonimizado,
com e-mail, consentimento **concedido** e fora da lista de supressão):

| Público | Regra |
|---|---|
| Todos | todos que aceitaram receber novidades |
| Atendidos nos últimos 90 dias | atendimento concluído nos últimos 90 dias |
| Sem atendimento há mais de N dias | já atendido, nenhum atendimento concluído nos últimos N dias |
| Nunca atendidos | sem atendimento concluído |
| Aniversariantes do mês | data de nascimento no mês atual |
| Assinantes | com benefício de assinatura hoje |
| Já atendidos por um profissional | atendimento concluído com o profissional escolhido |

A tela do rascunho mostra quantos recebem hoje.

## 3. Consentimento, descadastro e LGPD

- **"Desconhecido" não recebe.** Clientes importados com consentimento desconhecido ficam fora até
  escolherem ("Meus dados" ou cadastro). Nada é presumido.
- Todo e-mail de campanha leva o link **"Não quero mais receber"** (página, botão) e o cabeçalho
  `List-Unsubscribe` + `List-Unsubscribe-Post` (descadastro de **um clique** nos leitores de e-mail,
  RFC 8058). Os dois revogam o consentimento e põem o e-mail na supressão, com prova
  ([consentimento.md §3](consentimento.md#3-descadastro)).
- Conferido três vezes: ao fotografar o público, ao entregar o lote e ao enviar o e-mail. Quem saiu no meio do
  caminho é pulado.

## 4. Não atrapalha o atendimento

- E-mail transacional não espera campanha: cada e-mail é um job próprio; a campanha só entrega N por minuto à
  fila (`test_campanha_nao_trava_o_transacional`).
- Erro numa campanha não afeta agendamento, pagamento, assinatura ou atendimento (outra transação, outra
  rotina).
- Ritmo configurável (1 a 500 por minuto) para respeitar o limite do provedor (D-05).

## 5. Idempotência e concorrência

Destinatário único por (campanha, cliente); cada destinatário é **reivindicado** antes da entrega (dois
processos nunca entregam o mesmo); chave `campaign:{campanha}:{cliente}` na fila central. Processo que cai
no meio do lote: o destinatário volta para a fila depois de 10 minutos (sem duplicar: a chave devolve o
registro já criado). Testado com 4 processos reais ([fila.md §4](fila.md#4-concorrência-testada)).

## 6. Campanhas do sistema antigo

Importadas como **resumo** (D-20), marcadas `is_legacy`: aparecem na lista, nunca são editadas nem reenviadas.

## 7. PRECISA DE DECISÃO

| # | Ponto | Implementado (conservador) |
|---|---|---|
| P10-01 | Limite de frequência por cliente (ex.: no máximo 1 campanha por semana) | **Não inventado**: só o ritmo do provedor. Cada campanha é decisão explícita da equipe |
| P10-02 | Clientes importados com consentimento "desconhecido" | Ficam **fora** das campanhas até escolherem. Se o dono quiser pedir o consentimento, uma campanha transacional de "pedido de consentimento" precisa de decisão jurídica |
