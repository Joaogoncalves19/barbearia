# Pagamentos, descontos e arredondamento (Fase 6)

## 1. Dinheiro

Sempre **centavos inteiros** (`*_cents`), com o value object `Money` (soma, subtração, percentual em
pontos-base, sem `float`). A digitação aceita "50", "50,00", "1.250,90" e "R$ 30,00" (`Money::parse` /
`Money::tryParse`). R$ 50,00 = 5000; R$ 12,50 = 1250.

## 2. Formas de pagamento

As que o negócio usa hoje (sistema antigo: dinheiro, pix, débito, crédito, outro):

| Forma | Valor gravado | Entra na gaveta? |
|---|---|---|
| Dinheiro (`cash`) | `cash` | Sim (conta para o "esperado em dinheiro") |
| Pix (`pix`) | `pix` | Não (aparece no resumo por forma) |
| Débito (`debit_card`) | `debit_card` | Não |
| Crédito (`credit_card`) | `credit_card` | Não |
| Outro (`other`) | `other` | Não |

"Não informado" (`unknown`) existe **só** para pagamentos importados do sistema antigo; a tela e o serviço
recusam. Nenhuma integração de gateway nesta fase: o sistema **registra** o pagamento.

## 3. Pagamento dividido e gorjeta

- **Dividido:** sim, desde já (o roadmap e os relatórios financeiros da Fase 7 precisam). Cada forma é um
  `payment`. Ex.: total R$ 100 = Pix R$ 60 + Crédito R$ 40.
- **Soma exata:** a soma dos valores tem de ser **igual** ao total a pagar. Faltou ou sobrou um centavo:
  recusado, nada gravado.
- **Gorjeta:** à parte do total, por forma (`payments.tip_cents`), somada em `attendances.tip_cents`. Entra
  no caixa junto com o pagamento (passou pela maquininha ou pela gaveta). O repasse ao profissional é da
  Fase 7 (comissões).
- **Total zero** (desconto de 100%): conclui sem pagamento e sem caixa.
- **Pagar depois:** não existe (decisão do dono). Só se conclui pago.

## 4. Descontos

**Uma** regra: `App\Modules\Shared\Pricing\Discount` (quanto vale um desconto) e `PriceBreakdown` (totais).
Agendamento, atendimento e as promoções da Fase 8 usam as mesmas classes. Tipos de origem: o enum
`AdjustmentKind` que já existia (manual, cupom, fidelidade, assinatura, legado...). **Nenhum sistema
paralelo de desconto foi criado.**

Cada desconto guarda: tipo (`percent` | `fixed`), valor (pontos-base ou centavos), **valor antes**
(`base_cents`), **valor descontado** (`amount_cents`), **valor final** (base − desconto), motivo, quem
aplicou e a origem (`kind`).

| Regra | Onde |
|---|---|
| Incide só sobre serviços e combos, nunca sobre produtos vendidos (regra do sistema antigo) | `PriceBreakdown` |
| Percentual entre 0,01% e 100%; valor fixo ≥ R$ 0,01 | `Discount::percent/fixed` |
| Nunca maior que a base; total nunca negativo | `Discount::amountOn`, `PriceBreakdown` |
| Motivo obrigatório; só com `attendances.discount` | `AttendanceService::applyDiscount`, rota |
| Um desconto manual por atendimento (aplicar de novo substitui) | `AttendanceService::applyDiscount` |
| Descontos do agendamento (Fase 8) passam para o atendimento com a mesma origem | `openFromAppointment` |
| Recalculado enquanto o atendimento está aberto; **congela** na conclusão | `AttendancePricing::refreshDiscounts` |

## 5. Arredondamento

Uma regra só, em um lugar só (`Money::percentOf`, usada pelo `Discount`):

- **Percentual = base × pontos-base ÷ 10.000, meio centavo para cima** (half-up). Ex.: 50% de R$ 10,01 =
  R$ 5,005 → **R$ 5,01**; 33,33% de R$ 10,00 = R$ 3,333 → **R$ 3,33**.
- O percentual é calculado **uma vez sobre o total dos serviços**, nunca item a item (somar arredondamentos
  por item daria centavos de diferença). Ex.: 3 serviços de R$ 10,01 com 50%: sobre o total, R$ 15,02; item a
  item daria R$ 15,03.
- Vários descontos são aplicados **em ordem**, cada um sobre o que sobrou da base.
- O valor exibido é o valor **gravado** (centavos); tela, comprovante, caixa e relatórios leem o mesmo número.
- Rateio do desconto por item/profissional (comissão, Fase 7): **ainda não existe**. Recomendação: rateio
  proporcional com o método do maior resto, para a soma fechar no centavo.

Testes: `tests/Unit/DiscountTest.php` (percentual, fixo, zero, limites, inválidos, arredondamento, ordem,
total e não item a item).

## 6. Estorno

- **Nunca** se apaga ou edita um pagamento (`AppendOnly`). Estorno é um **novo** `payment` `kind = refund`
  que aponta o original (`refunds_payment_id`), com motivo, quem e quando.
- Parcial ou total: o total estornado nunca passa de valor + gorjeta do original.
- Sai do **caixa aberto** (movimentação negativa, mesma forma do original). Sem caixa aberto, recusado.
- Só de atendimento **concluído**; estorno de estorno não existe.
- O atendimento continua concluído com o valor do dia; o histórico mostra o estorno.
- Permissão `payments.refund` (proprietário e financeiro). Idempotente por `request_key`.
- Devolver a mercadoria ao estoque é outra ação (ver [estoque.md](estoque.md#5-estorno-e-devolução)).

## 7. Legado

Pagamentos importados do sistema antigo apontam o atendimento criado para o agendamento concluído
(`attendances.source = legacy`), sem caixa (`cash_session_id` nulo), com `amount_source = legacy_estimated`
quando o valor veio do catálogo antigo.
