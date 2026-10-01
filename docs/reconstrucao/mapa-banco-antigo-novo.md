# Mapa do banco antigo para o novo

De onde vem cada dado do modelo novo e o que acontece com cada tabela antiga. A implementação está
em `novo-sistema/app/Modules/LegacyImport/Steps/*` (uma etapa por grupo de tabelas).

Legenda da coluna **Destino**: **migra** (vira dado do modelo), **transforma** (vira outra estrutura),
**arquiva** (exportado para JSON no disco privado, não vira dado), **ignora** (só contado), **descarta**
(não importado por segurança; precisa ser refeito no `.env`). **Nada é apagado do banco antigo.**

## 1. Tabelas

| Tabela antiga | Destino | Tabela(s) nova(s) | Etapa |
|---|---|---|---|
| `users` | migra | `users` (perfis owner/manager/reception/finance) | StaffStep |
| `barbeiros` | transforma | `professionals` + `users` (perfil professional, se tiver login) + `professional_service`/`professional_package` + `financial_goals` (meta diária) | ProfessionalsStep |
| `horarios_trabalho` | migra | `working_hours` | ProfessionalsStep |
| `config_almoco_barbeiro` | transforma | `schedule_breaks` (1 h, todos os dias) | ProfessionalsStep |
| `barbeiro_ausencias` | migra | `time_off` | ProfessionalsStep |
| `horarios_bloqueados` | transforma | `blocked_slots` (intervalo de 30 min em UTC) | ProfessionalsStep |
| `categorias` | migra | `service_categories` | CatalogStep |
| `servicos` | migra | `services` | CatalogStep |
| `combos` | transforma | `packages` + `package_items` | CatalogStep |
| `produtos` | transforma | `products` (sem saldo) + movimento `legacy_opening` em `stock_movements` | CatalogStep / StockStep |
| `estoque_logs` | migra | `stock_movements` (venda de agendamento concluído aponta o atendimento dele; sem atendimento, vira ajuste com o motivo original) | StockStep |
| `clientes` | transforma | `customers` + `customer_notes` (notas do barbeiro) + `customer_merge_candidates` (duplicidades) | CustomersStep |
| `anotacoes_clientes` | transforma | `customer_notes` (visibilidade equipe) | CustomersStep |
| `barbeiros_favoritos` | migra | `customer_favorite_professionals` | CustomersStep |
| `notificacoes` | migra | `customer_notifications` (`lida`/`status` unificados) | CustomersStep |
| `email_optout` | transforma | `email_suppressions` + `consent_records` + `customers.marketing_email_consent = revoked` | CustomersStep |
| `planos` | transforma | `plans` + `plan_services` | SubscriptionsStep |
| `clientes_assinaturas` | migra | `subscriptions` (IDs do Stripe preservados) | SubscriptionsStep |
| `assinatura_pagamentos` | migra | `subscription_payments` (`referencia` → `gateway_payment_id`) | SubscriptionsStep |
| `webhook_eventos_processados` | migra | `gateway_events` | SubscriptionsStep |
| `cupoes` | migra | `coupons` | PromotionsStep |
| `vouchers` | migra | `gift_cards` | PromotionsStep |
| `agendamentos` | transforma | `appointments` + `appointment_items` + `appointment_adjustments` + `appointment_reminders` + `appointment_events`; concluídos: também `attendances` + `attendance_items` + `attendance_discounts` + `payments` (Fase 6) | AppointmentsStep |
| `agenda_historico` | migra | `appointment_events` (`legacy.<acao>`) | AppointmentsStep |
| `agenda_operacao` | transforma | `appointments.confirmation_requested_at` | AppointmentsStep |
| `avaliacoes` | migra | `reviews` | ReviewsStep |
| `avaliacoes_destacadas` | transforma | `reviews.is_featured` | ReviewsStep |
| `respostas_avaliacoes` | migra | `review_replies` | ReviewsStep |
| `fidelidade` | transforma | lançamento `legacy_opening` em `loyalty_entries` (diferença saldo − histórico) | LoyaltyStep |
| `fidelidade_historico` | migra | `loyalty_entries` (`legacy_history`) | LoyaltyStep |
| `cupom_usos` | migra | `coupon_redemptions` | LoyaltyStep |
| `despesas` | migra | `expenses` | FinanceStep |
| `comissoes_pagas` | migra | `commission_payouts` (sem recálculo) | FinanceStep |
| `vales` | migra | `advances` (`is_legacy`: histórico, já abatido no sistema antigo; nunca entra em repasse novo) | FinanceStep |
| `meta_financeira` | transforma | `financial_goals` (mensal, da barbearia) | FinanceStep |
| `campanhas` | transforma | `campaigns` (só resumo, D-20) | MarketingAuditStep |
| `campanha_destinatarios` | arquiva | — (D-20) | ArchiveStep |
| `admin_atividade` | migra | `audit_logs` (`legacy.activity`) | MarketingAuditStep |
| `configuracoes` | transforma / descarta | `settings` (`legacy.<secao>`, sem campos sensíveis); seções sensíveis descartadas | SettingsStep |
| `config` | arquiva | — (abandonada) | ArchiveStep |
| `admin_crm_clientes`, `admin_metas_equipe`, `admin_retencao`, `admin_conciliacao`, `admin_lista_espera` | arquiva | — (abandonadas, ver §3) | ArchiveStep |
| `clientes_tokens`, `password_resets`, `login_throttle`, `sys_login_attempts` | ignora | — (efêmeras; contêm hashes de token e IPs, por isso nem são exportadas) | ArchiveStep |
| `schema_migracoes`, `schema_migracoes_dados` | ignora | — (controle técnico do sistema antigo) | ArchiveStep |
| qualquer outra | ignora + pendência | — (`unknown_table`) | ArchiveStep |

## 2. Campos (tabelas principais)

### clientes → customers

| Antigo | Novo | Transformação |
|---|---|---|
| `id` (CL-…) | `legacy_references` | id novo inteiro; `public_id` ULID novo |
| `nome` | `name` | desescape HTML de 1 nível; vazio → "(sem nome)" + pendência |
| `email` | `email` | minúsculo, validado; inválido → nulo + pendência; repetido → nulo no segundo + par de duplicidade |
| `telefone` | `phone` | E.164 `+55…`; inválido/repetido idem |
| `cpf` | `cpf` | 11 dígitos com dígitos verificadores; inválido/repetido idem |
| `password_hash` | `password` | bcrypt mantido como está; outro formato → nulo + pendência |
| `data_nascimento` | `birth_date` | `Y-m-d` ou `d/m/Y`; inválida → nula |
| `foto_perfil` | `photo_path` | caminho mantido; cópia dos arquivos é da fase de virada |
| `codigo_indicacao` | `referral_code` | repetido → nulo no segundo |
| `indicado_por_id` | `referred_by_customer_id` | segunda passada; órfão/si mesmo → nulo |
| `status` | `status` | vazio/`ativo` → active; `inativo` → inactive; outro → inactive + pendência |
| `confirmation_token`, `confirmation_expira_em` | — | efêmeros, não migram |
| `notas_barbeiro` | `customer_notes` | visibilidade `professionals` |
| — | `marketing_email_consent` | `unknown`; `revoked` se houver opt-out |

### agendamentos → appointments (+ filhas)

| Antigo | Novo | Transformação |
|---|---|---|
| `id` (AG-…) | `code` | preservado |
| `data` + `hora` | `starts_at` | hora local de São Paulo → UTC; inválida → não importado + pendência |
| `servicos_ids` (CSV) | `appointment_items` | serviço/combo com preço do catálogo atual (`legacy_catalog_estimate`); inexistente → `legacy_unknown` sem preço |
| (derivado) | `ends_at` | início + soma das durações (serviço: slots×30; combo: soma dos slots dos serviços; mínimo 30 min) |
| `produtos_vendidos` (JSON) | `appointment_items` (produto) | valor gravado (`recorded`); JSON inválido → pendência |
| `desconto_aplicado` + `tipo_desconto` | `appointment_adjustments` | valor original; tipo mapeado (cupom, voucher, fidelidade…); desconhecido → `legacy_unknown` |
| (derivado) | `subtotal/discount/total_cents` | regra do financeiro antigo; nulos se algum preço é desconhecido |
| `status` | `status` + `cancelled_by`/`cancellation_reason` | ver tabela de status em [importador.md](importador.md#status) |
| `nome`, `email`, `telefone` | `customer_name/email/phone` | snapshot como estava |
| `cliente_id` | `customer_id` | órfão → nulo (agendamento sem conta) |
| `barbeiro_id` | `professional_id` + `professional_name` | órfão → profissional inativo "Profissional removido (id)" |
| `forma_pagamento`, `gorjeta`, `comanda_fechada_em` | `attendances` (`completed_at`, `tip_cents`) + `payments` (apontando o atendimento) | só para concluídos; pagamento só com valor conhecido; forma desconhecida → `unknown` |
| `presenca_confirmada` | `confirmed_at` | |
| `lembrete_data` / `lembrete_hora_em` | `appointment_reminders` | véspera sem horário (nulo); "horas antes" com horário |
| `payment_gateway`, `gateway_reference` | `payment_gateway`, `payment_gateway_reference` | preservados |
| `plano_provisorio` | `appointment_events` (`legacy.plan_signup_intent`) | |
| `observacoes` | `notes` | |
| `data_criacao` | `created_at` | nula se ilegível |

### barbeiros → professionals / users

| Antigo | Novo | Transformação |
|---|---|---|
| `nome` | `display_name` (e `users.name`) | |
| `username`, `password` | `users.username`, `users.password` | só cria usuário se houver `username`; colisão → sem usuário; senha não-bcrypt → sem senha |
| `status` | `is_active`, `is_bookable` | vazio = ativo |
| `comissao` | `commission_rules` (Fase 7: regra do profissional para serviços, percentual) | "37.5" → 3750; inválido ou zero → sem regra + pendência |
| `comissao_produtos` | `commission_rules` (regra do profissional para produtos, com o **mesmo** percentual, como o sistema antigo calculava) | só quando "sim" |
| `comissao_assinatura_tipo/valor` | `subscription_commission_*` | padrao/percentual/fixo/nenhuma |
| `servicos_ids` | `professional_service` / `professional_package` | |
| `meta_diaria` | `financial_goals` (diária) | |
| `foto` | `photo_path` | |

## 3. As seis tabelas abandonadas

Nenhuma tem tela no sistema atual (código de gestão criado e nunca ligado ao painel — ver
[legado-e-codigo-morto.md](legado-e-codigo-morto.md)). Todas são **exportadas para o arquivo morto**
(`storage/app/private/legacy-import/archive/run-<n>/<tabela>.json`) e não viram dados. O banco antigo não é alterado.

| Tabela | O que representava | Os dados aparecem em outro lugar? | Relevância | Decisão |
|---|---|---|---|---|
| `config` | Chave/valor criada pelo instalador (`aprovar`) | Sim: a seção `config` de `configuracoes` é a que o código lê | Nenhuma | Arquivar |
| `admin_crm_clientes` | Tags, observações e "status de relacionamento" por cliente | Parcialmente: observações duplicam `anotacoes_clientes` | Baixa; útil se houver tags preenchidas | Arquivar; se o dono aprovar tags (D-11), migrar para `customer_notes`/tags na Fase 7 |
| `admin_metas_equipe` | Meta mensal por profissional (atendimentos, faturamento, nota) | Parcialmente: `barbeiros.meta_diaria` (migrada) | Média se preenchida | Arquivar; migrar para `financial_goals` se D-11 aprovar metas |
| `admin_retencao` | Motivo/oferta para clientes em risco | Não | Baixa | Arquivar |
| `admin_conciliacao` | Marcação manual de pagamentos do gateway como revisados | Não (é anotação sobre `assinatura_pagamentos`) | Baixa | Arquivar |
| `admin_lista_espera` | Lista de espera por data/período | Não | **Alta se usada** (D-11 recomenda aprovar) | Arquivar; importar quando o módulo de lista de espera existir |

`campanha_destinatarios` também é arquivada (D-20: migrar só o resumo das campanhas).

## 4. Seções de configuração

| Seção | Destino |
|---|---|
| `config_geral`, `config_agendamento`, `config_lembretes`, `config_site`, `fidelidade_config`, `config_aniversario`, `config_indicacao`, `landing_page`, `theme_config`, `config` | `settings` com chave `legacy.<secao>`; campos com nome de segredo removidos (pendência) |
| `config_email`, `config_stripe`, `config_gemini`, `config_chatbot`, `config_cron` | **descartadas**: recadastrar no `.env` do sistema novo, de preferência com credenciais **novas** (as atuais ficaram em texto puro) |
