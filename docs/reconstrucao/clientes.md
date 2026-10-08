# Clientes no painel (Fase 13, P13-01)

Gestão dos clientes pela equipe, em **Clientes e vendas → Clientes** (`/painel/clientes`). A tela não
cria regra nova. Ela usa:
- a matriz de permissões ([papeis-permissoes.md](papeis-permissoes.md)) e a `CustomerPolicy`;
- a busca do balcão (`CustomerLookup`);
- o cadastro do site (`CustomerRegistration`);
- o extrato de pontos e o motor de promoções;
- a anonimização da conta do cliente (`CustomerErasure`, [retencao-lgpd.md](retencao-lgpd.md)).

## Telas

| Tela | Rota | O que mostra ou faz |
|---|---|---|
| Lista | `GET /painel/clientes` | Busca por nome, e-mail ou celular (e CPF completo, só para quem vê o CPF); filtro de situação (ativos, inativos, anonimizados, todos); celular, e-mail, último atendimento e situação. Paginada (25). Cadastros mesclados em outro ficam fora. Botão **Novo cliente** para quem pode cadastrar |
| Novo cliente | `GET /painel/clientes/novo`, `POST /painel/clientes` | Nome, CPF (obrigatório), celular, nascimento e e-mail (opcional). Sem senha e sem consentimento |
| Ficha | `GET /painel/clientes/{id público}` | Contato e cadastro; situação da conta, com **Desativar/Reativar**; próximos horários, favoritos e anotações; assinatura, pontos, benefícios de hoje e código de indicação; histórico paginado de agendamentos e atendimentos |
| Edição | `GET /painel/clientes/{id}/editar`, `PUT /painel/clientes/{id}` | Nome, celular e nascimento; CPF só para quem vê o CPF completo |
| Situação | `POST /painel/clientes/{id}/situacao` | Ativa ou desativa o cadastro. Para desativar, há confirmação |
| Anonimizar | `GET /painel/clientes/{id}/anonimizar` (senha reconfirmada), `POST` (mesma janela de 15 min + palavra ANONIMIZAR) | O que acontece, bloqueios e confirmação |

## Permissões

Sem mudança na matriz. As rotas exigem `customers.view`, e cada registro passa pela `CustomerPolicy`.

| Papel | Lista e ficha | Cadastrar (`customers.create`) | Editar e ativar/desativar (`customers.update`) | CPF completo e correção (`customers.view_cpf`) | Anonimizar (`customers.anonymize`) |
|---|:-:|:-:|:-:|:-:|:-:|
| Proprietário | ✅ | ✅ | ✅ | ✅ | ✅ |
| Gerente | ✅ | ✅ | ✅ | ✅ | — |
| Recepção | ✅ | ✅ | ✅ | — (vê `123.***.***-09`) | — |
| Financeiro | 403 | 403 | — | — | — |
| Profissional | 403 (vê os próprios clientes na área dele, Fase 12.5) | 403 | — | — | — |

Outros detalhes:
- Os pontos aparecem só com `loyalty.view`.
- O link da assinatura aparece só com `subscriptions.view`.
- Os links de agendamento e atendimento aparecem só para quem pode abrir o registro (Policies).
- O menu mostra "Clientes" só para quem tem `customers.view`.

## Cadastro pelo balcão

A equipe **inicia** o cadastro; o cliente conclui o acesso.
- **Validações:** as mesmas do cadastro pelo site e da conta do cliente.
  - Nome com 2 a 120 caracteres.
  - CPF **obrigatório**, com dígitos verificadores válidos, guardado só com os dígitos (como em todo
    cadastro).
  - Celular com DDD, guardado no formato E.164.
  - Nascimento no passado.
  - E-mail válido, guardado em minúsculas.
- **Unicidade:** CPF, celular e e-mail não podem pertencer a outro cliente, nem a cadastro excluído. A
  mensagem diz só qual dado conflita, nunca de quem é. Na recusa, nada é criado e nada é mesclado.
- **Senha:** a equipe não define. O cadastro nasce sem senha.
- **E-mail:**
  - a equipe não confirma. A conta nasce com o e-mail **não confirmado**;
  - sai o **mesmo link de confirmação** do cadastro pelo site;
  - o cliente cria a senha pelo link de acesso ou por "esqueci a senha". Esses dois caminhos já existiam,
    e cada um confirma o e-mail ao provar que o endereço é dele.
- **Sem e-mail:** o cliente é atendido só pelo balcão. Depois, o e-mail não é incluído pela equipe
  (decisão do dono, abaixo).
- **Consentimento de novidades:** fica "desconhecido". Só o cliente aceita, pela conta.
- **Auditoria:** dois registros com o autor da equipe:
  - `customer.registered`, com a descrição "Cadastro pelo painel (balcão)";
  - `created` do model, com o CPF mascarado.
- O cliente aparece **na hora** na lista e na busca do balcão (agenda e encaixe).
- **Campos ignorados:** senha, confirmação de e-mail, consentimento ou situação enviados no formulário.

Serviço: `CustomerRegistration::registerAtCounter`.

## Ativar e desativar

Decisão do dono na Fase 13. Serviço: `CustomerActivation`.
- **Desativar não apaga nada.** Histórico, pontos e horários já marcados continuam.
- O efeito é o que o sistema já tinha para cliente inativo:
  - não entra na conta; as sessões abertas caem na próxima requisição (`customer.active`);
  - sai da busca do balcão;
  - aparece no filtro "Inativos".
- **Reativar** devolve o acesso e a busca.
- **Cadastro anonimizado ou mesclado** não muda de situação.
- **Auditoria:** registro `updated` do model, com quem fez e o antes e depois da situação.

## Regras gerais

- **Busca por CPF:** só para quem vê o CPF completo. Para os outros, a busca por CPF não acha nada, para
  não revelar o número.
- **Edição:**
  - mesmas validações do cadastro;
  - o CPF não pode ficar em branco quando já existe. Cliente importado sem CPF pode ficar em branco; ele
    informa no próximo acesso.
- **Permissão por campo:** pedido de **edição** com `cpf` vindo de quem não tem `customers.view_cpf` é
  recusado inteiro (403), sem gravar nada. No **cadastro**, a recepção informa o CPF, que depois aparece
  mascarado para ela.
- **A equipe não altera** (decisão do dono):
  - e-mail e senha, que são o acesso do cliente. A troca de e-mail é feita por ele, com confirmação no
    endereço novo;
  - consentimentos de novidades e lembretes, cuja prova é do cliente;
  - mesclagem.

  Campos com esses nomes enviados no formulário são ignorados.
- **Cadastro anonimizado ou mesclado:** não pode ser editado, reativado nem anonimizado de novo
  (`CustomerPolicy`). A ficha continua consultável, com o histórico sem nome.
- **Auditoria:**
  - toda alteração do cadastro é registrada pelo model (`Auditable`): quem, quando, antes e depois, com
    CPF mascarado;
  - a anonimização registra `customer.anonymized`, com o autor da equipe e sem dado pessoal.
- **Anonimização:** é a mesma da exclusão de conta pelo cliente (R-33). Fica bloqueada enquanto houver:
  - horário marcado;
  - atendimento aberto;
  - assinatura vigente ou aguardando pagamento.

  Os motivos aparecem para a equipe com o que fazer antes. Não dá para desfazer.

## Testes

- `tests/Feature/Customers/PanelCustomersTest.php`: permissões por papel e menu, busca, filtros, ficha,
  CPF, edição auditada, campos fora do alcance, validações, anonimização e bloqueios.
- `tests/Feature/Customers/PanelCustomerRegistrationTest.php`:
  - cadastro por recepção, gerente e proprietário; 403 para financeiro e profissional;
  - CPF obrigatório, inválido e duplicado (inclusive de cadastro excluído), sem revelar o dono;
  - celular e e-mail validados e únicos;
  - sem senha, sem e-mail confirmado e sem consentimento pela equipe;
  - link de confirmação; auditoria com CPF mascarado; aparece na busca;
  - o cliente usa a conta depois de criar a senha pelo fluxo existente;
  - desativar e reativar (sem login, fora da busca, auditado); anonimizado não muda.
- `tests/e2e/clientes.spec.js`, no celular e no desktop:
  - recepção (ficha, edição, cadastro com erros de CPF, desativar e reativar);
  - financeiro (403 na lista e no cadastro);
  - proprietário (anonimização).
- `tests/e2e/temas.spec.js`: lista, novo cliente, ficha e edição nos 8 temas, no celular e no desktop.
