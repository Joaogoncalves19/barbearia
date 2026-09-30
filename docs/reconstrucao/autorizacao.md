# Autorização (Fase 3)

Quem pode fazer o quê, e **como isso é garantido no servidor**. Papéis e a matriz completa estão em
[papeis-permissoes.md](papeis-permissoes.md); a entrada no sistema, em [autenticacao.md](autenticacao.md).

## 1. Princípio: DENY BY DEFAULT

Nenhuma pessoa recebe acesso só porque conseguiu chegar a uma URL. Em todos os caminhos, a falta de
informação resulta em **negar**:

| Situação | Resultado |
|---|---|
| Habilidade não declarada em `config/permissions.php` | Não existe Gate: o Laravel nega |
| Papel sem entrada na matriz, ou usuário inativo | Nenhuma permissão |
| Ator de outro tipo (cliente numa regra da equipe e vice-versa) | Negado, sem erro 500 |
| Rota sem `auth:<guard>` + revalidação + `can:` | O CI quebra (`RouteAuthorizationTest`) |
| Registro de outra pessoa | Policy nega (404) |

**Não existe bypass de administrador:** não há `Gate::before`, e o proprietário não tem curinga. Ele recebe
cada habilidade **pela lista**, como todo mundo, e passa pelas mesmas Policies. Ações só do proprietário
(gestão de usuários, auditoria, configurações, LGPD) têm habilidade própria.

## 2. Duas camadas

```text
Requisição ─► Rota: auth:<guard> ─► staff.active / customer.active ─► auth.session ─► can:habilidade ─► can:metodo,registro ─► Controller
                   (quem é?)          (ainda pode entrar?)             (senha mudou?)    (o papel pode?)     (este registro é dele?)
```

1. **Gate = capacidade do papel (acesso vertical).** "Recepção pode ver clientes?" Uma Gate por habilidade
   declarada; resposta pela `PermissionMatrix`.
2. **Policy = o registro (acesso horizontal).** "Este agendamento é deste cliente?", "esta ficha é deste
   profissional?". Registradas no `AppServiceProvider`:

| Model | Policy | Regras principais |
|---|---|---|
| `User` (equipe) | `Identity/Policies/UserPolicy` | Só `users.manage`. Ninguém muda o **próprio** papel/status nem define senha provisória para si |
| `Customer` | `Customers/Policies/CustomerPolicy` | Cliente: só o próprio cadastro. Equipe: `customers.view` vê todos; profissional (`customers.view_own`) só quem tem agendamento com ele; CPF completo só com `customers.view_cpf` |
| `Professional` | `Team/Policies/ProfessionalPolicy` | `team.view` vê qualquer ficha; o profissional vê só a própria |
| `Appointment` | `Scheduling/Policies/AppointmentPolicy` | Cliente: só os próprios (remarcar/cancelar negado até a política D-13, Fase 5). Equipe: `*_all` todos; profissional (`*_own`) só a própria agenda |

Registro de outra pessoa responde **404** (`Response::denyAsNotFound()`): trocar o id na URL não confirma
nem que o registro existe. Falta de capacidade do papel responde **403**.

## 3. Onde a autorização acontece

- **Na rota**, sempre: `can:habilidade` e, com registro, `can:metodo,parametro`
  (ex.: `can:view,appointment`). Listagens partem do usuário logado
  (`$customer->appointments()`), nunca de um id vindo da requisição.
- **No controller**, quando a regra depende do que foi enviado. Exemplo: mudar papel/status de um usuário
  exige `changeRoleOrStatus`, e um formulário adulterado com `role` é recusado por inteiro (403).
- **No serviço**, para regras que valem para todos. Exemplo: sempre sobra pelo menos um proprietário ativo
  (`StaffAccounts`, R-IDENT-01).
- **Na interface**, só para esconder o que não se pode usar (`@can`). Esconder um botão **não** protege
  nada; o teste de autorização chama as rotas direto.

## 4. Proteções contra manipulação

| Ataque | Defesa | Teste |
|---|---|---|
| Trocar o id/código na URL (IDOR) | Policy por registro, com 404 | `HorizontalAccessTest::test_cliente_ve_o_proprio_agendamento_e_nunca_o_de_outro`, `test_profissional_ve_a_propria_ficha_e_nao_a_de_outro` |
| Enviar `id`/`customer_id` no formulário | O registro alterado é sempre o da sessão | `test_formulario_de_dados_altera_so_o_proprio_cadastro_mesmo_com_ids_enviados` |
| Enviar `cpf`, `status`, `role`, `is_active`... | Campos gravados um a um; `role`, `is_active`, `must_change_password`, `status`, consentimento fora do `$fillable` | `test_cliente_nao_altera_cpf_...`, `test_minha_conta_da_equipe_nao_permite_se_promover_nem_trocar_login` |
| Escalar o próprio papel | `UserPolicy::changeRoleOrStatus` nega para si mesmo | `AuthorizationTest::test_ninguem_muda_o_proprio_papel_nem_se_desativa` |
| Chamar endpoint administrativo direto | `can:users.manage` na rota | `AuthorizationTest::test_so_o_proprietario_gerencia_usuarios` |
| Sessão de uma conta na área da outra | Guards separados (`auth:web` × `auth:customer`) | `test_sessao_de_cliente_nao_abre_o_painel`, `test_sessao_da_equipe_nao_abre_a_area_do_cliente` |
| Painel aberto e desbloqueado | Reconfirmação de senha (15 min) antes da gestão de usuários | `test_gestao_de_usuarios_pede_a_senha_de_novo` |
| Usuário desativado ou rebaixado com sessão aberta | Revalidação a cada requisição | `test_usuario_desativado_perde_o_acesso_na_requisicao_seguinte`, `test_papel_rebaixado_perde_a_permissao_na_hora` |
| Requisição forjada (CSRF) | Token em todo formulário; nenhuma rota excluída; mudança de estado só por POST/PUT | `HttpHardeningTest::test_post_sem_token_csrf_e_recusado`, `test_nenhuma_rota_esta_fora_da_protecao_csrf` |

## 5. Regressão do princípio (item 19 do briefing)

`tests/Feature/Security/RouteAuthorizationTest.php` percorre **todas** as rotas e quebra o CI se:

- uma rota fora da lista pública explícita não tiver `auth:web` ou `auth:customer` (o `auth` sem guard é
  proibido, para não aceitar a conta errada);
- faltar a revalidação (`staff.active` / `customer.active`) ou o `auth.session`;
- faltar `can:`, ou a habilidade usada não estiver declarada, ou o parâmetro da policy não existir na rota;
- uma rota do painel não usar o guard da equipe (e `staff.password`), ou uma da área do cliente não usar o
  guard do cliente;
- uma página de conta/acesso não tiver `no-store`;
- um formulário que dispara e-mail ou testa segredo não tiver limite de tentativas;
- alguma rota `GET` tiver cara de ação (excluir, sair, gravar...).

A lista pública fica no próprio teste, **com o motivo de cada rota ser pública**. Acrescentar uma rota
pública exige editar essa lista, e isso aparece na revisão.

## 6. Como proteger uma tela nova (receita para as próximas fases)

1. Use uma habilidade existente de [papeis-permissoes.md](papeis-permissoes.md) ou declare uma nova em
   `config/permissions.php`, dando-a **explicitamente** aos papéis. Atualize a matriz do
   `PermissionMatrixTest` e a documentação.
2. Coloque a rota dentro do grupo `panel.` (equipe) ou `account.` (cliente). O guard e a revalidação vêm do
   grupo.
3. Acrescente `->middleware('can:habilidade')`. Se a rota recebe um registro, use também
   `can:metodo,parametro` e uma Policy (registrada no `AppServiceProvider`).
4. Escreva os testes: sucesso, papel sem permissão (403), registro de outra pessoa (404) e formulário
   adulterado.
