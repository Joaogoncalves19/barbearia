# Relatório da Fase 9 — Assinaturas

> **Status: concluída, aguardando aprovação do dono para a Fase 10.** Branch `claude/fase-9-assinaturas`,
> criada a partir de `claude/fase-8-promocoes-fidelidade`. Só dados fictícios; nenhum banco real; nenhuma
> chave do Stripe (real ou de teste) no repositório, no banco ou no CI; nenhuma chamada real ao Stripe (todas
> simuladas); nenhuma migração real; sistema antigo não alterado (lido só como fonte das regras).
>
> Legenda: **PASSOU** · **NÃO EXECUTADO** · **PENDENTE** · **FALHOU** · **DECISÕES NECESSÁRIAS**.

## 1. Escopo e critérios

| Pedido | Situação | Evidência |
|---|---|---|
| Cliente Stripe, assinatura, plano, status, período, cancelamento, renovação, eventos, pagamentos, falhas, webhooks | **PASSOU** | [assinaturas.md](assinaturas.md), [stripe.md](stripe.md), [webhooks.md](webhooks.md) |
| Histórico local próprio; IDs do Stripe preservados e nunca substituídos | **PASSOU** | `subscription_events`, `subscription_payments`, `gateway_events`; o model recusa trocar `gateway_subscription_id` |
| Webhook: assinatura, idempotência, evento guardado, processado/identificado, transação, replay, fora de ordem, reprocessamento | **PASSOU** | `WebhookTest` (11), `SubscriptionLifecycleTest` (14), `WebhookConcurrencyTest` (2, processos reais) |
| Webhook duplicado nunca gera duas cobranças, registros, ativações ou benefícios | **PASSOU** | `test_evento_guardado_uma_vez_e_reentrega_nao_duplica`, `test_mesma_fatura_em_outro_evento...`, concorrência |
| Estados definidos, só os necessários, transições documentadas; nenhuma requisição muda o estado direto | **PASSOU** | 6 estados ([assinaturas.md §3](assinaturas.md#3-estados-e-transições)); `canTransitionTo` no model; `test_transicao_direta_proibida_no_model` |
| Status separado do direito ao benefício; início e fim documentados | **PASSOU** | `SubscriptionBenefits`; [assinaturas.md §4](assinaturas.md#4-direito-ao-benefício) |
| Cancelamento imediato, no fim do período, reativação, histórico, data efetiva, origem; nunca apagar | **PASSOU** | [cancelamentos.md](cancelamentos.md); `SubscriptionActionsTest` |
| Falhas: recusado, pendente, atrasado, vencido, recuperado, cancelado por inadimplência; sem revogar por evento fora de ordem | **PASSOU** | `test_falha_de_pagamento_atraso_e_recuperacao...`, `test_pagamento_pendente...`, `test_inadimplencia_final...`, `test_fotografia_antiga...` |
| Planos: preço, periodicidade, status, benefícios, ID externo, versão; preço novo não reescreve o antigo | **PASSOU** | [planos.md](planos.md); `PlansTest` (7) |
| Benefícios documentados (serviços, desconto, limite, periodicidade, validade, acúmulo, cupom, fidelidade, vale-presente, aniversário) | **PASSOU** | [beneficios.md](beneficios.md); `SubscriptionBenefitTest` (13) |
| Financeiro separado (caixa, comissão, gorjeta, atendimento) | **PASSOU** | `test_assinatura_nao_mexe_no_caixa_nem_na_comissao`; [pagamentos.md §8](pagamentos.md#8-pagamentos-de-assinatura-fase-9) |
| Reembolso sem apagar cobrança; parcial | **PASSOU** | [reembolsos.md](reembolsos.md) |
| Permissões específicas; ninguém vê o segredo do Stripe | **PASSOU** | §5; `test_quem_acessa_o_que`, `test_resposta_e_tela_nunca_mostram_a_chave` |
| Segredos só em variável de ambiente; nada no log | **PASSOU** | [stripe.md §1](stripe.md#1-configuração-só-variável-de-ambiente) |
| Migração preparada (IDs, histórico, cancelamentos, pagamentos, situação), sem migração real | **PASSOU** | `ImportScenariosTest::test_assinatura_importada_e_reconhecida_pelos_eventos_do_stripe` |
| Ciclo completo em **modo teste do Stripe** (critério do roadmap) | **PENDENTE** | Depende das chaves de teste e de um endpoint de homologação do dono (§9) |
| Testes, PHPStan, Pint, build, navegador, CI | ver §8 e §9 | |

**Não implementado, conforme o briefing:** e-mail/campanhas, chatbot, site público, migração real,
relatórios financeiros completos, DRE, metas.

## 2. Decisões

**Do dono (início da fase):**

| # | Decisão |
|---|---|
| D-03 | Assinaturas mantidas, com Stripe |
| D-44 | Serviços incluídos saem de graça, sem limite de uso (como hoje) |
| D-45 | Benefício no motor único: um desconto só, vale o maior |
| D-46 | Comissão do serviço coberto sobre o preço de tabela, com a regra de assinante do profissional |
| D-47 | Adesão como hoje, no agendamento (Stripe Checkout), e por link de pagamento gerado no painel; ativa quando o Stripe confirma |

**Técnicas:**

| # | Decisão | Motivo |
|---|---|---|
| T9-01 | Adaptador HTTP próprio para o Stripe (versão fixada, idempotência) em vez do SDK | Poucas chamadas, testável sem rede, sem dependência nova ([stripe.md §2](stripe.md#2-chamadas-à-api)) |
| T9-02 | Estado × direito: direito pela data paga; só fatura paga estende | Evento fora de ordem nunca revoga nem concede indevidamente |
| T9-03 | Fotografia do Stripe só se for mais nova; direito só aumenta | "Não confiar na ordem" |
| T9-04 | `subscription_payments` só com pagamento recebido (imutável); falhas no histórico | Pagamento nunca muda de valor nem de situação |
| T9-05 | Preço por versão do plano, enviado ao Stripe como `price_data` na adesão | Preço antigo nunca reescrito; nada para sincronizar no Stripe |
| T9-06 | Webhook fora do grupo web (sem sessão/CSRF), limite próprio | O Stripe autentica pela assinatura do corpo |
| T9-07 | Ação da equipe vai primeiro ao Stripe e consolida a resposta | Se o Stripe recusar, nada muda aqui |
| T9-08 | Reembolso reserva o valor antes de chamar o Stripe | Dois reembolsos simultâneos nunca passam do pago, sem segurar a trava durante a rede |
| T9-09 | Após a adesão no agendamento, o cliente vai para a página interna e paga pelo botão (link) | Não abrir a CSP `form-action` para o domínio do Stripe |
| T9-10 | Comissões de assinante do cadastro do profissional viraram regras versionadas | Fonte única (como na Fase 7) |

**DECISÕES NECESSÁRIAS (PRECISA DE DECISÃO)** — implementadas da forma mais conservadora, detalhes em
[assinaturas.md §10](assinaturas.md#10-precisa-de-decisão):

| # | Ponto |
|---|---|
| P9-01 | Serviços não incluídos agendados junto da adesão: cobrados na barbearia (o antigo cobrava online) |
| P9-02 | Adesão sem pagamento concluído: o agendamento continua pelo valor normal (o antigo escondia) |
| P9-03 | Combo parcialmente coberto: só sai de graça se incluído no plano (revisar planos importados) |
| P9-04 | Troca de plano não suportada (cancelar e assinar o novo) |
| P9-05 | Reembolso não encerra o benefício |
| P9-06 | Valor fixo de comissão de assinante: por atendimento; serviços não incluídos seguem a regra normal |
| P9-07 | Tolerância de 1 dia vale para o benefício da assinatura ativa |
| P9-08 | Link enviado por cópia e na conta do cliente (e-mail na Fase 10) |
| P9-09 | Pausar assinatura não oferecido (`paused` = em atraso) |
| P9-10 | Prazo de retenção dos eventos do Stripe (contêm dados do cliente) |

## 3. Arquitetura

```text
Adesão (agendamento ou link) ── SubscriptionCheckout ── assinatura "aguardando pagamento" + sessão no Stripe
                                                          │
Stripe ── webhook assinado ── StripeWebhook (guarda 1x, transação, trava) ── SubscriptionLifecycle
                                                          │   fotografia mais nova → estado
                                                          │   fatura paga → pagamento (1x) + direito (só aumenta)
                                                          │   reembolso (1x)
Equipe/cliente ── SubscriptionManager ── Stripe primeiro ─┘   cancelar, reativar, reembolsar, expirar
Benefício ── SubscriptionBenefits (direito na data) ── PromotionEngine (vale o maior) ── agendamento/atendimento
Comissão ── CommissionCalculator (serviço coberto: tabela; regra de assinante)
```

Migration `2026_10_05_000100_create_subscription_engine_tables` (sem editar as anteriores; subir, descer e
subir testado em banco temporário): `plan_versions`, `plan_version_services`, `subscription_events`,
`subscription_refunds`; colunas novas em `subscriptions`, `subscription_payments`, `gateway_events`,
`attendance_discounts`; `plan_services`, `plans.price_cents` e `professionals.subscription_commission_*`
convertidos e removidos.

## 4. Revisões

| Revisão | Resultado |
|---|---|
| Segurança | Segredos só em variável de ambiente (vazias no `.env.example`); nenhuma tela mostra as chaves (testado); log sem chave nem dados do cliente (só caminho, status e código do erro); URLs de retorno do `APP_URL` (S-19); webhook sem sessão nem CSRF, com assinatura e janela; corpo do evento guardado no banco (dados pessoais: P9-10) |
| Webhooks | Assinatura, janela de 5 min, evento único, transação com reivindicação, 500 e reenvio na falha, `stale` para fotografia antiga, `unmatched` reprocessado no vínculo, comando de reprocessamento |
| Idempotência | Chave em toda escrita no Stripe; pagamento por fatura, reembolso por ID e por chave do pedido, evento por ID, link repetido devolve o mesmo (corrigido nesta revisão: o duplo clique criava uma segunda sessão) |
| Concorrência | Trava da assinatura (`version`) em todo processamento; sentinela de uma vigente por cliente; reserva do reembolso; processos reais (§7) |
| Permissões | 8 habilidades por ação; rota com `can:` e `throttle`; cliente só a própria; matriz testada |
| Auditoria | `plan.created`, `plan.versioned`, `plan.activated/deactivated`, `subscription.link_created`, `subscription.cancel_scheduled`, `subscription.cancelled`, `subscription.reactivated`, `subscription.refunded`, `subscription.refund_failed`; histórico próprio (`subscription_events`) com origem, ator, motivo e data efetiva |
| Integridade | 4 regras novas (R43 plano/versão, R44 direito/estado, R45 pagamento/reembolso, R46 evento) e R18/R19 revistas; teste grava por fora e confirma que o verificador acusa |

## 5. Permissões

| Habilidade | Proprietário | Gerente | Recepção | Financeiro | Profissional |
|---|:-:|:-:|:-:|:-:|:-:|
| `subscriptions.view` | ✓ | ✓ | ✓ | ✓ | |
| `subscriptions.payments` | ✓ | ✓ | | ✓ | |
| `subscriptions.create` (link) | ✓ | ✓ | ✓ | | |
| `subscriptions.cancel` | ✓ | ✓ | | | |
| `subscriptions.reactivate` | ✓ | ✓ | | | |
| `subscriptions.refund` | ✓ | | | ✓ | |
| `subscriptions.history` | ✓ | ✓ | | ✓ | |
| `plans.manage` | ✓ | | | | |

## 6. Regras do sistema antigo portadas (`tests/assinaturas.php`)

Benefício só com direito na data e no próprio dia do vencimento; tolerância de 1 dia; expirar após a
tolerância e de forma idempotente; renovação emenda no fim (nunca encurta); cancelamento agendado mantém o
benefício; reativação; em atraso mantém o benefício pago; cancelada com período vencido perde o benefício;
fim do período lido dos itens (formato novo); mesmo pagamento não duplica; evento reenviado recusado; adesão
reprocessada não encurta; uma assinatura vigente por cliente; comissão de assinante sobre a tabela
(padrão, percentual, fixo, nenhuma); MRR não conta cancelamento agendado. Todos cobertos pelos testes novos.

## 7. Concorrência

| Cenário | Resultado |
|---|---|
| O mesmo evento entregue por 4 processos PHP ao mesmo tempo | 1 aplicado, 3 duplicados; 1 pagamento; direito estendido uma vez |
| 5 eventos diferentes da mesma assinatura ao mesmo tempo (fatura paga, a mesma fatura por outro evento, fotografia nova de cancelamento, fotografia velha, falha velha) | 1 pagamento; direito estendido; estado final = fotografia mais nova (cancelamento agendado); nenhum evento falho; verificador limpo |
| Duplo clique em cancelar, reembolsar, gerar link | uma operação (chaves de idempotência, reserva, link devolvido) |

Contraprova no MySQL: **NÃO EXECUTADO** (D-02).

## 8. Testes

| Verificação | Resultado |
|---|---|
| PHPUnit | **PASSOU** — 670 testes (eram 607), 0 falhas, 0 pulados |
| — novos na Fase 9 | `Subscriptions/WebhookTest` (11), `SubscriptionLifecycleTest` (14), `SubscriptionBenefitTest` (13), `SubscriptionActionsTest` (15), `PlansTest` (7), `WebhookConcurrencyTest` (2), `ImportScenariosTest::test_assinatura_importada_e_reconhecida_pelos_eventos_do_stripe` |
| — ajustados (regra mudou, nenhum desativado) | `SchemaTest` e `ImporterTestCase` (tabelas novas), `PermissionMatrixTest` (8 habilidades), `RouteAuthorizationTest` (webhook é público com assinatura; limite `throttle:webhooks`), `ImportScenariosTest` (regra de assinante importada) |
| Larastan nível 6 | **PASSOU** — 0 erros |
| Pint | **PASSOU** |
| Build (Vite) | **PASSOU** |
| Migration (subir, descer e subir) | **PASSOU** (banco temporário) |
| Playwright + axe | **PASSOU** — 99 passando (eram 95; +4 da Fase 9, celular e desktop), 3 ignorados de propósito (os mesmos das fases anteriores), em **duas execuções seguidas** num banco SQLite novo, como no CI. O ajuste do duplo clique em "Gerar link" (§4) veio depois dessas execuções; ele só roda com o Stripe configurado, que o navegador não usa; a suíte PHP inteira foi repetida depois dele (670, verde) |
| — novos na Fase 9 | `assinaturas.spec.js` (celular e desktop): dono cria plano (com erro de validação), consulta assinaturas, a assinatura e os eventos; assinante agenda o serviço incluído e vê R$ 0,00 na confirmação e no agendamento; vê a própria assinatura. Axe e rolagem lateral em 6 telas |
| Ciclo em modo teste do Stripe | **PENDENTE** (§1) |

## 9. CI

**PENDENTE** — preenchido depois do push (ver o commit seguinte).

## 10. Problemas encontrados

1. **Heredoc do shell perdendo barras** em scripts de edição (já registrado): os scripts passaram a ser
   gravados como arquivo antes de rodar.
2. **Diretiva Blade colada numa palavra** (`pago@endif`) na tela da assinatura: reescrita em linhas
   separadas; pega pelo teste.
3. **`LIKE` com escape no SQLite** (reprocessar eventos sem vínculo não achava o evento): sem escape.
4. **Duplo clique em "Gerar link"** criava uma segunda sessão no Stripe (expirando a primeira): agora devolve o
   mesmo link (§4).
5. **Coluna removida no verificador** (R18 lia a comissão de assinante do profissional; no SQLite, coluna
   inexistente vira texto e contava todos): R18 passou a ler só as regras.
6. **Título repetido** ("Assinaturas" no título da página e no cartão) confundia leitores de tela e o teste de
   navegador: o cartão virou "Lista de assinaturas".

## 11. Pendências

1. **P9-01 a P9-10** (§2): decisões do dono.
2. **Ciclo completo em modo teste do Stripe**: o dono cria as chaves de teste e o endpoint de webhook de teste
   (homologação) com os eventos de [webhooks.md §1](webhooks.md#1-eventos-usados).
3. **Envio do link por e-mail**: Fase 10 (D-05).
4. **Receita de assinaturas nos relatórios financeiros**: fora desta fase (relatórios completos).
5. **Concorrência no MySQL**: D-02.
6. **Banco local de desenvolvimento**: repovoar com `php artisan db:seed` dentro de `novo-sistema`.

## 12. Documentação

Novos: [assinaturas.md](assinaturas.md), [planos.md](planos.md), [beneficios.md](beneficios.md),
[stripe.md](stripe.md), [webhooks.md](webhooks.md), [cancelamentos.md](cancelamentos.md),
[reembolsos.md](reembolsos.md). Atualizados: [pagamentos.md](pagamentos.md), [comissoes.md](comissoes.md),
[fidelidade.md](fidelidade.md), [promocoes.md](promocoes.md), [papeis-permissoes.md](papeis-permissoes.md),
[modelo-dados.md](modelo-dados.md) (seção 2.8 e apêndice regenerado, 65 tabelas), [regras-dados.md](regras-dados.md)
(regras 79 a 84), [mapa-banco-antigo-novo.md](mapa-banco-antigo-novo.md), [decisoes-pendentes.md](decisoes-pendentes.md)
(D-03, D-44 a D-47), [roadmap.md](roadmap.md), [README.md](README.md).

## 13. Riscos

| Risco | Mitigação |
|---|---|
| Cobrança duplicada na virada (dois sistemas recebendo webhooks) | Webhook só aponta para o novo sistema na Fase 13; eventos do antigo importados como processados |
| Endpoint do Stripe com eventos faltando | Lista exata em [webhooks.md §1](webhooks.md#1-eventos-usados); evento sem vínculo fica guardado e é reprocessado |
| Relógio do servidor fora (janela de 5 min) | Recusa com 400 e o Stripe reenvia; monitorar a hora do servidor |
| Webhook perdido | O Stripe reenvia por dias; rotina diária e reprocessamento manual |
| SQLite × MySQL | Travas por escrita na linha nos dois; repetir a concorrência no banco escolhido |

---

**Aguardando aprovação explícita do dono para a Fase 10.**
