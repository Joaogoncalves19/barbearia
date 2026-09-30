# Preços (Fase 4)

## 1. Dois preços, dois papéis

```text
Preço ATUAL do serviço (services.price_cents)
        │   editável pela gestão (permissão services.price), auditado
        ▼
usado quando um agendamento NOVO é criado (Fase 5)
        │   copiado UMA vez para o item do agendamento
        ▼
Preço REGISTRADO no agendamento/atendimento (appointment_items.unit_price_cents / total_cents)
            histórico IMUTÁVEL: não muda quando o preço atual muda
```

| | Preço atual | Preço histórico (registrado) |
|---|---|---|
| Onde | `services.price_cents` | `appointment_items.unit_price_cents`, `total_cents` (+ `price_source`) |
| Muda? | Sim, pela gestão | **Nunca**; correção é por ajuste/estorno (Fase 6) |
| Vale para | Agendamentos **novos** | O agendamento em que foi gravado |
| Usado por | Orçamento de um agendamento novo | Relatórios, comissão, caixa, recibo |

Tudo em **centavos inteiros** (`Money`, sem float). A digitação aceita "45", "45,00", "1.250,90" e "R$ 30,00".
O valor volta ao formulário como "45,00" (`Money::toInput`, inverso exato de `Money::parse`).

Essa separação já existia no modelo da Fase 2 (regra 19 de [regras-dados.md](regras-dados.md)). A Fase 4 não
copia valores em outros lugares, só garante que o preço atual é alterado de um jeito só (`ServiceAdmin`) e
auditado. Prova: `ServiceAdminTest::test_alterar_o_preco_atual_nao_altera_agendamentos_existentes`.

## 2. Histórico de preços: decisão

**Não foi criada uma tabela de histórico de preços.** Motivos:

| Necessidade | Já atendida por |
|---|---|
| Valor cobrado em cada atendimento (relatórios, comissão, recibo) | Snapshot no item do agendamento, que é a fonte que os relatórios devem usar |
| Quem mudou o preço, quando, de quanto para quanto (auditoria) | `audit_logs`: a trait `Auditable` grava `price_cents` antes/depois, o autor, a data e o IP |
| Consultar os preços anteriores de um serviço | Tela "Histórico de preço" na edição do serviço, lida da trilha de auditoria (`ServiceAdmin::priceHistory`) |
| Reconstruir decisões ("por que este atendimento custou X?") | Snapshot no item + origem do preço (`price_source`) + auditoria da alteração |

Uma tabela a mais repetiria a auditoria e criaria uma segunda "verdade" sobre preço. **Reavaliar** se surgir
necessidade de **preço agendado** (ex.: "a partir de 1º/12 o corte custa R$ 60"): aí sim uma tabela de
vigência faria sentido.

**Limitação conhecida:** serviços importados do sistema antigo não têm histórico anterior à importação. A
tela diz "Sem alterações registradas".

## 3. Regras

- Preço atual entre **R$ 1,00 e R$ 10.000,00** (`Service::MIN_PRICE_CENTS`/`MAX_PRICE_CENTS`), no formulário e
  no model. Serviço sem preço válido não é gravado.
- Alterar preço exige a permissão **`services.price`**. Quem pode editar o serviço mas não o preço vê o valor
  sem campo. Se o campo for enviado mesmo assim (formulário adulterado) com valor diferente, a resposta é
  **403** e nada é gravado.
- Criar um serviço já define o preço inicial (permissão `services.create`).
- Dois administradores alterando o preço ao mesmo tempo: o segundo recebe aviso e nada sobrescreve em
  silêncio (`lock_version`).
- Ao salvar, a mensagem lembra: "vale para agendamentos novos; os já feitos mantêm o valor registrado".

## 4. Combos

`packages.price_cents` segue a mesma separação (preço atual × snapshot no item). A duração do combo é a soma
das durações dos serviços (`Package::durationMinutes`), sem valor próprio. Não há tela de combos nesta fase.
