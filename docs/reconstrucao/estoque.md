# Estoque (Fase 6)

## 1. Princípio

O estoque é **consequência do histórico**: saldo = soma de `stock_movements.quantity`. Nenhuma tela ou serviço
edita um saldo. Movimentação é **só inclusão**; corrige-se com outra movimentação.

Única porta: `App\Modules\Catalog\Services\StockLedger`.

## 2. Tipos de movimentação

| Tipo | Sinal | Origem | Quem |
|---|---|---|---|
| Entrada (`purchase`) | + | compra/reposição, custo unitário opcional | `stock.receive` |
| Venda (`sale`) | − | **atendimento** (produto vendido) | conclusão do atendimento |
| Consumo (`consumption`) | − | **atendimento** (material usado no serviço) | conclusão do atendimento |
| Saída (`usage`) | − | uso interno/outra saída, motivo obrigatório | `stock.issue` |
| Perda (`loss`) | − | quebra, validade, motivo obrigatório | `stock.issue` |
| Ajuste de inventário (`adjustment`) | ± | contagem física − saldo, motivo obrigatório | `stock.adjust` |
| Estorno (`reversal`) | ± | aponta o movimento revertido (`reverses_movement_id`) | `stock.adjust` |
| Ajuste de migração (`legacy_opening`) | ± | importador (saldo antigo − soma) | importador |

"Devolução" = estorno de uma venda ou consumo (ver §5). "Correção autorizada" = ajuste de inventário ou
estorno, ambos com `stock.adjust`.

Cada movimento registra: produto, quantidade com sinal, tipo, motivo, origem (atendimento ou movimento
revertido), quem lançou, quando e o **saldo logo depois** (`balance_after`, para auditoria).

## 3. Regras

- **Saldo nunca negativo** por lançamento novo (venda, consumo, saída, perda, estorno). Só o importador
  traz o legado como está (e registra pendência se o saldo antigo era negativo).
- Quantidade inteira > 0 (ajuste: contagem ≥ 0; contagem igual ao saldo = nada a ajustar).
- Produto inativo: não recebe entrada nem entra em atendimento novo; pode ser baixado/ajustado.
- Venda e consumo **só** nascem do atendimento e o apontam (regra no model e no verificador R32).
- Baixa só na **conclusão** do atendimento: cancelar antes não mexe no estoque.
- Cada movimento pode ser estornado **uma vez** (índice único em `reverses_movement_id`); ajuste, estorno e
  ajuste de migração não são estornáveis (corrige-se com novo ajuste).

## 4. Estoque mínimo

`StockLedger::situation()`: **sem estoque** (saldo ≤ 0), **baixo** (saldo ≤ mínimo) ou **ok**. A lista de
produtos mostra a situação e tem o filtro "Estoque baixo". É a base para alertas futuros; **não** há
notificação nesta fase.

## 5. Estorno e devolução

- Movimento manual (entrada, saída, perda): "Estornar" na tela de estoque do produto, com motivo e
  confirmação.
- Venda/consumo de atendimento: "Devolver ao estoque" **no atendimento** (fica no histórico dele), com motivo e
  confirmação. A tela de estoque recusa estornar esses movimentos por fora.
- Devolver a mercadoria não devolve o dinheiro: o estorno do pagamento é outra ação
  ([pagamentos.md §6](pagamentos.md#6-estorno)).

## 6. Concorrência e idempotência

- Toda gravação começa escrevendo na linha do produto (`products.stock_version + 1`) e só depois lê o saldo.
  Vários produtos na mesma transação: ordem crescente de id.
- Teste real (`CheckoutConcurrencyTest::test_varios_tirando_as_ultimas_unidades_do_estoque`): 5 processos
  tiram 1 unidade de um produto com 3 → 3 conseguem, 2 recebem "estoque insuficiente", saldo 0, e os saldos
  gravados são 3, 2, 1, 0 (cada lançamento viu o anterior).
- Lançamento manual repetido com a mesma chave do formulário devolve o movimento já gravado.

## 7. Telas e permissões

| Tela | URL | Permissão |
|---|---|---|
| Estoque do produto (saldo, situação, entrada, saída/perda, ajuste, histórico com estorno) | `/painel/produtos/{id}/estoque` | `stock.view`; entrada `stock.receive`; saída/perda `stock.issue`; ajuste e estorno `stock.adjust` |

| Papel | Estoque |
|---|---|
| Proprietário, gerente | tudo |
| Recepção | ver, entrada, saída/perda (sem ajuste de inventário nem estorno) |
| Financeiro | só ver |
| Profissional | não acessa; o consumo do próprio atendimento baixa o estoque na conclusão |
