# Relatório da Fase 3 — Identidade, acesso e autorização

> **Status: concluída em 2026-09-30, aguardando aprovação explícita do dono para iniciar a Fase 4.**
> Branch `claude/fase-3-identidade`. Só dados fictícios; nenhum banco de produção acessado; sistema antigo
> não alterado; nenhum segredo no repositório.
>
> Legenda: **PASSOU** · **FALHOU** · **NÃO EXECUTADO** · **PENDENTE**.

## 1. Resumo

| Critério de aceite (briefing, item 24) | Situação | Evidência |
|---|---|---|
| Login funcionando | PASSOU | `StaffAuthenticationTest`, `CustomerAuthenticationTest`, E2E |
| Logout funcionando | PASSOU | `test_logout_so_por_post_e_encerra_a_sessao`, `test_logout_do_cliente`, E2E |
| Sessões protegidas | PASSOU | regeneração, criptografia, cookies, revalidação, `auth.session` ([seguranca-contas.md](seguranca-contas.md)) |
| Recuperação de senha | PASSOU | `StaffPasswordTest`, `CustomerMagicLinkAndPasswordTest` |
| Redefinição | PASSOU | idem (token de uso único, vencido, de outra conta, conta desativada) |
| Alteração de senha | PASSOU | `test_troca_exige_a_senha_atual_e_uma_senha_diferente`, E2E da senha provisória |
| Rate limiting | PASSOU | login, e-mails, cadastro, tokens, senha atual (429 testado) |
| Senhas nunca em texto | PASSOU | `test_senha_e_guardada_com_hash_e_nunca_em_texto`, auditoria sem hash |
| DENY BY DEFAULT | PASSOU | `RouteAuthorizationTest`, `PermissionMatrixTest` |
| Policies/Gates | PASSOU | 4 Policies + 25 Gates |
| Papéis definidos | PASSOU | [papeis-permissoes.md](papeis-permissoes.md) |
| Permissões documentadas | PASSOU | matriz no documento = matriz no teste |
| Acesso horizontal bloqueado | PASSOU | `HorizontalAccessTest`, E2E (URL trocada → 404) |
| Acesso vertical indevido bloqueado | PASSOU | `AuthorizationTest` (403 por papel) |
| Cliente isolado dos demais | PASSOU | `test_cliente_ve_o_proprio_agendamento_e_nunca_o_de_outro`, E2E |
| Profissional isolado | PASSOU | `test_profissional_ve_a_propria_ficha_e_nao_a_de_outro`, `..._propria_agenda_e_os_proprios_clientes`, E2E |
| Admin com autorização explícita | PASSOU | sem curinga, sem `Gate::before` (`test_nenhum_papel_tem_curinga_nem_o_proprietario`) |
| Testes passando | PASSOU | 252 PHPUnit, 68 Playwright |
| PHPStan sem erros | PASSOU | Larastan nível 6, 0 erros |
| CI verde | ver §9 | |
| Testes de navegador | PASSOU | 68 passando, 2 ignorados de propósito (§7) |
| Nenhum secret no código | PASSOU | `ConfigurationTest`, senhas de dev/E2E aleatórias por execução |
| Nenhum dado real | PASSOU | factories e contas de E2E fictícias (e-mails `.test`, CPFs gerados) |
| Login e recuperação com o Design System | PASSOU | componentes `x-ui.*`, direção A; capturas em `storage/e2e/telas/*-fase3-*` |
| Responsividade | PASSOU | E2E celular (Pixel 7) e desktop, sem rolagem lateral |
| Acessibilidade básica | PASSOU | axe (nenhuma violação séria/crítica) em 15 telas, nos dois tamanhos |
| Documentação | PASSOU | 4 documentos novos + roadmap, README, modelo de dados, regras, decisões |

## 2. Decisões de produto aplicadas

| Decisão | Como ficou |
|---|---|
| Equipe entra por usuário (briefing) **e** por e-mail (mensagem do dono) | Um campo só, "Usuário ou e-mail". O **usuário continua sendo o identificador** da conta; o e-mail é opcional (recuperação, avisos). Os dois são únicos e sem diferença de maiúsculas |
| Cliente entra com senha e link mágico | Os dois. Link mágico de uso único, 15 min, e o clique no e-mail só mostra o botão (login no POST) |
| **CPF obrigatório** (mensagem do dono, que substitui o "não obrigatório" do briefing) | Cadastro exige CPF válido e único. Cliente sem CPF no banco (importado/incompleto) precisa informar o CPF antes de usar a conta. O cliente não altera o CPF depois. CPF mascarado na tela e na auditoria. A coluna segue anulável no banco só para esses registros antigos (ver §10) |
| Duplicidades | Nada é mesclado sozinho. CPF de outro cadastro no "completar cadastro" vira candidato a mesclagem para a equipe revisar |
| Agendamentos antigos não concluídos | Histórico preservado; sem receita, comissão nem pontos. Já era assim no importador e agora é **testado** (`ImportScenariosTest::test_agendamento_passado_nao_concluido_nao_gera_receita_comissao_nem_pontos`). A classificação de cada um fica para a migração real |
| Sistema não está em produção | Não há migração real. A estratégia de senhas legadas está pronta e foi validada só com dados fictícios |

## 3. O que foi implementado

**Autenticação** ([autenticacao.md](autenticacao.md)):

- Login e logout da equipe (`/painel/entrar`) e do cliente (`/entrar`), com guards separados.
- Cadastro do cliente com confirmação de e-mail e aceite opcional de novidades (com prova).
- Link mágico.
- "Esqueci a senha" e redefinição para os dois tipos de conta, com tabelas de token separadas.
- Troca de senha logado.
- Senha provisória definida pelo proprietário, com troca obrigatória.
- Reconfirmação de senha antes da gestão de usuários.
- "Manter conectado" limitado a 14/30 dias.
- Queda das outras sessões quando a senha muda.
- Revalidação da conta a cada requisição.
- `app:create-owner` para o primeiro proprietário.

**Autorização** ([autorizacao.md](autorizacao.md), [papeis-permissoes.md](papeis-permissoes.md)):

- 24 habilidades da equipe e 1 do cliente, declaradas para as próximas fases.
- Matriz explícita por papel. O curinga do proprietário foi removido.
- `UserPolicy`, `CustomerPolicy`, `ProfessionalPolicy` e `AppointmentPolicy`: registro alheio responde 404.
- Proteção contra formulário adulterado e contra promover a si mesmo; sempre sobra 1 proprietário ativo.

**Telas (direção A):**

- Acesso da equipe: login, esqueci/redefinir senha, confirmar senha.
- Acesso do cliente: login, cadastro, link mágico (pedido e confirmação), esqueci/redefinir senha.
- Painel: início com menu por permissão, minha conta (com a lista do que o perfil pode fazer), trocar
  senha, usuários (lista, novo, editar, senha provisória), ficha do profissional (só leitura) e auditoria.
- Área do cliente (fundação): minha conta com os próprios horários, detalhe do horário, meus dados,
  senha e completar cadastro (CPF).

**Auditoria:** eventos de acesso em `audit_logs` (login, logout, link mágico, senha, papel, ativação...),
`User` passou a ser auditado; a tela `/painel/auditoria` é só do proprietário.

**Dados:** migration nova `2026_09_29_000100_add_account_security_tables`:

- `users`: `must_change_password` e `password_changed_at`;
- `customers`: `password_changed_at` e `last_login_at`;
- tabelas novas `customer_password_reset_tokens` e `customer_login_tokens`.

## 4. Arquitetura

- **Módulo Identity:**
  - `Authorization/PermissionMatrix`;
  - `Services/` (`CredentialAttempt`, `LoginCredentials`, `PasswordManager`, `MagicLinkService`,
    `CustomerRegistration`, `StaffAccounts`);
  - `Notifications/` (enfileiradas e criptografadas na fila);
  - `Policies/UserPolicy`, `Rules/MaxBytes`, `Models/CustomerLoginToken`.
- **Policies nos módulos donos do model:** `Customers`, `Team`, `Scheduling`.
- **`System/Services/AuditTrail`** para eventos de segurança.
- **Controllers finos por área:** `Auth/Staff`, `Auth/Customer`, `Account`, `Panel`.
- **Middlewares:** `staff.active`, `staff.password`, `customer.active`, `customer.complete`, `no-store`,
  mais `auth.session` e `password.confirm` do Laravel.
- **Soluções prontas do Laravel usadas:** broker de redefinição de senha, `hashed` + `rehash_on_login`,
  `AuthenticateSession`, `RequirePassword`, `throttle`, URLs assinadas, `Timebox` e CSRF.
- **Código próprio, só onde o framework não cobre:**
  - link mágico;
  - login com tempo igual para conta inexistente;
  - matriz de permissões.

## 5. Papéis e permissões

Proprietário, Gerente, Recepção, Financeiro e Profissional (equipe), mais o Cliente (conta separada). Não
foram criados papéis novos. A matriz completa está em [papeis-permissoes.md](papeis-permissoes.md) e é
conferida linha por linha no `PermissionMatrixTest`.

## 6. Senhas legadas

bcrypt do sistema antigo entra e é **refeito** no primeiro login. Qualquer outro formato fica **sem senha**
e exige redefinição (cliente pelo e-mail ou link mágico; equipe pela senha provisória do proprietário).
Nada é adivinhado ou convertido. Testes: §5 de [autenticacao.md](autenticacao.md).

## 7. Testes

| Verificação | Resultado |
|---|---|
| PHPUnit (Unit + Feature + Legado) | **PASSOU** — 252 testes (eram 151); 0 falhas, 0 pulados |
| — novos/reescritos na Fase 3 | `Identity/StaffAuthenticationTest` (16), `StaffPasswordTest` (15), `CustomerAuthenticationTest` (17), `CustomerMagicLinkAndPasswordTest` (14), `AccountBootstrapTest` (7), `Security/AuthorizationTest` (20), `HorizontalAccessTest` (14), `RouteAuthorizationTest` (8), `HttpHardeningTest` (9), `Unit/PermissionMatrixTest` (8), `ImportScenariosTest` (+1) |
| Larastan nível 6 | **PASSOU** — 0 erros |
| Pint | **PASSOU** |
| Build (Vite) | **PASSOU** |
| `composer audit` / `npm audit` | **PASSOU** — nenhuma vulnerabilidade |
| Importador com banco fictício (simulação, importação, reexecução) | **PASSOU** (local) |
| Playwright + axe (celular e desktop) | **PASSOU** — 68 passando, 2 ignorados: o que já era ignorado na Fase 1 e "login só com teclado" no celular (teclado físico só no desktop) |
| — novos na Fase 3 | 6 telas de acesso × 2 tamanhos (axe, CSP, sem rolagem lateral, `no-store`); login errado com mensagem neutra; login só com teclado; fluxo do proprietário (reconfirmação de senha, usuários, auditoria, sair); senha provisória obrigatória; profissional (ficha própria ok, de outro 404, usuários 403); cliente (só os próprios horários, horário alheio 404, sessão não abre o painel) |
| Regressão de segurança do sistema antigo (`tests/seguranca_fase1.php`) | **PASSOU** localmente, com uma ressalva. Na primeira execução local, **14 itens FALHARAM** por causa do ambiente: a pasta temporária do Windows tem acentos ("João Gonçalves") e quebra o banco/cookies do teste. Com `TMP` apontando para uma pasta ASCII, todos passaram. O bloco de navegador (S-02) fica **NÃO EXECUTADO localmente** (o script procura o Node com `command -v`, que não existe no Windows) e roda no CI |

Contas dos testes de navegador: o comando `app:e2e-accounts` (só `local`/`testing`) cria contas fictícias
com uma senha **aleatória gerada pelo Playwright a cada execução**.

## 8. Problemas encontrados e como foram tratados

1. **PHP 8.2 local:** a máquina tinha só o PHP do XAMPP. Com autorização do dono, foram instalados o
   PHP 8.4 (winget) e o Composer (checksum conferido). O requisito PHP 8.4+ não foi rebaixado.
2. **Curinga do proprietário (`'*'`):** conflitava com o item 13 do briefing (admin sem bypass). Foi
   removido: o proprietário recebe cada habilidade pela lista.
3. **Tokens de redefinição numa tabela só:** cliente e equipe com o mesmo e-mail sobrescreveriam o token um
   do outro. Foi criada uma tabela própria para clientes.
4. **Policies × tipo de conta:** o Gate do Laravel não confere o tipo do ator antes de chamar a policy; um
   cliente numa policy da equipe daria erro 500. Todas as policies aceitam os dois tipos e negam
   explicitamente (testado).
5. **Tempo de resposta revelava contas:** o Laravel responde mais rápido quando a conta não existe. O
   `CredentialAttempt` iguala o custo.
6. **"Manter conectado" de ~5 anos** (padrão do Laravel): limitado a 14/30 dias.
7. **Senhas > 72 bytes** seriam truncadas pelo bcrypt em silêncio: agora são recusadas com mensagem clara.
8. **Modo estrito do Eloquent nos testes:** models da factory sem as colunas padrão do banco. As factories
   passaram a recarregar o registro depois de criar.
9. **Servidor embutido do PHP no Windows** atende uma requisição por vez: os fluxos longos do E2E foram
   marcados como lentos e a espera das asserções subiu para 15 s. No CI (Linux) isso não muda o resultado.

## 9. CI

**PENDENTE** até o push desta branch. O resultado será registrado aqui.

## 10. Pendências

1. **Perfis editáveis pelo dono na tela** (roadmap): não feito. A matriz é configuração versionada e testada;
   editar na tela exige tabela de papéis/permissões e cuidado contra escalada. Recomendado só se o dono
   quiser mudar permissões com frequência.
2. **CPF obrigatório no banco (`NOT NULL`):** não aplicado, porque o importador e o cadastro de cliente pelo
   balcão (Fase 4/5) podem criar registros sem CPF. Hoje a obrigatoriedade é garantida em todo cadastro
   pelo site e antes de usar a conta. **Decisão para a Fase 4:** a equipe também deve exigir CPF ao
   cadastrar cliente no balcão? Se sim, dá para tornar a coluna `NOT NULL` depois da migração real.
3. **Troca de e-mail pelo próprio cliente** (com confirmação do novo endereço): Fase 12.
4. **Remarcar/cancelar pelo cliente:** depende da política de cancelamento (D-13), Fase 5. A policy já nega.
5. **Páginas legais (privacidade/termos)** para linkar no cadastro: Fase 11 (hoje o cadastro não tem link).
6. **Provedor de e-mail (D-05)** e **hospedagem (D-01)**: sem mudança. Sem eles, os e-mails de conta não
   saem em homologação/produção.
7. **Segundo fator (2FA)** para o proprietário: recomendado numa fase futura.

## 11. Riscos

| Risco | Mitigação |
|---|---|
| Sem provedor de e-mail, recuperação e link mágico não funcionam em produção | D-05 antes da homologação; a equipe tem a senha provisória como alternativa |
| Enumeração residual de CPF por e-mail (cadastro) | Resposta neutra na tela, limite por IP, log; ver [seguranca-contas.md §4](seguranca-contas.md#4-riscos-conhecidos-e-aceitos) |
| Matriz de permissões proposta pela equipe técnica | Conservadora (menor privilégio); o dono revisa [papeis-permissoes.md](papeis-permissoes.md) |
| Proprietário perde a própria senha e não tem e-mail | Cadastrar e-mail do proprietário (recomendado) ou ter um segundo proprietário |

## 12. Recomendações para a Fase 4 (catálogo e equipe)

1. **Revisar a matriz** de [papeis-permissoes.md](papeis-permissoes.md) antes de começar: as telas da
   Fase 4 usam `team.*`, `catalog.manage`, `customers.*` e `settings.manage`.
2. **Decidir o CPF no cadastro pelo balcão** (§10.2).
3. **Seguir a receita** de [autorizacao.md §6](autorizacao.md#6-como-proteger-uma-tela-nova-receita-para-as-próximas-fases)
   em cada tela nova: rota no grupo `panel.`, `can:`, Policy e testes de 403/404.
4. **Evoluir a ficha do profissional** (`/painel/profissionais/{id}`) e ligar a gestão de usuários à ficha,
   que hoje é criada automaticamente.
5. **Cadastrar e-mail do proprietário** assim que o sistema for para homologação.

---

**Aguardando aprovação explícita para iniciar a Fase 4.** Silêncio não é autorização.
