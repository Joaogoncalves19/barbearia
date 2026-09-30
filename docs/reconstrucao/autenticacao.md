# Autenticação (Fase 3)

Como as pessoas entram no sistema novo, recuperam o acesso e trocam a senha. Autorização (o que
cada pessoa pode fazer) está em [autorizacao.md](autorizacao.md); papéis e permissões em
[papeis-permissoes.md](papeis-permissoes.md); controles de segurança das contas em
[seguranca-contas.md](seguranca-contas.md).

## 1. Duas contas, dois guards

| | Equipe | Cliente |
|---|---|---|
| Tabela | `users` | `customers` |
| Guard | `web` | `customer` |
| Entra com | **usuário ou e-mail** + senha | e-mail + senha **ou** link mágico |
| Onde | `/painel/entrar` → `/painel` | `/entrar` → `/minha-conta` |
| Recuperação | link por e-mail (se tiver e-mail) ou senha provisória do proprietário | link por e-mail |
| "Manter conectado" | até 14 dias | até 30 dias |

As contas são separadas de propósito (decisão T-03 da Fase 1). Uma sessão de cliente nunca abre o
painel, e uma sessão da equipe nunca abre a área do cliente. Isso é testado nos dois sentidos.

Decisões de produto da Fase 3:

- **Equipe:** entra com o nome de usuário **ou** com o e-mail, no mesmo campo. Se o texto tem `@`, é
  tratado como e-mail. O **usuário continua sendo o identificador da conta**: o e-mail é opcional e serve
  para entrar, recuperar a senha e receber avisos.
- **Cliente:** entra com senha **e/ou** link mágico.
- **CPF do cliente: obrigatório.** Todo cadastro pelo site exige CPF válido (dígitos verificadores). Cliente
  que já esteja no banco sem CPF (importado ou cadastrado incompleto) precisa informar o CPF antes de usar
  qualquer tela da conta. Na tela, o CPF aparece sempre mascarado (`529.***.***-25`).

## 2. Fluxos

### 2.1 Login da equipe

1. `POST /painel/entrar` com `identifier` (usuário ou e-mail, sem diferenciar maiúsculas) e `password`.
2. `CredentialAttempt` busca **só contas ativas**. Conta inexistente, inativa, sem senha ou senha errada
   recebem **a mesma mensagem** ("Usuário, e-mail ou senha incorretos.") e o mesmo custo de tempo: quando a
   conta não existe, um hash descartável é calculado.
3. Sucesso: sessão regenerada (contra fixação), `last_login_at`, evento `auth.login` na auditoria.
4. Senha provisória (`must_change_password`)? Qualquer tela do painel manda para "Crie sua senha pessoal"
   até a troca.

### 2.2 Login do cliente com senha

Igual ao da equipe, por e-mail. Diferenças:

- só entra quem está ativo, **não mesclado**, **não anonimizado** e **não excluído**;
- e-mail **não confirmado** não entra: quem acertou a senha recebe um novo link de confirmação. Só quem
  sabe a senha chega a essa mensagem.

### 2.3 Link mágico (cliente)

1. `/entrar/link`: o cliente informa o e-mail. A resposta é sempre a mesma ("se este e-mail tiver cadastro,
   enviamos...") e com tempo igualado (`Timebox`).
2. Se a conta existe e pode entrar, é gerado um token aleatório de 64 caracteres. Só o **hash SHA-256** vai
   para `customer_login_tokens`. O link vale 15 minutos. Um pedido novo invalida os anteriores ainda não
   usados. Há no máximo um pedido por minuto por conta, e o excesso é ignorado em silêncio.
3. Abrir o link (`GET /entrar/link/{token}`) **não faz login**: mostra o botão "Entrar na minha conta"
   (`POST`). Assim, leitores de e-mail que pré-carregam links não gastam o token.
4. O `POST` gasta o token de forma **atômica** (`UPDATE ... WHERE used_at IS NULL`: dois cliques ao mesmo
   tempo, só um entra), confere de novo se a conta pode entrar e faz o login. Entrar pelo link também
   **confirma o e-mail**.

### 2.4 Cadastro do cliente

`/cadastro` pede nome, e-mail, **CPF (obrigatório)**, celular (opcional), senha e, opcionalmente, o aceite
de novidades por e-mail. A tela responde **sempre igual** ("enviamos um e-mail..."), para não revelar quem
já é cliente. O que muda é o e-mail que chega ao endereço informado:

| Situação | O que acontece |
|---|---|
| E-mail, CPF e celular livres | Conta criada com e-mail **não confirmado**, sem login automático; e-mail de confirmação (link assinado, 24 h) |
| E-mail já cadastrado | Nada é criado; o dono do e-mail recebe "você já tem cadastro" com link para definir a senha |
| CPF ou celular de outro cliente | Nada é criado nem mesclado; o endereço informado recebe "não conseguimos concluir" (sem dizer qual dado) |

O aceite de novidades vira `marketing_email_consent = granted` com registro de prova (`consent_records`).
Sem marcar, o consentimento fica `unknown` (regra da Fase 2: ausência não é consentimento).

### 2.5 Confirmação de e-mail

Link assinado e com validade (`/confirmar-email/{public_id}/{hash}`), gerado sempre a partir do `APP_URL`.
Leva o `public_id` (ULID), nunca o id sequencial, e o hash do e-mail **atual**: um link antigo não confirma
um e-mail novo. Não exige estar logado (a pessoa pode abrir em outro aparelho) e **não faz login**.

### 2.6 "Esqueci a senha"

- **Equipe:** aceita usuário ou e-mail; o link vai para o e-mail cadastrado. Conta sem e-mail não recebe
  nada. A tela explica, para todos, que quem não tem e-mail pede uma **senha provisória** ao proprietário.
- **Cliente:** por e-mail. Também serve para quem **nunca teve senha** (cadastrado no balcão, importado com
  hash não reconhecido): criar a senha pelo link confirma o e-mail.
- Tokens do broker do Laravel: guardados como hash, **uso único**, expiram em **60 min**, no máximo um por
  conta por minuto. Equipe e clientes usam **tabelas separadas** (`password_reset_tokens` e
  `customer_password_reset_tokens`), porque um cliente e alguém da equipe podem ter o mesmo e-mail.
- O link de redefinição não leva o e-mail na URL. A pessoa digita o e-mail na tela, e o broker confere que
  o token é daquela conta.
- Redefinir **não faz login**. A senha nova vale na hora e as outras sessões caem (seção 4).

### 2.7 Trocar a senha logado

- **Equipe** (`/painel/minha-conta/senha`): exige a senha atual, e a nova precisa ser diferente.
- **Cliente** (`/minha-conta/senha`): exige a senha atual. Quem só usa link mágico (sem senha) pode criar
  uma.
- As duas telas têm limite de tentativas (`throttle:password-check`) contra quem tenta adivinhar a senha
  atual numa sessão roubada.

### 2.8 Senha provisória (proprietário)

Para conta nova e para quem esqueceu a senha e não tem e-mail. O proprietário define a senha em
`/painel/usuarios/{id}/editar` e passa pessoalmente. Efeitos: `must_change_password = true`, as sessões
abertas dessa pessoa caem e ela cria a própria senha no primeiro acesso. Ninguém define senha provisória
para si mesmo.

### 2.9 Logout

Só por `POST` (com CSRF): `/painel/sair` e `/minha-conta/sair`. Invalida a sessão e troca o token CSRF.
Como equipe e cliente compartilham o cookie de sessão do navegador, sair de uma conta encerra a sessão
inteira daquele navegador.

## 3. Senhas

| Tema | Como é |
|---|---|
| Armazenamento | Cast `hashed` do Laravel com **bcrypt** (custo 12; 4 nos testes). Nunca em texto, nunca em log, nunca na auditoria. `password` e `remember_token` ficam ocultos na serialização |
| Política | Mínimo de 8 caracteres, com letras e números (`Password::defaults()`), e no máximo **72 bytes**, porque o bcrypt ignora o resto em silêncio (`MaxBytes`) |
| Campo de senha | Nunca é preenchido de volta depois de um erro |
| Senhas legadas | Ver seção 5 |

## 4. Sessões

- Driver `database`, **criptografada**, cookie `HttpOnly`, `SameSite=Lax`, `Secure` em HTTPS
  (`SESSION_SECURE_COOKIE=true` em produção/homologação), validade de 120 minutos sem uso.
- Regenerada no login, invalidada no logout.
- **Revalidação a cada requisição:** `staff.active` e `customer.active` derrubam na hora quem foi
  desativado, mesclado, anonimizado ou excluído. Papel alterado vale na hora, porque as permissões são
  lidas do banco em cada requisição.
- **Troca de senha derruba as outras sessões:** toda rota autenticada usa `auth.session`, que compara o hash
  da senha guardado na sessão (e no cookie de "manter conectado") com o atual. Toda gravação de senha
  (`PasswordManager`) também troca o `remember_token`, então todo "manter conectado" antigo deixa de valer.
- **Reconfirmação de senha** antes da gestão de usuários (`password.confirm`, válida por 15 min).
- Páginas de acesso e de conta com `Cache-Control: no-store`: o "voltar" do navegador depois do logout não
  mostra dados.

## 5. Senhas do sistema antigo

Estratégia da Fase 2 mantida ([importador.md](importador.md#senhas)):

| Hash importado | Resultado | Teste |
|---|---|---|
| bcrypt (`$2y$`, custo 10 do sistema antigo) | Entra normalmente; no primeiro login o hash é **refeito** com a configuração atual (`hashing.rehash_on_login`) | `CustomerAuthenticationTest::test_senha_antiga_bcrypt_do_sistema_legado_entra_e_e_re_hasheada`, `ImportScenariosTest::test_senhas_antigas_continuam_funcionando_e_sao_rehasheadas` |
| Qualquer outro formato (md5, texto puro...) | Importado **sem senha**: nunca confere. O cliente cria a senha pelo "Esqueci a senha" ou entra pelo link mágico; alguém da equipe recebe senha provisória do proprietário | `StaffAuthenticationTest::test_conta_sem_senha_nunca_entra`, `CustomerAuthenticationTest::test_cliente_sem_senha_nunca_entra_por_senha`, `CustomerMagicLinkAndPasswordTest::test_cliente_sem_senha_cria_uma_pela_redefinicao` |

Nada é adivinhado, quebrado ou convertido de forma insegura. **Observação:** o sistema ainda não está em
produção e não há dados reais a migrar. A estratégia fica pronta para quando houver, e foi validada só com
dados fictícios.

## 6. Primeiro proprietário

```bash
php artisan app:create-owner
```

- Só funciona enquanto **não existe nenhum** proprietário. Os demais usuários são criados pelo painel.
- A senha **nunca vai na linha de comando** (ficaria no histórico do shell): é digitada sem eco (e
  confirmada), ou gerada com `--generate-password` e mostrada uma única vez.
- A senha é provisória: a troca é obrigatória no primeiro acesso.
- Em produção/homologação exige confirmação interativa (ou `--force`, conscientemente).
- Opções: `--name`, `--username`, `--email` (opcional).

Em desenvolvimento, `php artisan db:seed` (só `local`/`testing`) cria um usuário por papel (usuário = nome
do papel) e um cliente de exemplo, com senha aleatória mostrada no terminal.

## 7. Onde está no código

| Peça | Arquivo |
|---|---|
| Tentativa de login (tempo igual) | `app/Modules/Identity/Services/CredentialAttempt.php` |
| Quem pode entrar (busca) | `app/Modules/Identity/Services/LoginCredentials.php` |
| Gravação de senha | `app/Modules/Identity/Services/PasswordManager.php` |
| Link mágico | `app/Modules/Identity/Services/MagicLinkService.php`, `Models/CustomerLoginToken.php` |
| Cadastro | `app/Modules/Identity/Services/CustomerRegistration.php` |
| Contas da equipe | `app/Modules/Identity/Services/StaffAccounts.php` |
| E-mails | `app/Modules/Identity/Notifications/*` (enfileirados e criptografados na fila) |
| Controllers | `app/Http/Controllers/Auth/{Staff,Customer}`, `Account/*`, `Panel/*` |
| Middlewares | `EnsureStaffIsActive`, `EnsureStaffPasswordIsCurrent`, `EnsureCustomerIsActive`, `EnsureCustomerProfileIsComplete`, `PreventCaching` |
| Primeiro proprietário | `app/Console/Commands/CreateOwner.php` |
