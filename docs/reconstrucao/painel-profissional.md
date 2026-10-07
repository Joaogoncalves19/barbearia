# Painel do profissional (Fase 12.5)

> Fase intermediária entre a 12 e a 13 (a numeração do roadmap não muda). Branch
> `claude/fase-12-5-painel-profissional`. O sistema é vendido como **instalação independente por
> barbearia**: nada de multi-tenant nem SaaS.

O profissional (papel `professional`) ganha uma área própria, **`/profissional`**, enxuta e pensada
primeiro para o celular: **Hoje, Agenda, Atendimentos, Ganhos e Perfil**. Ele não vê o menu do painel
administrativo. Toda regra continua onde já estava: agenda (`Availability`/`BookingService`), atendimento
(`AttendanceService`, conclusão atômica da Fase 6), comissão e extrato (`ProfessionalLedger`,
`CommissionRules`), permissões (`config/permissions.php` + Policies). A área só **lê** com essas peças e
**grava pelas mesmas rotas e controladores** do painel (mesma validação, mesma Policy, mesmo serviço).

## 1. Auditoria do painel antigo

Telas e arquivos auditados no sistema antigo (só leitura; nenhum código foi reaproveitado):

| Arquivo | O que é |
|---|---|
| `barbeiro.php` (971 linhas) | Painel inteiro em abas: Dashboard, Minha agenda, Histórico, Meus ganhos, Meu perfil |
| `barbeiro_actions.php` (526) | Ações: dados da comanda (AJAX), concluir, cancelar, reagendar, agendamento manual, anotação do cliente, fechar comanda, vender produto, pausa de almoço, perfil |
| `barbeiro_modals.php` (326) | Modais: pausa de almoço, ficha do cliente, vender produto, agendamento manual, reagendar, comanda, assistente IA |
| `js/painel_barbeiro.js`, `partials/painel_barbeiro_script1/2.php` | Abas, modais, comanda, horários livres (`get_horarios.php`), IA (`ajax_gemini.php`) |
| `check_new_appointments.php` + `js/notif_agendamentos.js` | Aviso de agendamento novo (consulta a cada 15 s, com som) |
| `imprimir_agenda_dia.php` | Impressão da agenda do dia (o barbeiro imprime só a dele) |
| `imprimir_recibo_comissao.php` | Recibo de comissão paga |

Achados de segurança e qualidade no antigo (registrados, não copiados):

- `partials/painel_barbeiro_script1.php` despeja no JavaScript da página **todos os clientes e todos os
  agendamentos** da barbearia (nome, telefone, e-mail, anotações), não só os do barbeiro.
- A anotação do cliente (`salvar_nota_cliente`) grava em qualquer cliente pelo id, sem conferir vínculo (S-14).
- Reagendar não confere o expediente; o cálculo de ocupação era refeito à parte em cada ação (30 min fixos).
- O valor "a cobrar" era recalculado na tela, com a regra de assinatura repetida em três lugares.
- Avisos com SweetAlert não carregado (somem); modo escuro próprio em `localStorage`.

## 2. Funcionalidade antiga × sistema novo

| Funcionalidade antiga | Existe no novo? | Onde está | Deve entrar no painel? | Decisão |
|---|---|---|---|---|
| Login próprio do barbeiro (`login.php`) | Sim | `/painel/entrar` (usuário ou e-mail), papel Profissional | Sim (entrada) | **Absorvida.** Mesmo login da equipe; quem é profissional cai direto em `/profissional` |
| Saudação e resumo do dia (n.º de atendimentos, restantes) | Sim (parcial) | Início do painel (`DashboardController`) | Sim | **Reconstruída** em *Hoje*: na cadeira, próximo cliente, resto do dia |
| Próximo cliente com "Finalizar corte" | Sim | Agendamento → "Cliente chegou: abrir atendimento"; atendimento → concluir | Sim | **Reconstruída** em *Hoje*, com o fluxo atual (abrir → iniciar → concluir) |
| Linha do tempo do dia | Sim | Agenda do painel | Sim | **Reconstruída** em *Hoje* (resto do dia) e *Agenda* |
| Ganho líquido de hoje (comissão) | Sim | Lançamentos de comissão e gorjeta (Fase 7) | Sim | **Reconstruída**: *Hoje* soma os lançamentos do dia; nada recalculado |
| Faturamento bruto do dia/semana/mês | Sim | Total congelado de cada atendimento concluído | Sim | **Reconstruída** em *Ganhos* (soma dos totais já gravados dos atendimentos dele) |
| Ticket médio do dia | Não | — | Não | **Descartada**: indicador de relatório; relatórios gerais estão fora do escopo |
| Avaliação média e quantidade | Sim | Avaliações publicadas (`reviews.view_own`) | Sim | **Reconstruída** em *Perfil* (só publicadas, dos atendimentos dele) |
| Taxa de comparecimento | Não | — | Não | **Descartada**: indicador de relatório; as faltas aparecem na agenda |
| Meta diária editável pelo barbeiro + barra de progresso | Não | — | Pendente | **PRECISA DE DECISÃO (P12.5-01)**: exige campo novo (não há onde guardar sem migração) |
| Gráfico de comissão dos últimos 7 dias | Sim (dados) | Lançamentos de comissão e gorjeta | Sim | **Reconstruída** em *Ganhos* (soma por dia dos lançamentos, em lista acessível) |
| Agenda de hoje em grade de 30 min, livre/ocupado/passou | Sim | `Availability` (regra única) | Sim | **Reconstruída** em *Agenda*: tempo livre vem de `Availability::freeWindows`, a mesma fotografia do dia usada para reservar |
| Encaixar cliente num horário livre | Sim | `/painel/agenda/novo` (`BookingService`, `createFor`) e encaixe do atendimento | Sim | **Reaproveitada**: o livre da agenda leva ao formulário atual, dentro da área do profissional |
| Três vistas da agenda (linha do tempo, lista, cartões) | — | — | Não | **Descartada**: uma vista só, a linha do tempo em lista (boa no celular) |
| Próximos dias (tabela) | Sim | Agenda por dia | Sim | **Reconstruída**: navegação por dia e faixa da semana com a contagem de cada dia |
| Histórico completo com busca e paginação | Sim (parcial) | Lista de atendimentos por dia | Sim | **Reconstruída** em *Atendimentos*: por dia e busca pelo nome do cliente (só os dele) |
| Concluir agendamento direto (sem comanda) | Não | — | Não | **Descartada**: no novo, concluir é sempre pelo atendimento (pagamento fecha exatamente com o total, Fase 6) |
| Cancelar agendamento | Sim | `AppointmentPolicy@cancel` (só a própria agenda) | Sim | **Reaproveitada** (rota e regra atuais) |
| Reagendar | Sim | `/painel/agendamentos/{código}/remarcar` (`BookingService`) | Sim | **Reaproveitada**; o novo confere expediente, pausa, folga e bloqueio (o antigo não) |
| Agendamento manual pelo barbeiro (só os serviços dele) | Sim | `/painel/agenda/novo` (`createFor`, serviços do profissional) | Sim | **Reaproveitada** dentro da área do profissional |
| Marcar falta | Sim | `appointments.no-show` | Sim | **Reaproveitada** |
| Comanda: serviços extras, forma de pagamento, gorjeta, fechar | Sim | `AttendanceService` (Fase 6) | Sim | **Reconstruída** a tela (*Atendimento*), com a conclusão atômica atual (pagamentos, gorjeta, caixa, estoque, comissão) |
| Vender produto (baixa imediata do estoque) | Sim | Atendimento → produto (baixa na conclusão) | Sim | **Reaproveitada** (regra nova: estoque sai só ao concluir) |
| Valor a cobrar com desconto de assinatura recalculado na tela | Sim | Motor único de preço e promoção | Sim | **Reaproveitada**: o total vem de `AttendancePricing` |
| Ficha do cliente (histórico, e-mail, CPF, nascimento, IA "raio-x") | Parcial | `CustomerPolicy` (`customers.view_own`) | Sim (enxuta) | **Reconstruída enxuta**: nome, telefone, atendimentos com ele, anotações. Sem e-mail, CPF, nascimento nem IA |
| Anotações do barbeiro sobre o cliente | Sim (dados) | `customer_notes`, visibilidade "profissionais" (importada de `notas_barbeiro`) | Sim | **Reconstruída**: lê e registra anotações, só de clientes dele (habilidade nova `customers.notes_own`) |
| Pausa de almoço configurada pelo barbeiro | Sim (outra regra) | Pausas do expediente (`schedule.working_hours`, gerência) | Consulta | **Mostrada** no Perfil e na Agenda; o barbeiro configurar sozinho é **PRECISA DE DECISÃO (P12.5-02)** |
| Perfil: nome e senha | Sim | Minha conta (nome) e Senha | Sim | **Reaproveitada** (mesmos formulários e regras de senha) |
| Perfil: foto | Sim | Ficha do profissional (`professionals.display`, gerência) | Consulta | **Mostrada**; o barbeiro trocar a própria foto e apresentação é **PRECISA DE DECISÃO (P12.5-03)** |
| Sua taxa de comissão (somente leitura) | Sim | `CommissionRules::resolve` | Sim | **Reconstruída** em *Ganhos* (regra em vigor, só leitura) |
| Extrato: comissões, gorjetas, vales, repasses, saldo | Sim | Extrato da Fase 7 (`ProfessionalLedger`) | Sim | **Reconstruída** em *Ganhos*, só os dados dele |
| Recibo de comissão (imprimir) | Sim | Recibo do repasse (`receipts.payout`, Policy) | Sim | **Reaproveitada** (link em *Ganhos*) |
| Imprimir agenda do dia | Não (tela) | — | Sim | **Reconstruída**: botão "Imprimir" na *Agenda*, com estilo de impressão |
| Aviso de agendamento novo com som (consulta a cada 15 s) | Não | — | Pendente | **PRECISA DE DECISÃO (P12.5-04)**: exige um canal de avisos para a equipe (não existe) |
| Botão "Chamar no Zap" (link `wa.me`) | Não | — | Pendente | **PRECISA DE DECISÃO (P12.5-05)**: WhatsApp está fora desta fase; o telefone vira link de ligação (`tel:`) |
| Assistente IA de mensagem (Gemini) | Não | — | Não | **Descartada nesta fase** (IA é Fase 14) |
| Modo escuro próprio | Não | Tema da instalação (8 temas) | Não | **Substituída**: o profissional recebe o tema escolhido pelo dono |
| Botão "Atualizar" | — | — | Não | **Descartada** (recarregar a página) |
| Avisos com SweetAlert (quebrados) | Sim | Mensagem de status do layout | Sim | **Substituída** |
| Desativado perde o acesso no próximo clique | Sim | Middleware `staff.active` | Sim | **Absorvida** |
| Todos os clientes e agendamentos no JavaScript da página | — | — | Não | **Descartada** (vazamento): só o necessário, renderizado no servidor e conferido pela Policy |

## 3. Navegação e telas

| Tela | Endereço | O que responde |
|---|---|---|
| **Hoje** | `/profissional` | Quem está comigo agora (tempo decorrido × previsto, iniciar/continuar/finalizar), quem é o próximo (encaixe, atraso, situação), o resto do dia com o tempo livre, quanto já produzi hoje |
| **Agenda** | `/profissional/agenda?data=` | O dia escolhido em linha do tempo: agendamentos com a duração real, encaixes, faltas, cancelados, em andamento, concluídos, pausas, bloqueios, folga e tempo livre; semana com contagem por dia; imprimir |
| **Agendamento** | `/profissional/agendamentos/{código}` | Cliente (nome, telefone), serviços, horário, observações, anotações do cliente, atendimentos anteriores com ele; abrir atendimento, confirmar, falta, remarcar, cancelar |
| **Atendimentos** | `/profissional/atendimentos?data=&busca=` | Os atendimentos dele por dia, ou a busca pelo nome do cliente; abrir encaixe |
| **Atendimento** | `/profissional/atendimentos/{código}` | Cliente, tempo, itens, material, observações, total; iniciar, incluir serviço e produto, finalizar com pagamento e gorjeta |
| **Ganhos** | `/profissional/ganhos?mes=` | A receber agora (comissão + gorjeta − vales), hoje/semana/mês, últimos 7 dias, extrato do mês, repasses com recibo, regra de comissão em vigor |
| **Perfil** | `/profissional/perfil` | Conta (nome, usuário, e-mail, senha), ficha (foto, apresentação, serviços), expediente e pausas, folgas, avaliações |

Formulários que já existiam (novo agendamento, remarcar, encaixe, senha, recibo, avaliações) abrem
**dentro da moldura do profissional**: o layout do painel troca o menu administrativo pela navegação do
profissional para quem tem a área. Endereços do painel que têm equivalente na área do profissional
(início, agenda, agendamento, atendimentos, atendimento, extrato, ficha, minha conta) levam para lá.

## 4. Permissões

Duas habilidades novas, específicas, negadas por padrão e só do papel Profissional:

| Habilidade | Significado | Papéis |
|---|---|---|
| `professional_area.access` | Usar a área do profissional (Hoje, agenda, atendimentos, ganhos e perfil próprios) | Profissional |
| `customers.notes_own` | Ler e registrar anotações sobre os próprios clientes (preferências, cuidados) | Profissional |

A área exige também uma ficha de profissional ligada ao usuário; sem ficha, 403. Tudo o mais continua nas
habilidades e Policies existentes (`appointments.*_own`, `attendances.*_own`, `payments.receive`,
`commissions.view_own`, `customers.view_own`, `reviews.view_own`). Registro de outro profissional = 404
(não confirma que existe); tela administrativa = 403.

### Cadastro do profissional com usuário e senha

Pedido do dono durante a fase, como no sistema antigo: o **login e a senha do barbeiro são criados no
próprio cadastro do profissional** (Equipe e catálogo → Profissionais → Novo ou Editar → "Acesso ao
painel"):

- Campos: usuário (para entrar), senha provisória e e-mail (opcional). Em branco, a ficha é criada sem acesso.
- Só o proprietário vê e usa esses campos (`users.manage`, o mesmo de Usuários). Antes de abrir o
  formulário, ele reconfirma a senha, como na tela Usuários. O gerente continua cadastrando a ficha sem login.
- As regras são as da tela Usuários (`StaffAccounts::createForProfessional`):
  - usuário único e só com minúsculas;
  - senha provisória pela política de senha, trocada obrigatoriamente no primeiro acesso;
  - papel Profissional;
  - auditoria `user.created`, sem a senha.
- Ficha e login são gravados numa transação só: se o login falhar, a ficha não é criada.
- A senha provisória nunca volta para a sessão quando o formulário é recusado.
- Profissional que já tem acesso: o cadastro mostra o usuário e a situação e permite dar uma **nova senha
  provisória**. Desativar o acesso ou trocar o e-mail continua em Usuários.
- Ligar uma conta que já existe continua possível (opção recolhida "Ele já tem uma conta?").
- A tela Usuários continua funcionando como antes.

## 5. Decisões pendentes

| Código | Assunto | Recomendação |
|---|---|---|
| P12.5-01 | Meta diária do profissional (o antigo tinha, editável pelo barbeiro) | Não recriar agora; se quiser, a meta é definida pela gerência numa fase própria (campo novo) |
| P12.5-02 | Profissional configurar a própria pausa (antigo: almoço de 1 h) | Manter com a gerência: pausa muda o que o cliente vê no site |
| P12.5-03 | Profissional trocar a própria foto e apresentação do site | Permitir com aprovação da gerência, ou manter com a gerência (P11-02) |
| P12.5-04 | Aviso de agendamento novo para o profissional (antigo: consulta a cada 15 s com som) | Avaliar junto com os avisos da equipe; não criar canal à parte |
| P12.5-05 | Botão de WhatsApp (`wa.me`) no telefone do cliente | Aguardar a decisão de WhatsApp (Fase 14); hoje, link de ligação |
| P12.5-06 | Histórico do cliente: só os atendimentos com ele ou os de toda a equipe | Só com ele (menor exposição); ampliar se o dono quiser |
| P12.5-07 | Dono ou gerente que também atende usar a área do profissional | Hoje a área é do papel Profissional; outro papel continua no painel |
