# Planos de assinatura (Fase 9)

Serviço: `app/Modules/Subscriptions/Services/Plans.php`. Tela: **Assinaturas › Planos** (`plans.manage`, só o
proprietário, como as regras de comissão).

## 1. Plano e versões

| Onde | O quê |
|---|---|
| `plans` | Nome, descrição, aberto/fechado para novas adesões (nunca apagado) |
| `plan_versions` | Versão (1, 2, ...), preço em centavos, periodicidade (mensal), início e fim, motivo, quem criou. Imutável: só o encerramento muda. Uma versão atual por plano (sentinela `current_plan_id`) |
| `plan_version_services` | Serviços incluídos na versão |

- **Mudar preço ou serviços = nova versão**, com motivo. A anterior fica no histórico.
- **Quem já assina continua na versão contratada**: o benefício segue os serviços da versão dele e o Stripe
  cobra o preço fixado na adesão (o preço vai na sessão de pagamento como `price_data`, sem depender de um
  preço cadastrado no Stripe). Alteração de preço nunca reescreve assinaturas antigas.
- Fechar o plano para novas adesões não mexe em quem já assina.
- Periodicidade: só **mensal** (R-26; como no sistema antigo).
- Preço entre R$ 0,01 e R$ 100.000,00; ao menos um serviço incluído.
- Auditoria: `plan.created`, `plan.versioned` (antes e depois), `plan.activated`, `plan.deactivated`.

## 2. Importação

Plano do sistema antigo vira o plano com a versão 1 (preço e serviços do antigo); preço zero vira R$ 0,01 com
pendência para revisão; serviço inexistente fica de fora com pendência.

## 3. Testes

`PlansTest` (7): criar e versionar pelo painel; inválido recusado; versão imutável; assinatura não troca de
versão; plano fechado não aceita adesão; histórico; verificador. `SubscriptionLifecycleTest::test_versao_do_plano_nao_muda_quem_ja_assina`.
