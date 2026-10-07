# Relatório da Fase 12.5 — Painel do profissional

> Fase intermediária entre a 12 e a 13 (a numeração do roadmap não muda). Branch
> `claude/fase-12-5-painel-profissional`, criada a partir de `claude/refinamento-visual-temas`.
> **Status: concluída, aguardando aprovação explícita do dono.** A Fase 13 não foi iniciada.
>
> O sistema é vendido como **instalação independente por barbearia**: nada de multi-tenant, várias
> barbearias no mesmo banco ou SaaS.

Documento da fase: [painel-profissional.md](painel-profissional.md) (auditoria, tabela funcionalidade
antiga × nova, telas, permissões, decisões).

## 1. O que foi auditado no sistema antigo

Só leitura, nenhum código reaproveitado:

- `barbeiro.php`: o painel inteiro em abas (Dashboard, Minha agenda, Histórico, Meus ganhos, Meu perfil).
- `barbeiro_actions.php`: dados da comanda, concluir, cancelar, reagendar, agendamento manual, anotação do
  cliente, fechar comanda, vender produto, pausa de almoço, perfil.
- `barbeiro_modals.php`: os modais (almoço, ficha do cliente, produto, agendamento manual, reagendar,
  comanda, assistente IA).
- `js/painel_barbeiro.js` e `partials/painel_barbeiro_script1/2.php`: abas, comanda, horários livres, IA.
- `check_new_appointments.php`: aviso de agendamento novo com som.
- `imprimir_agenda_dia.php` e `imprimir_recibo_comissao.php`.

Achados no antigo (registrados, não copiados):

- A página do barbeiro carregava no JavaScript **todos os clientes e agendamentos da barbearia** (nome,
  telefone, e-mail, anotações), não só os dele.
- A anotação do cliente gravava em qualquer cliente pelo id, sem conferir vínculo (S-14).
- O reagendamento não conferia o expediente.
- O cálculo de ocupação e o valor a cobrar eram refeitos na tela, com a regra de assinatura repetida em três
  lugares.
- Os avisos usavam SweetAlert, que não era carregado, então não apareciam.

## 2. O que foi aproveitado (conceitualmente) e o que foi descartado

37 funcionalidades antigas foram avaliadas, uma por linha, na tabela de
[painel-profissional.md §2](painel-profissional.md#2-funcionalidade-antiga--sistema-novo).

**Reconstruídas na área nova, sobre as regras atuais:**
- Hoje (o próximo cliente, a linha do tempo do dia e quanto ganhou hoje).
- Agenda do dia com os horários livres, na mesma regra da agenda.
- Navegação por dia e semana, e impressão da agenda.
- Histórico por dia e busca pelo nome do cliente.
- Comanda (o atendimento).
- Ganhos (comissão, gorjeta, vales, repasses e saldo, mais os últimos 7 dias e a regra de comissão em vigor, só para consulta).
- Ficha do cliente enxuta e anotações do cliente.
- Avaliação média.

**Reaproveitadas** (os formulários e as regras que já existiam):
- agendamento manual, reagendar, cancelar e marcar falta;
- encaixe;
- incluir serviço e vender produto;
- finalizar com pagamento e gorjeta (a conclusão atômica da Fase 6);
- nome e senha;
- recibo do repasse.

**Descartadas, com o motivo:**

| Funcionalidade antiga | Motivo |
|---|---|
| Ticket médio e taxa de comparecimento | São indicadores de relatório, e relatórios gerais estão fora do escopo |
| Três modos de ver a agenda | Ficou um só |
| Concluir sem passar pela comanda | No sistema novo o pagamento precisa fechar com o total |
| E-mail, CPF e data de nascimento na ficha do cliente | Não são necessários para atender |
| Assistente de IA | A IA fica para a Fase 14 |
| Modo escuro próprio | O tema já é o da instalação |
| Botão "Atualizar" | Recarregar a página faz o mesmo |
| Despejar os dados de todos no JavaScript da página | Era um vazamento de dados |

**Viraram decisões pendentes** (§9): meta diária, pausa configurada pelo próprio barbeiro, foto e
apresentação editadas por ele, aviso de agendamento novo e botão de WhatsApp.

## 3. Telas criadas

Telas em `/profissional`. No celular os destinos ficam numa barra fixa embaixo; no desktop, num trilho à
esquerda.

| Tela | O que responde |
|---|---|
| **Hoje** | O cliente na cadeira agora: tempo decorrido, ao vivo, contra o previsto, com Iniciar, Continuar e Finalizar. O próximo cliente, com os sinais de encaixe, atraso e "a confirmar" e o botão "Cliente chegou". O resto do dia, com o tempo livre. Os números do dia e quanto já produziu |
| **Agenda** | Um dia em linha do tempo: agendamentos com a duração real, em andamento, concluídos, faltas, cancelados e encaixes, mais pausas, bloqueios, folga e tempo livre. Tem a semana com a contagem de cada dia, ir para outro dia e imprimir |
| **Agendamento** | Cliente (nome, telefone que liga com um toque), serviços, horário, observações, anotações do cliente e os atendimentos anteriores com ele. Ações: abrir o atendimento, confirmar, registrar falta, remarcar e cancelar |
| **Atendimentos** | Os atendimentos dele, por dia ou buscando pelo nome do cliente. Botão de encaixe |
| **Atendimento** | Feito para usar ao lado da cadeira: tempo, conta, itens, material, observações e anotações. Ações: iniciar, incluir serviço e produto, e finalizar e receber (o mesmo formulário da comanda). Concluído, mostra o total pago, a gorjeta e o comprovante |
| **Ganhos** | O que tem a receber (comissão + gorjeta − vales), o ganho de hoje, da semana e do mês, os últimos 7 dias, o extrato do mês, os repasses com recibo e a regra de comissão em vigor, só para consulta |
| **Perfil** | Conta (nome editável, usuário, e-mail e senha), ficha do site, serviços, expediente, pausas, próximas folgas e avaliações |

**Telas que já existiam:**
- Novo agendamento, remarcar, encaixe, senha, recibo e avaliações abrem dentro da moldura do profissional.
- Para quem tem a área, o layout do painel troca o menu administrativo pela navegação do profissional.
- Os endereços do painel que têm equivalente na área levam para lá: início, agenda, agendamento, atendimentos, atendimento, extrato, ficha e minha conta.

**Desenho:**
- Usa só o design system e os tokens do tema.
- O bloco "na cadeira" usa a superfície da barra do tema (invertida no Ofício), com o fio de destaque.
- Blocos separados por fio, não por cartão. Números na fonte de números do tema.
- O arquivo `areas/professional.css` não tem nenhuma cor fixa nem regra para um tema só; um teste confere isso.

### 3.1 Cadastro do barbeiro com usuário e senha (pedido do dono durante a fase)

Como no sistema antigo, o proprietário cria o **usuário e a senha provisória do barbeiro no próprio
cadastro do profissional**, no bloco "Acesso ao painel" do Novo ou Editar.

Ficou assim:
- Exige `users.manage` e a senha reconfirmada, como na tela Usuários. O gerente segue cadastrando só a ficha.
- Ficha e login são gravados juntos, numa transação: ou os dois, ou nenhum.
- A senha provisória é trocada no primeiro acesso.
- Na edição, o proprietário vê o usuário e pode dar uma nova senha provisória.
- O endereço `/profissional/entrar` leva para o login da equipe.

Detalhes em [painel-profissional.md](painel-profissional.md#cadastro-do-profissional-com-usuário-e-senha).

## 4. Arquitetura (sem lógica duplicada)

**O que só lê:**
- Os controladores da área (`app/Http/Controllers/Professional/`) só leem e montam a tela.
- O profissional vem sempre do usuário logado; nenhuma rota da área recebe id de profissional.

**O que grava:** as gravações usam as **rotas e controladores do painel**, com a mesma validação, a mesma
Policy e o mesmo serviço:
- `AttendanceService`, inclusive a conclusão atômica e idempotente da Fase 6;
- `BookingService`;
- os controladores de agendamento.

Os redirecionamentos `back()` devolvem para a tela da área.

**Peças novas, todas nos módulos donos da regra:**
- `Availability::dayOverview`: o tempo livre do dia sai do **mesmo retrato** (`planFor`) que decide a reserva. Só informa; reservar continua passando por `check()` e `slots()`.
- `ProfessionalLedger::month` e `earnedBetween`: a leitura do extrato da Fase 7. O extrato do painel passou a usar o mesmo método, então não há duas consultas.
- `PaymentMethod::counterOptions`: as formas de pagamento do balcão, numa lista só para a comanda e para o atendimento do profissional.
- O partial `panel/checkout/partials/complete-dialog`: o mesmo formulário de "Concluir e receber" nas duas telas.
- `CustomerNotes` e `CustomerPolicy@notes`: as anotações do cliente, sobre a tabela `customer_notes`, que já existia. O importador já grava ali as notas do barbeiro do sistema antigo.

**O que não mudou:**
- O banco: nenhuma migração nova; `customer_notes` já existia.
- As regras de agenda, preço, desconto, atendimento e comissão.

## 5. Permissões

Duas habilidades novas, específicas, **negadas por padrão** e só do papel Profissional. As duas têm teste e
estão documentadas em [papeis-permissoes.md](papeis-permissoes.md).

| Habilidade | Para quê |
|---|---|
| `professional_area.access` | Usar a área `/profissional`. Exige também a ficha de profissional ligada ao usuário; sem ela, 403 |
| `customers.notes_own` | Ler e registrar anotações só de clientes que têm agendamento com ele. Remover, só as próprias |

Tudo o mais continua nas habilidades `_own` e Policies existentes:
- Registro de outro profissional pela URL: 404.
- Tela administrativa: 403.
- Desconto, estorno, repasse, vale, ajuste e regra de comissão continuam negados ao profissional.

A auditoria registra a anotação (quem, quando e o tamanho), nunca o texto.

## 6. Testes

**PHP:**
- 818 testes passando: 800 da base e 18 novos (13 em `tests/Feature/Team/ProfessionalAreaTest.php` e 5 em `tests/Feature/Team/ProfessionalAccessTest.php`).
- PHPStan sem erros, Pint ok e build ok.

`ProfessionalAreaTest` cobre:
- o login cai em Hoje, sem o menu administrativo;
- as telas mostram só o próprio dia, e o dia sem atendimento mostra o estado vazio e o próximo;
- só o profissional com ficha entra: dono, gerente, recepção, financeiro e profissional sem ficha recebem 403, e visitante e cliente vão para o login;
- o painel administrativo é negado, inclusive pedidos montados à mão (desconto, ajuste, repasse, vale e regra de comissão);
- isolamento ao trocar ids e códigos na URL: agendamento, atendimento, extrato, repasse, recibo, comprovante, filtros e busca;
- gravar no atendimento do outro profissional é negado e nada muda;
- as páginas do painel levam para a área, as mensagens sobrevivem e os outros papéis continuam no painel;
- o fluxo completo do atendimento:
  - abrir, iniciar, incluir serviço e produto, e observação;
  - um valor adulterado é recusado;
  - o duplo clique na conclusão não duplica nada;
  - a comissão é lançada;
- anotações: visibilidade, auditoria sem o texto, limite de tamanho, cliente alheio, e só quem escreveu remove;
- o tempo livre bate com a regra da agenda (todo horário oferecido cabe num tempo livre, e todo tempo livre oferece horário), conta a partir de agora no dia de hoje e é vazio em dia de folga;
- os 8 temas;
- o CSS só usa tokens;
- abrir as telas não altera nenhuma tabela.

`ProfessionalAccessTest` cobre o cadastro com login (§3.1):
- o proprietário cria a ficha e o login juntos, a senha não vai para a auditoria e o barbeiro entra e é obrigado a trocar a senha;
- sem reconfirmar a senha, o formulário pede a senha e o envio é recusado (403);
- o gerente cadastra a ficha, mas não cria login (403);
- a validação recusa usuário repetido, falta de senha, senha fraca e "criar e ligar ao mesmo tempo", sem gravar nada pela metade e sem a senha voltar para a sessão;
- na edição: cria o acesso para quem não tinha e dá nova senha provisória para quem tem; o gerente não troca senha.

`RouteAuthorizationTest`: o endereço `/profissional/entrar`, que só redireciona para o login da equipe, entrou na lista de rotas públicas.

**Testes existentes ajustados:** 5 testes PHP e 3 E2E que abriam páginas do painel como profissional. Nenhum
foi removido nem desativado, e as verificações de isolamento continuam as mesmas. Agora eles seguem o
redirecionamento para a página equivalente da área:
- `CheckoutPanelTest`, `FinancePanelTest`, `StaffAgendaTest`, `CatalogAuthorizationTest` e `HorizontalAccessTest`;
- E2E: `agenda.spec`, `comissao.spec` e `identidade.spec`.

**Navegador:** o `profissional.spec.js` novo roda no celular e no desktop, com o tablet na verificação de
responsividade. Cobre:
- login;
- Hoje, sem o menu administrativo, e foco de teclado;
- agenda só dele e anotação do cliente (registrar e remover);
- horário e atendimento do profissional B pela URL dão 404;
- do cliente que chegou à finalização, com pagamento e gorjeta;
- Hoje com o atendimento em andamento;
- ganhos e perfil sem gestão;
- extrato de outro pelo id;
- painel administrativo dá 403;
- tablet sem rolagem lateral;
- axe em cada tela e nenhum erro de console ou CSP;
- o proprietário cadastra o barbeiro com usuário e senha provisória (reconfirmando a senha), e o barbeiro entra, troca a senha e cai na área dele.

O `temas.spec.js` passou a incluir as 5 telas da área em cada um dos 8 temas, no desktop e no celular.

| Verificação | Resultado |
|---|---|
| PHP (PHPUnit) | 818 passaram |
| PHPStan | 0 erros |
| Pint | ok |
| Build | ok |
| Navegador, 1ª rodada final (banco novo) | 148 passaram, 4 pulados, 0 falhas (23,2 min) |
| Navegador, 2ª rodada final (banco novo) | 148 passaram, 4 pulados, 0 falhas (18,4 min) |
| CI | ver §6.1 |

### 6.1 Histórico das rodadas

**Rodadas anteriores:**
- A primeira rodada completa teve 6 falhas, todas dos testes e não do sistema:
  - nos 3 testes E2E adaptados, a busca pelo rótulo "Profissional" achava também a navegação "Área do profissional";
  - os laços que trocam ids na URL passaram do tempo limite de 30 s.
  - Correção: rótulo exato, `test.slow()` e um laço menor.
- Depois do pedido do cadastro com login (§3.1), o teste novo do cadastro falhou: ele entrava com a senha das contas de teste, não com a senha provisória recém-criada. Corrigi o teste. O sistema já estava certo; o `ProfessionalAccessTest` em PHP confirma.

**Pulados (4):** os mesmos 3 de antes desta fase, mais a verificação de tablet no projeto celular, que roda só uma vez, no desktop.

**Projeto `temas`:** os 8 temas passaram nas duas rodadas finais, agora incluindo as 5 telas da área do profissional no desktop e no celular.

## 7. Problemas encontrados e correções

| Problema | Correção |
|---|---|
| Carregamento preguiçoso (lazy loading) bloqueado em ambiente local na tela Hoje, com mais de um atendimento aberto: a Policy lia o profissional de cada atendimento | Profissional carregado junto (`with('professional')`) em Hoje, Agendamento e Atendimento |
| Rolagem lateral no celular no Agendamento: telefone longo dentro da grade | Colunas `minmax(0, 1fr)` e quebra de texto nos valores |
| Tabela de períodos larga demais no celular, em Ganhos | Virou lista (período, comissão · gorjeta, total) |
| No celular, os números do dia vinham antes de quem está na cadeira | Na cadeira e o próximo primeiro; os números depois (no desktop, no topo) |
| Excesso de controles acima da agenda no celular | A semana escolhe o dia; os botões de dia e o imprimir aparecem só a partir do tablet; o "outro dia" ficou recolhido |
| A Blade não compila uma diretiva colada numa palavra (`Estornado@elseif`) | Expressões no lugar das diretivas em linha |
| A linha do tempo da agenda quebrava num dia sem agendamento (coleção Eloquent vazia misturada com listas) | `toBase()` antes de juntar |
| O formulário "Novo agendamento" perdia o horário escolhido no passo 1 e não pré-selecionava o único profissional | O passo 1 leva a hora; com um profissional só, ele já vem selecionado |

## 8. Capturas

Em [img/painel-profissional/](img/painel-profissional/): Hoje, Agenda, Agendamento, Atendimento, Ganhos e
Perfil (mais Hoje com atendimento em andamento e atendimento concluído), no celular e no desktop, geradas
pelo teste de navegador com dados fictícios. São capturas da página inteira: no celular, a barra de
navegação fixa aparece no meio da imagem, na altura da tela, e não no fim.

## 9. Decisões pendentes (PRECISA DE DECISÃO)

| Código | Assunto | Recomendação |
|---|---|---|
| P12.5-01 | Meta diária do profissional (o antigo tinha, editável pelo barbeiro) | Não recriar agora; se o dono quiser, a meta é definida pela gerência numa fase própria (exige um campo novo) |
| P12.5-02 | O profissional configurar a própria pausa (no antigo, almoço de 1 h) | Manter com a gerência: a pausa muda o que o cliente vê no site |
| P12.5-03 | O profissional trocar a própria foto e apresentação do site | Permitir com aprovação da gerência, ou manter com a gerência (P11-02) |
| P12.5-04 | Aviso de agendamento novo para o profissional (no antigo, consulta a cada 15 s, com som) | Avaliar junto com os avisos da equipe, sem canal à parte |
| P12.5-05 | Botão de WhatsApp (`wa.me`) no telefone do cliente | Aguardar a decisão sobre WhatsApp (Fase 14); hoje, link de ligação |
| P12.5-06 | Histórico do cliente: só os atendimentos com ele ou os de toda a equipe | Só com ele (menor exposição) |
| P12.5-07 | Dono ou gerente que também atende usar a área do profissional | Hoje a área é do papel Profissional; os outros papéis continuam no painel |

## 10. Limitações e pendências fora do escopo

- O telefone do cliente aparece sem máscara (já registrado no refinamento visual). Formatar é de todas as telas, não só desta área.
- O tempo decorrido se atualiza sozinho a cada 30 s; o resto da tela atualiza ao recarregar. Não há aviso em tempo real (P12.5-04).
- O perfil não edita foto, apresentação, serviços nem expediente: isso é da gerência (P12.5-02, P12.5-03).
- Imprimir a agenda usa a impressão do navegador, com o estilo de impressão da tela. Não há PDF.
- Continuam abertas: Fase 12 (P12-01 a P12-08), refinamento visual (T-01 a T-06) e as pendências de homologação (Resend real, ciclo Stripe em modo teste, webhook real, teste com usuários, Lighthouse).
