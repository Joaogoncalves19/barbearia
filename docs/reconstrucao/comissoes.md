# Comissões (Fase 7)

Comissão é a **remuneração do profissional calculada por uma regra**. Ela é diferente da **gorjeta**, que é o
valor que o cliente destinou ao profissional e não passa por regra nenhuma (decisão do dono, D-31; ver
[repasses.md](repasses.md)). As duas ficam em razões separados e só se encontram no repasse.

## 1. Uma regra por operação

| Operação | Único lugar |
|---|---|
| Qual regra vale para um item; criar, trocar e encerrar regras | `App\Modules\Finance\Services\CommissionRules` |
| Calcular a comissão de um atendimento concluído | `App\Modules\Finance\Services\CommissionCalculator` |
| Gravar comissão e gorjeta (conclusão, estorno, ajuste) | `App\Modules\Finance\Services\ProfessionalLedger` |
| Rateio do desconto entre os serviços | `App\Modules\Shared\Pricing\PriceBreakdown::shareDiscount` (junto do cálculo de totais) |

Telas (`Panel\Finance\*Controller`) só leem a intenção e chamam esses serviços.

## 2. Regras de comissão

**Fonte única:** a tabela `commission_rules`. Os campos de comissão que existiam no cadastro do profissional
(`commission_rate_bp`, `commission_on_products`) viraram regras na migration da Fase 7 e **deixaram de
existir**: duas fontes da mesma regra divergiriam. As colunas de comissão de **assinatura** do profissional
viraram regras (alvo "atendimento de assinante") na migration da Fase 9 e também saíram.

**Sobre o que:**

| Alvo | Base | Tipos aceitos |
|---|---|---|
| Serviços (e combos) | valor do item **depois** da sua parte do desconto (§3) | percentual, valor fixo por unidade, sem comissão |
| Produtos vendidos | valor do item (desconto não incide em produto) | percentual, sem comissão |
| Atendimento de assinante (Fase 9, D-46) | **preço de tabela** do serviço coberto pela assinatura (o cliente paga R$ 0) | percentual, valor fixo **por atendimento**, sem comissão |

**Precedência** (a mais específica vence):

```text
Serviço:  profissional + serviço  >  serviço (todos os profissionais)  >  profissional (todos os serviços)  >  padrão da barbearia  >  nenhuma
Produto:  profissional  >  padrão da barbearia  >  nenhuma
Assinante (serviço coberto):  profissional  >  padrão da barbearia  >  regra normal do serviço sobre o preço de tabela
```

- "Sem comissão" é uma regra: vence as mais gerais (ex.: "Lavagem não paga comissão", mesmo que o
  profissional tenha 40%).
- Sem regra nenhuma: comissão zero.
- Percentual entre 0% e 100% (pontos-base, 4000 = 40%); valor fixo entre R$ 0,00 e R$ 100.000,00, só para
  serviço. O valor fixo não depende do desconto e pode ser maior que a base (como no sistema antigo).

**Versionadas, nunca editadas:** mudar uma regra **encerra** a que está em vigor (`ends_at`) e cria outra
(`starts_at`), na mesma transação. A sentinela `current_scope` (única) garante uma regra em vigor por escopo;
duas pessoas mudando a mesma regra ao mesmo tempo: a segunda recebe "outra pessoa acabou de alterar". Regra
encerrada não muda mais; nenhuma regra é apagada. Cada mudança vai para a auditoria (`commission.rule_set`,
`commission.rule_cleared`) com antes, depois e motivo.

**Quem configura:** só o proprietário (`commissions.configure`). Ver §8.

## 3. Cálculo (na conclusão do atendimento)

Dentro da **mesma transação** da conclusão (`AttendanceService::complete`, passo 7): se o cálculo ou a
gravação falhar, nada do atendimento é gravado (pagamento, caixa, estoque, agendamento). Testado com uma
falha real (`test_falha_ao_registrar_a_comissao_desfaz_a_conclusao_inteira`).

1. **Quem recebe:** o profissional que **efetivamente** atendeu (`attendances.professional_id`; pode ter
   sido trocado durante o atendimento).
2. **Valores congelados:** itens e desconto total do atendimento concluído; nunca o preço atual do catálogo.
3. **Rateio do desconto** entre os serviços pelo **maior resto**: cada serviço recebe a parte proporcional
   ao seu valor, arredondada para baixo; os centavos que sobram vão, um a um, para a maior fração
   descartada (empate: o primeiro item). A soma das partes é exatamente o desconto; nenhuma parte passa do
   valor do item. Ex.: desconto de R$ 10,01 sobre Corte R$ 50,00 + Barba R$ 30,00 → R$ 6,26 e R$ 3,75.
4. **Regra:** a que vale no **instante da conclusão** para aquele profissional e item (§2).
5. **Valor:** percentual = base × taxa, meio centavo para cima (`Money::percentOf`, o mesmo arredondamento
   dos descontos); fixo = valor × quantidade; sem regra = 0.
6. **Um lançamento por item**, mesmo quando dá zero (o histórico diz por quê: "Sem comissão", "Sem regra de
   comissão para este item"). Índice único em `commission_entries.attendance_item_id`.

Exemplo (testado em `test_integracao_atendimento_pagamento_comissao_gorjeta_devido_repasse`): Corte R$ 50,00
+ Barba R$ 30,00 + Pomada R$ 35,00, desconto de 10% nos serviços, João com 40% em serviços e 10% em
produtos → bases R$ 45,00, R$ 27,00 e R$ 35,00 → comissões R$ 18,00, R$ 10,80 e R$ 3,50.

## 4. O lançamento (histórico, não referência)

`commission_entries` é **só inclusão** (`AppendOnly`): depois de gravado, só o vínculo com o repasse muda.

| Dado | Coluna | Por quê |
|---|---|---|
| Tipo | `kind`: `earned` (calculada), `refund` (estorno), `adjustment` (ajuste) | Corrigir = lançar, nunca editar |
| Profissional, atendimento, item, pagamento de origem | `professional_id`, `attendance_id`, `attendance_item_id`, `payment_id` | Rastreabilidade |
| Regra usada | `commission_rule_id` + `rule` (fotografia: escopo, tipo, taxa, valor fixo, descrição) | A regra pode mudar depois; o lançamento diz qual valeu |
| Nome do item, quantidade, base, taxa, valor | `item_name`, `quantity`, `base_cents`, `rate_bp`, `amount_cents` | Valor do dia, nunca recalculado |
| Quando, quem, motivo, chave | `occurred_at`, `created_by_user_id`, `reason`, `request_key` | Auditoria e idempotência |
| Repasse | `commission_payout_id` | Nulo = em aberto |

**Regra fundamental (pedida pelo dono):** nenhum valor já lançado muda porque alguém alterou depois o
preço do serviço, o percentual, o profissional, o nome ou qualquer configuração. Testado em
`test_mudar_regra_preco_e_nome_depois_nao_altera_a_comissao_calculada` (o registro inteiro é comparado
antes e depois).

## 5. Estorno de pagamento (decisão do dono, D-35)

Quem estorna informa **quanto do estorno é gorjeta** (o formulário sugere a gorjeta ainda não estornada):

- a parte da gorjeta vira **gorjeta negativa** (ver [repasses.md §3](repasses.md));
- o resto reduz a comissão **na mesma proporção** do valor estornado sobre o total cobrado:
  `alvo = round(comissão calculada × estornado acumulado ÷ total)`, meio centavo para cima, e o lançamento
  novo é a diferença para o que já foi revertido. Calcular sobre o **acumulado** faz vários estornos
  parciais somarem exatamente o mesmo que um estorno único, e o estorno total zerar a comissão.
- O estorno de pagamento passou a separar, como o pagamento, a parte do serviço/produto (`amount_cents`)
  da parte da gorjeta (`tip_cents`).
- Estorno depois do repasse: o lançamento negativo fica **em aberto** e é descontado no próximo repasse.

## 6. Correção manual

`ProfessionalLedger::adjust`: comissão ou gorjeta, a favor ou contra o profissional, com **motivo**,
autor, chave (repetir o envio não duplica) e, opcionalmente, o atendimento (que precisa ser do mesmo
profissional e estar concluído). Auditoria `commission.adjusted` / `tip.adjusted`. Permissão
`commissions.correct`.

## 7. Telas

| Tela | URL | Permissão |
|---|---|---|
| Saldo em aberto de cada profissional | `/painel/comissoes` | `commissions.view` |
| Extrato do profissional (mês a mês: comissões, gorjetas, vales, repasses) | `/painel/comissoes/profissionais/{id}?mes=AAAA-MM` | `commissions.view` (todos) ou `commissions.view_own` (só o próprio; outro = 404) |
| Meu extrato (atalho do profissional) | `/painel/minhas-comissoes` | `commissions.view_own` |
| Regras em vigor e definir/encerrar regra | `/painel/comissoes/regras` | `commissions.configure` |
| Histórico (todas as versões de regra, correções, repasses estornados) | `/painel/comissoes/historico` | `commissions.history` |
| Ajuste (modal no extrato) | — | `commissions.correct` |

## 8. Permissões

| Habilidade | Proprietário | Gerente | Financeiro | Recepção | Profissional |
|---|---|---|---|---|---|
| `commissions.view` | ✓ | ✓ | ✓ | | |
| `commissions.view_own` | | | | | ✓ |
| `commissions.configure` | ✓ | | | | |
| `commissions.correct` | ✓ | | ✓ | | |
| `commissions.history` | ✓ | ✓ | ✓ | | |

Nenhum acesso administrativo amplo: ver não dá direito a configurar, corrigir ou pagar. Matriz completa em
[papeis-permissoes.md](papeis-permissoes.md).

## 9. Fora desta fase

- Comissão de atendimento de **assinatura** (o sistema antigo tinha modo padrão/percentual/fixo/nenhuma):
  Fase 9, junto das assinaturas; as colunas `subscription_commission_*` do profissional viram regras lá.
- Meta diária do profissional, DRE, relatórios gerais: fases seguintes.
- Taxa da maquininha descontada da comissão/gorjeta: não existe (o sistema antigo também não descontava);
  **PRECISA DE DECISÃO** se o dono quiser (relatório da Fase 7).

## 10. Atendimento de assinante (Fase 9)

Decisão do dono (D-46), como no sistema antigo: o serviço **coberto pela assinatura** (sai de graça para o
cliente) tem comissão sobre o **preço de tabela** do item. Regra de assinante do profissional (ou padrão da
barbearia): percentual sobre a tabela, valor fixo **por atendimento** (lançado no primeiro serviço coberto;
os outros cobertos ficam com zero e a observação) ou sem comissão. Sem regra de assinante, vale a regra normal
do serviço sobre o preço de tabela (o "padrão" do sistema antigo). O desconto da assinatura é atribuído só aos
serviços cobertos; os outros itens do mesmo atendimento seguem a regra normal sobre o valor cobrado. A
mensalidade da assinatura nunca gera comissão. O lançamento guarda a observação "Serviço coberto pela
assinatura: comissão sobre o preço de tabela". Testes: `SubscriptionBenefitTest`.
