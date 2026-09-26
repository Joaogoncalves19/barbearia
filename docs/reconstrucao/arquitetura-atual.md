# 3. Arquitetura atual

## 3.1 Como o sistema funciona hoje

```text
                     Navegador (site, cliente, barbeiro, admin, chatbot)
                                        │  HTTP (Apache + .htaccess)
                                        ▼
 ┌───────────────────────────────────────────────────────────────────────────┐
 │  ~47 arquivos .php na raiz = ~47 "endpoints" independentes                │
 │   index / agendamento / cliente / barbeiro / admin / assistente / ajax_*  │
 │                                                                           │
 │   Cada um: require 'functions.php'  ─────────────────────────────┐        │
 │                                                                  ▼        │
 │   functions.php (bootstrap)                                               │
 │   ├─ headers de segurança (CSP, HSTS, X-Frame...)                         │
 │   ├─ sessão, CSRF (2 implementações), throttle, uploads                   │
 │   └─ require das 14 libs:                                                 │
 │        utils · db · config · auth · email · email_layout · agendamento    │
 │        marketing · notificacao · relatorio · admin_gestao · admin_agenda  │
 │        ia · seo            (+ chatbot_agent, pwa, migrations por demanda) │
 │                                                                           │
 │   getDB() → PDO SQLite (singleton) → aplicarMigracoes() em TODA conexão   │
 └───────────────────────────────────────────────────────────────────────────┘
          │                       │                        │
          ▼                       ▼                        ▼
   _dados/database.sqlite    SMTP (PHPMailer)      Stripe / Groq / Gemini (cURL)
   (configurações e segredos
    também ficam aqui)
```

### Fluxo típico de uma página do painel admin

1. `admin.php` inclui `functions.php`, valida a sessão e o tempo de inatividade (60 min).
2. Lê **todas as linhas** de 13 tabelas com `lerDados()` (agendamentos, clientes,
   avaliações, despesas...).
3. Inclui `admin_actions.php`. Se houver `?action=`, despacha para `actions/<arquivo>.php`,
   que executa e redireciona com `?success=` ou `?error=`.
4. Inclui `admin_data.php`, que filtra e agrega os arrays em PHP (em memória).
5. Renderiza o "casco" do painel. As abas são carregadas depois via
   `admin.php?ajax_tab=X`, e **cada carregamento de aba repete os passos 1 a 4**.
6. Serializa tudo num objeto JS global (`adminJSData`): clientes, agendamentos,
   avaliações, barbeiros, horários...

### Fluxo do agendamento online

1. `agendamento.php` inclui `agendamento_data.php` (carrega barbeiros, serviços, combos,
   planos, avaliações e clientes) e renderiza o wizard.
2. O JS busca horários em `get_horarios.php` conforme data e profissional.
3. O POST volta para o próprio `agendamento.php`, que inclui `processar_agendamento.php`:
   ~560 linhas procedurais que validam, calculam descontos (cupom, voucher, fidelidade,
   aniversário, indicação, assinatura), gravam e chamam o Stripe via cURL, se preciso.

### Três caminhos diferentes criam agendamentos

| Caminho | Arquivo | Regras próprias |
|---------|---------|-----------------|
| Site | `processar_agendamento.php` | Sim (mais completo) |
| Chatbot | `assistente.php:_botCriarAgendamento` + `lib/chatbot_agent.php` | Sim (subconjunto) |
| Admin / barbeiro manual | `actions/agendamentos.php`, `barbeiro_actions.php:270` | Sim (outro subconjunto) |

E **quatro** caminhos reagendam (cliente, barbeiro, admin e agente de IA), cada um
com validações diferentes. O do cliente não valida nada (S-04).

## 3.2 Componentes

| Componente | Responsabilidade real |
|------------|-----------------------|
| **Frontend** | HTML server-side + JS por página. Parte da regra de negócio (valores, descontos, durações) é recalculada no navegador (`js/agendamento.js`, `js/admin_detalhes.js`, `js/cliente.js`) |
| **Backend** | Arquivos PHP procedurais. Sem controllers, services ou repositórios |
| **"APIs"** | Endpoints PHP que devolvem JSON, sem contrato, versão ou padrão de erro |
| **Serviços** | Funções em `lib/` agrupadas por tema, com efeitos colaterais (criam tabela, gravam log, mandam e-mail) |
| **Autenticação** | Sessão PHP nativa com três "flags" diferentes (`loggedin`, `barbeiro_loggedin`, `cliente_logado`) |
| **Autorização** | Perfis fixos em código (por aba), checados no despachante de ações e no carregamento de aba. Endpoints AJAX checam só "está logado" |
| **Persistência** | PDO SQLite. Mistura de `lerDados()` (lê a tabela inteira) e SQL direto espalhado |
| **Configuração** | Tabela `configuracoes` (JSON por seção). Semeada com valores padrão na primeira leitura |
| **Integrações** | cURL direto no meio das páginas (Stripe em 3 arquivos; IA em 2 libs) |
| **Agendamentos em segundo plano** | Cron opcional; o resto roda "de carona" em requisições de usuários |

## 3.3 Acoplamentos e problemas estruturais

Cada item traz a evidência. Todos **confirmados**.

### 3.3.1 Regra de negócio dentro da interface

- Cálculo do valor de um atendimento aparece em **~20 arquivos** (`desconto_aplicado` é lido
  em 25 arquivos), inclusive em JavaScript: `js/admin_detalhes.js:54,199`,
  `js/cliente.js`, `js/admin_ui_core.js`.
- Regras de desconto de assinatura duplicadas **dentro do mesmo arquivo**
  (`processar_agendamento.php`, blocos "adesão" e "assinante ativo" com a mesma lógica
  copiada) e novamente em `lib/agendamento_functions.php:calcularDescontoAssinaturaCliente`.
- `agendamento_data.php:5-18` decide ícones de serviço por palavras-chave no nome.

### 3.3.2 Acesso direto ao banco em lugares inadequados

- Abas de interface criam tabelas: `admin_tabs/financeiro.php:27,38,57`, `admin_tabs/servicos.php:16`.
- Páginas públicas criam tabelas: `index.php:38`, `agendamento.php:35,117`, `registro.php:80`.
- 40+ `CREATE TABLE IF NOT EXISTS` fora do instalador; a mesma tabela é "criada" em até 5
  lugares com **definições diferentes** (ex.: `agendamentos` em `get_horarios.php:13`,
  `processar_agendamento.php:349`, `assistente.php:196`, `barbeiro_actions.php:345`,
  `actions/agendamentos.php:484`).

### 3.3.3 Lógica duplicada

| Duplicação | Onde |
|------------|------|
| Dois sistemas de CSRF | `gerarTokenCsrf/validarTokenCsrf` e `generate_csrf_token/verify_csrf_token` (`functions.php:254-273` e `:898-915`) |
| Dois sistemas de throttle de login | `sys_login_attempts` (`login.php`, só por IP) e `login_throttle` (`functions.php`, por IP + identificador) |
| Três caminhos de criação de agendamento | ver 3.1 |
| Quatro caminhos de reagendamento | cliente, barbeiro, admin, agente |
| Cálculo de "slots" de combo | `agendamento_data.php`, `barbeiro_actions.php`, `lib/relatorio_functions.php`, `get_horarios.php`, `assistente.php` |
| Newsletter/reativação/lembrete de avaliação | versão antiga (sem interface) + versão nova por campanhas |
| Salvar Stripe / salvar tema | ação antiga + ação nova |
| Fuso horário | configurável em `functions.php:119-129`, mas **17 arquivos** chamam `date_default_timezone_set('America/Sao_Paulo')` de novo, anulando a configuração (ex.: `admin.php:11`, `agendamento_data.php:3`, `cron_lembretes.php:14`) |

### 3.3.4 Módulos, funções e componentes gigantes

| Arquivo | Linhas | Observação |
|---------|-------:|------------|
| `admin_tabs/servicos.php` | 1.349 | serviços + categorias + combos + planos + estoque + histórico numa só tela |
| `lib/marketing_functions.php` | 1.213 | fidelidade + cupons + vouchers + **assinaturas + Stripe** + indicação + campanhas + opt-out |
| `admin_tabs/financeiro.php` | 1.201 | HTML + SQL + JS + chamadas de IA |
| `assistente.php` | 1.072 | roteador de intenções + login + cadastro + agendamento + IA |
| `barbeiro.php` | 971 | painel inteiro |
| `admin_tabs/configuracoes.php` | 951 | 9 sub-seções |
| `functions.php` | 916 | bootstrap + segurança + sessão + throttle + tokens + uploads |
| `processar_agendamento.php` | 559 | um único fluxo procedural |

`lib/marketing_functions.php` concentra responsabilidades sem relação entre si (a lógica de
cobrança recorrente mora no arquivo de "marketing").

### 3.3.5 Carregamento de dados sem limite

- `lerDados()` (`lib/db_functions.php:47`) faz `SELECT colunas FROM tabela` **sem WHERE**.
  Usado para agendamentos e clientes no painel, no agendamento e no barbeiro.
- `barbeiro_actions.php:451` lê **todos os agendamentos** para validar um só.
- O custo cresce linearmente com o histórico. **Precisa de validação** o volume real, mas o
  modelo não escala e aumenta a exposição de dados (tudo vai para o JS).

### 3.3.6 Chamadas a serviços externos espalhadas

- Stripe: `processar_agendamento.php:~418-470` (cria o Checkout), `webhook_stripe.php`
  (eventos e clientes), `actions/clientes.php:225` (cancelamento). Sem cliente HTTP comum,
  sem timeout configurado, sem retry.
- IA: `lib/ia_functions.php` e `lib/chatbot_agent.php`, chamados por `ajax_gemini.php`,
  `assistente.php` e `actions/marketing.php`.

### 3.3.7 Tratamento de erros inconsistente

- Padrões misturados: `die()`, `exit` com redirect `?error=`, JSON `{success:false}`,
  JSON `{status:'error'}`, exceções engolidas em `catch {}` silenciosos (dezenas).
- Mensagens de exceção devolvidas ao usuário em alguns pontos
  (`ajax_gemini.php`: "Erro ao buscar avaliações...: " + mensagem; `install.php`).
- Falhas de gravação às vezes só vão para o log, e o usuário vê sucesso.

### 3.3.8 Validações duplicadas ou ausentes

- `validarDadosCadastroCliente()` foi criada para unificar cadastro (bom), mas edição de
  perfil (`cliente_actions.php:salvar_perfil`) não a usa (nome e telefone sem validação).
- Datas e horas de reagendamento do cliente não são validadas.
- Validação duplicada entre JS (formulário) e PHP, com regras diferentes.

### 3.3.9 Dados de preço não congelados

O agendamento guarda apenas `servicos_ids` e `desconto_aplicado`. O valor é **recalculado
pelo preço atual do serviço** (`lib/relatorio_functions.php:calcularValoresAgendamentoRelatorio`).
Consequências:

- mudar o preço de um serviço **altera o faturamento histórico** nos relatórios;
- excluir um serviço **apaga sua receita** de todos os relatórios passados;
- comissões já pagas deixam de bater com os relatórios.

É o problema estrutural de maior impacto para a reconstrução.

### 3.3.10 Modelo de horário rígido

Tudo assume blocos de 30 minutos (`slots × 30`, `% 30 !== 0`), em pelo menos 8 arquivos.
Um serviço de 45 minutos não é representável.

### 3.3.11 Schema mutável em tempo de execução

- As rotinas de migração rodam a cada conexão. As de dados rodam **antes** do controle de
  versão, de propósito (`lib/migrations.php:666-675`), e fazem consultas a cada requisição.
- Colunas adicionadas por `ALTER TABLE` em funções `garantir*()` fora das migrations.
- Resultado: não existe um schema único e verificável. O banco de cada instalação pode ser
  diferente, dependendo de quais telas já foram abertas.

### 3.3.12 Dependências circulares e globais

- Não há classes nem namespaces. Tudo é função global carregada de uma vez, então não há
  ciclo de `require`, mas há **dependência implícita por variável global**: `actions/*.php`
  dependem de `$action`, `$id`, `$pdo` e dos arrays montados em `admin.php`;
  `processar_agendamento.php` depende de `$combosArr`, `$servicosArr` e `$barbeirosAtivosArr`
  do arquivo que o inclui.
- Muitas funções testam `function_exists()` antes de chamar outras, sinal de que a ordem de
  carregamento não é garantida.

### 3.3.13 Pontos fortes (a preservar como conhecimento)

O código atual tem bastante trabalho de endurecimento que deve virar **requisito** do novo:

- prepared statements em todo lugar (nenhuma injeção de SQL encontrada);
- `password_hash`, `session_regenerate_id`, cookies `HttpOnly/SameSite`, "lembrar-me" com
  selector/validator;
- throttle de login, cadastro, reset de senha e chatbot;
- upload validado por conteúdo, com nome aleatório e PHP bloqueado na pasta;
- webhook do Stripe com HMAC, janela de tempo, nova busca do evento e idempotência;
- índices únicos contra horário duplicado e cliente duplicado;
- anonimização na exclusão de conta e opt-out vinculado ao cliente;
- comentários que explicam **por que** cada regra existe (fonte valiosa de requisitos).

## 3.4 Diagnóstico em uma frase

> O sistema cresceu por acréscimo: cada funcionalidade nova criou seu próprio caminho de
> dados, suas próprias tabelas e suas próprias regras. Há muito conhecimento de negócio
> correto, mas espalhado, duplicado e sem uma fonte única de verdade para **agenda,
> preço e permissão**.
