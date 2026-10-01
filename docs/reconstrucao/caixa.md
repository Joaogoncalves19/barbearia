# Caixa (Fase 6)

O caixa é o registro do dinheiro que passa pelo balcão num período (abertura → fechamento). **Não é conta
bancária** e não é o financeiro (despesas, comissões, DRE: Fase 7).

## 1. Regra de negócio

- **Um caixa aberto por vez na barbearia** (decisão do dono, Fase 6). Todos (recepção, gerente, profissional
  que conclui o próprio atendimento) lançam no mesmo caixa. Garantido no banco (`cash_sessions.open_marker`
  único, 1 enquanto aberto) e no `CashRegister`.
- O sistema antigo não tinha caixa: não havia regra a preservar.

## 2. Modelo

| Tabela | Conteúdo |
|---|---|
| `cash_sessions` | Abertura (quem, quando, valor inicial em dinheiro, observação) e fechamento (quem, quando, **esperado**, **contado**, **diferença**, justificativa). Fechado não muda mais |
| `cash_movements` | Razão do caixa, **só inclusão**: tipo, forma, valor com sinal (entrada > 0, saída < 0), origem (`payment_id` para pagamento/estorno), descrição/motivo, quem, quando, `request_key` |

Tipos de movimentação:

| Tipo | Sinal | Origem | Quem lança |
|---|---|---|---|
| Pagamento (`payment`) | + | o `payment` do atendimento (valor + gorjeta) | a conclusão do atendimento |
| Estorno (`refund`) | − | o `payment` de estorno | o estorno |
| Suprimento (`supply`) | + | motivo | equipe (`cash.move`) |
| Sangria (`withdrawal`) | − | motivo | equipe (`cash.move`) |

Toda movimentação de pagamento/estorno aponta o pagamento (e só essas); a regra está no model e no verificador
de integridade (R30/R31).

## 3. Operações

Única porta: `App\Modules\Finance\Services\CashRegister`.

| Operação | Regras |
|---|---|
| Abrir | Não pode haver outro aberto; valor inicial ≥ 0 |
| Suprimento | Valor > 0, motivo obrigatório |
| Sangria | Valor > 0, motivo obrigatório, **nunca mais que o dinheiro esperado na gaveta** |
| Receber pagamento | Só com caixa aberto (a conclusão do atendimento trava o caixa) |
| Fechar | Informa o dinheiro **contado**; o sistema calcula o **esperado** e a **diferença**. Diferença ≠ 0 exige justificativa. Fechar de novo = "caixa já fechado" |

**Esperado em dinheiro** = valor inicial + movimentos em **dinheiro** (pagamentos e suprimentos entram;
estornos e sangrias saem). Pix e cartões aparecem no resumo por forma, mas não estão na gaveta.

## 4. Concorrência e idempotência

- Toda operação num caixa começa escrevendo na linha do caixa (`version + 1`): movimentar e fechar ao mesmo
  tempo ficam em fila; quem chega depois do fechamento recebe "caixa fechado".
- Duas aberturas simultâneas: o índice único barra a segunda.
- Teste real (`CheckoutConcurrencyTest::test_fechamento_simultaneo_do_mesmo_caixa`): 3 processos fecham o mesmo
  caixa → 1 fecha, 2 recebem "já fechado".
- Suprimento/sangria repetidos com a mesma chave do formulário não duplicam.

## 5. Telas e permissões

| Tela | URL | Permissão |
|---|---|---|
| Caixa (abrir; resumo; suprimento, sangria e fechar em modais; movimentações; caixas fechados) | `/painel/caixa` | `cash.view` (abrir: `cash.open`; suprimento/sangria: `cash.move`; fechar: `cash.close`) |
| Caixa fechado (resumo, diferença, justificativa, razão) | `/painel/caixa/{id}` | `cash.view` |

| Papel | Caixa |
|---|---|
| Proprietário, gerente, recepção | ver, abrir, suprimento/sangria, fechar |
| Financeiro | só ver |
| Profissional | não vê o caixa; ao concluir o **próprio** atendimento, o pagamento entra no caixa aberto (`payments.receive`) |

## 6. Auditoria

`cash.opened`, `cash.supply`, `cash.withdrawal`, `cash.closed` (esperado, contado, diferença) pela
`AuditTrail`; `Auditable` na sessão de caixa (antes/depois). A razão (`cash_movements`) é o histórico.
