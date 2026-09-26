# Estratégia de duplicidades

> Regra principal: **nada é mesclado automaticamente.** Duplicidade é detectada, registrada e resolvida por
> uma pessoa (decisão D-21). Uma mesclagem errada junta o histórico, os pontos e o login de duas pessoas
> diferentes e não tem volta limpa. Uma duplicidade pendente só incomoda.

## 1. Onde pode haver duplicidade no sistema antigo

| Dado | Por que existe no antigo | Tratamento na importação |
|---|---|---|
| Cliente com mesmo e-mail (inclusive só por maiúsculas/espaços) | Índice único condicional, criado só se não houvesse duplicata (B-08); cadastro pelo chatbot e pela recepção | O **primeiro cadastrado** (ordem de inserção) fica com o e-mail; nos seguintes o campo fica vazio e o par vai para `customer_merge_candidates` com o valor original |
| Cliente com mesmo telefone (formatos diferentes) | Telefone guardado como digitado | Normalizado para E.164 antes de comparar; mesmo tratamento |
| Cliente com mesmo CPF | Idem | Mesmo tratamento |
| Código de indicação repetido | Sem índice único | O segundo fica sem código (pendência); não é duplicidade de pessoa |
| Usuário de login repetido (admin × barbeiro, maiúsculas) | `barbeiros.username` sem único; `users.username` sensível a maiúsculas | O segundo fica **sem usuário e inativo** (ninguém herda o acesso do outro) |
| Código de cupom / vale repetido | Sem índice único (B-07) | Importa o primeiro; os demais ficam fora, com pendência e a linha completa no contexto |
| Expediente repetido no mesmo dia | Tabela sem chave (B-06) | Importa o primeiro (o sistema atual só lê o primeiro) |
| Bloqueio de horário repetido | Tabela sem chave | Importa um |
| Uso de cupom repetido pelo mesmo cliente | Tabela sem chave | Importa um (regra atual: um uso por cliente) |
| Duas avaliações do mesmo agendamento | Sem único | As duas entram; a segunda fica sem o vínculo com o agendamento |
| Referência de pagamento de assinatura repetida | Sem único | Gravada só no primeiro pagamento |
| ID de assinatura do gateway em dois clientes | — | Copiado só no primeiro; pendência de severidade **erro** (conferir no Stripe) |
| Dois agendamentos futuros no mesmo horário do profissional | Corrida no agendamento antigo | Os dois entram; pendência bloqueante `future_overlap` para a recepção resolver antes da virada |

## 2. Por que "o primeiro fica com o dado"

- É determinístico (ordem de inserção no SQLite) e se repete igual em todos os ensaios.
- Não apaga nada: o valor retirado do segundo cadastro fica guardado em `customer_merge_candidates.match_value`
  e na pendência.
- O segundo cadastro continua existindo com todo o seu histórico (agendamentos, pontos, assinatura).
- **Consequência:** o segundo cliente pode ficar sem e-mail de login até a decisão. Por isso a lista de
  duplicidades deve ser revisada **antes da virada** (é pequena na maioria das barbearias).

## 3. Estrutura

`customer_merge_candidates`: `customer_id` (quem ficou com o dado), `duplicate_customer_id`, `match_field`
(`email`/`phone`/`cpf`/`referral_code`), `match_value`, `status` (`pending`/`merged`/`dismissed`),
`import_run_id`, `notes`, `resolved_by_user_id`, `resolved_at`. Único por par e campo.

Depois da virada, o mesmo mecanismo serve para duplicidades criadas no dia a dia: o `DuplicateCustomerFinder`
detecta conflitos por e-mail, telefone e CPF normalizados e **só informa**; a tela de cadastro (Fase 7) usa isso
para avisar "já existe um cliente com este telefone".

## 4. Mesclagem assistida (a implementar na Fase 7, com aprovação do dono)

Proposta, a confirmar em D-21:

1. A tela mostra os dois cadastros lado a lado, com agendamentos, pontos, assinatura e consentimento.
2. A pessoa escolhe qual cadastro fica e, campo a campo, qual valor fica.
3. Numa transação: agendamentos, pagamentos, avaliações, notas e lançamentos de pontos passam para o
   cadastro mantido; o outro recebe `merged_into_customer_id` e soft delete (não é apagado).
4. **Consentimento:** vale o mais restritivo (qualquer opt-out prevalece).
5. **Assinatura:** se os dois têm assinatura vigente, a mesclagem é bloqueada até resolver no gateway.
6. A mesclagem vai para a auditoria e o par fica `merged` com quem resolveu e quando.

## 5. Critérios que NÃO são usados para detectar duplicidade

Nome parecido, mesmo sobrenome ou mesma data de nascimento **não** geram pares: produziriam muitos falsos
positivos (família, xarás). Podem virar sugestão manual no futuro, nunca regra automática.
