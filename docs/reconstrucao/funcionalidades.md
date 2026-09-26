# 2. Matriz de funcionalidades do sistema atual

**Como ler:**

- **Utilizada?** indica se há caminho real de uso no código (interface que dispara +
  backend que executa). Uso **em produção** (se o dono realmente usa) é sempre
  *precisa de validação*: não houve acesso a produção.
- **Deve permanecer?** é uma **recomendação**. A decisão final é do dono do produto
  (ver [decisoes-pendentes.md](decisoes-pendentes.md)). Nada é descartado sem decisão explícita.
  - **Manter** = reconstruir com o mesmo objetivo.
  - **Reformular** = manter o objetivo, mudar regra ou experiência.
  - **A decidir** = depende do dono.
  - **Remover** = sugerido descartar (sempre com justificativa).

## 2.1 Site público

| Funcionalidade | Localização | Utilizada? | Estado | Deve permanecer? |
|---|---|---|---|---|
| Landing page (hero com vídeo, sobre, números, serviços em destaque, planos, equipe, barbeiro em destaque, avaliações, chamada final, localização, rodapé) | `index.php`, `partials/index_*.php` | Sim | ATIVA | **Reformular** (nova identidade, ver proposta-design) |
| Textos da landing editáveis pelo admin | `admin_tabs/landingpage.php`, `salvarLandingPageConfig()` | Sim | ATIVA | Manter (com conteúdo estruturado) |
| Números "clientes / anos / cortes" | `carregarLandingPageConfig()` stat1..3 | Sim | ATIVA, com **valores fictícios padrão** (1500, 10, 5000) | Reformular (só exibir se preenchido) |
| Termos de uso e política de privacidade (modais) | `index.php:538-552` | Sim | ATIVA (HTML livre do admin) | Manter (páginas próprias) |
| Mapa (Google Maps embed) | `index.php:512` | Sim | ATIVA | Manter |
| Horário de funcionamento derivado da agenda da equipe | `index.php` | Sim | ATIVA | Manter |
| Dados estruturados de SEO (JSON-LD LocalBusiness) | `lib/seo_functions.php` | Sim | ATIVA | Manter |
| Botão "Admin" no topo do site público | `index.php:214` | Sim | ATIVA | **Remover do site** (acesso por URL própria) |
| PWA (manifesto, service worker, ícones gerados, botão instalar) | `manifest.php`, `js/sw.js`, `js/pwa_install.js`, `lib/pwa_functions.php` | Sim | ATIVA | A decidir |
| Widget do chatbot | `chatbot_widget.php` | Sim | ATIVA | A decidir (ver 2.8) |

## 2.2 Agendamento online (cliente)

| Funcionalidade | Localização | Utilizada? | Estado | Deve permanecer? |
|---|---|---|---|---|
| Wizard: dados → profissional → serviços/combos → data → horário → confirmação | `agendamento.php`, `js/agendamento*.js`, `js/script.js` | Sim | ATIVA | **Reformular** (menos passos; ver proposta) |
| Exige login **antes** de agendar | `agendamento.php:129` | Sim | ATIVA | Reformular (ver horários sem login; login/cadastro rápido só no fim) |
| Cálculo de horários livres (expediente, almoço, bloqueios, ausências, ocupação, antecedência mínima/máxima) | `get_horarios.php`, `lib/agendamento_functions.php` | Sim | ATIVA | Manter (motor único) |
| Grade fixa de 30 min (duração = "slots" × 30) | vários | Sim | ATIVA | Reformular (duração em minutos) |
| Revalidação no servidor + índice único contra horário duplicado | `processar_agendamento.php:~340`, `lib/migrations.php:571` | Sim | ATIVA | Manter |
| Barbeiros favoritos | `agendamento.php:14-50` | Sim | ATIVA | A decidir |
| Cupom / voucher no agendamento | `processar_agendamento.php`, `validate_discount_code` | Sim | ATIVA | Manter |
| Resgate de fidelidade no agendamento | `processar_agendamento.php` | Sim | ATIVA | Manter |
| Desconto de aniversário / indicação automáticos | `processar_agendamento.php` | Sim | ATIVA | Manter |
| Adesão a plano de assinatura durante o agendamento (Stripe Checkout) | `processar_agendamento.php:~418` | Sim, se o Stripe estiver configurado | PARCIALMENTE ATIVA | A decidir |
| E-mail + notificação de confirmação | `processar_agendamento.php` | Sim | ATIVA | Manter |
| Reagendar por `?reagendar_id=` (apaga o antigo e cria novo) | `agendamento_data.php:47`, `processar_agendamento.php:355` | **Nenhuma tela usa**, mas o caminho funciona | LEGADA + **falha de segurança (S-01)** | **Remover** |

## 2.3 Área do cliente

| Funcionalidade | Localização | Utilizada? | Estado | Deve permanecer? |
|---|---|---|---|---|
| Cadastro com confirmação por e-mail (48 h), honeypot, limite por IP | `registro.php`, `confirmar_email.php` | Sim | ATIVA | Manter |
| Login por e-mail, telefone ou CPF + "lembrar-me" | `login_cliente.php`, `functions.php:172` | Sim | ATIVA | Manter (definir identificador principal) |
| Esqueci / redefinir senha | `esqueci_senha.php`, `redefinir_senha.php` | Sim | ATIVA | Manter |
| Painel: próximos agendamentos, cancelar, reagendar | `cliente.php`, `cliente_actions.php` | Sim | ATIVA, **sem regras** (S-04, S-13) | Reformular (política de cancelamento/reagendamento) |
| Histórico e comprovante (impressão/PDF) | `cliente_tabs/historico.php`, `imprimir_comprovativo_cliente.php` | Sim | ATIVA | Manter |
| Avaliar atendimento + ver resposta da barbearia | `cliente_tabs/avaliacoes.php`, `salvar_avaliacao.php`, `avaliar.php` | Sim | ATIVA | Manter |
| Fidelidade (pontos e histórico) | `cliente_tabs/fidelidade.php` | Sim | ATIVA | Manter |
| Indicação (código e link) | `cliente_tabs/indicacao.php` | Sim | ATIVA | A decidir |
| Editar perfil + foto com recorte | `cliente_tabs/dados.php`, Cropper.js | Sim | ATIVA | Manter |
| Alterar senha | `cliente_actions.php` | Sim | ATIVA | Manter |
| Excluir conta (anonimiza histórico — LGPD) | `lib/auth_functions.php:excluirContaClientePermanente` | Sim | ATIVA | Manter (+ exportação de dados) |
| Notificações no app | `lib/notificacao_functions.php` | Sim | ATIVA | Manter |
| Confirmar presença por link | `confirmar_presenca.php` | Sim | ATIVA | Manter |
| Descadastro de marketing por link | `descadastrar.php` | Sim | ATIVA | Manter |

## 2.4 Painel do barbeiro

| Funcionalidade | Localização | Utilizada? | Estado | Deve permanecer? |
|---|---|---|---|---|
| Início (resumo do dia, metas) | `barbeiro.php` aba `inicio` | Sim | ATIVA | Manter |
| Agenda própria: concluir, cancelar, reagendar | `barbeiro.php`, `barbeiro_actions.php` | Sim | ATIVA (reagendar não valida expediente) | Manter |
| Agendamento manual pelo barbeiro | `barbeiro_actions.php:270` | Sim | ATIVA | Manter |
| Comanda: fechar atendimento, forma de pagamento, gorjeta, venda de produto | `barbeiro_actions.php:373,450` | Sim | ATIVA | Manter |
| Pausa de almoço fixa (1 h por dia) | `barbeiro_modals.php`, `config_almoco_barbeiro` | Sim | ATIVA | Reformular (como intervalo no expediente) |
| Anotações sobre o cliente | `salvar_nota_cliente` | Sim | ATIVA (sem checar vínculo, S-14) | Manter |
| Financeiro próprio (comissões) | aba `financeiro` | Sim | ATIVA | Manter |
| Histórico | aba `historico` | Sim | ATIVA | Manter |
| Perfil (foto, senha) | aba `perfil` | Sim | ATIVA | Manter |
| Avisos com SweetAlert | `js/painel_barbeiro.js:294,388` | Sim | **QUEBRADA** (SweetAlert não é carregado em `barbeiro.php`; avisos somem) | Manter (corrigir no novo) |
| Detalhes/IA do barbeiro (reuso de `admin_detalhes.js`) | `barbeiro.php:960` | Sim | ATIVA | Reformular |

## 2.5 Painel administrativo

| Funcionalidade | Localização | Utilizada? | Estado | Deve permanecer? |
|---|---|---|---|---|
| Dashboard (KPIs, comparação de períodos, alertas, atividade recente, gráficos) | `admin_tabs/dashboard.php`, `admin_data.php`, `js/admin_charts.js` | Sim | ATIVA | Reformular (menos cartões, mais foco) |
| Alertas operacionais (polling de 90 s) | `admin_alertas.php`, `obterAlertasGestaoAdmin` | Sim | ATIVA | Manter |
| Busca global e atalhos | `admin.php`, `js/admin_gestao.js` | Sim | ATIVA | Manter |
| Tema claro/escuro | `admin.php` | Sim | ATIVA | A decidir |
| Agenda: lista/filtro por dia, aprovar, concluir, cancelar, reagendar, excluir | `admin_tabs/agendamentos.php`, `actions/agendamentos.php` | Sim | ATIVA | Manter |
| Agendamento manual / combo pelo admin | `admin_modals/p2_agendamento_combo.php` | Sim | ATIVA | Manter |
| Confirmação de presença operacional (status "confirmado/pendente") | `actions/agenda_admin.php` | Sim | ATIVA | Manter |
| Histórico de alterações do agendamento | `agenda_historico` | Sim | ATIVA | Manter (auditoria) |
| Comanda (fechamento, pagamento, gorjeta, produtos) | `ajax_comanda.php`, `fechar_comanda` | Sim | ATIVA | Manter |
| Impressão da agenda do dia | `imprimir_agenda_dia.php` | Sim | ATIVA | Manter |
| Lembretes manuais + configuração dos automáticos | `actions/agendamentos.php` | Sim | ATIVA | Manter |
| Limpar agendamentos antigos | `limpar_agendamentos_antigos` | **Sem interface** | ABANDONADA | Remover (substituída por "limpar por período") |
| Rejeitar agendamento | `rejeitar` | **Sem interface** | LEGADA (fluxo de aprovação manual extinto) | A decidir |
| Clientes: CRUD, ativar/inativar, anotações, raio-X por IA | `admin_tabs/clientes.php`, `actions/clientes.php` | Sim | ATIVA | Manter |
| Assinaturas: lista, cancelar (Stripe), MRR | `admin_tabs/assinaturas.php` | Sim | ATIVA | A decidir (depende de 2.2) |
| Ativar assinatura manualmente | `ativar_assinatura` | **Sem interface** | ABANDONADA (regra "assinatura 100% online") | Remover ou decidir |
| Serviços, categorias, combos, planos | `admin_tabs/servicos.php`, `actions/servicos.php` | Sim | ATIVA | Manter |
| Produtos e estoque (entrada/saída, estoque mínimo, custo, histórico "Kardex") | `admin_tabs/servicos.php` | Sim | ATIVA | Manter |
| Equipe: CRUD, serviços que realiza, comissão (serviço, produto, assinatura), meta diária | `admin_tabs/barbeiros.php`, `actions/barbeiros.php` | Sim | ATIVA | Manter |
| Grade semanal de horários, bloqueios de horário, bloqueio do mês inteiro | `actions/barbeiros.php` | Sim | ATIVA | Manter |
| Ausências (folga, férias, atestado) | `barbeiro_ausencias` | Sim | ATIVA | Manter |
| Financeiro: despesas (recorrentes, vencimento, pagamento), comissões pagas, vales, meta financeira, DRE | `admin_tabs/financeiro.php`, `actions/financeiro.php` | Sim | ATIVA | Manter |
| Recibo de comissão (impressão) | `imprimir_recibo_comissao.php` | Sim | ATIVA | Manter |
| Relatórios (receita, equipe, serviços, horários de pico, clientes em risco, descontos, CMV, ocupação) + impressão + CSV | `admin_tabs/relatorios.php`, `lib/relatorio_functions.php`, `imprimir_relatorio.php`, `exportar_relatorio.php` | Sim | ATIVA | Manter (com dados confiáveis, ver banco) |
| Marketing: campanhas por segmento com envio em lotes, pré-visualização, teste, reativação, texto por IA | `admin_tabs/marketing.php`, `lib/marketing_functions.php` | Sim | ATIVA | Manter (com fila de envio) |
| Newsletter antiga (envio direto) | `enviar_newsletter` | **Sem interface** | DUPLICADA/ABANDONADA (substituída por campanhas) | Remover |
| Reativação antiga | `enviar_reativacao` | **Sem interface** | DUPLICADA/ABANDONADA | Remover |
| Cupons (percentual/fixo, validade, limite de uso, ativo) | `actions/marketing.php` | Sim | ATIVA | Manter |
| Vouchers / vale-presente + impressão | `gerar_voucher`, `imprimir_voucher.php` | Sim | ATIVA | Manter |
| Aniversário: configuração | `salvar_config_aniversario` | Sim | ATIVA | Manter |
| Aniversário: envio manual de cupom | `enviar_cupom_aniversario` | **Sem interface** | ABANDONADA | A decidir |
| Indicação: configuração | `salvar_config_indicacao` | **Sem interface** | ABANDONADA (config só editável pelo banco) | A decidir |
| Fidelidade: regras (por visita ou por valor, tipos de recompensa), ajuste manual, exportação | `admin_tabs/fidelidade.php` | Sim | ATIVA | Manter |
| Avaliações: moderação, destaque na landing, resposta, exclusão, lembretes, exportação, resumo por IA | `admin_tabs/avaliacoes.php`, `actions/avaliacoes.php` | Sim | ATIVA | Manter |
| Lembrete de avaliação antigo | `enviar_lembretes_avaliacao` | **Sem interface** | DUPLICADA/ABANDONADA (substituído por `lembrete_iniciar`) | Remover |
| Configurações: dados gerais, regras de agendamento, SMTP (+ teste), pagamentos/Stripe, IA/chatbot, tema, usuários e perfis, backup, limpeza, otimização | `admin_tabs/configuracoes.php`, `actions/configuracoes.php` | Sim | ATIVA | Manter (segredos fora do banco) |
| Salvar Stripe (ação antiga) | `salvar_config_stripe` | **Sem interface** | DUPLICADA (substituída por `salvar_config_pagamentos`) | Remover |
| Salvar tema (ação separada) | `salvar_tema` | **Sem interface** | DUPLICADA (tema salvo junto da landing) | Remover |
| Backup do banco (download do `.sqlite`) e backup de dados | `backup_sqlite`, `backup_dados` | Sim | ATIVA | Reformular (backup automático + criptografado) |
| Limpar todos os dados / por período | `limpar_dados`, `limpar_por_periodo` | Sim | ATIVA (destrutiva) | Reformular (só proprietário, com confirmação forte) |
| Reportar problema ao desenvolvedor | `actions/suporte.php` | Sim | ATIVA (e-mail pessoal fixo no código) | A decidir |
| CRM (tags, status de relacionamento) | `gestao_salvar_crm`, `admin_crm_clientes` | **Sem interface; dados nunca lidos** | ABANDONADA | A decidir |
| Metas por membro da equipe | `gestao_salvar_meta`, `admin_metas_equipe` | **Sem interface; nunca lidas** | ABANDONADA | A decidir |
| Retenção de clientes | `gestao_salvar_retencao`, `admin_retencao` | **Sem interface; nunca lida** | ABANDONADA | A decidir |
| Conciliação de pagamentos | `gestao_conciliar_pagamento`, `admin_conciliacao` | **Sem interface; nunca lida** | ABANDONADA | A decidir |
| Lista de espera | `gestao_salvar_espera`, `gestao_status_espera` | **Sem interface; nunca listada** | ABANDONADA | A decidir (útil para barbearia) |
| Reagendamento rápido pelo dashboard | `gestao_reagendar_rapido`; `js/admin_gestao.js:641,700` procura o formulário `gestao-quick-reschedule-form`, que **não existe em nenhum PHP** | Não | ABANDONADA (backend + JS órfãos) | Remover (reagendar já existe na agenda) |
| Ações em lote na agenda | `agenda_acao_lote` | **Sem interface** | ABANDONADA | A decidir |
| Alterar perfil de usuário (gestão) | `gestao_salvar_perfil_usuario` | **Sem interface** (perfil é editado em `salvar_usuario`) | DUPLICADA | Remover |
| Registro de atividade do admin | `admin_atividade`, `registrarAtividadeGestao` | Sim | ATIVA (registra só o nome da ação) | Reformular (auditoria completa) |

## 2.6 Assinaturas e pagamentos

| Funcionalidade | Localização | Utilizada? | Estado | Deve permanecer? |
|---|---|---|---|---|
| Planos mensais com serviços inclusos | `planos` | Sim | ATIVA | A decidir |
| Checkout Stripe (modo assinatura) | `processar_agendamento.php` | Sim, se configurado | ATIVA | A decidir |
| Webhook (adesão, renovação, cancelamento agendado, reativação, inadimplência) com idempotência | `webhook_stripe.php`, `lib/marketing_functions.php` | Sim | ATIVA | Manter se assinatura permanecer |
| Cancelamento pelo painel (chama a API do Stripe) | `actions/clientes.php:225` | Sim | ATIVA | Manter |
| Expiração automática com tolerância | `expirarAssinaturasVencidas` | Sim | ATIVA | Manter |
| Comissão sobre atendimento de assinante (padrão, fixa, %) | `calcularComissaoAtendimento` | Sim | ATIVA | Manter |
| Pagamento avulso online (Pix/cartão por atendimento) | — | **Não existe** | — | A decidir (novo) |

## 2.7 Comunicação

| Funcionalidade | Localização | Utilizada? | Estado | Deve permanecer? |
|---|---|---|---|---|
| E-mails transacionais (12 templates) | `email_templates/`, `lib/email_functions.php` | Sim | ATIVA | Manter |
| Lembretes (véspera e horas antes), idempotentes | `enviarLembretesAgendamentos`, `cron_lembretes.php` | Sim | ATIVA, **dependente de cron** (precisa de validação em produção) | Manter |
| Notificações no app (cliente) | `notificacoes` | Sim | ATIVA | Manter |
| Polling de novos agendamentos com som (admin/barbeiro) | `check_new_appointments.php`, `js/notif_agendamentos.js` | Sim | ATIVA | Manter |
| WhatsApp | links `wa.me` | Sim | ATIVA (apenas links) | Manter; API oficial a decidir |
| Opt-out de marketing vinculado ao cliente (LGPD) | `email_optout` | Sim | ATIVA | Manter |

## 2.8 Assistente / chatbot e IA

| Funcionalidade | Localização | Utilizada? | Estado | Deve permanecer? |
|---|---|---|---|---|
| Chatbot por regras (serviços, planos, profissionais, horários, contato, pagamento) | `assistente.php` | Sim | ATIVA | A decidir |
| Agendamento pelo chat (3º caminho de agendamento, com lógica própria) | `assistente.php:_botCriarAgendamento` | Sim | ATIVA / **DUPLICADA** | A decidir (se ficar, usar o motor único) |
| Login e cadastro dentro do chat (cadastro **sem confirmação de e-mail**) | `assistente.php:709-800` | Sim | ATIVA (S-07) | Reformular ou remover |
| Agente de IA que executa ações (agendar, cancelar, reagendar, atualizar perfil) | `lib/chatbot_agent.php` | Sim, se IA ligada | ATIVA | A decidir |
| Textos e análises por IA no painel (marketing, raio-X de cliente, parecer de RH, resumo de avaliações, insights financeiros) | `ajax_gemini.php` | Sim, se houver chave | ATIVA | A decidir (opcional, fase tardia) |
| Rodízio de várias chaves e alternativa entre provedores | `lib/ia_functions.php` | Sim | ATIVA | Simplificar |

## 2.9 Operação técnica

| Funcionalidade | Localização | Utilizada? | Estado | Deve permanecer? |
|---|---|---|---|---|
| Instalador web | `install.php` | Sim (1ª vez) | ATIVA (continua acessível depois, S-11) | Substituir por comando de setup |
| Migrations automáticas a cada requisição | `lib/migrations.php` | Sim | ATIVA | Substituir por migrations versionadas |
| Correção de dados com escape HTML duplo | `migracaoCorrigirEscapeDuplo` | Sim (uma vez) | LEGADA (já aplicada) | Não portar; considerar na migração |
| Suíte de testes de assinaturas | `tests/assinaturas.php` | Sim (manual) | ATIVA | Portar os cenários |
| Log de aplicação em arquivo | `_logs/app_log.txt` | Sim | ATIVA | Reformular (log estruturado, sem dados sensíveis) |

## 2.10 Resumo

- **~95 funcionalidades** mapeadas.
- **ATIVAS:** a grande maioria. O sistema é funcionalmente rico.
- **ABANDONADAS ou DUPLICADAS com backend vivo:** 19 ações do painel sem nenhuma
  interface que as dispare (lista confirmada em [legado-e-codigo-morto.md](legado-e-codigo-morto.md)).
- **QUEBRADA:** avisos do painel do barbeiro (SweetAlert não carregado).
- **Com defeito de regra de negócio:** reagendamento e cancelamento pelo cliente.
- **Sugeridas para remoção:** reagendar por URL, ações duplicadas antigas e o botão
  "Admin" no site. Todas as demais dependem de decisão.
