# 12. Estratégia de migração dos dados

> **Princípio:** nenhum dado é descartado sem decisão explícita. O banco atual é
> **somente leitura** durante todo o processo: o importador lê de uma **cópia**.

## 12.1 Visão geral

```text
 Produção atual (intocada)
        │  1. backup verificado (cópia do .sqlite + uploads/)
        ▼
 Cópia de trabalho (read-only) ──► 2. diagnóstico ──► relatório de qualidade
        │
        ▼
 3. Importador (tools/legacy-import)  ──►  Banco novo (homologação)
        │         extrair → transformar → carregar → conciliar
        ▼
 4. Relatório de conciliação (contagens, somas, órfãos, pendências)
        │   repetido a cada ensaio até ficar "limpo"
        ▼
 5. Ensaio geral em homologação  →  6. Migração final (janela curta)  →  7. Conferência pós-migração
                                                  │
                                                  └── rollback: volta a apontar para o sistema atual
```

## 12.2 Passo 1 — Backup

- Parar escritas no sistema atual (modo manutenção) **só na migração final**. Nos ensaios, basta
  uma cópia a quente com `sqlite3 .backup` (consistente mesmo com o sistema no ar) ou cópia
  do arquivo fora do horário de uso.
- Copiar `_dados/database.sqlite`, `uploads/` e `_logs/app_log.txt` (o log registra
  duplicatas que impediram índices únicos).
- Guardar três cópias: local, nuvem criptografada e mídia offline. Calcular o hash (SHA-256)
  e testar a restauração abrindo a cópia.

## 12.3 Diagnóstico inicial (somente leitura)

Rodar sobre a **cópia** antes de iniciar a Fase de dados. Exemplos de consultas:

```sql
-- Volume por tabela
SELECT 'agendamentos', COUNT(*) FROM agendamentos UNION ALL
SELECT 'clientes', COUNT(*) FROM clientes UNION ALL
SELECT 'avaliacoes', COUNT(*) FROM avaliacoes;   -- (repetir para todas)

-- Colunas realmente existentes (o schema varia por instalação)
SELECT name, sql FROM sqlite_master WHERE type IN ('table','index');

-- Agendamentos órfãos
SELECT COUNT(*) FROM agendamentos a
 WHERE a.barbeiro_id <> '' AND NOT EXISTS (SELECT 1 FROM barbeiros b WHERE b.id = a.barbeiro_id);
SELECT COUNT(*) FROM agendamentos a
 WHERE a.cliente_id <> '' AND NOT EXISTS (SELECT 1 FROM clientes c WHERE c.id = a.cliente_id);

-- Itens de agendamento que não existem mais no catálogo (receita "sumida")
-- (feito no importador, pois servicos_ids é CSV)

-- Duplicatas de cliente
SELECT LOWER(email), COUNT(*) FROM clientes WHERE email <> '' GROUP BY LOWER(email) HAVING COUNT(*) > 1;
SELECT telefone, COUNT(*) FROM clientes WHERE telefone <> '' GROUP BY telefone HAVING COUNT(*) > 1;

-- Horários duplicados (mesmo profissional/data/hora ativos)
SELECT barbeiro_id, data, hora, COUNT(*) FROM agendamentos
 WHERE status NOT IN ('cancelado','cancelado_pelo_cliente','rejeitado')
 GROUP BY 1,2,3 HAVING COUNT(*) > 1;

-- Formatos inválidos
SELECT COUNT(*) FROM agendamentos WHERE data NOT GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]';
SELECT DISTINCT status FROM agendamentos;
SELECT COUNT(*) FROM servicos WHERE CAST(valor AS REAL) <= 0;

-- Dados que ainda podem ter escape HTML duplo
SELECT COUNT(*) FROM clientes WHERE nome LIKE '%&amp;%' OR nome LIKE '%&#039;%';
```

O resultado vira o **relatório de qualidade de dados**, que o dono revisa. Cada anomalia
recebe uma decisão (corrigir, mesclar, manter como está, arquivar).

## 12.4 Passo 3 — Mapeamento e transformações

| Origem (atual) | Destino (novo) | Transformação |
|---|---|---|
| `users` | `staff_users` + perfil | `username` vira nome/login; e-mail a preencher (**D-10**); hash bcrypt mantido; `role` → perfil |
| `barbeiros` | `staff_users` (perfil profissional) + `professionals` | Hash de `password` mantido; `servicos_ids` (CSV) → `professional_service`; comissões → regras do profissional; `meta_diaria` → meta |
| `clientes` | `customers` | Telefone normalizado; e-mail minúsculo; `status` → ativo/pendente; hash mantido; `codigo_indicacao` e `indicado_por_id` mapeados; `notas_barbeiro` + `anotacoes_clientes` → `customer_notes` (com autor "migração") |
| `categorias`, `servicos`, `combos`, `produtos` | catálogo | `valor` TEXT → centavos; `slots × 30` → `duration_min`; `combos.servicos_ids` → `package_items` |
| `horarios_trabalho` + `config_almoco_barbeiro` | `working_hours` | Almoço vira quebra do expediente (duas faixas no dia); duplicatas descartadas **com registro** |
| `horarios_bloqueados` | `blocked_slots` | hora (30 min) → intervalo |
| `barbeiro_ausencias` | `time_off` | direto |
| `agendamentos` | `appointments` + `appointment_items` + `appointment_adjustments` + `payments` | Ver 12.5 |
| `agenda_historico` | `appointment_events` | direto; ações textuais preservadas |
| `agenda_operacao`, `presenca_confirmada` | `appointments.confirmed_at` | |
| `avaliacoes`, `respostas_avaliacoes`, `avaliacoes_destacadas` | `reviews`, `review_replies`, `reviews.featured` | `rating` TEXT → inteiro |
| `fidelidade` + `fidelidade_historico` | `loyalty_ledger` | Ver 12.6 |
| `cupoes`, `cupom_usos` | `coupons`, `coupon_redemptions` | Códigos duplicados resolvidos antes (**decisão**) |
| `vouchers` | `gift_cards` | direto; códigos preservados |
| `planos`, `clientes_assinaturas`, `assinatura_pagamentos` | `plans`, `plan_services`, `subscriptions`, `subscription_payments` | IDs do Stripe preservados (continuidade da cobrança) |
| `webhook_eventos_processados` | `gateway_events` | Preservar para idempotência |
| `despesas`, `comissoes_pagas`, `vales`, `meta_financeira` | `expenses`, `commission_payouts`, `advances`, settings | valores em centavos |
| `estoque_logs` | `stock_movements` | Saldo atual do produto conferido contra a soma dos movimentos; diferença vira um "ajuste de migração" |
| `campanhas`, `campanha_destinatarios` | `campaigns`, `campaign_recipients` | Histórico (opcional: arquivar só o resumo — **decisão**) |
| `email_optout` | `customers.marketing_opt_out_at` + lista de opt-out de e-mails sem cadastro | **Obrigatório preservar** (LGPD) |
| `notificacoes` | `notifications` | `lida`/`status` unificados |
| `configuracoes` | `settings` (não sensíveis) | Segredos **não** migram para o banco: vão para o `.env` manualmente |
| `admin_atividade` | `audit_logs` (legado) | |
| `clientes_tokens`, `password_resets`, `login_throttle`, `sys_login_attempts` | — | **Não migrar** (dados efêmeros). Clientes fazem login de novo |
| `admin_crm_clientes`, `admin_metas_equipe`, `admin_retencao`, `admin_conciliacao`, `admin_lista_espera`, `config` | arquivo morto (JSON) | Exportados para arquivo; migrados só se a funcionalidade for aprovada |
| `uploads/` | armazenamento novo | Fotos de clientes/equipe/logo copiadas e reprocessadas (tamanhos/WebP); arquivos sem referência vão para arquivo morto |

## 12.5 Agendamentos: o ponto mais delicado

O banco atual **não guarda o preço cobrado**. Estratégia:

1. `servicos_ids` é separado em itens. Cada item recebe o **preço atual do catálogo no momento
   da migração**, marcado como `price_source = 'estimado_migracao'`.
2. Itens cujo serviço/combo não existe mais viram item "Serviço removido" com valor 0 e
   marcação de pendência (aparece no relatório).
3. `desconto_aplicado` + `tipo_desconto` viram um `appointment_adjustment` com a origem.
4. `produtos_vendidos` (JSON) vira itens de produto com o valor gravado no JSON (esse sim é histórico).
5. `forma_pagamento`, `gorjeta` e `comanda_fechada_em` viram `payment` para os concluídos.
6. Comissões já pagas (`comissoes_pagas`) são migradas **como estão**. Não são recalculadas.
7. Status mapeados:

| Atual | Novo |
|---|---|
| `aprovado` | `confirmed` (futuro) / `no_show_pending_review` (passado não concluído, **decisão**) |
| `pendente` | `pending` |
| `concluido` | `completed` |
| `cancelado` | `cancelled` (por: estabelecimento) |
| `cancelado_pelo_cliente` | `cancelled` (por: cliente) |
| `rejeitado` | `cancelled` (motivo: rejeitado) |
| `aguardando_pagamento` | `cancelled` se expirado; senão `awaiting_payment` |

8. `ends_at` = início + soma das durações.
9. Conflitos de sobreposição encontrados em agendamentos **futuros** geram lista para a
   recepção resolver antes da virada.

**Conciliação obrigatória:** faturamento por mês calculado pelo sistema atual (a regra atual,
com preços atuais) × faturamento migrado. Devem ser **iguais** para todos os meses, porque
a mesma base de preço foi usada. Qualquer diferença é bug do importador.

## 12.6 Fidelidade

- O saldo atual (`fidelidade.pontos`) é a verdade do cliente.
- O histórico (`fidelidade_historico`) é migrado como lançamentos.
- Se a soma do histórico ≠ saldo, cria-se um lançamento "Ajuste de migração" com a diferença,
  para o saldo novo bater exatamente com o atual.

## 12.7 Registros órfãos e duplicidades

| Caso | Tratamento padrão proposto (sujeito a decisão) |
|---|---|
| Agendamento de profissional inexistente | Criar profissional "Ex-colaborador (migrado)" inativo e vincular |
| Agendamento de cliente inexistente | Manter como agendamento sem conta (nome/telefone do próprio registro) |
| Avaliação de agendamento inexistente | Manter a avaliação vinculada ao profissional, sem agendamento |
| Clientes duplicados (mesmo e-mail/telefone) | **Não mesclar automaticamente.** Lista para decisão; mesclagem assistida preserva todos os agendamentos |
| Cupons com código repetido | Sufixar o mais antigo e registrar |
| Horários de trabalho duplicados | Manter um e registrar |
| Dados com escape HTML duplo remanescente | Desescapar um nível (mesma regra de `migracaoDesescapar`) e registrar |

## 12.8 Validação e conferência pós-migração

Checklist automático (o importador falha se algum item não bater):

- [ ] Contagem por entidade: origem = destino + descartes registrados.
- [ ] Soma de faturamento por mês e por profissional idêntica.
- [ ] Soma de comissões pagas, despesas e vales idêntica.
- [ ] Saldo de fidelidade idêntico por cliente.
- [ ] Assinaturas vigentes: mesmo número, mesmas datas de fim e mesmos IDs de gateway.
- [ ] Opt-outs: 100% preservados.
- [ ] Agendamentos futuros: todos presentes, sem sobreposição não resolvida.
- [ ] Amostra manual de 20 clientes, 20 agendamentos e 5 profissionais conferida na tela pelo dono.
- [ ] Login de teste com senha antiga (cliente, profissional e admin) funcionando.

## 12.9 Migração final e rollback

1. Aviso aos clientes/equipe sobre a janela (sugestão: após o fechamento, fora de dia de pico).
2. Modo manutenção no sistema atual (bloqueia novos agendamentos).
3. Backup final + hash.
4. Importação + conciliação automática.
5. Conferência rápida pelo dono (roteiro de 15 min).
6. Virada: DNS ou apontamento do domínio para o sistema novo; atualização da URL do webhook
   no Stripe (ou alias mantido); cron do agendador ativado.
7. Monitoramento reforçado nas primeiras 72 h.

**Rollback** (critério definido antes: ex.: falha de login, falha de agendamento ou
divergência financeira):

- Reapontar o domínio para o sistema atual, que ficou **intocado** com o banco anterior à janela.
- Agendamentos criados no sistema novo durante o período são exportados e reinseridos
  manualmente pela recepção (por isso a janela e o monitoramento curtos).
- O sistema atual fica disponível em modo somente leitura por pelo menos 30 dias após a virada.

## 12.10 Segredos e integrações na virada

- Senha SMTP, chaves do Stripe e chaves de IA são **recadastradas** no `.env` do novo sistema
  (recomendado: **rotacionar** todas, porque as atuais ficaram em texto puro no banco e em backups).
- Segredos HMAC antigos são aceitos só pelas rotas de compatibilidade, por prazo limitado
  (ex.: 60 dias), e depois descartados.
