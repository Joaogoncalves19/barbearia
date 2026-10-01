# Produtos (Fase 6)

Cadastro mínimo para vender e controlar estoque na barbearia. Não é um ERP.

## 1. Campos

| Campo | Coluna | Observação |
|---|---|---|
| Nome | `name` | Único entre os não excluídos |
| Código | `sku` | Opcional, único, maiúsculo (código de barras ou do fornecedor) |
| Descrição | `description` | Opcional |
| Unidade | `unit` | `un`, `cx`, `fr`, `pct`. O estoque é contado em **inteiros** dessa unidade |
| Preço de venda | `price_cents` | **Opcional**: vazio = insumo, só usado no serviço, não vendido |
| Custo unitário | `cost_cents` | Opcional; fotografado na venda, no consumo e na entrada |
| Estoque mínimo | `min_stock` | Opcional; abaixo ou igual = "estoque baixo" |
| Ativo | `is_active` | Inativo não entra em atendimentos novos nem recebe entrada |
| Versão | `lock_version` | Edição simultânea não sobrescreve em silêncio |

**Não existe coluna de saldo.** O saldo é a soma das movimentações ([estoque.md](estoque.md)). A categoria
(`category_id`, herdada do modelo da Fase 2) não tem tela: não foi necessária.

## 2. Regras

- Única porta de gravação: `App\Modules\Catalog\Services\ProductAdmin`.
- Preço e custo entre R$ 0,00 e R$ 10.000,00, em centavos (no formulário e no model).
- Mudar preço ou custo muda só o valor **atual**: vendas e consumos já feitos guardam o próprio valor
  (item/consumo do atendimento e movimento de estoque). Auditoria com antes/depois.
- **Desativar** tira de atendimentos novos, sem mexer no histórico nem nas movimentações antigas.
- **Excluir** (exclusão física) só se o produto **nunca** teve movimentação, venda ou consumo. Com histórico:
  só desativar.

## 3. Telas e permissões

| Tela | URL | Permissão |
|---|---|---|
| Produtos (saldo, situação, filtros ativos/estoque baixo/inativos/todos) | `/painel/produtos` | `products.view` |
| Novo / editar | `/painel/produtos/novo`, `/painel/produtos/{id}/editar` | `products.create` / `products.update` |
| Ativar, desativar, excluir sem histórico | ações na lista / no formulário | `products.toggle` |
| Estoque do produto | `/painel/produtos/{id}/estoque` | `stock.view` (ver [estoque.md](estoque.md)) |

| Papel | Produtos |
|---|---|
| Proprietário, gerente | tudo |
| Recepção, financeiro | só consulta |
| Profissional | não acessa (escolhe produtos só dentro do próprio atendimento) |
