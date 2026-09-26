# Arquitetura do novo sistema (Fase 1)

Implementação da proposta de [proposta-arquitetura.md](proposta-arquitetura.md). Este
documento descreve **o que existe** em `novo-sistema/` e as **convenções obrigatórias**
para as próximas fases.

## 1. Visão geral

```text
novo-sistema/
├── app/
│   ├── Console/Commands/      comandos do sistema (batimento do agendador, diagnóstico)
│   ├── Http/
│   │   ├── Controllers/<Área> Site · Auth · Panel · Prototypes (finos)
│   │   ├── Middleware/        SecurityHeaders · EnsureStaffIsActive · EnsurePrototypesEnabled
│   │   └── Requests/<Área>    validação de entrada (FormRequest)
│   ├── Modules/<Módulo>/      regras de negócio por domínio
│   │   ├── Identity/          User (equipe), StaffRole
│   │   └── Shared/            Money (centavos), BaseJob
│   ├── Providers/             AppServiceProvider (URLs, Gates, rate limit, filas)
│   ├── Support/Prototypes/    dados de exemplo das telas de referência (temporário)
│   └── View/Components/       componentes Blade com lógica (Icon)
├── config/barbearia.php       infraestrutura do sistema (lida do .env)
├── config/permissions.php     matriz de permissões (deny by default)
├── database/                  migrations, factories, seeders
├── resources/{css,js,views,icons}
├── routes/web.php, routes/console.php
└── tests/{Unit,Feature,Legacy,e2e}
```

Stack: **Laravel 13.33**, **PHP 8.3+** (testado em 8.4), **Blade**, **Alpine.js 3 (build CSP)**,
**Vite 8**, **SQLite**, fila e cache em banco, **PHPUnit 12**, **Playwright + axe-core**.

## 2. Responsabilidades por camada

Cada abstração existe por um motivo. Nenhuma camada é criada "para o futuro".

| Camada | Responsabilidade | Não pode |
|---|---|---|
| **Rota** (`routes/web.php`) | Mapear URL → controller e declarar a proteção (`auth`, `can:`, `throttle`) | Ter lógica |
| **FormRequest** (`app/Http/Requests`) | Validar e normalizar a entrada; mensagens em português | Consultar regra de negócio complexa |
| **Controller** (`app/Http/Controllers/<Área>`) | Receber a requisição validada, chamar **um** serviço/ação, devolver view/redirect | Ter regra de negócio, SQL ou cálculo de valor |
| **Policy** (`app/Modules/<M>/Policies`) | Autorização **por registro** (acesso horizontal: "este agendamento é deste cliente?") | Ser ignorada: todo acesso a registro de outro usuário passa por policy |
| **Gate** (`config/permissions.php`) | Autorização **por capacidade** do papel ("pode ver o financeiro?") | Conceder habilidade não declarada |
| **Model** (`app/Modules/<M>/Models`) | Mapear tabela, casts, relacionamentos, escopos simples | Orquestrar processos (enviar e-mail, chamar gateway) |
| **Service** (`app/Modules/<M>/Services`) | Regra de negócio com estado ou várias etapas (ex.: `BookingService`, `Availability`, `Pricing`) | Conhecer HTTP (request, sessão, redirect) |
| **Action** | Só quando uma operação isolada é reutilizada por mais de um canal (painel, chatbot, importador) e não cabe num serviço existente | Virar camada obrigatória |
| **Job** (`app/Modules/<M>/Jobs`, estende `BaseJob`) | Trabalho assíncrono e **idempotente** (lembrete, campanha, webhook) | Depender de estado da sessão |
| **Event/Listener** | Efeitos colaterais desacoplados (ex.: "agendamento concluído" → pontos de fidelidade, comissão) | Ser usado para fluxo principal que precisa de resposta síncrona |
| **Notification** | Mensagens a usuários (e-mail, no app), sempre enfileiradas | Montar HTML com dado não escapado |
| **View/Component** (`resources/views`) | Apresentação. Componentes `x-ui.*` do design system | Calcular preço, disponibilidade ou permissão |

**Regra de dependência entre módulos:** um módulo usa o **serviço** de outro, nunca as
tabelas ou modelos internos dele diretamente. Ex.: `Scheduling` pede preço a `Pricing`;
não lê a tabela de cupons.

## 3. Princípio: DENY BY DEFAULT

Implementado desde a fundação:

1. **Habilidades declaradas:** `config/permissions.php` lista todas as habilidades. O
   `AppServiceProvider` registra uma Gate para cada uma. Habilidade não declarada não tem Gate
   e o Laravel **nega**. Não existe `Gate::before` concedendo tudo a ninguém.
2. **Papéis explícitos:** cada papel lista o que pode. O curinga `*` do proprietário vale
   **só para habilidades declaradas**. Papel sem entrada = nenhuma permissão.
3. **Rotas:** toda rota precisa estar na lista pública explícita **ou** ter `auth` + `can:`.
   O teste `RouteAuthorizationTest` percorre todas as rotas e **quebra o CI** se uma rota
   nova esquecer a proteção.
4. **Usuário revalidado a cada requisição:** `EnsureStaffIsActive` derruba a sessão de quem foi
   desativado (corrige o achado S-09 do sistema antigo).
5. **Acesso horizontal:** dados de outro cliente/profissional são protegidos por **Policies**
   por registro (a partir da Fase 2), além das Gates. Teste de IDOR obrigatório em todo recurso.
6. **Mass assignment:** `role` e `is_active` ficam fora de `$fillable`; mudar papel é ação explícita.
7. **Arquivos privados:** o disco `local` não é servido por URL (`serve => false`).

Papéis atuais: `owner`, `manager`, `reception`, `finance`, `professional` (`StaffRole`).
Clientes terão modelo e *guard* próprios (Fase 3).

## 4. Princípio: DADOS HISTÓRICOS IMUTÁVEIS

> **Dados históricos de uma transação preservam o estado válido no momento em que ela ocorreu.**

O problema do sistema antigo (relatórios recalculados com o preço **atual** do serviço) não
pode existir. Regras para a Fase 2 em diante:

| Dado | Como é preservado |
|---|---|
| **Preço** | O agendamento grava itens com nome, `price_cents` e duração **copiados** do catálogo no momento |
| **Desconto / promoção** | Cada desconto vira uma linha de ajuste com tipo, origem (cupom X, fidelidade…) e valor em centavos |
| **Comissão** | Calculada no fechamento e **gravada** (base, percentual/regra, valor). Não é recalculada depois |
| **Forma de pagamento** | Registro de pagamento imutável (forma, valor, gorjeta, data, quem recebeu). Correção = estorno + novo lançamento |
| **Pontos** | Razão (ledger) de lançamentos. Saldo = soma. Nunca um número sobrescrito |
| **Assinatura** | Histórico de assinaturas e pagamentos. Mudar o plano não altera os pagamentos anteriores |

Consequências técnicas:

- Dinheiro sempre em **centavos inteiros** (`App\Modules\Shared\Support\Money`, sem float).
- Alterar o catálogo vale só para o futuro. Relatório usa o que foi gravado.
- Registros financeiros não são apagados: são estornados ou cancelados com motivo.
- Exclusão física só para dados sem valor histórico ou por obrigação legal (LGPD, com anonimização).

## 5. Banco de dados

- Motor padrão: **SQLite** (arquivo `database/database.sqlite`), com código portável para
  MySQL/MariaDB (só recursos do query builder/Eloquent). Ver `decisoes-fase-1.md`.
- Horários gravados em **UTC** (`config/app.php`); exibidos em `BARBEARIA_TIMEZONE`.
- Schema só por **migrations versionadas**. Nada de `CREATE/ALTER` em tempo de execução.
- Nesta fase existem só as tabelas da fundação: `users` (equipe, com `role` e `is_active`),
  `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.
  O modelo completo nasce na Fase 2, validado pelo importador do banco antigo.
- Testes usam SQLite **em memória** e `RefreshDatabase`. Nenhum teste depende de dado real.
- Seeders: `DevelopmentSeeder` cria 1 usuário por papel, **só** em `local/testing`, com senha
  aleatória mostrada no terminal (não existe senha fixa no código).

## 6. Ambientes e configuração

| Ambiente | `APP_ENV` | Observações |
|---|---|---|
| Desenvolvimento | `local` | `.env` do dev; protótipos ligados; e-mail vai para o log |
| Testes | `testing` | Definido em `phpunit.xml`: banco em memória, fila `sync`, e-mail `array`, `APP_URL=https://barbearia.test` |
| Homologação | `homologacao` | HTTPS, `APP_DEBUG=false`, cookie seguro, dados anonimizados, protótipos ligados |
| Produção | `production` | HTTPS, protótipos **desligados**, host confiável = host do `APP_URL` |

- **Nenhuma credencial no código.** Tudo via `.env` (fora do Git). O `.env.example` documenta
  todas as variáveis com campos sensíveis vazios. O teste `ConfigurationTest` varre `app/`,
  `config/`, `routes/`, `database/` e `resources/` à procura de padrões de segredo.
- `config/barbearia.php` guarda só infraestrutura (fuso, direção visual, protótipos, fila).
  Regras de negócio configuráveis pelo dono irão para uma tabela tipada (Fase 2).
- `php artisan app:diagnose` confere APP_KEY, APP_URL/HTTPS, debug, cookie seguro, banco,
  migrations, fila, jobs com falha, agendador e e-mail, sem imprimir segredos.

## 7. Segurança HTTP

- **CSP estrita** (`SecurityHeaders`): sem `unsafe-inline` e sem `unsafe-eval`. Scripts e
  estilos só do próprio domínio ou com **nonce** por requisição. Por isso: nenhum `style=""`,
  nenhum `onclick=""` e Alpine no **build CSP** (testado em `HttpHardeningTest`).
- Também: `X-Frame-Options: DENY`, `frame-ancestors 'none'`, `nosniff`, `Referrer-Policy`,
  `Permissions-Policy`, `COOP`, HSTS em HTTPS.
- **URLs sempre pelo `APP_URL`** (`URL::useOrigin`), nunca pelo cabeçalho `Host` (regressão do
  S-06 testada). Em produção/homologação o *TrustHosts* só aceita o host do `APP_URL`.
- Sessão criptografada, `HttpOnly`, `SameSite=Lax`, `Secure` em HTTPS, regenerada no login.
- Login com **rate limit** duplo (por e-mail+IP e por IP), mensagem neutra, log em canal
  `security` sem dados sensíveis (e-mail vai como hash).
- CSRF em todo formulário; mudança de estado só por POST (logout inclusive).
- Páginas de erro próprias, sem detalhe técnico, e que não dependem de sessão.

## 8. Filas e agendador

- Fila `database` (sem Redis, adequado ao porte). Base `BaseJob`: 3 tentativas, espera de 1,
  5 e 15 minutos, timeout de 120 s, falha registrada no canal `jobs`. Um listener global
  (`Queue::failing`) registra falhas de qualquer job.
- Agendador (`routes/console.php`): batimento a cada minuto (usado pelo diagnóstico), limpeza
  de jobs com falha e de lotes, e **worker acionado pelo cron** quando
  `QUEUE_WORK_VIA_SCHEDULER=true` (hospedagem sem supervisor).
- **Jobs de negócio ainda não existem.** Toda tarefa futura deve ser idempotente (chave única
  do tipo "lembrete X do agendamento Y enviado").

## 9. Logs

| Canal | Arquivo | Uso |
|---|---|---|
| `stack` → `daily` | `storage/logs/laravel-*.log` | Log geral (14 dias) |
| `jobs` | `storage/logs/jobs-*.log` | Falhas de fila/agendador (30 dias) |
| `security` | `storage/logs/security-*.log` | Login recusado, sessão derrubada (90 dias) |

Nunca registrar senha, token, número de cartão ou payload completo de gateway.

## 10. Convenções

- **Idioma:** código (classes, métodos, variáveis, tabelas, colunas, rotas nomeadas) em
  **inglês**; textos de interface, mensagens, comentários e documentação em **português**.
  URLs públicas em português (`/entrar`, `/painel`, `/prototipos`).
- **Estilo:** Laravel Pint (preset Laravel), obrigatório no CI.
- **Nomes de rota:** `area.recurso.acao` (ex.: `panel.appointments.show`).
- **Controllers:** um por recurso; métodos REST (`index`, `show`, `store`, `update`, `destroy`)
  ou invocáveis para ação única.
- **Valores monetários:** colunas `*_cents` (inteiro) + `Money` no código.
- **Datas:** colunas `*_at` (timestamp UTC); datas sem hora `*_date`.
- **Testes:** todo recurso novo traz teste de sucesso, validação, autorização (incluindo
  acesso horizontal) e, quando houver interface, verificação no Playwright.
- **Commits:** mensagem em português, no imperativo, explicando o porquê.
- **Nada do sistema antigo é copiado.** Fluxo: compreender → decidir → reimplementar.

## 11. Pendências técnicas conhecidas

- **Análise estática (PHPStan/Larastan):** não instalada. O download dos pacotes pelo GitHub
  foi bloqueado neste ambiente (HTTP 403 no proxy). Adicionar no início da Fase 2, a partir
  de um ambiente com acesso, com nível alto e bloqueando o CI.
- **Deploy automatizado para homologação:** depende da escolha da hospedagem (D-01). O CI
  já valida tudo; falta só o passo de publicação.
- **Protótipos:** `app/Support/Prototypes`, `PrototypeController` e `resources/views/prototypes`
  são temporários e serão removidos quando as telas reais existirem.
