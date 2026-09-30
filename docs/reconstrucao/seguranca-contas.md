# Segurança das contas (Fase 3)

Checklist do item 17 do briefing: o que foi verificado, como e qual teste prova. Preferimos sempre o
mecanismo que o Laravel já oferece; o que foi escrito à mão está marcado com *(próprio)* e justificado.

## 1. Controles

| Tema | Como está | Teste |
|---|---|---|
| **CSRF** | Middleware do Laravel em todas as rotas web, **sem exceções**; token em todo formulário; mudança de estado só por POST/PUT (logout inclusive) | `HttpHardeningTest::test_post_sem_token_csrf_e_recusado`, `test_nenhuma_rota_esta_fora_da_protecao_csrf`, `test_formularios_levam_token_csrf`, `RouteAuthorizationTest::test_nenhuma_rota_altera_dados_por_get` |
| **Sessão** | `database`, criptografada, 120 min sem uso, regenerada no login, invalidada no logout | `StaffAuthenticationTest::test_login_regenera_a_sessao_contra_fixacao`, `HttpHardeningTest::test_sessao_criptografada_e_com_validade` |
| **Cookies** | `HttpOnly`; `SameSite=Lax`; `Secure` quando HTTPS (`SESSION_SECURE_COOKIE=true` em produção/homologação, conferido por `app:diagnose`) | `HttpHardeningTest::test_cookie_de_sessao_e_http_only_same_site_e_seguro_quando_https` |
| **Expiração** | Sessão 120 min; "manter conectado" 14 dias (equipe) e 30 dias (cliente), contra ~5 anos no padrão do Laravel; reconfirmação de senha vale 15 min; tokens de redefinição 60 min; link mágico 15 min; confirmação de e-mail 24 h | `test_manter_conectado_dura_no_maximo_14_dias`, `..._30_dias`, `test_token_vencido_e_recusado`, `test_link_magico_vence`, `test_link_de_confirmacao_..._vencido...` |
| **Revalidação** | Conta desativada, mesclada, anonimizada ou excluída perde o acesso na **próxima requisição**; papel alterado vale na hora | `AuthorizationTest::test_usuario_desativado_...`, `test_papel_rebaixado_...` |
| **Outras sessões** | Troca/redefinição de senha derruba as demais sessões (`auth.session`) e o "manter conectado" antigo (novo `remember_token`) | `StaffPasswordTest::test_trocar_a_senha_derruba_as_outras_sessoes`, `test_redefinicao_invalida_o_manter_conectado_antigo`, `AuthorizationTest::test_senha_provisoria_derruba_as_sessoes_da_pessoa` |
| **Rate limiting** | Login: 5/min por conta+IP e 30/min por IP. E-mails (redefinição, link mágico): 3 por conta e 10 por IP a cada 10 min. Cadastro: 5/h por IP. Uso de token: 10/min por IP. Senha atual (troca/reconfirmação): 5/min. A chave por conta é o **hash** do identificador, normalizado (maiúsculas e espaços não contornam o limite) | `test_tentativas_em_excesso_sao_bloqueadas_com_429`, `test_limite_vale_para_o_mesmo_login_escrito_de_outro_jeito`, `test_pedidos_em_excesso_do_mesmo_ip_sao_bloqueados`, `test_cadastros_em_excesso_...`, `test_tentativas_de_token_em_excesso_...`, `RouteAuthorizationTest::test_formularios_que_disparam_e_mail_ou_testam_segredo_tem_limite` |
| **Enumeração de contas** | Mesma mensagem **e** mesmo custo para conta inexistente, inativa, sem senha ou senha errada *(próprio: `CredentialAttempt` calcula um hash descartável quando a conta não existe, porque o Laravel responde mais rápido nesse caso)*. "Esqueci a senha", link mágico e cadastro com resposta neutra e tempo igualado (`Timebox`); e-mails enfileirados | `test_senha_errada_e_conta_inexistente_recebem_a_mesma_resposta`, `test_conta_inexistente_inativa_ou_sem_e_mail_...`, `test_e_mail_sem_conta_ou_conta_inativa_...`, `test_e_mail_ja_cadastrado_tem_a_mesma_resposta...`, `test_cpf_de_outro_cliente_nao_cria_nem_mescla_e_a_tela_nao_revela` |
| **Mensagens de erro** | Neutras, em português, sem detalhe técnico; páginas de erro próprias (403, 404, 419, 429, 500, 503); `APP_DEBUG=false` fora do ambiente local | `AuthorizationTest::test_sem_a_permissao_da_rota_o_acesso_e_negado_com_403` |
| **Recuperação de senha** | Broker do Laravel: token aleatório guardado como **hash**, **uso único**, 60 min, 1 por minuto por conta; tabelas separadas equipe × cliente; link sempre pelo `APP_URL` (regressão S-06); sem e-mail na URL | `StaffPasswordTest::*`, `CustomerMagicLinkAndPasswordTest::test_cliente_e_equipe_com_o_mesmo_e_mail_tem_tokens_separados`, `HttpHardeningTest::test_links_gerados_usam_app_url_e_ignoram_o_cabecalho_host` |
| **Link mágico** *(próprio)* | Não existe no Laravel. Token de 64 caracteres, só o SHA-256 no banco, uso único **atômico**, 15 min, o pedido novo invalida os anteriores, o GET não faz login (só o POST) | `CustomerMagicLinkAndPasswordTest::*` |
| **Autorização** | Deny by default, Gates + Policies, sem bypass de administrador ([autorizacao.md](autorizacao.md)) | `AuthorizationTest`, `HorizontalAccessTest`, `RouteAuthorizationTest`, `PermissionMatrixTest` |
| **Validação de entrada** | FormRequests/`validate()` em todo formulário; e-mail, usuário, CPF (dígitos verificadores), celular (E.164) normalizados; `role`/`is_active`/`status` fora do `$fillable`; limite de 72 bytes na senha (bcrypt) | `test_cpf_e_obrigatorio_e_precisa_ser_valido`, `test_usuario_nao_pode_ter_arroba_nem_repetir`, `test_senha_nova_precisa_seguir_a_politica` |
| **Cabeçalhos** | CSP estrita (sem `unsafe-inline`/`unsafe-eval`), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, HSTS em HTTPS; páginas de conta e de acesso com `Cache-Control: no-store` | `HttpHardeningTest::test_cabecalhos_de_seguranca_e_csp_estrita`, `test_paginas_de_acesso_e_de_conta_nao_ficam_em_cache` |

## 2. Segredos nunca em log nem no banco em texto

- Senhas: só hash (bcrypt). Nunca na auditoria nem nos logs: a trait `Auditable` exclui `password` e
  `remember_token`, e a `AuditTrail` descarta chaves com cara de segredo. Teste:
  `StaffAuthenticationTest::test_login_e_logout_vao_para_a_auditoria_sem_senha`.
- Tokens (redefinição, link mágico): só o hash no banco. O token em texto existe apenas dentro do e-mail. Na
  fila, a notificação é **criptografada** (`ShouldBeEncrypted`), então o token não fica legível em
  `jobs`/`failed_jobs`. Parâmetros com token/senha são marcados `#[\SensitiveParameter]` (não aparecem em
  stack traces).
- Tentativas de login recusadas vão para o log `security` com o identificador em **hash SHA-256**, nunca a
  senha nem o e-mail em claro. Elas **não** vão para a auditoria no banco: um ataque encheria a tabela.
- CPF: mascarado na tela, na auditoria e nos candidatos a mesclagem.
- **Atenção em desenvolvimento:** com `MAIL_MAILER=log`, o e-mail inteiro (com o link) vai para
  `storage/logs`. Isso é aceitável só localmente; em produção/homologação o mailer é SMTP ou um provedor
  (D-05), e o `app:diagnose` acusa `log`.

## 3. Auditoria de acesso

Eventos em `audit_logs` (somente inclusão), visíveis em `/painel/auditoria` (só o proprietário):

`auth.login`, `auth.logout`, `auth.magic_link_used`, `auth.email_verified`, `password.changed`,
`password.reset`, `password.temporary_set`, `customer.registered`, `customer.cpf_conflict`,
`user.created`, `user.bootstrap_owner`, `user.role_changed`, `user.activated`, `user.deactivated`, além
das alterações de dados de `User` e `Customer` registradas pela trait `Auditable` (sem senha, CPF
mascarado).

## 4. Riscos conhecidos e aceitos

| Risco | Por que foi aceito | Mitigação |
|---|---|---|
| No cadastro, quem controla um e-mail pode descobrir se um CPF já está cadastrado (recebe "não conseguimos concluir" em vez do link de confirmação) | É preciso dizer algo ao dono legítimo do CPF | A tela não revela nada, o e-mail não diz qual dado conflitou, limite de 5 cadastros/h por IP, log `security` |
| Ao completar o CPF, um cliente logado descobre que o CPF pertence a outro cadastro | Precisa ser orientado a procurar a barbearia | Exige estar logado; o par vai para revisão da equipe (nunca mescla) |
| Confirmar o e-mail é um GET que altera dado | É o padrão dos links de confirmação; o link é assinado, vence e só marca o e-mail como confirmado (não faz login) | Assinatura + validade + hash do e-mail atual + limite |
| Equipe e cliente compartilham o cookie de sessão do navegador | Guards separados já isolam as contas | Sair de uma encerra a sessão inteira daquele navegador (documentado) |
| Sem segundo fator (2FA) | Fora do escopo desta fase | Recomendado para o proprietário numa fase futura |
