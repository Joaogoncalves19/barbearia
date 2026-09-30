# Papéis e permissões (Fase 3)

Fonte da verdade: `novo-sistema/config/permissions.php`. A tabela da seção 3 é conferida pelo
`PermissionMatrixTest::test_matriz_papel_por_habilidade`: mudar a configuração sem mudar o teste (e este
documento) quebra o CI.

## 1. Papéis

Os tipos de pessoa pedidos no briefing (administrador, profissional, equipe, cliente) mapeiam para os
papéis que o domínio já tinha desde a Fase 1. **Não foram criados papéis novos.**

| Tipo (briefing) | Papel no sistema | Conta | Responsabilidade |
|---|---|---|---|
| Administrador | **Proprietário** (`owner`) | equipe | Administração completa, habilidade por habilidade: usuários, auditoria, configurações, LGPD, financeiro |
| Equipe | **Gerente** (`manager`) | equipe | Opera a barbearia (agenda, clientes, equipe, catálogo, caixa, relatórios, marketing). **Não** gerencia usuários, auditoria, configurações, lançamentos financeiros nem LGPD |
| Equipe | **Recepção** (`reception`) | equipe | Agenda, clientes e caixa do dia |
| Equipe | **Financeiro** (`finance`) | equipe | Financeiro e relatórios; sem agenda e sem clientes |
| Profissional/barbeiro | **Profissional** (`professional`) | equipe | Só o que é dele: a própria agenda, os próprios clientes e a própria ficha |
| Cliente | *(conta de cliente)* | cliente | Só os próprios dados, agendamentos e histórico |

- O usuário com papel **Profissional** fica ligado a uma **ficha de profissional** (`professionals.user_id`).
  Ao criar o usuário, a ficha é criada (mínima, ainda sem receber agendamentos, até a Fase 4 configurar
  serviços e horários). Um profissional pode existir **sem** login.
- O **cliente não tem papel**: toda conta de cliente ativa recebe só as `customer_abilities` (hoje,
  `account.access`), e o acesso a registros é decidido pelas Policies.

## 2. Habilidades

Declaradas agora, para que as próximas fases **só as usem**. A tela de cada módulo chega na fase dele.

| Habilidade | Significado | Usada a partir de |
|---|---|---|
| `panel.access` | Acessar o painel da equipe (e a própria conta) | Fase 1 |
| `system.health.view` | Ver a saúde do sistema | Fase 1 |
| `users.manage` | Criar, editar, desativar e redefinir a senha de usuários da equipe | **Fase 3** |
| `audit.view` | Ver a trilha de auditoria | **Fase 3** |
| `customers.view` | Ver qualquer cliente | Fase 4 |
| `customers.view_own` | Ver só os clientes que atendeu ou vai atender | Fase 4/5 |
| `customers.create` / `customers.update` | Cadastrar / editar clientes | Fase 4 |
| `customers.view_cpf` | Ver o CPF completo (fora disso, sempre mascarado) | Fase 4 |
| `customers.anonymize` | Anonimizar cliente (LGPD) | Fase 12 |
| `appointments.view_all` / `appointments.view_own` | Ver a agenda de todos / só a própria | Fase 5 |
| `appointments.manage` / `appointments.manage_own` | Criar e remarcar qualquer agendamento / só os da própria agenda | Fase 5 |
| `appointments.cancel` | Cancelar qualquer agendamento | Fase 5 |
| `team.view` / `team.manage` | Ver / gerenciar profissionais, horários e ausências | Fase 4 |
| `catalog.manage` | Serviços, combos, produtos e estoque | Fase 4 |
| `checkout.operate` | Fechar atendimento e lançar pagamento | Fase 6 |
| `finance.view` / `finance.manage` | Ver o financeiro / lançar despesas, vales e pagar comissões | Fase 7 |
| `reports.view` | Relatórios | Fase 7 |
| `marketing.manage` | Cupons, campanhas e fidelidade | Fases 8 e 10 |
| `settings.manage` | Configurações do estabelecimento | Fase 4 |
| `account.access` *(cliente)* | Acessar a própria conta de cliente | **Fase 3** |

## 3. Matriz papel × habilidade

✔ = permitido. Vazio = negado (deny by default).

| Habilidade | Proprietário | Gerente | Recepção | Financeiro | Profissional |
|---|:-:|:-:|:-:|:-:|:-:|
| `panel.access` | ✔ | ✔ | ✔ | ✔ | ✔ |
| `system.health.view` | ✔ | ✔ | | | |
| `users.manage` | ✔ | | | | |
| `audit.view` | ✔ | | | | |
| `customers.view` | ✔ | ✔ | ✔ | | |
| `customers.view_own` | | | | | ✔ |
| `customers.create` | ✔ | ✔ | ✔ | | |
| `customers.update` | ✔ | ✔ | ✔ | | |
| `customers.view_cpf` | ✔ | ✔ | | | |
| `customers.anonymize` | ✔ | | | | |
| `appointments.view_all` | ✔ | ✔ | ✔ | | |
| `appointments.view_own` | | | | | ✔ |
| `appointments.manage` | ✔ | ✔ | ✔ | | |
| `appointments.manage_own` | | | | | ✔ |
| `appointments.cancel` | ✔ | ✔ | ✔ | | |
| `team.view` | ✔ | ✔ | ✔ | | |
| `team.manage` | ✔ | ✔ | | | |
| `catalog.manage` | ✔ | ✔ | | | |
| `checkout.operate` | ✔ | ✔ | ✔ | | |
| `finance.view` | ✔ | ✔ | | ✔ | |
| `finance.manage` | ✔ | | | ✔ | |
| `reports.view` | ✔ | ✔ | | ✔ | |
| `marketing.manage` | ✔ | ✔ | | | |
| `settings.manage` | ✔ | | | | |

A distribuição acima é uma **proposta técnica conservadora** (menor privilégio) baseada nos perfis do
sistema antigo. Ajustar é mudar uma linha em `config/permissions.php` e a linha correspondente no teste e
aqui. Perfis editáveis pelo dono na tela ficaram para depois (ver o relatório da fase).

## 4. Regras por registro (Policies)

| Quem | Cliente (`Customer`) | Agendamento (`Appointment`) | Ficha (`Professional`) | Usuário (`User`) |
|---|---|---|---|---|
| Proprietário | ver, editar, CPF, anonimizar | ver, alterar, cancelar | ver | gerenciar (menos o próprio papel/status/senha provisória) |
| Gerente | ver, editar, CPF | ver, alterar, cancelar | ver | — |
| Recepção | ver, editar | ver, alterar, cancelar | ver | — |
| Financeiro | — | — | — (404) | — |
| Profissional | ver só quem tem agendamento com ele | ver/alterar/cancelar só na própria agenda | só a própria | — |
| Cliente | só o próprio (sem CPF de terceiros) | ver só os próprios; remarcar/cancelar: **ainda não** (D-13, Fase 5) | — | — |

Registro alheio → **404**; papel sem a capacidade → **403**. Teste da matriz:
`HorizontalAccessTest::test_matriz_das_policies_de_cliente_e_agendamento_por_papel`.
