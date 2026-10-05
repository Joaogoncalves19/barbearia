# Relatório da Fase 10 — Comunicação e avaliações

> **Status: concluída, aguardando aprovação explícita do dono para a Fase 11.** Branch
> `claude/fase-10-comunicacao`, criada a partir de `claude/fase-9-assinaturas`. Só dados fictícios; nenhum
> banco real; nenhum provedor de e-mail real (D-05 pendente: em desenvolvimento, testes e CI os e-mails ficam
> no log/memória); nenhuma credencial no código, no banco, no painel ou no log; nenhuma migração real;
> sistema antigo não alterado (lido só como fonte das regras).
>
> Legenda: **PASSOU** · **NÃO EXECUTADO** · **PENDENTE** · **FALHOU** · **DECISÕES NECESSÁRIAS**.

## 1. Escopo e critérios

| Pedido | Situação | Evidência |
|---|---|---|
| Sistema central de e-mail: modelos, envio, fila, novas tentativas, falhas, log, preferências, descadastro | **PASSOU** | [emails.md](emails.md), [templates.md](templates.md), [fila.md](fila.md); `OutboxTest` (11) |
| Assíncrono; nenhuma requisição espera o provedor | **PASSOU** | Job depois do commit, fila `emails`; `MAIL_TIMEOUT` (10 s) e `timeout` do job (60 s); `test_o_envio_espera_o_commit...`, `test_job_vai_para_a_fila...` |
| Modelos com a nova identidade | **PASSOU** (aprovação no provedor **PENDENTE**, D-05) | Layout da direção A; prévia de todos os modelos no painel; `test_previa_de_todos_os_modelos...` |
| Lembretes: horário, status, cancelamento, remarcação, preferências, descadastro; cancelado/remarcado nunca recebe o antigo; agendador duas vezes não duplica | **PASSOU** | [lembretes.md](lembretes.md); `RemindersTest` (9); `CommunicationConcurrencyTest` (4 processos) |
| Confirmação de presença | **PASSOU** | Link assinado com validade até o horário; GET mostra, POST confirma; adulterado/vencido = 403 |
| Assinatura: só o previsto (D-50), pelo estado consolidado, nunca só porque chegou webhook | **PASSOU** | `SubscriptionMessagesTest` (6): webhook repetido, recuperação antes do envio, cancelamento desfeito, renovação só aviso |
| Campanhas separadas do transacional; consentimento, descadastro, preferências, LGPD, limite de envio; nunca para quem saiu | **PASSOU** | [campanhas.md](campanhas.md); `CampaignsTest` (10); concorrência com 4 processos |
| Avaliações: base válida, sem duplicidade, só o próprio, moderação auditada, comentário nunca HTML (S-02) | **PASSOU** | [avaliacoes.md](avaliacoes.md); `ReviewsTest` (10) |
| Fila: retry, backoff, idempotência, falha definitiva, log de erro, reprocessamento; job duas vezes não reenvia | **PASSOU** | [fila.md](fila.md); `test_falha_volta_para_a_fila...`, `test_mesma_chave_nunca_enfileira...` |
| Preferências: transacional × marketing; descadastro de marketing não bloqueia o necessário | **PASSOU** | [consentimento.md](consentimento.md); `test_descadastro_de_marketing_nao_bloqueia_transacional...` |
| LGPD: respeitar o consentimento; "desconhecido" nunca vira "sim"; registrar mudanças | **PASSOU** | `test_salvar_sem_escolher_nao_inventa_consentimento`, `test_aceitar_e_recusar_marketing_com_prova...` |
| Notificações no app | **PASSOU** | Minha conta → Avisos; `test_avisos_so_do_proprio_cliente` |
| Retenção dos eventos do Stripe (P9-10) | **PASSOU** | Rotina diária, auditada, idempotente; R51; `test_retencao_dos_eventos_do_stripe_12_meses` |
| Campanha de teste entregue a uma lista-semente sem quem fez opt-out (critério do roadmap) | **PENDENTE** | Lógica testada (opt-out excluído em três pontos); entrega real depende do provedor (D-05) |
| E2E 4 (cliente avalia), 12 (lembrete uma vez + presença), 14 (campanha em lote com opt-out) | **PASSOU** (4 no navegador; 12 e 14 em testes HTTP) | `comunicacao.spec.js`; `RemindersTest`, `CampaignsTest` (o envio em lote é rotina do agendador, sem tela) |

**Não implementado, conforme o briefing:** migração real, troca definitiva, produção, chatbot, funcionalidades
fora do roadmap (site público, envio do link de assinatura por e-mail, WhatsApp/SMS).

## 2. Decisões

**Do dono (início da fase):**

| # | Decisão |
|---|---|
| D-48 | Avaliação só publicada depois da aprovação da equipe |
| D-49 | Pedido de avaliação por e-mail + aviso na conta; prazo de 30 dias |
| D-50 | E-mails de assinatura só na ativação, falha de pagamento e cancelamento; renovação só aviso na conta |
| D-51 | Lembretes como hoje: véspera a partir das 9h e 2 h antes, configuráveis, link de presença, e-mail + aviso |
| P9-10 | (aprovação da Fase 9) eventos do Stripe guardados 12 meses; depois, rotina auditável remove o corpo |

**Técnicas:**

| # | Decisão | Motivo |
|---|---|---|
| T10-01 | Registro central (`email_messages`) com modelo + parâmetros; corpo montado no envio | O e-mail reflete o estado atual (cancelado/remarcado não sai); nada sensível guardado |
| T10-02 | Chave de unicidade por acontecimento + reivindicação na entrega | No máximo um envio por registro, mesmo com job repetido ou dois workers |
| T10-03 | Job disparado depois do commit | Acontecimento desfeito não envia nada |
| T10-04 | Lembrete preso ao horário (`scheduled_for`) | Remarcar gera outro lembrete; o antigo nunca sai |
| T10-05 | Aviso de assinatura pedido na transição aplicada (`SubscriptionLifecycle::transition`) | Estado consolidado, nunca o webhook |
| T10-06 | Descadastro de um clique fora do grupo web, URL assinada | Leitores de e-mail não têm sessão/CSRF; nenhuma rota web perdeu o CSRF |
| T10-07 | Erro do provedor vira `EmailDeliveryFailed` com a mensagem limpa | O erro cru (usuário, servidor, senha) nunca chega ao log, ao `failed_jobs` nem ao registro |
| T10-08 | E-mails de conta/segurança continuam como notificações próprias (Fase 3) | Levam tokens; não podem ir para o registro central |
| T10-09 | `marketing.manage` substituída por 10 habilidades por ação | Disparar campanha separado de escrever; ver registro separado de reenviar |

**DECISÕES NECESSÁRIAS (PRECISA DE DECISÃO)** — implementadas da forma mais conservadora:

| # | Ponto | Implementado |
|---|---|---|
| D-05 | Provedor de e-mail | Pendente desde a Fase 1. Sem ele: entrega real, aprovação visual e devoluções automáticas ficam PENDENTES |
| P10-01 | Limite de frequência de campanhas por cliente | Não inventado; só o ritmo por minuto ([campanhas.md §7](campanhas.md#7-precisa-de-decisão)) |
| P10-02 | Clientes importados com consentimento "desconhecido" | Fora das campanhas até escolherem; pedir consentimento por e-mail precisa de decisão jurídica |
| P10-03 | Prazo de retenção do registro de e-mails e dos avisos | Guardados, sem expurgo; entra na política de retenção/LGPD (Fase 12) ([consentimento.md §5](consentimento.md#5-lgpd-e-retenção)) |
| P10-04 | Enviar o link de pagamento da assinatura por e-mail (P9-08) | Não pedido em D-50: continua por cópia e na conta do cliente |

## 3. Arquitetura

```text
BookingService / SubscriptionLifecycle / Receipts / Reminders / ReviewRequests / Campaigns
   └─ CustomerMessages (o que o domínio comunica) ── InAppNotifier (avisos na conta, chave única)
        └─ Outbox::queue (registro + chave única) ── job SendEmailMessage (depois do commit, fila "emails")
             └─ Outbox::deliver: reivindica → consentimento/supressão → modelo (estado atual) → CommunicationMail
Links públicos assinados: /presenca/{código} (BookingService::confirmPresence), /descadastro/{id} (+ um clique)
Agendador: lembretes 5 min · pedidos de avaliação 10 min · campanhas 1 min · retenção 03:30 · worker 1 min
```

Migration `2026_10_06_000100_create_communication_tables` (sem editar as anteriores; subir, descer e subir
testado em banco temporário): `email_messages`, `campaign_recipients`; colunas novas em `campaigns`,
`appointment_reminders` (único por horário, linhas existentes presas ao horário atual), `appointments`,
`customers`, `customer_notifications`, `reviews`, `review_replies` (único), `gateway_events`.

## 4. Revisões

| Revisão | Resultado |
|---|---|
| Segurança | Nenhuma credencial em código/banco/painel/log (SMTP só no ambiente; erro mascarado e encapsulado, T10-07; log global de falhas de fila também mascarado). Links públicos assinados (presença com validade; descadastro com identificador público, nunca id ou e-mail); GET nunca muda estado; adulterado = 403 (testado). Comentário, resposta e texto de campanha escapados (S-02, testado com `<script>`); assunto de campanha em uma linha (sem injeção de cabeçalho, testado). Prévia dos modelos só com dados fictícios. Registro mostra o e-mail mascarado. Novos limites: `email-links`, `email-actions`, `account-actions` |
| Fila | Depois do commit; 4 tentativas (1/5/15/60 min); falha definitiva com erro; reenvio manual auditado; worker processa `emails` antes de `default` |
| Idempotência | Chave única por acontecimento; reivindicação do registro; lembrete único por horário; destinatário único por campanha; aviso único; disparo por chave |
| Consentimento | Marketing: concedido + sem supressão, conferido no público, no lote e no envio; "desconhecido" nunca muda sozinho; prova em `consent_records` |
| Descadastro | Página (botão) e um clique (RFC 8058); não bloqueia transacional; repetir não registra de novo |
| Permissões | 10 habilidades por ação; rotas com `can:` e `throttle`; Policy de avaliação; matriz testada; menu só mostra o permitido (testado) |
| Auditoria | `review.submitted/approved/rejected/featured/unfeatured/replied`, `campaign.started/test_sent/cancelled` (e criação/edição pelo `Auditable`), `email.retried`, `communication.settings_changed`, `retention.gateway_events`; presença confirmada no histórico do agendamento |
| Integridade | 5 regras novas: R47 registro de e-mail, R48 campanha, R49 avaliação, R50 lembrete, R51 retenção do Stripe |

## 5. Permissões

| Habilidade | Proprietário | Gerente | Recepção | Financeiro | Profissional |
|---|:-:|:-:|:-:|:-:|:-:|
| `communications.view` | ✓ | ✓ | | | |
| `communications.retry` | ✓ | ✓ | | | |
| `communications.settings` | ✓ | | | | |
| `campaigns.view` | ✓ | ✓ | | | |
| `campaigns.manage` | ✓ | ✓ | | | |
| `campaigns.send` | ✓ | ✓ | | | |
| `reviews.view` | ✓ | ✓ | ✓ | | |
| `reviews.view_own` | | | | | ✓ |
| `reviews.moderate` | ✓ | ✓ | | | |
| `reviews.reply` | ✓ | ✓ | | | |

Cliente (`account.access`): avalia só o próprio atendimento concluído, vê só os próprios avisos e muda só as
próprias preferências.

## 6. Regras do sistema antigo portadas

Lembrete da véspera a partir das 9h e "horas antes" na janela, **só para agendamentos aprovados**, uma vez por
data/horário (`lib/agendamento_functions.php`); link de confirmar presença; avaliação de atendimento concluído
com destaque e resposta; campanhas por segmento (todos, ativos 90 dias, sem retorno, novos, aniversariantes,
assinantes, por profissional) com descadastro. Diferenças: avaliação agora passa por moderação (D-48);
"desconhecido" não recebe campanha (o antigo não distinguia).

## 7. Concorrência

| Cenário | Resultado |
|---|---|
| 4 processos rodando os lembretes ao mesmo tempo (3 agendamentos na janela) | 1 lembrete, 1 e-mail e 1 aviso por horário; verificador limpo |
| 4 processos entregando o lote da mesma campanha (12 destinatários) | 12 e-mails, 1 por cliente; campanha concluída; verificador limpo |
| Mesmo e-mail pedido duas vezes / job repetido | 1 registro, 1 envio |
| Duplo envio de avaliação | índice único: "já avaliado" |

Contraprova no MySQL: **NÃO EXECUTADO** (D-02).

## 8. Testes

| Verificação | Resultado |
|---|---|
| PHPUnit | **PASSOU** — 727 testes (eram 670), 0 falhas, 0 pulados |
| — novos na Fase 10 | `Communication/OutboxTest` (11), `RemindersTest` (9), `ReviewsTest` (10), `CampaignsTest` (10), `SubscriptionMessagesTest` (6), `PreferencesAndPanelTest` (8), `CommunicationConcurrencyTest` (2, processos reais); `RouteAuthorizationTest::test_links_dos_emails_exigem_assinatura` |
| — ajustados (regra mudou, nenhum desativado) | `ReceiptsTest` (comprovante agora pela fila central), `SchemaTest` e `ImporterTestCase` (tabelas e únicos novos), `PermissionMatrixTest` (10 habilidades), `RouteAuthorizationTest` (5 rotas públicas assinadas), `ImportScenariosTest` (avaliações importadas aprovadas; uma resposta por avaliação) |
| Larastan nível 6 | **PASSOU** — 0 erros |
| Pint | **PASSOU** |
| Build (Vite) | **PASSOU** |
| Migration (subir, descer e subir) | **PASSOU** (banco temporário) |
| Playwright + axe | **PASSOU** — 103 passando (eram 99; +4 da Fase 10, celular e desktop), 3 ignorados de propósito (os mesmos das fases anteriores), em **duas execuções seguidas** num banco SQLite novo, como no CI. Depois delas entraram só a limpeza do erro do provedor (T10-07) e o assunto de campanha em uma linha, com testes PHP próprios; a suíte PHP inteira foi repetida depois (727, verde) |
| — novos na Fase 10 | `comunicacao.spec.js` (celular e desktop): cliente avalia (erro de validação, comentário com HTML exibido como texto), escolhe preferências e vê avisos; dono aprova e responde; campanha em rascunho com envio de teste; registro de e-mails (endereço mascarado); configuração. Axe e rolagem lateral em 11 telas |
| Entrega real e aprovação visual no provedor | **PENDENTE** (D-05) |
| Ciclo em modo teste do Stripe (Fase 9) | **PENDENTE** (homologação, chaves de teste do dono) |

## 9. CI

**PASSOU.** Run nº 39 (commit `4baedcd`, https://github.com/Joaogoncalves19/barbearia/actions/runs/37381954030),
PHP 8.4, os dois jobs verdes em todos os passos:

- **Novo sistema (Laravel):** dependências, Pint, Larastan nível 6, auditoria de dependências, build, testes
  PHP (inclusive os de concorrência com processos reais: agenda, caixa, promoções, webhooks e comunicação),
  importador com banco fictício e Playwright + axe. Nenhum provedor de e-mail nem chave do Stripe no CI.
- **Sistema atual:** regressão de segurança S-01 a S-04.

O commit seguinte só atualiza este relatório (documentação).

## 10. Problemas encontrados

1. **Heredoc do shell** perdeu barras invertidas (uma expressão regular virou bytes de controle em
   `Campaigns.php`): achado na revisão e corrigido; scripts de edição passaram a ser gravados como arquivo.
2. **Importador** gravava campanhas novas sem `is_legacy` (a migration só marcava as já existentes): o
   verificador (R48) acusou e a importação inteira foi recusada; corrigido no `MarketingAuditStep`.
3. **Retenção do Stripe** apagava também o erro técnico, o que quebrava a regra R46 (evento falho tem o erro):
   o erro técnico agora fica (não traz o corpo do evento).
4. **Resposta a avaliação** conferia a situação de um objeto antigo: passou a ler a situação atual dentro da
   transação.
5. **Títulos repetidos** ("Campanhas" no título e no cartão): o cartão virou "Lista de campanhas".
6. **Erro cru do provedor** chegaria ao `failed_jobs` e ao log global: agora sai só a mensagem limpa (T10-07).

## 11. Pendências

1. **D-05 (provedor de e-mail):** escolher o provedor, configurar SPF/DKIM/DMARC, enviar a uma lista-semente,
   aprovar os modelos visualmente e ligar devolução/reclamação à lista de supressão.
2. **P10-01 a P10-04** (§2): decisões do dono.
3. **Ciclo em modo teste do Stripe** (Fase 9): na homologação, com chave de teste, webhook de teste, eventos
   reais de teste, conferência do estado local e do histórico (e agora dos e-mails de assinatura).
4. **Anonimização do cliente e política de retenção** (Fase 12): incluir `email_messages` e avisos
   ([consentimento.md §5](consentimento.md#5-lgpd-e-retenção)).
5. **Concorrência no MySQL:** D-02.
6. **Banco local de desenvolvimento:** repovoar com `php artisan db:seed` dentro de `novo-sistema`.

## 12. Documentação

Novos: [emails.md](emails.md), [templates.md](templates.md), [fila.md](fila.md), [lembretes.md](lembretes.md),
[campanhas.md](campanhas.md), [avaliacoes.md](avaliacoes.md), [consentimento.md](consentimento.md) (descadastro,
LGPD, retenção). Atualizados: [assinaturas.md](assinaturas.md) (§11 retenção; P9-08),
[papeis-permissoes.md](papeis-permissoes.md), [modelo-dados.md](modelo-dados.md) (seção 2.9 e apêndice
regenerado, 67 tabelas), [regras-dados.md](regras-dados.md) (regras 85 a 90),
[decisoes-pendentes.md](decisoes-pendentes.md) (D-48 a D-51; D-05), [roadmap.md](roadmap.md),
[README.md](README.md).

## 13. Riscos

| Risco | Mitigação |
|---|---|
| Provedor recusa/limita envios (Gmail atual) | D-05: provedor transacional; ritmo de campanha configurável; fila com novas tentativas |
| Dois sistemas mandando lembretes na virada | Lembretes já enviados importados presos ao horário; o agendador do novo só liga na Fase 13 |
| Worker parado (cron ausente) | E-mails ficam na fila sem perder nada; `app:diagnose` mostra o batimento do agendador |
| Registro "enviando" após queda no meio da entrega | Não reenvia sozinho (no máximo uma vez); aparece no painel |
| Retenção de dados pessoais nos registros | P10-03; anonimização na Fase 12 |

---

**Fase 10 concluída. Aguardando aprovação explícita do dono para a Fase 11. Não avançar automaticamente.**
