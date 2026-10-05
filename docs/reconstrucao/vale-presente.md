# Vale-presente (Fase 8)

**Decisão do dono (D-41): o vale-presente é forma de pagamento**, não desconto. A venda entra no caixa; quem
usa paga o atendimento com ele; a comissão é sobre o valor cheio do serviço.

Serviço: `app/Modules/Loyalty/Services/GiftCards.php`. Telas: **Promoções › Vales-presente**.

## 1. Venda

- Valor (R$ 0,01 a R$ 100.000,00), forma de pagamento (dinheiro, Pix, débito, crédito, outro), validade
  (padrão sugerido: um ano; pode ficar sem validade), quem comprou, presenteado, e-mails e mensagem.
- Exige **caixa aberto**: a venda vira movimentação `gift_card_sale` (entrada, na forma usada). Em dinheiro,
  entra na gaveta.
- Código gerado `PRESENTE-XXXXXXXX` (8 caracteres sem ambiguidade), único.
- Idempotente por chave (`request_key`), auditado (`gift_card.sold`).
- Quem vende: `gift_cards.sell` (proprietário, gerente, recepção).

## 2. Uso (R-13: uso único)

- Na conclusão do atendimento, como uma linha de pagamento "Vale-presente" com o código.
- O vale paga **de uma vez** o menor entre o seu valor e o total a pagar. Se o vale for maior que o total,
  a sobra **não** vira saldo (uso único). Se for menor, o resto é pago por outra forma.
- Conferido e travado na transação da conclusão (`gift_cards.version`): disponível, dentro da validade,
  valor exato. Usado por dois atendimentos ao mesmo tempo: só um consegue (`payments.gift_card_id` único).
- **Não entra na gaveta** (o dinheiro entrou na venda): o pagamento não aponta o caixa e não gera
  movimentação.
- Gorjeta não pode ser paga com vale.
- A comissão é calculada normalmente sobre o serviço (o vale é só a forma de pagamento).

## 3. Cancelamento

- Só vale **disponível** (nem usado, nem cancelado). Motivo obrigatório. `gift_cards.cancel`
  (proprietário e financeiro).
- Se a venda foi registrada no sistema novo, o valor é **devolvido pelo caixa aberto** na mesma forma
  (`gift_card_refund`, saída). Em dinheiro, só se houver dinheiro na gaveta.
- Vales importados do sistema antigo (`is_legacy`) são cancelados sem devolução pelo caixa (a venda não foi
  registrada aqui).
- Nada é apagado; o vale guarda quem cancelou, quando e por quê. Auditado (`gift_card.cancelled`).

## 4. Estorno de pagamento feito com vale

**Bloqueado** pelo caixa (`gift_card_not_refundable`): não entrou dinheiro naquele atendimento. A correção é
decisão do dono (ver PRECISA DE DECISÃO).

## 5. Impressão e e-mail

"Imprimir ou enviar" na ficha do vale abre o comprovante do vale (código, valor, validade, presenteado e
mensagem) para imprimir ou enviar por e-mail ao comprador ou ao presenteado. Ver [comprovantes.md](comprovantes.md).

## 6. Integridade (verificador R41)

Vale usado tem exatamente um pagamento (até o valor do vale); pagamento com a forma vale-presente aponta um
vale (e só ele); venda e devolução no caixa somam exatamente o valor do vale.

## 7. PRECISA DE DECISÃO

| # | Ponto | Implementado (mais conservador) |
|---|---|---|
| P8-06 | Sobra do vale maior que o atendimento | Uso único (R-13): a sobra é perdida; se o valor digitado for outro, o sistema recusa e informa o valor a usar |
| P8-07 | Estorno de atendimento pago com vale | Bloqueado; não há "reativar vale" nem devolução automática |

## 8. Testes

`GiftCardsTest` (7): venda no caixa e idempotente; venda inválida não grava; uso único sem gaveta e com
comissão cheia; valor exato exigido; vencido/inexistente/gorjeta recusados; cancelamento devolve pelo caixa;
estorno bloqueado; vale imutável. `PromotionsPanelTest::test_venda_e_cancelamento_de_vale_pelo_painel`.
E2E: venda e comprovante para imprimir.
