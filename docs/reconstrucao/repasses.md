# Gorjeta, vales e repasse (Fase 7)

O que o profissional tem a receber vem de **três razões separados**, cada um só de inclusão:

```text
commission_entries  (comissão: calculada por regra, ver comissoes.md)
tip_entries         (gorjeta: valor que o cliente deixou para o profissional)
advances            (vales: adiantamentos, abatidos no repasse)

valor devido (em aberto) = comissão + gorjeta − vales     (lançamentos sem repasse)
repasse                  = fecha o valor devido até um instante e registra o pagamento
```

## 1. Uma regra por operação

| Operação | Único lugar |
|---|---|
| Gorjeta na conclusão e no estorno; saldo em aberto; ajuste | `App\Modules\Finance\Services\ProfessionalLedger` |
| Vale e estorno de vale | `App\Modules\Finance\Services\Advances` |
| Repasse e estorno de repasse | `App\Modules\Finance\Services\Payouts` |
| Saída/entrada de dinheiro no caixa | `CashRegister::recordProfessionalMovement` (o mesmo caixa da Fase 6) |

## 2. Saldo em aberto

`ProfessionalLedger::open(profissional, até)`: soma dos lançamentos **sem repasse** com `occurred_at` até o
instante. É o número da tela "Comissões" e do extrato, e exatamente o que o próximo repasse vai fechar.
Vales do sistema antigo não entram (§4).

## 3. Gorjeta

- **Conclusão do atendimento:** uma gorjeta por pagamento com gorjeta (`payments.tip_cents > 0`), para o
  profissional que atendeu, **apontando o pagamento de origem** (`tip_entries.payment_id`, único). Mesma
  transação da conclusão.
- **Não passa por regra:** vai inteira para o profissional, mesmo sem nenhuma regra de comissão
  (`test_profissional_sem_comissao`). Não entra na base da comissão.
- **Estorno:** a parte da gorjeta informada no estorno vira gorjeta negativa, apontando o estorno
  (decisão do dono, D-35). O estorno nunca devolve mais gorjeta do que a ainda não estornada daquele
  pagamento.
- **Ajuste manual:** com motivo e autor (ex.: gorjeta em dinheiro entregue direto no balcão).
- **Histórico:** o extrato mostra cada gorjeta com o atendimento, a forma de pagamento e se já foi repassada.

## 4. Vales (decisão do dono, D-34)

- **Lançar:** valor, forma (dinheiro, Pix ou outro/transferência) e motivo. Em **dinheiro, sai do caixa
  aberto** (movimento `advance`, recusado se não houver dinheiro esperado suficiente, como a sangria).
- **Abatido** no próximo repasse.
- **Estornar:** registro novo, negativo, apontando o vale (uma vez; `reverses_advance_id` único). Em dinheiro,
  o dinheiro volta para o caixa aberto (`advance_reversal`). O vale original nunca muda.
- **Sistema antigo:** os vales importados ficam como **histórico** (`is_legacy`): o sistema antigo já os
  abatia no mês; nunca entram num repasse novo e não são estornáveis aqui.

## 5. Repasse

**Fechar e pagar** (`Payouts::pay`), numa transação que primeiro trava o saldo do profissional
(`professionals.ledger_version`):

1. junta **todos** os lançamentos em aberto até o instante de corte (comissão, gorjeta, vales);
2. nada em aberto: "não há valores em aberto"; líquido negativo (vales, estornos ou ajustes maiores que o
   valor a receber): recusado, nada gravado;
3. grava o repasse com os totais (comissão, gorjeta, vales, líquido), a forma, o corte, quem pagou e a
   **fotografia** dos lançamentos incluídos (ids e valores);
4. marca esses lançamentos com o repasse, **só os que ainda estavam em aberto**; se a contagem não bater,
   desfaz tudo (um lançamento nunca entra em dois repasses);
5. em **dinheiro**, a saída vai para o **caixa aberto** (`payout`, decisão do dono D-37), recusada se não
   houver dinheiro esperado suficiente; Pix/outro só ficam registrados. Líquido zero (vale = devido) fecha
   sem movimentar dinheiro.

**Estornar** (`Payouts::reverse`), com motivo: os lançamentos voltam a ficar em aberto (para um novo
repasse); o repasse fica no histórico com a fotografia, o motivo, quem e quando; se foi pago em dinheiro, o
valor volta para o caixa aberto (`payout_reversal`). Uma vez só. Repasses importados do sistema antigo
(sem lançamentos) não são estornáveis.

O repasse é imutável: a única mudança permitida é o estorno.

## 6. Transações, travas, idempotência

| Operação | Trava, nesta ordem | Idempotência |
|---|---|---|
| Repasse | saldo do profissional → caixa aberto (se dinheiro) | `commission_payouts.request_key` (único) |
| Estorno de repasse | saldo do profissional → caixa aberto (se foi em dinheiro) | `reversal_request_key` (único) |
| Vale / estorno de vale | saldo do profissional → caixa aberto (se dinheiro) | `advances.request_key` (único) |
| Ajuste | saldo do profissional | `request_key` (único) |
| Conclusão e estorno de pagamento | os da Fase 6 (atendimento → caixa → produtos); comissão e gorjeta são inclusões | `completion_key` / `payments.request_key` |

A conclusão não trava o saldo do profissional: um repasse simultâneo só fecha o que já estava gravado; o
que a conclusão gravar fica para o próximo. Ordem fixa = sem impasse.

**Teste de concorrência real** (`CheckoutConcurrencyTest::test_repasse_simultaneo_do_mesmo_profissional`):
4 processos PHP registrando ao mesmo tempo o repasse do mesmo profissional → **1** repasse; os outros 3
recebem "não há valores em aberto"; nenhum lançamento fora do repasse; verificador limpo.

## 7. Telas

| Tela | URL | Permissão |
|---|---|---|
| Conferir e registrar repasse | `/painel/comissoes/profissionais/{id}/repasse` | `payouts.create` |
| Repasses (todos, filtro por profissional) | `/painel/repasses` | `payouts.view` |
| Repasse (o que entrou, estorno) | `/painel/repasses/{id}` | `payouts.view` ou o próprio profissional (`CommissionPayoutPolicy`; outro = 404) |
| Estornar repasse (modal) | — | `payouts.reverse` |
| Lançar vale (modal no extrato) / estornar vale | — | `advances.create` / `advances.reverse` |

| Habilidade | Proprietário | Gerente | Financeiro | Recepção | Profissional |
|---|---|---|---|---|---|
| `payouts.view` | ✓ | ✓ | ✓ | | (só os próprios) |
| `payouts.create` | ✓ | | ✓ | | |
| `payouts.reverse` | ✓ | | ✓ | | |
| `advances.create` | ✓ | | ✓ | | |
| `advances.reverse` | ✓ | | ✓ | | |

## 8. Auditoria e integridade

- Auditoria: `payout.created`, `payout.reversed`, `advance.issued`, `advance.reversed`, `tip.adjusted`,
  `commission.adjusted`, além das regras ([comissoes.md §2](comissoes.md)) e do caixa.
- Verificador de integridade: R34 (uma comissão por item de atendimento concluído), R35 (toda gorjeta paga
  ou estornada no razão de gorjeta, com o mesmo valor), R36 (estorno de comissão nunca passa da calculada),
  R37 (repasse fecha: líquido = comissão + gorjeta − vales; lançamentos vinculados somam o mesmo; dinheiro
  igual no caixa; repasse estornado sem lançamentos presos), R38 (vale e estorno de vale coerentes; vale
  antigo nunca em repasse novo). R18 passou a olhar as regras de comissão.

## 9. Fora desta fase

Recibo impresso do repasse (o sistema antigo tinha), despesas, meta, DRE e relatórios gerais: fases
seguintes. O comprovante na tela (`/painel/repasses/{id}`) já mostra tudo o que entrou.
