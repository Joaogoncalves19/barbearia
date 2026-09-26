# 4. Banco de dados atual

## 4.1 Resumo

| Item | Situação |
|------|----------|
| Motor | SQLite 3, arquivo `_dados/database.sqlite` (**confirmado**) |
| Tabelas | **50** (32 no instalador + 18 criadas em tempo de execução) |
| Chaves estrangeiras | **Nenhuma** declarada |
| Tipos | Quase tudo `TEXT`, inclusive dinheiro, datas, horas, percentuais e contadores |
| Listas | IDs relacionados guardados como texto separado por vírgula (`servicos_ids`) |
| JSON em coluna | `agendamentos.produtos_vendidos`, `configuracoes.dados_json`, `campanhas.extra_json` |
| IDs | Texto com prefixo: `AG-XXXXXX`, `CL-XXXXXX`, `sv-...`, `br-...`, `vch-...`, `desp-...`; alguns `INTEGER AUTOINCREMENT` |
| Migrations | `lib/migrations.php` (versão de schema = 4) + dezenas de `CREATE/ALTER` espalhados |
| Seeds | Configurações padrão gravadas na primeira leitura (`_lerConfigSQLite`) |
| Dados de teste | Nenhum seed de teste. Os padrões de configuração contêm textos fictícios ("Barbearia Fictícia", "Rua Exemplo, 123", 1500 clientes) |

**Método:** o schema abaixo foi extraído de uma cópia local instalada do zero e navegada
por todas as abas do painel, somado às definições encontradas no código para as tabelas
que só nascem quando uma funcionalidade específica é usada. **Precisa de validação:** o banco
de produção pode ter colunas extras ou faltantes, conforme as telas que já foram abertas.

## 4.2 Tabelas por domínio

Legenda: `*` = chave primária · (U) = índice único · (I) = índice comum.

### Identidade e acesso

| Tabela | Colunas | Observações |
|--------|---------|-------------|
| `users` | username*, password_hash, role, permissions | Administradores. `permissions` **nunca é usada** |
| `barbeiros` | id*, nome, foto, username, password, status, servicos_ids, comissao, comissao_produtos, meta_diaria, comissao_assinatura_tipo, comissao_assinatura_valor | Barbeiro = profissional **e** usuário de login na mesma tabela. `username` sem índice único. Serviços em CSV |
| `clientes` | id*, nome, email (U parcial, LOWER), telefone (U parcial), password_hash, data_nascimento, foto_perfil, codigo_indicacao (I), cpf (U parcial), indicado_por_id, status, confirmation_token, notas_barbeiro, confirmation_expira_em | Índices únicos só são criados se não houver duplicatas (senão ficam só no log) |
| `clientes_tokens` | selector*, cliente_id (I), token_hash, expires_at, created_at, last_used_at | "Lembrar-me" |
| `password_resets` | email, token, expiry | Sem PK. Token em hash |
| `login_throttle` | chave*, tentativas, bloqueio_ate, atualizado_em | Limites de login, cadastro, reset e chatbot |
| `sys_login_attempts` | ip_address*, attempts, last_attempt | **Duplicata** de `login_throttle`, usada só em `login.php` |
| `admin_atividade` | id*, usuario, acao, detalhes, created_at | Auditoria rasa |

### Catálogo e equipe

| Tabela | Colunas | Observações |
|--------|---------|-------------|
| `categorias` | id*, nome, ordem | `ordem` é TEXT |
| `servicos` | id*, nome, valor, slots, categoria_id, descricao | `valor` TEXT; duração em "slots" de 30 min |
| `combos` | id*, nome, servicos_ids, valor, categoria_id | Serviços em CSV |
| `planos` | id*, nome, valor, servicos_ids | Serviços inclusos em CSV |
| `produtos` | id*, nome, valor, quantidade, categoria_id, estoque_minimo, custo | Estoque como número mutável (sem saldo derivado do histórico) |
| `estoque_logs` | id*, produto_id, tipo, quantidade, motivo, data_hora, usuario | Histórico de movimentação |
| `horarios_trabalho` | barbeiro_id, dia, inicio, fim, ativo | **Sem PK** (permite duplicar o mesmo dia) |
| `horarios_bloqueados` | barbeiro_id, data, hora | **Sem PK** |
| `config_almoco_barbeiro` | barbeiro_id*, horario, status | Almoço fixo de 1 h |
| `barbeiro_ausencias` | id*, barbeiro_id, data_inicio, data_fim, tipo, motivo, criado_em | Folga/férias/atestado |
| `barbeiros_favoritos` | (cliente_id, barbeiro_id)*, created_at | |

### Agenda

| Tabela | Colunas | Observações |
|--------|---------|-------------|
| `agendamentos` | id*, nome, email, telefone, barbeiro_id, servicos_ids, data, hora, status, desconto_aplicado, tipo_desconto, observacoes, produtos_vendidos (JSON), plano_provisorio, cliente_id, data_criacao, presenca_confirmada, payment_gateway, gateway_reference, gorjeta, forma_pagamento, comanda_fechada_em, lembrete_data, lembrete_hora_em | **24 colunas**, 7 índices + (U) `idx_uq_ag_slot` (barbeiro, data, hora) parcial. Guarda cópia de nome, e-mail e telefone do cliente. **Não guarda preço nem duração** |
| `agenda_historico` | id*, agendamento_id, acao, detalhes, usuario, created_at | Linha do tempo do agendamento |
| `agenda_operacao` | agendamento_id*, confirmacao_status, updated_at, updated_by | Confirmação operacional (1:1 com agendamento) |
| `admin_lista_espera` | id*, cliente_id, nome, telefone, barbeiro_id, data_preferida, periodo, observacoes, status, created_at | **Abandonada** (sem tela) |

Valores de `agendamentos.status` encontrados no código: `aprovado`, `pendente`, `concluido`,
`cancelado`, `cancelado_pelo_cliente`, `rejeitado`, `aguardando_pagamento`.
Valores de `tipo_desconto`: `cupom`, `voucher`, `fidelidade`, `aniversario`, `indicacao`,
`assinatura_vip`, `adesao_plano`, vazio.

### Financeiro

| Tabela | Colunas | Observações |
|--------|---------|-------------|
| `despesas` | id*, descricao, valor, data_despesa, data_vencimento, data_pagamento, categoria, status, recorrente, recorrencia_origem | `data_despesa` aparentemente substituída por `data_vencimento` (precisa de validação) |
| `comissoes_pagas` | id*, barbeiro_id, valor, data_pagamento, periodo_inicio, periodo_fim, mes_ano, valor_total_servicos, gorjeta | Período guardado de **duas formas** (`periodo_*` e `mes_ano`) |
| `vales` | id*, barbeiro_id, valor, data_vale, mes_referencia, descricao | Adiantamentos |
| `meta_financeira` | id*, valor | Linha única |
| `admin_conciliacao` | referencia*, gateway, status, observacao, updated_at | **Abandonada** |
| `admin_metas_equipe` | (barbeiro_id, mes_ano)*, meta_atendimentos, meta_faturamento, meta_avaliacao | **Abandonada** (e duplica `barbeiros.meta_diaria`) |

### Assinaturas

| Tabela | Colunas | Observações |
|--------|---------|-------------|
| `clientes_assinaturas` | cliente_id* (U), plano_id, data_inicio, data_fim, status, gateway, gateway_subscription_id (I), gateway_status, ultimo_pagamento_id, cancelamento_em, gateway_customer_id | Uma assinatura por cliente (sem histórico de assinaturas anteriores) |
| `assinatura_pagamentos` | id*, cliente_id (I), plano_id, gateway, gateway_subscription_id, valor (REAL), moeda, status, data_pagamento (I), tipo, referencia, created_at | |
| `webhook_eventos_processados` | (gateway, evento_id)*, tipo, processado_em | Idempotência |

### Marketing e relacionamento

| Tabela | Colunas | Observações |
|--------|---------|-------------|
| `fidelidade` | id* (=cliente_id), pontos | Saldo mutável |
| `fidelidade_historico` | cliente_id, pontos, descricao, timestamp | Sem PK; saldo **não** é derivado dele |
| `cupoes` | id*, codigo, desconto_percentual, usos_maximos, data_validade, usos_atuais, tipo_desconto, valor_desconto, ativo | `codigo` **sem índice único** |
| `cupom_usos` | cupom_id, cliente_id | Sem PK nem data |
| `vouchers` | id*, codigo, valor, status, data_criacao, agendamento_id_uso, data_validade, comprador | `codigo` sem índice único |
| `campanhas` | id*, tipo, template, assunto, corpo, segmento, extra_json, total, enviados, falhas, status, criada_em, concluida_em, criada_por | |
| `campanha_destinatarios` | id*, campanha_id (I), cliente_id, nome, email, ref, status, enviado_em | |
| `email_optout` | email*, criado_em, cliente_id (I) | |
| `notificacoes` | id*, cliente_id, mensagem, lida, data_criacao, status, timestamp | **Duas colunas para "lida"** (`lida` e `status`) e duas para data (`data_criacao` e `timestamp`) |
| `avaliacoes` | id*, agendamento_id, cliente_id, barbeiro_id, rating (TEXT), comment, timestamp | Sem índice único por agendamento (precisa de validação se há avaliação dupla) |
| `avaliacoes_destacadas` | id_avaliacao* | Poderia ser uma coluna |
| `respostas_avaliacoes` | id_resposta*, id_avaliacao, texto_resposta, timestamp | |
| `anotacoes_clientes` | id* (=cliente_id), anotacao | Anotação do admin (1 por cliente) |
| `admin_crm_clientes` | cliente_id*, tags, observacoes, status_relacionamento, updated_at | **Abandonada** |
| `admin_retencao` | cliente_id*, motivo, oferta, status, updated_at | **Abandonada** |

Observação: há **três lugares diferentes para anotar sobre um cliente**:
`anotacoes_clientes` (admin), `clientes.notas_barbeiro` (barbeiro) e
`admin_crm_clientes.observacoes` (abandonada).

### Sistema

| Tabela | Colunas | Observações |
|--------|---------|-------------|
| `configuracoes` | secao*, dados_json | Todas as configurações e **segredos em texto puro** |
| `config` | chave*, valor | **Abandonada:** criada pelo instalador e nunca lida nem escrita |
| `schema_migracoes` | versao*, aplicada_em | |
| `schema_migracoes_dados` | chave*, aplicada_em | |

## 4.3 Relacionamentos (implícitos, sem FK)

```text
agendamentos.cliente_id      → clientes.id          (pode ser vazio: agendamento sem conta)
agendamentos.barbeiro_id     → barbeiros.id
agendamentos.servicos_ids    → servicos.id | combos.id   (CSV misturando os dois)
agendamentos.plano_provisorio→ planos.id
combos.servicos_ids          → servicos.id           (CSV)
planos.servicos_ids          → servicos.id           (CSV)
barbeiros.servicos_ids       → servicos.id | combos.id   (CSV)
servicos/combos/produtos.categoria_id → categorias.id
clientes.indicado_por_id     → clientes.id
clientes_assinaturas.cliente_id / plano_id → clientes.id / planos.id
assinatura_pagamentos.cliente_id / plano_id
avaliacoes.agendamento_id / cliente_id / barbeiro_id
respostas_avaliacoes.id_avaliacao → avaliacoes.id
avaliacoes_destacadas.id_avaliacao → avaliacoes.id
fidelidade.id                → clientes.id
fidelidade_historico.cliente_id → clientes.id
cupom_usos.cupom_id / cliente_id
vouchers.agendamento_id_uso  → agendamentos.id
horarios_trabalho / horarios_bloqueados / barbeiro_ausencias / config_almoco_barbeiro.barbeiro_id → barbeiros.id
comissoes_pagas / vales.barbeiro_id → barbeiros.id
estoque_logs.produto_id      → produtos.id
agenda_historico / agenda_operacao.agendamento_id → agendamentos.id
campanha_destinatarios.campanha_id → campanhas.id
email_optout.cliente_id      → clientes.id
notificacoes.cliente_id      → clientes.id
```

## 4.4 Mapa conceitual

```text
Cliente ─────────────┬── Agendamentos ──┬── Profissional (Barbeiro)
  │                  │                  ├── Itens: Serviços / Combos (CSV, sem preço congelado)
  │                  │                  ├── Produtos vendidos (JSON)
  │                  │                  ├── Pagamento: forma + gorjeta (colunas soltas)
  │                  │                  ├── Desconto: cupom | voucher | fidelidade | aniversário |
  │                  │                  │             indicação | assinatura
  │                  │                  ├── Histórico + confirmação operacional
  │                  │                  └── Avaliação ── Resposta / Destaque
  │                  │
  ├── Assinatura (1) ── Plano ── Serviços inclusos
  │        └── Pagamentos da assinatura ── Stripe
  ├── Fidelidade: saldo + histórico
  ├── Indicação: código próprio + quem indicou
  ├── Notificações · Favoritos · Opt-out · Tokens "lembrar-me"
  └── Anotações (admin) · Notas (barbeiro)

Profissional ── Expediente semanal · Almoço · Bloqueios · Ausências
             ── Serviços que realiza · Comissões pagas · Vales

Catálogo: Categorias ── Serviços · Combos · Produtos (estoque + movimentações) · Planos
Financeiro: Despesas (recorrentes) · Comissões pagas · Vales · Meta
Marketing: Campanhas ── Destinatários · Cupons ── Usos · Vouchers
Sistema: Configurações (JSON) · Administradores (perfis) · Atividade · Throttle · Migrações
```

## 4.5 Problemas encontrados

### Integridade

| # | Problema | Evidência | Impacto |
|---|----------|-----------|---------|
| B-01 | Sem chaves estrangeiras | todo o schema | Registros órfãos possíveis (agendamento de barbeiro excluído, avaliação de agendamento apagado). **Precisa de validação** no banco real |
| B-02 | Preço e duração não congelados no agendamento | `agendamentos` sem colunas de valor | Relatórios, comissões e histórico mudam quando o catálogo muda (ver arquitetura 3.3.9) |
| B-03 | Listas em CSV | `servicos_ids` em 4 tabelas | Sem integridade, sem índice, parse repetido em PHP e JS |
| B-04 | CSV mistura serviços e combos | `agendamentos.servicos_ids` | O tipo do item é deduzido pelo prefixo do ID |
| B-05 | Exclusão física | `excluir_servico`, `excluir_barbeiro`, reagendamento que apaga o agendamento | Perda de histórico |
| B-06 | Tabelas sem PK | `horarios_trabalho`, `horarios_bloqueados`, `password_resets`, `fidelidade_historico`, `cupom_usos` | Duplicatas silenciosas |
| B-07 | Códigos sem unicidade | `cupoes.codigo`, `vouchers.codigo`, `barbeiros.username` | Dois cupons com o mesmo código; login ambíguo |
| B-08 | Índices únicos condicionais | `migracaoIndicesUnicosClientes` só cria se não houver duplicatas | Em produção eles podem **não existir**. Precisa de validação (ver log) |
| B-09 | Saldos mutáveis sem razão | `fidelidade.pontos`, `produtos.quantidade` | O saldo pode divergir do histórico |
| B-10 | Cópia de dados do cliente no agendamento | `agendamentos.nome/email/telefone` | Divergência; o perfil do cliente reescreve o histórico (`cliente_actions.php:salvar_perfil`) |

### Tipos e formatos

| # | Problema | Evidência |
|---|----------|-----------|
| B-11 | Dinheiro como `TEXT` (e às vezes `REAL`) | `valor`, `desconto_aplicado`, `comissao`, `gorjeta` |
| B-12 | Datas e horas como texto em formatos variados (`Y-m-d`, `Y-m-d H:i:s`, timestamp inteiro) | `expiry` INTEGER, `data_criacao` TEXT, `timestamp` TEXT |
| B-13 | Booleanos como texto (`'ativo'`, `'1'`, `1`) | `horarios_trabalho.ativo`, `barbeiros.status`, `notificacoes.lida` |
| B-14 | Nomes de colunas inconsistentes | `id_avaliacao` × `agendamento_id`; `timestamp` × `created_at` × `criado_em` × `data_criacao` |
| B-15 | Dados que já foram salvos com escape HTML duplo | `migracaoCorrigirEscapeDuplo` (corrigido em 2026-08-31, precisa de validação no banco real) |

### Duplicidades e abandono

| # | Problema |
|---|----------|
| B-16 | `sys_login_attempts` × `login_throttle` |
| B-17 | `notificacoes.lida` × `notificacoes.status`; `data_criacao` × `timestamp` |
| B-18 | `comissoes_pagas.periodo_inicio/fim` × `mes_ano` |
| B-19 | `barbeiros.meta_diaria` × `admin_metas_equipe` |
| B-20 | Três tabelas de anotações de cliente |
| B-21 | Tabelas abandonadas: `config`, `admin_crm_clientes`, `admin_metas_equipe`, `admin_retencao`, `admin_conciliacao`, `admin_lista_espera` |
| B-22 | Configuração legada `config_gemini` (substituída por `config_chatbot.gemini_keys`) e seção `config` (`aprovar`), lida mas sem efeito |

### Estruturas que dificultam a nova arquitetura

1. Barbeiro como usuário e profissional na mesma tabela: dificulta permissões unificadas.
2. Administradores com PK = `username`: renomear quebra referências de auditoria.
3. Configuração como blob JSON sem schema: validação e tipos ficam no código de leitura.
4. Segredos no banco: backups e downloads do `.sqlite` expõem credenciais.
5. Uma única assinatura por cliente, sem histórico.
6. Ausência de pagamento como entidade: forma de pagamento e gorjeta são colunas do agendamento.

## 4.6 Diagnóstico sugerido no banco real (somente leitura)

Antes da Fase 2, rodar sobre uma **cópia** do banco de produção as consultas do
[estrategia-migracao.md](estrategia-migracao.md#123-diagnóstico-inicial-somente-leitura) para
medir volume, órfãos, duplicatas, formatos inválidos e colunas realmente presentes.
**Nenhuma alteração é feita no banco nesta fase.**
