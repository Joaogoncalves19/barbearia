# 10. Proposta de nova arquitetura

## 10.1 Restrições que orientam as escolhas

- **Tamanho real:** uma barbearia, poucos profissionais, dezenas a centenas de agendamentos
  por semana. Não há necessidade de escala horizontal.
- **Equipe:** o código atual é PHP. Trocar de linguagem só se justifica com ganho claro.
- **Operação:** precisa de tarefas agendadas confiáveis (lembretes, expiração, campanhas).
  Hoje elas dependem de cron opcional ou de uma aba aberta.
- **Segurança:** boa parte dos problemas atuais nasce de reimplementar à mão coisas que
  frameworks maduros já resolvem (CSRF, throttle, migrations, autorização, filas).
- **Continuidade:** o sistema atual continua no ar até a troca. O novo roda em paralelo.

## 10.2 Visão geral

```text
                        Navegador
                            │
                            ▼
                  public/index.php  (única entrada; assets compilados)
                            │
        ┌───────────────────┼─────────────────────────┐
        ▼                   ▼                         ▼
   Área Site          Área Cliente/Profissional    Área Admin         Webhooks
   (controllers)      (controllers)                (controllers)      (Stripe)
        └───────────────────┼─────────────────────────┘
                            ▼
               Módulos de domínio (monólito modular)
   Identidade · Catálogo · Equipe · Agenda · Preço · Atendimento/Caixa ·
   Financeiro · Promoções/Fidelidade · Assinaturas · Comunicação ·
   Avaliações · Conteúdo do site · Relatórios · Auditoria
                            │
         ┌──────────────────┼──────────────────┐
         ▼                  ▼                  ▼
     Banco (ORM +      Fila de jobs        Integrações (adaptadores)
     migrations)       + agendador         SMTP · Stripe · IA (opcional)
```

## 10.3 Decisões arquiteturais

Cada decisão segue o formato pedido: problema, solução, motivo, alternativa considerada, impacto.

### AD-01 — Linguagem e framework: PHP 8.3+ com Laravel

- **Problema:** o sistema reimplementa à mão roteamento, CSRF (2 vezes), throttle (2 vezes),
  migrations em tempo de execução, autenticação, autorização, e-mail e tarefas agendadas.
  Vários achados de segurança vêm daí (S-09, S-12, S-15, S-22).
- **Solução:** Laravel (versão estável mais recente no início da Fase 1), em PHP 8.3+.
- **Motivo:** traz prontos e testados roteamento, CSRF, rate limiting, autenticação por
  "guards", policies de autorização, migrations versionadas, filas, agendador, e-mail,
  validação e ferramentas de teste. Mantém a linguagem que o projeto já usa e roda em
  hospedagens PHP comuns com SSH e cron.
- **Alternativas consideradas:**
  - *PHP puro organizado (ou microframework como Slim):* mais leve, mas exigiria montar e
    manter as mesmas peças que hoje dão problema. Rejeitada.
  - *Node.js/Next.js ou outra stack JS:* troca de linguagem e de hospedagem sem ganho
    proporcional para o tamanho do negócio. Rejeitada.
  - *Plataforma SaaS de agendamento (Trinks, Booksy etc.):* perde os diferenciais próprios
    (fidelidade, assinatura, relatórios e site próprios) e o controle dos dados. Fica
    registrada como alternativa de negócio, fora do escopo técnico.
- **Impacto:** exige hospedagem com PHP 8.3+, Composer, acesso SSH e cron (a hospedagem
  gratuita atual, provavelmente InfinityFree, **não atende**). Ver **D-01** em decisoes-pendentes.

### AD-02 — Monólito modular

- **Problema:** hoje as regras se espalham por arquivos de tela, e um arquivo de "marketing"
  concentra cobrança recorrente.
- **Solução:** uma aplicação única, organizada em módulos por domínio. Cada módulo tem os
  próprios modelos, serviços de domínio, policies e eventos. Controllers ficam finos e
  chamam serviços. Um módulo não acessa as tabelas de outro diretamente; usa o serviço dele.
- **Motivo:** fronteiras claras sem o custo operacional de vários serviços.
- **Alternativa:** microserviços. Rejeitada: complexidade de deploy e comunicação
  incompatível com o tamanho do negócio.
- **Impacto:** exige disciplina de dependências entre módulos, verificada em revisão de
  código (e, se útil, por teste de arquitetura).

Estrutura de pastas proposta:

```text
app/
├── Modules/
│   ├── Identity/        (usuários da equipe, clientes, perfis, permissões)
│   ├── Catalog/         (categorias, serviços, combos, produtos, estoque)
│   ├── Team/            (profissionais, expediente, intervalos, ausências, bloqueios)
│   ├── Scheduling/      (disponibilidade, agendamentos, políticas, lembretes)
│   ├── Pricing/         (orçamento, descontos, precedência)
│   ├── Checkout/        (comanda, pagamentos, gorjetas, venda de produtos)
│   ├── Finance/         (despesas, comissões, vales, DRE)
│   ├── Loyalty/         (fidelidade, cupons, vale-presente, indicação, aniversário)
│   ├── Subscriptions/   (planos, assinaturas, gateway)          [decisão]
│   ├── Communication/   (e-mails, campanhas, opt-out, notificações)
│   ├── Reviews/         (avaliações, respostas, destaque)
│   ├── SiteContent/     (conteúdo da home, galeria, páginas legais)
│   ├── Reporting/       (consultas de leitura para relatórios)
│   └── Audit/           (registro de auditoria)
├── Http/Controllers/{Site,Customer,Professional,Admin,Webhooks}
resources/
├── views/{site,customer,professional,admin,emails,components}
├── css/{tokens.css, site/, panel/}
└── js/{site/, panel/}
database/{migrations,seeders,factories}
tests/{Unit,Feature,Browser}
tools/legacy-import/      (importador do banco antigo; ver estrategia-migracao)
public/                   (raiz pública: index.php + assets compilados)
```

### AD-03 — Interface renderizada no servidor + JavaScript mínimo

- **Problema:** hoje há regra de negócio duplicada no navegador e um "SPA caseiro" que
  serializa todas as tabelas em JavaScript (S-02, S-03).
- **Solução:** páginas renderizadas no servidor (Blade, com escape automático), componentes
  de interface reutilizáveis e **Alpine.js** para interações locais (abrir/fechar, passos do
  agendamento, seleção de horários). O navegador pede ao servidor os dados de que precisa
  (ex.: horários livres, orçamento do agendamento) e **não recalcula preço nem disponibilidade**.
- **Motivo:** simples, rápido, fácil de testar, bom para SEO no site público, e elimina a
  exposição de dados.
- **Alternativa:** SPA (React/Vue) + API. Rejeitada: duas aplicações para manter e mais
  superfície de erro, sem necessidade real. *Livewire* pode ser avaliado para telas densas
  do admin num experimento curto na Fase 1, mas **não** é premissa.
- **Impacto:** o painel faz navegação por páginas (com carregamento parcial onde fizer
  sentido), sem estado global em JavaScript.

### AD-04 — Banco: SQLite (modo WAL) por padrão, portável para MySQL

- **Problema:** schema sem integridade, sem tipos, mutável em tempo de execução.
- **Solução:** schema novo definido **só** por migrations versionadas, com chaves estrangeiras,
  tipos corretos e restrições únicas. Motor padrão **SQLite em modo WAL**, com o código
  usando só recursos portáveis (via ORM/query builder) para permitir MySQL/MariaDB se necessário.
- **Motivo:** para uma barbearia, SQLite é suficiente, não exige servidor de banco, e o backup é
  cópia de arquivo. A migração a partir do banco atual (também SQLite) fica mais simples.
- **Alternativa:** MySQL/MariaDB desde o início. Válida se a hospedagem escolhida oferecer banco
  gerenciado com backup, ou se houver plano de várias unidades. Ver **D-02**.
- **Impacto:** gatilhos para migrar para MySQL: várias unidades, muitos processos escrevendo em
  paralelo ou necessidade de acesso externo ao banco.

### AD-05 — Tipos e tempo

- Dinheiro em **centavos inteiros** (`price_cents`), nunca `float` ou texto.
- Durações em **minutos**. Grade de horários configurável (padrão proposto: 15 min).
- Datas e horas gravadas em **UTC** (`starts_at`, `ends_at`) e exibidas no fuso do
  estabelecimento (configuração única, respeitada em todo lugar, ao contrário do atual).
- IDs internos inteiros. Códigos públicos (cupom, vale-presente, links) gerados com fonte
  criptográfica e **nunca** reaproveitando o ID interno.

### AD-06 — Motor único de agenda

- **Problema:** 3 caminhos de criação e 4 de reagendamento, com validações diferentes (S-01, S-04).
- **Solução:** módulo `Scheduling` com dois serviços:
  - `Availability`: calcula horários livres a partir de expediente, intervalos, ausências,
    bloqueios, agendamentos existentes, duração total, antecedências e grade.
  - `BookingService`: `quote`, `create`, `reschedule`, `cancel`, `complete`, `noShow`. Aplica
    políticas (prazos, status permitidos, quem pode o quê) e registra o histórico.
- **Concorrência:** gravação em transação com nova checagem de sobreposição (`ends_at`
  considerado) dentro da transação e índice único de apoio. No SQLite, a escrita é serializada
  (`BEGIN IMMEDIATE`).
- **Alternativa:** manter validações por canal. Rejeitada (causa atual de inconsistências).
- **Impacto:** site, cliente, profissional, admin e chatbot chamam o mesmo código; os testes
  cobrem o motor uma única vez.

### AD-07 — Histórico financeiro imutável

- **Problema:** relatórios recalculam o valor pelo preço atual (arquitetura-atual 3.3.9).
- **Solução:** o agendamento guarda **itens** (serviço/combo/produto, nome, preço e duração no
  momento), **ajustes** (descontos com origem e valor) e **pagamentos** (forma, valor, gorjeta).
  Comissão calculada no fechamento e **gravada**.
- **Motivo:** relatórios, comissões e comprovantes passam a bater sempre.
- **Impacto:** mudanças no catálogo valem só para agendamentos futuros.

### AD-08 — Motor de preço único

- **Solução:** módulo `Pricing` que recebe (cliente, itens, código promocional, uso de fidelidade)
  e devolve um **orçamento** com a linha de cada desconto e o motivo de escolha. Implementa as
  regras R-10 a R-16 e R-11 (assinatura) de [proposta-produto.md](proposta-produto.md#95-regras-de-negócio-atuais-a-preservar-fonte-código).
- O mesmo orçamento é exibido ao cliente, gravado no agendamento e usado na comanda.

### AD-09 — Identidade e autorização

- Dois tipos de conta: **equipe** (proprietário, gerente, recepção, financeiro, profissional)
  e **clientes**, com sessões separadas.
- Permissões finas agrupadas em perfis editáveis; **negar por padrão**; checagem em policies
  (servidor), nunca só escondendo botões.
- Sessões revalidadas: usuário desativado ou com perfil alterado perde o acesso na hora.
- Compatibilidade: hashes bcrypt atuais (`password_hash`) são aceitos pelo Laravel, então
  clientes, barbeiros e admins **mantêm as senhas** na migração.

### AD-10 — Configurações e segredos

- **Segredos** (SMTP, Stripe, chaves de IA, segredos HMAC, chave do app) em variáveis de
  ambiente (`.env` fora da raiz pública). Se o dono precisar editar algum pelo painel, ele
  é gravado **criptografado**.
- **Configurações de negócio** (regras de agenda, fidelidade, lembretes, textos do site) em
  tabela tipada e validada, com valores padrão no código, **sem** dados fictícios exibidos ao público.

### AD-11 — Tarefas em segundo plano

- Agendador do framework (uma entrada de cron por minuto) + fila em banco para: e-mails,
  lembretes, campanhas, expiração de assinaturas e reservas, despesas recorrentes e
  processamento de webhooks.
- Envios idempotentes (chave por agendamento + tipo de lembrete).
- **Impacto:** exige cron na hospedagem (D-01).

### AD-12 — Integrações por adaptadores

- E-mail: componente de e-mail do framework (SMTP ou provedor transacional; ver **D-05**).
- Stripe: SDK oficial, versão da API fixada; webhook registra o evento, processa em transação
  e só então marca como concluído (corrige S-21).
- IA (opcional): interface única `AiClient`, com um provedor configurado; saída tratada
  sempre como texto.
- Cada adaptador tem um *fake* para testes.

### AD-13 — Frontend e design system

- Build com **Vite**; CSS próprio com **tokens** (variáveis CSS) compartilhados entre site e
  painel, e duas "peles": `site` (marca) e `panel` (produtividade). Sem framework de UI pesado.
- Ícones em SVG (subconjunto de uma biblioteca aberta, ex.: Lucide), fontes hospedadas localmente.
- Imagens responsivas (`srcset`, WebP/AVIF) geradas no upload.
- Acessibilidade WCAG 2.2 AA como critério de aceite.
- CSP estrita com nonce (sem `'unsafe-inline'`).

### AD-14 — Observabilidade e auditoria

- Log estruturado por canal, com rotação e **sem** segredos ou dados sensíveis.
- Rastreamento de erros (ex.: Sentry, opcional; ver **D-18**).
- Tabela de auditoria para ações administrativas: autor, ação, entidade, antes/depois, IP e data.
- Página de saúde (fila, agendador, último backup, e-mail) no painel do proprietário.

### AD-15 — Compatibilidade de URLs antigas

Links já enviados por e-mail e integrações precisam continuar funcionando após a troca:

| URL antiga | Tratamento |
|------------|-----------|
| `/`, `/agendamento`, `/cliente`, `/login_cliente`, `/registro` | Redirecionamento 301 para as novas rotas |
| `/avaliar.php?a=&t=` | Rota de compatibilidade que valida o token antigo (por período limitado) |
| `/confirmar_presenca.php?ag=&t=` | Idem |
| `/descadastrar.php?e=&t=` | Idem (opt-out deve funcionar sempre) |
| `/webhook_stripe.php` | Mantida como alias do novo endpoint até atualizar a URL no painel do Stripe |
| `/cron_lembretes.php` | Removida (substituída pelo agendador) |

### AD-16 — Ambientes, CI e deploy

- Ambientes: **local**, **homologação** (dados anonimizados) e **produção**.
- CI (GitHub Actions): lint, análise estática (PHPStan/Larastan), testes, build de assets,
  `composer audit`/`npm audit`.
- Deploy automatizado a partir de branch/tag, com migrations, backup prévio e rollback.
- O sistema atual **não é tocado**. A troca acontece por DNS ou apontamento de pasta (ver roadmap).

### AD-17 — Chatbot e IA (opcionais)

- Se mantidos (**D-04**), entram só depois do núcleo estável. O chatbot vira um cliente dos
  serviços `Availability`/`BookingService`/`Pricing`. **Não** autentica por chat e **não** cria
  contas sem confirmação.

## 10.4 Modelo de dados proposto (conceitual)

```text
IDENTIDADE
  staff_users (id, name, email*, password, role_id, active, …)
  roles (id, name) · permissions (id, key*) · role_permission
  customers (id, name, email*, phone*, cpf*, birth_date, photo, password, email_verified_at,
             referral_code*, referred_by_id → customers, marketing_opt_out_at, anonymized_at, …)

CATÁLOGO
  categories · services (id, category_id, name, description, duration_min, price_cents, active, sort)
  packages (combos) · package_items (package_id, service_id)
  products (id, name, price_cents, cost_cents, min_stock, active) · stock_movements (…, qty, reason, user)

EQUIPE
  professionals (id, staff_user_id, display_name, bio, photo, instagram, commission rules…)
  professional_service (professional_id, service_id)
  working_hours (professional_id, weekday, start, end)       ← intervalos = várias faixas no dia
  time_off (professional_id, starts_at, ends_at, type, reason)
  blocked_slots (professional_id, starts_at, ends_at, reason)

AGENDA
  appointments (id, customer_id?, professional_id, starts_at, ends_at, status, source,
                customer_name, customer_phone, customer_email,   ← contato de quem não tem conta
                notes, created_by, cancelled_at, cancel_reason, confirmed_at, …)
  appointment_items (appointment_id, type, ref_id, name, price_cents, duration_min)
  appointment_adjustments (appointment_id, type, source_ref, amount_cents, description)
  appointment_events (appointment_id, event, data_json, actor, created_at)
  reminders_sent (appointment_id, kind, sent_at)                  ← idempotência

CAIXA E FINANCEIRO
  payments (id, appointment_id?, method, amount_cents, tip_cents, paid_at, received_by)
  commissions (id, professional_id, appointment_id, base_cents, rate, amount_cents, status)
  commission_payouts (id, professional_id, period_start, period_end, total_cents, paid_at)
  advances (vales) · expenses (id, category, description, amount_cents, due_date, paid_at,
                              recurrence_rule, parent_id)

PROMOÇÕES E FIDELIDADE
  coupons (code*, type, value, valid_until, max_uses, active) · coupon_redemptions (coupon_id, customer_id, appointment_id)
  gift_cards (code*, amount_cents, buyer, valid_until, redeemed_appointment_id, redeemed_at)
  loyalty_ledger (customer_id, points, reason, appointment_id, created_at)  ← saldo = soma
  referral_rewards

ASSINATURAS  [decisão]
  plans · plan_services · subscriptions (histórico completo, 1 vigente por cliente)
  subscription_payments · gateway_events (gateway, event_id*, status, processed_at)

COMUNICAÇÃO E AVALIAÇÕES
  notifications · campaigns · campaign_recipients · email_templates (opcional)
  reviews (appointment_id*, customer_id, professional_id, rating, comment, status, featured)
  review_replies

SITE E SISTEMA
  site_sections / site_media (galeria) · settings (key*, value tipado) · audit_logs
  waitlist_entries [decisão]
```

## 10.5 O que **não** entra na arquitetura

- Microserviços, Kubernetes, filas externas (Redis/RabbitMQ), GraphQL, SPA.
- Multiempresa/multiunidade (a arquitetura não impede, mas não será construída sem decisão).
- Reaproveitamento de código do sistema atual: ele é **referência de regras**, não base.
