# Papéis e permissões (Fases 3 a 9)

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
| `agenda.view` | Consultar a agenda (todos ou só a própria, conforme `view_all`/`view_own`) | **Fase 5** |
| `schedule.settings` | Horário de funcionamento e regras da agenda | **Fase 5** |
| `schedule.working_hours` | Expediente e pausas dos profissionais | **Fase 5** |
| `schedule.time_off` | Lançar e remover folgas | **Fase 5** |
| `schedule.blocks` | Criar e remover bloqueios | **Fase 5** |
| `services.view` | Ver serviços e categorias | **Fase 4** |
| `services.create` | Criar serviços e categorias (inclui o preço inicial) | **Fase 4** |
| `services.update` | Editar serviços e categorias (nome, descrição, duração, categoria) | **Fase 4** |
| `services.toggle` | Ativar/desativar serviços e categorias (e excluir os nunca usados) | **Fase 4** |
| `services.price` | Alterar o preço atual dos serviços | **Fase 4** |
| `services.display` | Ordem, destaque, visibilidade no site e imagem dos serviços e categorias | **Fase 4** |
| `professionals.view` | Ver profissionais (lista e fichas) | **Fase 4** |
| `professionals.create` | Cadastrar profissionais | **Fase 4** |
| `professionals.update` | Editar profissionais (dados e conta de acesso vinculada) | **Fase 4** |
| `professionals.toggle` | Ativar/desativar profissionais | **Fase 4** |
| `professionals.services` | Definir quais serviços cada profissional executa | **Fase 4** |
| `professionals.display` | Ordem, destaque, visibilidade no site, foto e apresentação dos profissionais | **Fase 4** |
| `attendances.view` / `attendances.view_own` | Ver todos os atendimentos / só os próprios | **Fase 6** |
| `attendances.manage` / `attendances.manage_own` | Abrir, iniciar, editar e concluir qualquer atendimento / só os próprios | **Fase 6** |
| `attendances.discount` | Aplicar e retirar desconto no atendimento | **Fase 6** |
| `attendances.cancel` | Cancelar qualquer atendimento não concluído | **Fase 6** |
| `payments.receive` | Registrar pagamento ao concluir (entra no caixa aberto; não dá acesso ao caixa) | **Fase 6** |
| `payments.refund` | Estornar pagamento | **Fase 6** |
| `cash.view` / `cash.open` / `cash.move` / `cash.close` | Ver o caixa / abrir / suprimento e sangria / fechar | **Fase 6** |
| `products.view` / `products.create` / `products.update` / `products.toggle` | Ver / cadastrar / editar / ativar, desativar e excluir (sem histórico) produtos | **Fase 6** |
| `stock.view` / `stock.receive` / `stock.issue` / `stock.adjust` | Ver estoque / entrada / saída e perda / ajuste de inventário e estorno | **Fase 6** |
| `commissions.view` / `commissions.view_own` | Ver comissões, gorjetas, vales e saldo de todos / só o próprio extrato | **Fase 7** |
| `commissions.configure` | Configurar regras de comissão | **Fase 7** |
| `commissions.correct` | Lançar ajuste (correção) de comissão ou gorjeta | **Fase 7** |
| `commissions.history` | Consultar o histórico de regras, correções e repasses estornados | **Fase 7** |
| `payouts.view` / `payouts.create` / `payouts.reverse` | Ver repasses / registrar repasse / estornar repasse | **Fase 7** |
| `advances.create` / `advances.reverse` | Lançar vale / estornar vale | **Fase 7** |
| `reports.view` | Relatórios | Fase 7 |
| `coupons.view` / `coupons.manage` | Ver cupons / criar, editar, pausar e reativar cupons | **Fase 8** |
| `promotions.apply` | Aplicar cupom ou resgate de pontos no atendimento (balcão) | **Fase 8** |
| `promotions.configure` | Configurar fidelidade, aniversário e indicação | **Fase 8** |
| `loyalty.view` / `loyalty.adjust` | Ver pontos e extrato dos clientes / ajustar pontos (motivo obrigatório) | **Fase 8** |
| `gift_cards.view` / `gift_cards.sell` / `gift_cards.cancel` | Ver vales-presente (e o comprovante) / vender / cancelar com devolução | **Fase 8** |
| `subscriptions.view` / `subscriptions.payments` | Ver assinaturas / ver pagamentos e reembolsos | **Fase 9** |
| `subscriptions.create` | Gerar link de pagamento de assinatura | **Fase 9** |
| `subscriptions.cancel` / `subscriptions.reactivate` | Cancelar / reativar assinatura | **Fase 9** |
| `subscriptions.refund` | Reembolsar pagamento de assinatura | **Fase 9** |
| `subscriptions.history` | Histórico das assinaturas e eventos do Stripe | **Fase 9** |
| `plans.manage` | Configurar planos e versões | **Fase 9** |
| `communications.view` | Registro de e-mails (endereço mascarado) e prévia dos modelos | **Fase 10** |
| `communications.retry` | Reenviar e-mail que falhou | **Fase 10** |
| `communications.settings` | Configurar lembretes, pedido de avaliação e ritmo das campanhas | **Fase 10** |
| `campaigns.view` / `campaigns.manage` / `campaigns.send` | Ver campanhas / rascunho e teste / disparar e cancelar | **Fase 10** |
| `reviews.view` / `reviews.view_own` | Ver todas as avaliações / só as publicadas dos próprios atendimentos | **Fase 10** |
| `reviews.moderate` / `reviews.reply` | Aprovar, recusar (com motivo) e destacar / responder | **Fase 10** |
| `site.manage` | Conteúdo (textos, contatos, páginas legais) e imagens do site público | **Fase 11** |
| `settings.manage` | Configurações do estabelecimento | Fase 4 |
| `professional_area.access` | Usar a área do profissional (Hoje, agenda, atendimentos, ganhos e perfil próprios) | **Fase 12.5** |
| `customers.notes_own` | Ler e registrar anotações sobre os próprios clientes (preferências, cuidados) | **Fase 12.5** |
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
| `agenda.view` | ✔ | ✔ | ✔ | | ✔ |
| `schedule.settings` | ✔ | ✔ | | | |
| `schedule.working_hours` | ✔ | ✔ | | | |
| `schedule.time_off` | ✔ | ✔ | ✔ | | |
| `schedule.blocks` | ✔ | ✔ | ✔ | | |
| `services.view` | ✔ | ✔ | ✔ | | |
| `services.create` | ✔ | ✔ | | | |
| `services.update` | ✔ | ✔ | | | |
| `services.toggle` | ✔ | ✔ | | | |
| `services.price` | ✔ | ✔ | | | |
| `services.display` | ✔ | ✔ | | | |
| `professionals.view` | ✔ | ✔ | ✔ | | |
| `professionals.create` | ✔ | ✔ | | | |
| `professionals.update` | ✔ | ✔ | | | |
| `professionals.toggle` | ✔ | ✔ | | | |
| `professionals.services` | ✔ | ✔ | | | |
| `professionals.display` | ✔ | ✔ | | | |
| `attendances.view` | ✔ | ✔ | ✔ | ✔ | |
| `attendances.view_own` | | | | | ✔ |
| `attendances.manage` | ✔ | ✔ | ✔ | | |
| `attendances.manage_own` | | | | | ✔ |
| `attendances.discount` | ✔ | ✔ | | | |
| `attendances.cancel` | ✔ | ✔ | ✔ | | |
| `payments.receive` | ✔ | ✔ | ✔ | | ✔ |
| `payments.refund` | ✔ | | | ✔ | |
| `cash.view` | ✔ | ✔ | ✔ | ✔ | |
| `cash.open` / `cash.move` / `cash.close` | ✔ | ✔ | ✔ | | |
| `products.view` | ✔ | ✔ | ✔ | ✔ | |
| `products.create` / `update` / `toggle` | ✔ | ✔ | | | |
| `stock.view` | ✔ | ✔ | ✔ | ✔ | |
| `stock.receive` / `stock.issue` | ✔ | ✔ | ✔ | | |
| `stock.adjust` | ✔ | ✔ | | | |
| `commissions.view` | ✔ | ✔ | | ✔ | |
| `commissions.view_own` | | | | | ✔ |
| `commissions.configure` | ✔ | | | | |
| `commissions.correct` | ✔ | | | ✔ | |
| `commissions.history` | ✔ | ✔ | | ✔ | |
| `payouts.view` | ✔ | ✔ | | ✔ | |
| `payouts.create` / `payouts.reverse` | ✔ | | | ✔ | |
| `advances.create` / `advances.reverse` | ✔ | | | ✔ | |
| `reports.view` | ✔ | ✔ | | ✔ | |
| `coupons.view` | ✔ | ✔ | ✔ | ✔ | |
| `coupons.manage` | ✔ | ✔ | | | |
| `promotions.apply` | ✔ | ✔ | ✔ | | ✔ |
| `promotions.configure` | ✔ | | | | |
| `loyalty.view` | ✔ | ✔ | ✔ | ✔ | |
| `loyalty.adjust` | ✔ | ✔ | | | |
| `gift_cards.view` | ✔ | ✔ | ✔ | ✔ | |
| `gift_cards.sell` | ✔ | ✔ | ✔ | | |
| `gift_cards.cancel` | ✔ | | | ✔ | |
| `subscriptions.view` | ✔ | ✔ | ✔ | ✔ | |
| `subscriptions.payments` | ✔ | ✔ | | ✔ | |
| `subscriptions.create` | ✔ | ✔ | ✔ | | |
| `subscriptions.cancel` | ✔ | ✔ | | | |
| `subscriptions.reactivate` | ✔ | ✔ | | | |
| `subscriptions.refund` | ✔ | | | ✔ | |
| `subscriptions.history` | ✔ | ✔ | | ✔ | |
| `plans.manage` | ✔ | | | | |
| `communications.view` | ✔ | ✔ | | | |
| `communications.retry` | ✔ | ✔ | | | |
| `communications.settings` | ✔ | | | | |
| `campaigns.view` | ✔ | ✔ | | | |
| `campaigns.manage` | ✔ | ✔ | | | |
| `campaigns.send` | ✔ | ✔ | | | |
| `reviews.view` | ✔ | ✔ | ✔ | | |
| `reviews.view_own` | | | | | ✔ |
| `reviews.moderate` | ✔ | ✔ | | | |
| `reviews.reply` | ✔ | ✔ | | | |
| `site.manage` | ✔ | ✔ | | | |
| `settings.manage` | ✔ | | | | |
| `professional_area.access` | | | | | ✔ |
| `customers.notes_own` | | | | | ✔ |

A distribuição acima é uma **proposta técnica conservadora** (menor privilégio) baseada nos perfis do
sistema antigo. Ajustar é mudar uma linha em `config/permissions.php` e a linha correspondente no teste e
aqui. Perfis editáveis pelo dono na tela ficaram para depois (ver o relatório da fase).

## 4. Regras por registro (Policies)

**Fase 4:** `team.view`, `team.manage` e `catalog.manage` (declaradas na Fase 3, ainda sem telas) foram
substituídas pelas habilidades granulares acima, uma por ação, como pediu o briefing. Produtos e estoque
ganham habilidade própria quando tiverem tela (Fase 6). A recepção **consulta** catálogo e equipe (precisa
saber preço, duração e quem faz o quê), mas não altera nada.

**Permissão por campo:** preço (`services.price`) e exibição (`*.display`) são conferidos também **por
campo**: quem edita o serviço sem poder mudar o preço nem vê o campo, e um pedido adulterado com o preço
diferente recebe 403, sem gravar nada (`CatalogAuthorizationTest`).

| Quem | Cliente (`Customer`) | Agendamento (`Appointment`) | Ficha (`Professional`) | Usuário (`User`) |
|---|---|---|---|---|
| Proprietário | ver, editar, CPF, anonimizar | ver, alterar, cancelar | ver | gerenciar (menos o próprio papel/status/senha provisória) |
| Gerente | ver, editar, CPF | ver, alterar, cancelar | ver | — |
| Recepção | ver, editar | ver, alterar, cancelar | ver | — |
| Financeiro | — | — | — (404) | — |
| Profissional | ver só quem tem agendamento com ele | ver/alterar/cancelar só na própria agenda | só a própria | — |
| Cliente | só o próprio (sem CPF de terceiros) | ver, remarcar e cancelar só os próprios e futuros (prazos no `BookingService`); alheio = 404 | — | — |

**Fase 5 — agenda:** consultar (`agenda.view`) é separado de configurar (`schedule.*`). O profissional
consulta **só a própria** agenda (filtro na URL é ignorado), cria e remarca só nela (`createFor`) e não
configura nada. A recepção lança folgas e bloqueios, mas não mexe no funcionamento, nas regras nem no
expediente. Criar agendamento em nome de um profissional passa por `AppointmentPolicy@createFor`.

**Fase 6 — atendimento, caixa e estoque:** `checkout.operate` (declarada na Fase 3, sem tela) foi substituída
pelas habilidades granulares acima. `AttendancePolicy`: o profissional vê, abre, edita e conclui **só os
próprios** atendimentos (alheio = 404) e abre encaixe só como ele mesmo (`openFor`); concluir exige também
`payments.receive`, e desconto exige `attendances.discount`. A recepção opera atendimento e caixa do dia,
mas não dá desconto manual, não estorna e não ajusta inventário. O gerente não estorna (lançamento
financeiro, como na Fase 3). O financeiro consulta atendimentos, caixa e estoque e estorna. O cliente vê só o
**comprovante** dos próprios atendimentos concluídos. Desconto pela recepção e estorno pelo gerente foram
decididos pelo dono: **não** (D-29, D-30).

**Fase 7 — comissão, gorjeta, vales e repasse:** `finance.view`/`finance.manage` (declaradas na Fase 3, sem
tela, amplas demais: "lançar despesas, vales e pagar comissões") foram substituídas por habilidades por ação:
ver, ver o próprio, configurar regra, corrigir, histórico, ver/registrar/estornar repasse, lançar/estornar
vale. Regra de comissão: **só o proprietário**. Pagar, corrigir e estornar: proprietário e financeiro. O
gerente consulta (não lança finanças, como nas fases anteriores). O profissional vê **só o próprio**
extrato e os próprios repasses: `ProfessionalPolicy@viewLedger` e `CommissionPayoutPolicy@view` (de outro =
404). A recepção não vê comissões. Detalhes em [comissoes.md](comissoes.md) e [repasses.md](repasses.md).

Registro alheio → **404**; papel sem a capacidade → **403**. Teste da matriz:
`HorizontalAccessTest::test_matriz_das_policies_de_cliente_e_agendamento_por_papel`.

## Fase 8 — promoções, vale-presente e comprovantes

- Nove habilidades por ação, sem acesso amplo: cupom (ver / gerenciar), aplicar promoção no balcão,
  configurar o programa (só o proprietário, como as regras de comissão), pontos (ver / ajustar) e
  vale-presente (ver / vender / cancelar). `marketing.manage` fica só para campanhas (Fase 10).
- Aplicar promoção no balcão exige também poder editar aquele atendimento (`can:update,attendance`): o
  profissional só aplica nos próprios.
- Cancelar vale devolve dinheiro: proprietário e financeiro (mesmo critério do estorno, D-30).
- Comprovantes usam a permissão (ou policy) da tela do próprio documento; o cliente só vê e envia os
  próprios, para o próprio e-mail ([comprovantes.md](comprovantes.md)).
- Gravações com `throttle:money`; envio de comprovante com `throttle:receipts`.

## Fase 9 — assinaturas

- Oito habilidades por ação, sem acesso amplo. Planos só o proprietário (como as regras de comissão).
  Reembolso: proprietário e financeiro (como o estorno, D-30). Cancelar e reativar: proprietário e gerente.
- Ninguém vê as chaves do Stripe: ficam só em variáveis de ambiente; o painel mostra apenas se o pagamento
  online está configurado.
- O cliente vê e gerencia só a própria assinatura (cancelar a renovação, desfazer); cancelamento imediato,
  reembolso e plano são só da equipe.
- O webhook é público por natureza: a autenticidade é a assinatura do Stripe ([webhooks.md](webhooks.md)).

## Fase 10 — comunicação e avaliações

- `marketing.manage` (declarada na Fase 3, sem tela) foi substituída por habilidades por ação: ver,
  rascunho/teste e **disparo** de campanha são separados; ver o registro de e-mails não dá direito a reenviar;
  configurar lembretes e ritmo é só do proprietário (como as demais configurações).
- Avaliações: recepção consulta; moderar e responder, proprietário e gerente; o profissional vê só as
  **publicadas** dos próprios atendimentos (`ReviewPolicy::viewAny` + filtro no controller), com o primeiro
  nome do cliente.
- Cliente: avalia só o próprio atendimento concluído (Policy do atendimento; alheio = 404); preferências e
  avisos só os próprios (a consulta parte do cliente logado).
- Links dos e-mails (confirmar presença, descadastro, um clique) são públicos por natureza: a garantia é a
  **assinatura da URL** (`signed`), testada em `RouteAuthorizationTest::test_links_dos_emails_exigem_assinatura`.
- Limites: `throttle:email-links` (links públicos), `throttle:email-actions` (teste de campanha e reenvio),
  `throttle:account-actions` (avaliação e preferências), `throttle:money` (moderação, campanha, configuração).

## Fase 11 — site público

- `site.manage` (proprietário e gerente): textos, contatos, páginas legais e imagens do site. Serviços,
  preços, fotos de serviço e equipe continuam nas habilidades do catálogo e da equipe (Fase 4): o site não tem
  cadastro paralelo.
- Páginas públicas são só leitura e não exigem conta; tudo que não está publicado responde 404.
- O canal do cliente só agenda serviço e profissional publicados (regra na `Availability`); a equipe agenda
  qualquer um ativo.

## Fase 12 — área do cliente

- O cliente não tem papel nem habilidades além de `account.access`: tudo o que ele faz parte do próprio
  cadastro, e cada registro na URL passa pela Policy (`AppointmentPolicy`, `AttendancePolicy`,
  `CustomerPolicy`): alheio = 404. Ações sensíveis (exportar dados, excluir a conta, trocar e-mail) pedem a
  senha de novo (`customer.reauth`). Detalhes: [area-do-cliente.md](area-do-cliente.md).
- `customers.anonymize` (só o proprietário): o serviço de anonimização (`CustomerErasure`) aceita um usuário
  da equipe com essa habilidade como autor. Na Fase 12 ainda não havia tela de clientes no painel (P12-03);
  ela chegou na Fase 13 (abaixo).
- Pelo canal do cliente, a remarcação só oferece profissional publicado (P11-03); a equipe remarca com
  qualquer profissional ativo (`appointments.manage`).

## Fase 12.5 — área do profissional

- `professional_area.access` (só o Profissional): a área `/profissional` exige também a **ficha ligada** ao
  usuário (sem ficha, 403). Nenhuma rota da área recebe id de profissional: tudo parte do usuário logado.
  Registro de outro profissional na URL = 404 (Policies de sempre); tela administrativa = 403.
- Quem tem a área não navega pelo painel administrativo: as páginas do painel com equivalente (início,
  agenda, agendamento, atendimentos, atendimento, extrato, ficha, minha conta) levam para a área
  (`RedirectProfessionalToArea`, só GET, só registro próprio); as demais que ele já podia abrir (novo
  agendamento, remarcar, encaixe, senha, recibo, avaliações) abrem na moldura do profissional.
- As gravações continuam nas rotas do painel, com as mesmas habilidades `_own` e Policies (abrir, iniciar,
  itens, observações, concluir com `payments.receive`, cancelar o próprio). Desconto, estorno, repasse, vale,
  ajuste e regra de comissão continuam negados ao profissional.
- `customers.notes_own` (só o Profissional): ler e registrar anotações (visibilidade "profissionais") só de
  clientes que têm agendamento com ele (`CustomerPolicy@notes`; outro = 404). Remove só a própria. A
  auditoria guarda quem, quando e o tamanho, nunca o texto. Testes: `ProfessionalAreaTest`.
- Login e senha do profissional no próprio cadastro (pedido do dono): os campos "Acesso ao painel" do
  cadastro do profissional exigem `users.manage` (só o proprietário) e a senha reconfirmada, como a tela
  Usuários. Pedido montado à mão por quem não tem `users.manage` = 403, sem gravar nada. O gerente continua
  cadastrando a ficha (`professionals.create`) sem login. Nova senha provisória só para conta de outro
  (`UserPolicy@setTemporaryPassword`). Testes: `ProfessionalAccessTest`.

## Fase 13 — tela Clientes do painel (P13-01)

- Nenhuma habilidade nova nem papel alterado: a tela usa `customers.view` (rotas), `customers.update`,
  `customers.view_cpf` e `customers.anonymize` pela `CustomerPolicy`. Proprietário, gerente e recepção veem
  e editam; financeiro e profissional recebem 403 (o profissional vê os próprios clientes na área dele).
- CPF completo e correção de CPF: só com `customers.view_cpf` (proprietário e gerente). Pedido com `cpf` de
  quem não tem = 403, sem gravar nada (permissão por campo, como no catálogo). A busca por CPF também só
  funciona para quem vê o CPF.
- Anonimizar: só o proprietário, com senha reconfirmada e a palavra ANONIMIZAR. A `CustomerPolicy` passou a
  negar edição e anonimização de cadastro já anonimizado ou mesclado.
- A equipe não altera e-mail, senha, consentimentos, situação nem mesclagem do cliente.
- Detalhes: [clientes.md](clientes.md). Testes: `PanelCustomersTest`, `tests/e2e/clientes.spec.js`.
