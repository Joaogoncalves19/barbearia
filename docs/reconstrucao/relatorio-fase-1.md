# Relatório final — Fase 1

**Fundação técnica, correções críticas e Design System** · concluída em 2026-09-26 ·
**aguardando aprovação explícita para iniciar a Fase 2.**

## 1. O que foi implementado

### Etapa B — correções no sistema atual (isoladas)

As 4 vulnerabilidades Altas da auditoria foram corrigidas com mudanças mínimas, sem
refatoração e sem mudança de banco ([seguranca-correcoes.md](seguranca-correcoes.md)):

| ID | Correção |
|----|----------|
| S-01 | Removido o fluxo `?reagendar_id=` que apagava agendamento de terceiros |
| S-02 | Escape de texto de clientes (comentário, nome, horário, contato) e das respostas da IA antes de `innerHTML`, no painel admin, no painel do barbeiro e no perfil público |
| S-03 | Página pública de agendamento não serializa mais clientes, IDs de avaliações/agendamentos nem logins de barbeiros |
| S-04 | Reagendamento pelo cliente valida status, data, hora, grade, antecedência, expediente e ocupação; não reativa cancelados |

### Etapas C a F — novo sistema (`novo-sistema/`)

- **Laravel 13.33 / PHP 8.3+**, projeto limpo, sem nenhum arquivo do sistema antigo.
- **Configuração de ambiente:** `.env.example` completo e sem segredos, `config/barbearia.php`,
  locale `pt_BR`, fuso de exibição separado do UTC do banco, sessão criptografada, logs por
  canal (`jobs`, `security`), páginas de erro próprias.
- **Banco:** SQLite com migrations da fundação (equipe com papel e ativo, sessões, cache,
  filas), factories e seeder de desenvolvimento com senha aleatória.
- **Autenticação da equipe:** login por e-mail, rate limit duplo, sessão regenerada, mensagem
  neutra, usuário inativo bloqueado, logout só por POST.
- **Autorização deny-by-default:** matriz de permissões por papel, Gates só para habilidades
  declaradas, revalidação do usuário a cada requisição e teste que falha se alguma rota ficar
  sem proteção.
- **Segurança HTTP:** CSP estrita com nonce (sem `unsafe-inline`/`unsafe-eval`), cabeçalhos,
  URLs sempre pelo `APP_URL` (imune a Host header), TrustHosts em produção, disco privado não
  exposto.
- **Filas e agendador:** fila em banco, `BaseJob` com retentativas, espera e registro de falha,
  batimento do agendador, limpeza da fila, worker pelo cron para hospedagem compartilhada.
- **`php artisan app:diagnose`:** verificação do ambiente após o deploy.
- **`Money`:** valor em centavos sem float, base do princípio de dados históricos.
- **CI** (`.github/workflows/ci.yml`): Pint, auditorias, PHPUnit, build, Playwright e a
  regressão de segurança do sistema antigo.

### Etapas G e H — identidade e design system

- **Duas direções visuais completas** (A "Ofício contemporâneo", recomendada; B "Urbano
  gráfico"), trocáveis por `?direcao=a|b` ([identidade-visual.md](identidade-visual.md)).
- **Tokens** (primitivos, semânticos, escalas), superfícies clara/escura, fontes locais,
  47 ícones Lucide.
- **28 componentes/padrões** com estados: botão (5 variantes, 3 tamanhos, carregando), link,
  input, select, textarea, checkbox, radio, switch, cartão selecionável, card, badge, alerta,
  toast, modal, confirmação, dropdown, tabela responsiva, paginação, avatar, breadcrumbs,
  abas, segmentado, etapas, menu, loading, skeleton, estado vazio, estado de erro e foto/marcador
  ([design-system.md](design-system.md)).
- **5 telas de referência + catálogo:** Home, Serviços, Agendamento (4 etapas, sem cadastro
  prévio), Painel "Hoje", Agenda (grade por profissional e lista). Todas marcadas como
  protótipo, com dados de **exemplo** identificados, `noindex` e 404 em produção.

## 2. O que foi decidido

Detalhes em [decisoes-fase-1.md](decisoes-fase-1.md).

- **Técnico:** Laravel 13 em pasta separada; módulos por domínio; tabela `users` só para
  equipe; permissões por matriz + Gates; Alpine build CSP; CSS próprio (Tailwind removido);
  fontes e ícones locais; UTC + centavos; fila/cache em banco; worker pelo cron; PHPUnit +
  Playwright + axe.
- **Provisório (confirmar):** SQLite (D-02) e login da equipe por e-mail (D-10).
- **Executado com autorização:** correções emergenciais no sistema atual (D-16).

## 3. O que ficou pendente

| Pendência | Com quem | Bloqueia |
|---|---|---|
| Escolher a direção visual A ou B (recomendação: A) | Dono | Replicação das telas (Fase 3 em diante) |
| Logo, nome de exibição e frase da marca | Dono | Fase 11 |
| Sessão de fotos | Dono | Fase 11 |
| Hospedagem com SSH e cron (D-01) | Dono | Deploy de homologação |
| Publicar as 4 correções na hospedagem atual e rotacionar credenciais | Dono | Nada (mas é urgente) |
| PHPStan/Larastan | Técnico | Início da Fase 2 (o download foi bloqueado neste ambiente) |
| Deploy automatizado de homologação | Técnico, após D-01 | — |

## 4. Problemas encontrados (e como foram tratados)

| Problema | Tratamento |
|---|---|
| O teste de S-04 dependia de dados que o S-01 apagava (falha em cascata) | Dados de teste separados por cenário |
| `.gitignore` do sistema antigo não ignorava `_logs/app_log.txt` (nome real do log) | Incluído `_logs/*.txt` (evita versionar log com dados pessoais) |
| Laravel 13 expõe `storage/{path}` (inclusive upload por PUT) por padrão | Desligado (`serve => false`), coberto por teste |
| Edição de arquivos do sistema antigo alterava as quebras de linha (CRLF) | Quebras originais preservadas; o diff mostra só a mudança real |
| Validação visual pegou defeitos que os testes não pegavam: estilos compartilhados presos ao CSS do site, borda padrão do navegador nos blocos da agenda, `fieldset` com borda no agendamento, marcador do hero sobre o título | Corrigidos e revalidados por captura |
| No celular, a barra fixa do agendamento cobria o botão "Continuar" | Botão duplicado removido no celular; teste E2E cobre o fluxo |
| Download de pacotes pelo GitHub bloqueado (HTTP 403) | PHPStan adiado e documentado |

Nenhuma vulnerabilidade nova foi introduzida: as mudanças no sistema antigo só removem código
ou escapam saída, e o sistema novo tem testes de CSP, cabeçalhos, rotas e segredos.

## 5. Testes executados e resultados

| Suíte | Resultado |
|---|---|
| `tests/seguranca_fase1.php` (sistema antigo) — **antes** das correções | 17 falhas (as 4 vulnerabilidades reproduzidas) |
| `tests/seguranca_fase1.php` — **depois** | **25/25** verificações OK, incluindo XSS no navegador real |
| `tests/assinaturas.php` (suíte já existente do sistema antigo) | **40/40** (sem regressão) |
| `php artisan test` (novo sistema: Unit + Feature) | **64 testes, 222 asserções, todos passando** |
| `php artisan test --testsuite=Legado` | **1/1** (executa a regressão do sistema antigo) |
| `npx playwright test` (8 telas × 2 direções × celular/desktop + interações) | **41 passando**, 1 pulado de propósito (teste só de celular no projeto desktop) |
| Checagem extra de rolagem lateral e console em 360/768/1024 px | Nenhum problema |
| `vendor/bin/pint --test` | OK |
| `npm run build` | OK (CSS núcleo 7 KB gzip; JS 24,5 KB gzip) |
| `composer audit` / `npm audit` | 0 vulnerabilidades |

Coberto pelos testes do novo sistema: inicialização, conexão com o banco, migrations (ida e
volta), configuração e ausência de segredos, autenticação, autorização e deny-by-default,
inventário de rotas, cabeçalhos/CSP, Host header, CSRF, filas, agendador, diagnóstico,
protótipos (e 404 em produção), acessibilidade (axe), erros de console, violações de CSP e
responsividade.

## 6. Arquivos importantes

**Sistema atual (modificados na Etapa B):** `agendamento_data.php`, `processar_agendamento.php`,
`cliente_actions.php`, `js/admin_detalhes.js`, `js/script.js`, `admin_tabs/avaliacoes.php`,
`.gitignore`. Novos: `tests/seguranca_fase1.php`, `tests/seguranca_xss_navegador.mjs`.

**Novo sistema (principais):**

| Arquivo | Papel |
|---|---|
| `novo-sistema/config/permissions.php` | Matriz de permissões |
| `novo-sistema/config/barbearia.php` | Configuração do sistema |
| `novo-sistema/.env.example` | Variáveis de ambiente documentadas |
| `novo-sistema/bootstrap/app.php` | Middlewares, TrustHosts, redirecionamentos |
| `novo-sistema/app/Providers/AppServiceProvider.php` | URLs pelo APP_URL, Gates, rate limit, log de filas |
| `novo-sistema/app/Http/Middleware/*` | CSP/cabeçalhos, usuário ativo, trava de protótipos |
| `novo-sistema/app/Modules/Identity/*` | `User` (equipe) e `StaffRole` |
| `novo-sistema/app/Modules/Shared/*` | `Money`, `BaseJob` |
| `novo-sistema/app/Console/Commands/*` | Batimento do agendador, diagnóstico |
| `novo-sistema/routes/web.php`, `routes/console.php` | Rotas e agendador |
| `novo-sistema/resources/css/*` | Tokens, base, componentes, áreas |
| `novo-sistema/resources/views/components/*` | Componentes e layouts |
| `novo-sistema/resources/views/prototypes/*` | Telas de referência (temporárias) |
| `novo-sistema/tests/*` | PHPUnit (Unit, Feature, Legacy) e Playwright (e2e) |
| `.github/workflows/ci.yml` | CI |

**Documentação:** `arquitetura-nova.md`, `design-system.md`, `identidade-visual.md`,
`seguranca-correcoes.md`, `decisoes-fase-1.md`, este relatório, `roadmap.md` atualizado e
capturas em `img/fase1/`.

## 7. Decisões arquiteturais (resumo)

1. Monólito modular (`app/Modules`) com controllers finos e serviços de domínio.
2. **Deny by default** em três camadas: rotas (teste de inventário), Gates (habilidades
   declaradas) e Policies por registro (a partir da Fase 2).
3. **Dados históricos imutáveis:** preço, desconto, comissão, pagamento, pontos e assinatura
   congelados na transação; dinheiro em centavos; estorno em vez de edição.
4. Schema só por migrations; UTC no banco.
5. Segredos só no ambiente; URL pública fixa.
6. CSP estrita, o que obriga um frontend sem inline e Alpine CSP.
7. Trabalho assíncrono sempre por jobs idempotentes.

## 8. Decisões de design (resumo)

1. Site e painel com experiências distintas sobre os mesmos tokens e componentes.
2. Site escuro e quente com seções claras alternadas; painel claro, denso e só com Inter.
3. Um único acento, reservado para a conversão ("Agendar").
4. Tipografia de título com personalidade (Fraunces na A, Bricolage na B).
5. Sombras só no que flutua; divisores em vez de cartões nas listas do site.
6. Fotografia como protagonista, com marcadores que dizem qual foto real vai em cada lugar.
7. Nenhum dado fictício apresentado como real; nada de links de administração no site.
8. Mobile first com barra inferior de agendamento e resumo fixo no fluxo de agendamento.

## 9. Riscos

| Risco | Probabilidade | Mitigação |
|---|---|---|
| Hospedagem sem SSH/cron atrasar a homologação | Média | Decidir D-01 antes da Fase 2; código já suporta hospedagem compartilhada |
| Falta de fotos reais deixar o site genérico | Alta, se não houver sessão | Sessão de fotos antes da Fase 11; marcadores já especificam cada foto |
| Correções não publicadas deixarem o sistema atual vulnerável | Alta enquanto não publicar | Publicar os 6 arquivos e rotacionar credenciais |
| Direção visual mudar depois de muitas telas | Baixa | Aprovar A/B agora; a troca é feita nos tokens |
| Dados reais mais sujos que o previsto | Média | Diagnóstico somente leitura no início da Fase 2 |
| Alpine CSP limitar interações complexas (ex.: agenda arrastável) | Baixa | Componentes JS registrados; avaliar caso a caso |

## 10. Recomendações para a Fase 2

1. **Antes de começar:** aprovar a direção visual, decidir a hospedagem (D-01) e confirmar
   SQLite (D-02); publicar as correções de segurança no site atual.
2. Fornecer uma **cópia do banco de produção** e da pasta `uploads/` para o diagnóstico
   somente leitura ([estrategia-migracao.md](estrategia-migracao.md#123-diagnóstico-inicial-somente-leitura)).
3. Instalar **PHPStan/Larastan** no CI como primeira tarefa.
4. Modelar todas as entidades aprovadas já seguindo o princípio de dados históricos
   (itens, ajustes, pagamentos e comissões congelados; razão de pontos).
5. Construir o importador com conciliação financeira mês a mês como critério de aceite.
6. Escrever as Policies por registro junto com cada modelo (acesso horizontal testado).
7. Decidir as funcionalidades "A decidir" que têm tabela (D-03 assinaturas, D-11 abandonadas,
   D-17 agendamentos passados não concluídos, D-20 histórico de campanhas, D-21 duplicados).

---

**Aguardando sua aprovação explícita para iniciar a Fase 2.**
