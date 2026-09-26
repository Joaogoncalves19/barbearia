# 1. Auditoria — Inventário completo do sistema atual

Commit auditado: `4ee4b82` (branch `main`). Todos os itens abaixo são **confirmados**
por leitura do código, salvo indicação.

## 1.1 Visão geral

| Item | Valor |
|------|-------|
| Linguagem | PHP procedural (sem framework), HTML/CSS/JS "vanilla" |
| Versão de PHP exigida | `>=7.3` no `composer.json`, `7.4` no `install.php` (**inconsistente**) |
| Banco | SQLite em arquivo único: `_dados/database.sqlite` |
| Servidor web | Apache com `.htaccess` (reescrita de URL sem `.php`, cache e gzip) |
| Hospedagem | **Provável:** hospedagem compartilhada gratuita (InfinityFree). Evidência: comentários em `assistente.php:3-4` e `:47-48` sobre o firewall do InfinityFree |
| Build | Nenhum. Sem bundler, sem minificação, sem Composer instalado (`vendor/` ausente) |
| Deploy | **Provável:** upload manual de arquivos (FTP/gerenciador). Não há script nem CI |
| Testes | 1 script CLI (`tests/assinaturas.php`) |
| Documentação | Nenhuma além de comentários no código |

### Tamanho do código (sem `uploads/` e sem `PHPMailer/`)

| Área | Arquivos | Linhas |
|------|---------:|-------:|
| PHP na raiz (páginas e endpoints) | 47 | 15.322 |
| `lib/` (regras e utilitários) | 17 | 6.879 |
| `admin_tabs/` (abas do painel) | 13 | 9.150 |
| `actions/` (ações do painel) | 11 | 2.805 |
| `admin_modals/` | 8 | 1.254 |
| `partials/` (CSS/JS inline em PHP) | 6 | 1.441 |
| `cliente_tabs/` | 5 | 350 |
| `email_templates/` | 12 | 305 |
| `js/` | 18 | 6.568 |
| `css/` | 12 | 7.321 |
| `tests/` | 1 | 383 |
| **Total próprio** | **150** | **≈ 51.800** |

Além disso: 1.137 atributos `style="..."` e 91 handlers inline (`onclick=` etc.) nos
arquivos PHP, e 30 blocos `<style>` embutidos.

## 1.2 Estrutura de diretórios

```text
/                         ← raiz pública do site (TODO arquivo é acessível por HTTP)
├── .htaccess             ← URL sem .php, gzip, cache de estáticos
├── robots.txt            ← cita arquivos que não existem mais (ver legado)
├── composer.json         ← declara PHPMailer, mas não há vendor/
├── functions.php         ← "bootstrap": headers de segurança, sessão, CSRF,
│                            throttle, uploads e require de todas as libs
├── install.php           ← instalador (cria tabelas + 1º administrador)
├── index.php             ← landing page pública
├── agendamento.php       ← agendamento online (inclui agendamento_data.php
│                            e processar_agendamento.php)
├── login.php             ← login de admin E de barbeiro
├── login_cliente.php, registro.php, esqueci_senha.php, redefinir_senha.php,
│   confirmar_email.php, logout_cliente.php
├── cliente.php           ← área do cliente (+ cliente_data/actions/modals, cliente_tabs/)
├── barbeiro.php          ← painel do barbeiro (+ barbeiro_actions/modals)
├── admin.php             ← painel administrativo (SPA "caseira" com abas via AJAX)
│   ├── admin_actions.php ← roteador de ações → actions/*.php
│   ├── admin_data.php    ← prepara dados do dashboard/relatórios
│   ├── admin_alertas.php ← polling de alertas
│   ├── admin_modals.php  ← inclui admin_modals/p1..p8
│   └── admin_tabs/       ← 13 abas
├── assistente.php        ← chatbot público (regras + IA opcional que EXECUTA ações)
├── chatbot_widget.php    ← widget flutuante do chatbot
├── ajax_*.php            ← endpoints AJAX (IA, comanda, teste de SMTP)
├── get_horarios.php      ← horários livres (usado pelo agendamento e painéis)
├── check_new_appointments.php ← polling de novos agendamentos
├── webhook_stripe.php    ← webhook de assinaturas
├── cron_lembretes.php    ← rotina de lembretes (CLI ou URL com token)
├── avaliar.php, salvar_avaliacao.php ← avaliação pós-atendimento
├── confirmar_presenca.php, descadastrar.php ← links públicos assinados (HMAC)
├── imprimir_*.php, exportar_relatorio.php ← impressões e CSV
├── manifest.php, pwa_head.php, js/sw.js ← PWA
├── lib/                  ← 17 módulos de funções
├── actions/              ← 11 arquivos de ações do painel
├── admin_tabs/, admin_modals/, cliente_tabs/, partials/
├── email_templates/      ← 12 templates de e-mail
├── css/ (12), js/ (18)
├── PHPMailer/            ← PHPMailer 6.10.0 copiado manualmente
├── tests/assinaturas.php
├── _dados/               ← banco SQLite (bloqueado por .htaccess)
├── _logs/                ← app_log.txt (bloqueado por .htaccess)
└── uploads/              ← imagens e vídeo (PHP bloqueado por .htaccess)
```

**Observação estrutural:** não existe separação entre código e raiz pública.
Bibliotecas, ações, templates e "includes" ficam todos dentro da pasta servida
pelo Apache. Algumas pastas têm `.htaccess` de bloqueio (`_dados`, `_logs`, `lib`,
`admin_modals`, `email_templates`, `partials`, `tests`, `js` parcial), mas
**`actions/`, `admin_tabs/`, `cliente_tabs/` e os includes da raiz não têm**
(ver [seguranca.md](seguranca.md), S-15).

## 1.3 Superfícies do sistema

| Área | Ponto de entrada | Autenticação |
|------|------------------|--------------|
| Site público | `index.php` | Nenhuma |
| Agendamento online | `agendamento.php` | Exige cliente logado para enviar |
| Área do cliente | `cliente.php` | Sessão de cliente + cookie "lembrar-me" |
| Painel do barbeiro | `barbeiro.php` | Sessão de barbeiro (login em `login.php`) |
| Painel administrativo | `admin.php` | Sessão de admin + perfil (proprietário, gerente, recepção, financeiro) |
| Chatbot | `assistente.php` + `chatbot_widget.php` | Público; login/cadastro dentro do chat |
| Integrações | `webhook_stripe.php`, `cron_lembretes.php` | Assinatura HMAC / token |
| Links de e-mail | `avaliar.php`, `confirmar_presenca.php`, `descadastrar.php`, `confirmar_email.php`, `redefinir_senha.php` | Token no link |

## 1.4 Frontend

- HTML gerado por PHP, com muito CSS e JS inline.
- Sem framework JS. Cada página carrega seus próprios scripts (mapa abaixo).
- O painel admin carrega **todas** as tabelas em memória e as serializa num objeto
  JavaScript global (`adminJSData`) a cada carregamento.
- Tema: cor de destaque configurável (`css/custom_theme.css.php`, gerado a partir do banco).
- Ícones: Font Awesome 6.4.0 (CDN). Fontes: Google Fonts (Inter, Outfit).

| Página | CSS | JS |
|--------|-----|----|
| `admin.php` | admin_components, admin_gestao, admin_style, admin_theme, custom_theme, notif_agendamentos, style | admin_agendamentos, admin_charts, admin_detalhes, admin_gestao, admin_ui, admin_ui_core, notif_agendamentos |
| `agendamento.php` | agendamento, agendamento_wizard, custom_theme, style | agendamento, agendamento_wizard, script |
| `barbeiro.php` | custom_theme, notif_agendamentos, painel_barbeiro, style | admin_detalhes, notif_agendamentos, painel_barbeiro |
| `cliente.php` | custom_theme, style (+ `partials/cliente_style.php`) | cliente (+ `partials/cliente_script.php`) |
| `index.php` | `partials/index_style.php` (inline) | `partials/index_script.php` (inline) |
| telas de login/cadastro | auth, custom_theme, style | auth |

## 1.5 Backend

- Cada arquivo `.php` da raiz é um endpoint. Não há roteador central, exceto o
  despachante de ações do painel (`admin_actions.php`, 107 ações mapeadas).
- `functions.php` é incluído por quase tudo e carrega **as 14 bibliotecas** a cada requisição.
- Toda requisição que abre o banco executa as rotinas de migração (`lib/migrations.php`).
- Várias páginas criam tabelas e colunas em tempo de execução (`CREATE TABLE IF NOT EXISTS`
  em 40+ lugares, `ALTER TABLE` fora das migrations em 26 lugares).

## 1.6 APIs / endpoints (JSON ou AJAX)

| Endpoint | Consumidor | Proteção |
|----------|-----------|----------|
| `get_horarios.php` | agendamento, painéis | sessão |
| `check_new_appointments.php` | admin, barbeiro (polling) | sessão admin/barbeiro |
| `admin_alertas.php` | admin (polling a cada 90 s) | sessão admin |
| `ajax_comanda.php` | admin | sessão admin |
| `ajax_config.php` | admin (teste de SMTP) | sessão admin (**sem checar perfil**) |
| `ajax_gemini.php` | admin, barbeiro (textos e análises por IA) | sessão admin/barbeiro, sem CSRF |
| `assistente.php` | widget do chatbot | público, limite de 60 mensagens/10 min por IP |
| `agendamento.php` (POST `action=`) | favoritar barbeiro, validar cupom | CSRF |
| `webhook_stripe.php` | Stripe | HMAC + nova busca do evento na API |

## 1.7 Banco de dados

SQLite, ~50 tabelas, sem chaves estrangeiras, valores monetários como `TEXT`,
listas de IDs em CSV. Detalhes em [banco-atual.md](banco-atual.md).

## 1.8 Autenticação

| Tipo | Onde | Mecanismo |
|------|------|-----------|
| Admin | `login.php` (`login_type=admin`) | tabela `users`, `password_hash`, throttle por IP (`sys_login_attempts`) |
| Barbeiro | `login.php` (`login_type=barbeiro`) | tabela `barbeiros` (coluna `password`), por `username` ou **nome** |
| Cliente | `login_cliente.php` | e-mail/telefone/CPF + senha, throttle `login_throttle`, cookie "lembrar-me" (selector/validator) |
| Cliente (chat) | `assistente.php` | senha digitada no chat (**sem o throttle de login**) |

Autorização do admin: 4 perfis fixos em código (`lib/admin_gestao_functions.php`),
aplicados por aba. A coluna `users.permissions` existe, mas não é usada.

## 1.9 Integrações externas

| Serviço | Uso | Onde | Estado |
|---------|-----|------|--------|
| **SMTP** (PHPMailer) | E-mails transacionais e campanhas | `lib/email_functions.php` | ATIVA |
| **Stripe** | Assinatura mensal (Checkout + webhooks + cancelamento) | `processar_agendamento.php`, `webhook_stripe.php`, `actions/clientes.php` | ATIVA (opcional) |
| **Groq** (API compatível com OpenAI) | IA do chatbot e textos do painel | `lib/ia_functions.php`, `lib/chatbot_agent.php` | ATIVA (opcional) |
| **Google Gemini** (endpoint compatível com OpenAI) | Alternativa de IA quando a Groq falha | `lib/ia_functions.php` | ATIVA (opcional) |
| Cerebras | Citado em comentários como alternativa de IA | só comentários | **Não existe no código** (documentação enganosa) |
| **WhatsApp** | Só links `wa.me` (sem API) | landing, marketing, configurações | ATIVA (apenas links) |
| **Google Maps** | Mapa incorporado (iframe) | `index.php` | ATIVA |
| Mixkit | Som de notificação (mp3 externo) | admin, barbeiro | ATIVA |

## 1.10 Armazenamento de arquivos e uploads

- Pasta `uploads/` (PHP bloqueado por `.htaccess`), subpastas por cliente (`cliente-<id>`).
- Upload com validação de MIME (`finfo`), `getimagesize` e nome aleatório
  (`functions.php:823-892`). Tamanho máximo de 3 a 5 MB.
- Recorte de foto do cliente no navegador (Cropper.js) e envio em base64.
- Geração de ícones do PWA com GD (`lib/pwa_functions.php`).
- **Arquivos pesados sem uso:** `bg.png` (2,5 MB), `bg2.png` (2,4 MB) e
  `minha-conta-barbearia-illustration.png` (1,9 MB) não são referenciados em lugar nenhum.

## 1.11 E-mails

12 templates em `email_templates/` com layout comum (`lib/email_layout.php`):
confirmação, cancelamento, reagendamento, lembrete, lembrete de avaliação, avaliação,
resposta a avaliação, confirmação de cadastro, redefinição de senha, reativação de
cliente, newsletter genérica e suporte ao desenvolvedor.

## 1.12 Cron jobs, workers e scripts

| Rotina | Como roda | Faz o quê |
|--------|-----------|-----------|
| `cron_lembretes.php` | CLI (`php cron_lembretes.php`) ou URL `?token=` | Lembretes da véspera e "X horas antes", expira assinaturas vencidas |
| Campanhas de marketing | Processadas em **lotes disparados pelo navegador do admin** (`campanha_lote`) | Envio em massa sem worker |
| Despesas recorrentes | Geradas "sob demanda" ao abrir o financeiro (`garantirDespesasRecorrentes`) | Sem agendador |
| Expiração de assinaturas | No cron **e** a cada carregamento do painel (`admin_data.php`) | Redundante, intencional |
| Liberação de horários com pagamento pendente | Ao consultar horários (`liberarAgendamentosPagamentoExpirado`) | Sem agendador |

Não há fila nem worker. **Precisa de validação:** se o cron está configurado em produção
(o InfinityFree não oferece cron; o provável é que os lembretes automáticos **não rodem**).

## 1.13 Variáveis de ambiente e configuração

- **Não há `.env` nem variáveis de ambiente.** Toda configuração fica na tabela
  `configuracoes` (JSON por seção), incluindo **segredos em texto puro**: senha SMTP,
  chave secreta e segredo de webhook do Stripe, chaves de IA.
- Constantes no código: fuso horário padrão, segredos HMAC de avaliação e descadastro
  (**fixos no código-fonte**), validade de tokens, proxies confiáveis.
- Seções de configuração: `config_geral`, `landing_page`, `theme_config`, `config_email`,
  `config_agendamento`, `config_lembretes`, `config_chatbot`, `config_gemini` (legado),
  `config_stripe`, `config_site`, `config_cron`, `fidelidade_config`,
  `config_aniversario`, `config_indicacao`, `config` (legado).

## 1.14 Dependências

PHPMailer copiado na pasta + bibliotecas por CDN (Font Awesome, Chart.js, SweetAlert2,
flatpickr, Cropper.js, html2pdf.js). Detalhes em [dependencias.md](dependencias.md).

## 1.15 Build, deploy, testes e documentação

- **Build:** inexistente. O cache de CSS/JS é resolvido com `?v=<mtime>` (`assetUrl()`).
- **Deploy:** inexistente como processo. Não há ambiente de homologação.
- **Testes:** `tests/assinaturas.php` (verificação de assinaturas por CLI, sobre uma cópia
  do banco). Não há testes para agendamento, preços, autenticação ou interface.
- **Documentação:** só comentários, alguns desatualizados (ex.: "Somente leitura do banco"
  no topo do `assistente.php`, que hoje grava agendamentos e contas).
