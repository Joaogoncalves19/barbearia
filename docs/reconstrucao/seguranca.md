# 6. Auditoria inicial de segurança

> **Nada foi corrigido nesta fase.** Este documento lista problema, localização, risco e
> recomendação. Nenhum segredo real foi copiado para cá: onde há segredo no código,
> ele é indicado por arquivo e linha, sem o valor.
>
> **Método:** revisão manual do código. Nenhum ataque foi executado contra produção.
> "Confirmado" significa que o caminho vulnerável foi verificado no código; a exploração
> em produção **não** foi testada.

## 6.1 Quadro-resumo

| ID | Severidade | Problema | Certeza |
|----|-----------|----------|---------|
| S-01 | **Alta** | Cliente logado consegue apagar agendamento de qualquer pessoa (reagendar por URL) | Confirmado |
| S-02 | **Alta** | XSS armazenado: comentário de avaliação e nome do agendamento executam no painel admin e no do barbeiro | Confirmado |
| S-03 | **Alta** | Página pública de agendamento expõe todos os clientes, avaliações e usernames dos barbeiros | Confirmado |
| S-04 | **Alta** | Reagendamento pelo cliente sem validação (horário ocupado, fora do expediente, revive agendamento cancelado ou concluído) | Confirmado |
| S-05 | Média | Segredos HMAC fixos no código-fonte (links de avaliação e de descadastro podem ser forjados) | Confirmado |
| S-06 | Média | Envenenamento de Host: links de reset de senha e de confirmação montados a partir do cabeçalho `Host` | Confirmado no código; exploração **precisa de validação** (depende da hospedagem) |
| S-07 | Média | Login pelo chatbot sem throttle de login e sem regenerar a sessão | Confirmado |
| S-08 | Média | Qualquer perfil de admin pode enviar a senha SMTP salva para um servidor arbitrário | Confirmado |
| S-09 | Média | Perfil de acesso "falha aberto": perfil desconhecido ou usuário excluído vira **proprietário** | Confirmado |
| S-10 | Média | Segredos (SMTP, Stripe, IA) em texto puro no banco; backup baixa tudo | Confirmado |
| S-11 | Média | `install.php` continua acessível após a instalação, com `display_errors=1` | Confirmado |
| S-12 | Média | Ações do painel aceitam GET com token CSRF na URL | Confirmado |
| S-13 | Baixa | Cancelamento pelo cliente sem regras (inclui atendimento concluído) | Confirmado |
| S-14 | Baixa | Barbeiro pode sobrescrever anotações de qualquer cliente | Confirmado |
| S-15 | Baixa | Arquivos "include" acessíveis diretamente por HTTP; `.htaccess` de `_logs` só na sintaxe antiga | Confirmado (impacto atual baixo) |
| S-16 | Baixa | Resposta da IA inserida como HTML (injeção de prompt vira XSS) | Confirmado |
| S-17 | Baixa | Bibliotecas por CDN sem SRI e sem versão fixa | Confirmado |
| S-18 | Baixa | CSP com `'unsafe-inline'` | Confirmado |
| S-19 | Baixa | URLs de retorno do Stripe montadas a partir do `Host` | Confirmado |
| S-20 | Baixa | IDs gerados com `str_shuffle` (não criptográfico) | Confirmado |
| S-21 | Baixa | Webhook marca o evento como processado antes de processar (falha no meio = evento perdido) | Confirmado |
| S-22 | Informativa | Throttles de login duplicados e inconsistentes | Confirmado |
| S-23 | Informativa | E-mail pessoal do desenvolvedor fixo no código | Confirmado |
| S-24 | Informativa | Logs podem conter dados pessoais (e-mail e nome em mensagens) | Provável |

**Encadeamento relevante:** S-03 expõe `agendamento_id` de todos os atendimentos avaliados.
Com esse ID, S-01 permite a qualquer cliente cadastrado **apagar** esses agendamentos, e
S-05 permite **forjar avaliações** em nome deles.

## 6.2 Detalhamento

### S-01 — Exclusão de agendamento de terceiros (IDOR) · Alta

- **Localização:** `agendamento_data.php:47-48` grava `$_GET['reagendar_id']` na sessão sem
  checar o dono. `processar_agendamento.php:355-357` executa
  `DELETE FROM agendamentos WHERE id = ?` com esse valor no próximo agendamento concluído.
- **Risco:** qualquer cliente logado apaga o agendamento de outra pessoa ao informar o ID na
  URL e fazer um agendamento qualquer. Como o parâmetro chega por GET, um link malicioso
  também pode "armar" a sessão da vítima. Nenhuma tela usa esse fluxo hoje (legado).
- **Recomendação:** remover o fluxo. No sistema novo, reagendamento é uma operação sobre o
  próprio agendamento (nunca apagar e recriar), com checagem de dono e política de prazos.

### S-02 — XSS armazenado no painel · Alta

- **Localização:** `js/admin_detalhes.js:172` (`${av.comment}`) e `:206` (`${ag.nome}`),
  inseridos via `innerHTML`. Os dados vêm de `adminJSData` (`admin.php:448`), sem escape.
  O comentário é gravado cru por `salvar_avaliacao.php:55-61`; o nome vem do formulário
  público de agendamento. O arquivo é carregado por `admin.php:502` e `barbeiro.php:960`.
- **Risco:** um cliente escreve um comentário com HTML/JS. Quando o admin abre os detalhes do
  profissional, o script roda na sessão do admin (o CSP permite `'unsafe-inline'`), podendo
  ler o token CSRF e disparar ações administrativas.
- **Recomendação:** no novo sistema, nunca montar HTML com dados do usuário por
  concatenação. Usar templates com escape automático e `textContent` no JS. Adotar CSP
  sem `'unsafe-inline'` (com nonce). Incluir testes de XSS nos campos livres.

### S-03 — Exposição pública de dados · Alta

- **Localização:** `agendamento.php:732` (barbeiros com `username`), `:748` (todas as
  avaliações com `cliente_id` e `agendamento_id`), `:749` (todos os clientes: `id` e `nome`).
  Dados carregados em `agendamento_data.php:64,96-97`. A página abre **sem login**.
- **Risco:** vazamento de dados pessoais (LGPD), enumeração de usernames de login dos
  barbeiros e dos IDs de agendamento usados em S-01 e S-05.
- **Recomendação:** o frontend público recebe só o necessário (nome de exibição do
  barbeiro, nota média, comentários aprovados sem identificadores internos). Nunca enviar
  identificadores de login.

### S-04 — Reagendamento pelo cliente sem validação · Alta (integridade)

- **Localização:** `cliente_actions.php:155-190`. Verifica o dono, mas não valida formato
  de data/hora, disponibilidade, expediente, ausência, antecedência nem status atual, e
  força `status = 'aprovado'`.
- **Risco:** encaixes em cima de outros clientes (sobreposição de duração não é barrada pelo
  índice único), horários fora do expediente, e "ressuscitar" atendimentos cancelados ou
  concluídos, o que distorce agenda e relatórios.
- **Recomendação:** um único serviço de domínio de agenda para criar, reagendar e cancelar,
  usado por todos os canais, com política de prazos configurável.

### S-05 — Segredos HMAC fixos no código · Média

- **Localização:** `lib/marketing_functions.php:880-882` (descadastro) e `:914-915`
  (avaliação). Os valores estão no repositório e são iguais em toda instalação.
- **Risco:** qualquer pessoa pode gerar links válidos de avaliação para qualquer
  `agendamento_id` e descadastrar qualquer e-mail das campanhas.
- **Recomendação:** segredo aleatório por instalação, em variável de ambiente. Considerar
  rotação e tokens armazenados com expiração.

### S-06 — Envenenamento do cabeçalho Host · Média (precisa de validação)

- **Localização:** `functions.php:94-97` monta `BASE_URL` a partir de `HTTP_HOST`.
  Usado em `esqueci_senha.php:41` (link de redefinição), `registro.php:103` (confirmação),
  `lib/marketing_functions.php:921,930`. `siteUrl()` (`lib/config_functions.php`)
  **persiste** esse valor para o cron usar nos e-mails.
- **Risco:** se o servidor aceitar um `Host` arbitrário, um atacante pede "esqueci a senha"
  de uma vítima com `Host: dominio-do-atacante`. O e-mail legítimo leva a vítima ao domínio
  do atacante com um token válido. Também pode "envenenar" o `site_url` salvo.
- **Recomendação:** URL pública fixa em configuração de ambiente (`APP_URL`), nunca derivada
  do cabeçalho.

### S-07 — Login pelo chatbot · Média

- **Localização:** `assistente.php:766` (`password_verify` com a mensagem digitada).
  Não usa `loginBloqueadoAte/registrarFalhaLogin` e não chama `session_regenerate_id`.
  O único limite é o de 60 mensagens por 10 minutos por IP.
- **Risco:** canal de força bruta que contorna o limite de 5 tentativas do login normal;
  possível fixação de sessão.
- **Recomendação:** não autenticar por chat. O chatbot, se mantido, redireciona para o login
  padrão ou usa um link mágico.

### S-08 — Exfiltração da senha SMTP · Média

- **Localização:** `ajax_config.php:19-60`. Aceita `host` do formulário e, se a senha vier
  vazia, usa a senha salva. Só exige "estar logado como admin", sem checar perfil.
- **Risco:** um usuário com perfil "Recepção" informa um host sob seu controle e recebe a
  senha SMTP da barbearia.
- **Recomendação:** testar sempre com a configuração salva. Restringir a quem pode editar
  configurações e nunca reaproveitar um segredo salvo com parâmetros novos.

### S-09 — Autorização que falha aberta · Média

- **Localização:** `lib/admin_gestao_functions.php:101-110` (`obterPerfilAdminUsuario`) e
  `adminPodeAcessarAba`. Perfil vazio, desconhecido ou **usuário inexistente** (excluído com
  a sessão ainda aberta) resulta em `proprietario`. `admin.php` só verifica
  `$_SESSION['loggedin']`.
- **Risco:** um usuário excluído ou rebaixado mantém, ou até ganha, acesso total até a sessão
  expirar.
- **Recomendação:** negar por padrão. Revalidar o usuário a cada requisição e invalidar sessões
  ao excluir ou alterar perfil.

### S-10 — Segredos em texto puro no banco · Média

- **Localização:** tabela `configuracoes` (seções `config_email`, `config_stripe`,
  `config_chatbot`, `config_gemini`). A ação `backup_sqlite`
  (`actions/configuracoes.php:253-265`) baixa o arquivo inteiro.
- **Risco:** quem tiver o backup (ou um perfil com acesso a Configurações) obtém a chave
  secreta do Stripe e as demais credenciais.
- **Recomendação:** segredos em variáveis de ambiente ou criptografados com chave fora do banco.
  Backups criptografados e restritos ao proprietário.

### S-11 — Instalador exposto · Média

- **Localização:** `install.php:4-5` (`display_errors=1`), sem CSRF, sempre acessível. Após a
  instalação, só a criação de admin é bloqueada; a página continua mostrando versão do PHP,
  extensões, permissões de pastas e mensagens de exceção do banco.
- **Recomendação:** instalação por comando de terminal ou bloqueio definitivo após concluir.

### S-12 — Ações por GET · Média

- **Localização:** `admin_actions.php:20` (`$_REQUEST['action']`) e `:143`
  (`$_REQUEST['csrf_token']`). Botões de concluir, cancelar e excluir são links.
- **Risco:** o token vaza em histórico, logs e cabeçalho `Referer`. Ações destrutivas por
  simples navegação ou pré-carregamento de links.
- **Recomendação:** toda mudança de estado só por POST (ou PUT/PATCH/DELETE) com token no corpo.

### S-13 — Cancelamento sem regras · Baixa

- **Localização:** `cliente_actions.php:37-66`.
- **Risco:** o cliente cancela atendimento já concluído ou em andamento, alterando relatórios
  e comissões.
- **Recomendação:** política de cancelamento (status permitidos, prazo mínimo).

### S-14 — Anotações do barbeiro sem vínculo · Baixa

- **Localização:** `barbeiro_actions.php:362-370`: `UPDATE clientes SET notas_barbeiro = ? WHERE id = ?`,
  com `cliente_id` vindo do formulário.
- **Recomendação:** permitir só para clientes atendidos pelo barbeiro, ou restringir por permissão.

### S-15 — Includes acessíveis e `.htaccess` antigo · Baixa

- **Localização:** `actions/*.php`, `admin_tabs/*.php`, `cliente_tabs/*.php`,
  `admin_data.php`, `cliente_data.php`, `processar_agendamento.php`,
  `agendamento_data.php`, `header_app.php`, `barbeiro_modals.php`, `cliente_modals.php` não têm
  bloqueio. Hoje, acessados diretamente, geram erro (dependem de variáveis de quem os inclui).
  `_logs/.htaccess` usa só `Order/Deny` (Apache 2.2). No Apache 2.4 sem `mod_access_compat`,
  isso gera erro 500, que bloqueia por acidente.
- **Recomendação:** raiz pública contendo só o front controller e os assets.

### S-16 — Saída da IA como HTML · Baixa

- **Localização:** `js/admin_detalhes.js:251,675` (`innerHTML = ... + result.resposta`).
  A IA recebe comentários de clientes como entrada (`js/admin_detalhes.js:80`).
- **Recomendação:** tratar a resposta da IA como texto não confiável.

### S-17 — CDNs sem integridade · Baixa

- **Localização:** Chart.js sem versão (`cdn.jsdelivr.net/npm/chart.js`), flatpickr sem versão
  (e l10n via `npmcdn.com`), SweetAlert2 `@11`. Nenhum `integrity=`.
- **Recomendação:** empacotar dependências no build ou fixar versão com SRI.

### S-18 — CSP permissiva · Baixa

- **Localização:** `functions.php:52-68`. Necessária hoje por 1.137 atributos `style` e 91
  handlers inline.
- **Recomendação:** o novo frontend não usa handlers inline; CSP com nonce.

### S-19 — URLs de retorno do Stripe pelo Host · Baixa

- **Localização:** `processar_agendamento.php:418`.
- **Recomendação:** usar `APP_URL`.

### S-20 — IDs não criptográficos · Baixa

- **Localização:** `processar_agendamento.php` (`AG-` com `str_shuffle`),
  `lib/auth_functions.php` (`CL-`, código de indicação), `actions/marketing.php:133` (código de
  voucher). `str_shuffle` não usa gerador criptográfico e **não repete caracteres**, o que
  reduz o espaço de combinações.
- **Recomendação:** IDs internos não expostos (inteiros ou ULID/UUID) e códigos públicos gerados
  com `random_bytes`.

### S-21 — Idempotência do webhook antes do processamento · Baixa

- **Localização:** `webhook_stripe.php:161` reserva o evento, depois processa. O `http_response_code(200)`
  é enviado antes do processamento.
- **Risco:** se o processamento falhar no meio, o Stripe não reenvia e a assinatura fica inconsistente.
- **Recomendação:** registrar o evento, processar numa transação e marcar como concluído no fim;
  responder erro para permitir o reenvio.

### S-22 — Throttles duplicados · Informativa

`login.php` usa `sys_login_attempts` (só por IP, 5 tentativas/5 min). `login_cliente.php`
usa `login_throttle` (IP + identificador, e global por IP). Comportamentos diferentes para o
mesmo tipo de ataque.

### S-23 — E-mail pessoal fixo · Informativa

`actions/suporte.php:36` envia os relatos de problema para um e-mail pessoal fixo no código.
Deve ser configurável (e opcional).

### S-24 — Dados pessoais em logs · Informativa (provável)

`log_activity` recebe mensagens com IDs de cliente, e-mails de campanha e erros de API
(`log_activity("Erro API Stripe: " . $response)`). O log fica em arquivo sem rotação.

## 6.3 Controles que já funcionam (manter como requisito)

- SQL sempre parametrizado; nenhuma injeção de SQL encontrada.
- Senhas com `password_hash`/`password_verify` (bcrypt), inclusive dos barbeiros.
- Sessão com `HttpOnly`, `SameSite=Lax`, `Secure` em HTTPS, `use_strict_mode`, regeneração no login padrão.
- CSRF na maioria dos formulários.
- "Lembrar-me" com selector + validator em hash e revogação na troca de senha.
- Throttle de login de cliente, cadastro, reset de senha (resposta neutra) e chatbot.
- Upload validado por conteúdo, com nome aleatório e execução de PHP bloqueada em `uploads/`.
- Webhook do Stripe: HMAC, janela de 5 min, nova busca do evento na API, tabela de idempotência.
- Cabeçalhos: HSTS, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, CSP (parcial).
- Exclusão de conta com anonimização e opt-out ligado ao ID do cliente.

## 6.4 Requisitos de segurança para o sistema novo

1. Autorização **negada por padrão**, com matriz de permissões testada automaticamente.
2. Toda regra de negócio no servidor, num único serviço por domínio.
3. Escape automático nos templates; zero HTML montado por concatenação com dado do usuário.
4. Segredos só em ambiente (`.env` fora da raiz pública) ou criptografados.
5. URL pública fixa (`APP_URL`).
6. Raiz pública mínima (`public/index.php` + assets).
7. Mudança de estado só por POST (ou verbos equivalentes) com CSRF.
8. CSP estrita com nonce; dependências empacotadas.
9. Rate limit central (login, cadastro, reset, chatbot, endpoints públicos).
10. Log de auditoria de ações administrativas (quem, o quê, antes/depois), sem segredos.
11. Backups automáticos criptografados, com restauração testada.
12. Dados mínimos no frontend público.

## 6.5 Área do cliente (Fase 12)

Revisão da conta do cliente ([area-do-cliente.md](area-do-cliente.md#7-segurança-resumo)):

| Risco | Controle | Teste |
|---|---|---|
| IDOR (trocar o código na URL) | Policies com 404 para registro alheio; consultas a partir do cliente logado; toda rota `account.*` com parâmetro conferida | `CustomerAreaAccessTest` |
| IDs previsíveis | Agendamento e atendimento pela URL só pelo código aleatório; id numérico = 404 | `test_urls_usam_codigo_e_nao_o_id_sequencial` |
| Sessão esquecida em aparelho emprestado | Exportar, excluir conta e trocar e-mail pedem a senha de novo (15 min) | `AccountErasureTest`, `EmailChangeAndExportTest` |
| Tomada de conta pela troca de e-mail | Link só no endereço novo, hash do token, 60 min, uso único, mesma conta conectada, POST; aviso ao endereço antigo; links antigos invalidados | `EmailChangeAndExportTest` |
| Enumeração de e-mail | Troca para endereço de outro cadastro tem a mesma resposta e não envia nada | idem |
| Valores do navegador | Preço, desconto, pontos e elegibilidade recalculados no servidor | `test_beneficio_nao_vem_do_navegador` |
| Exposição de dados | CPF sempre mascarado (telas e exportação); exportação sem dados de outras pessoas nem internos | `test_cpf_nunca_aparece_inteiro_em_tela_da_conta`, `test_exportacao_traz_so_os_dados_do_proprio_cliente` |
| Ação por GET | Excluir conta e confirmar troca de e-mail só por POST/DELETE com CSRF | `RouteAuthorizationTest::test_nenhuma_rota_altera_dados_por_get` |
| Abuso | Limites em exportar (5/h), troca de e-mail, link, senha, ações da conta | — |
