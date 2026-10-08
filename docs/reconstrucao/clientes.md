# Clientes no painel (Fase 13, P13-01)

Gestão dos clientes pela equipe, em **Clientes e vendas → Clientes** (`/painel/clientes`). A tela não
cria regra nova: usa a matriz de permissões ([papeis-permissoes.md](papeis-permissoes.md)), a
`CustomerPolicy`, a busca do balcão (`CustomerLookup`), o extrato de pontos, o motor de promoções e a
anonimização da conta do cliente (`CustomerErasure`, [retencao-lgpd.md](retencao-lgpd.md)).

## Telas

| Tela | Rota | O que mostra ou faz |
|---|---|---|
| Lista | `GET /painel/clientes` | Busca por nome, e-mail ou celular (e CPF completo, só para quem vê o CPF); filtro de situação (ativos, inativos, anonimizados, todos); celular, e-mail, último atendimento e situação. Paginada (25). Cadastros mesclados em outro ficam fora |
| Ficha | `GET /painel/clientes/{id público}` | Contato e cadastro; situação da conta; próximos horários, favoritos e anotações; assinatura, pontos, benefícios de hoje e código de indicação; histórico paginado de agendamentos e atendimentos |
| Edição | `GET /painel/clientes/{id}/editar`, `PUT /painel/clientes/{id}` | Nome, celular e nascimento; CPF só para quem vê o CPF completo |
| Anonimizar | `GET /painel/clientes/{id}/anonimizar` (senha reconfirmada), `POST` (mesma janela de 15 min + palavra ANONIMIZAR) | O que acontece, bloqueios e confirmação |

## Permissões

Sem mudança na matriz. As rotas exigem `customers.view`, e cada registro passa pela `CustomerPolicy`.

| Papel | Lista e ficha | Editar (`customers.update`) | CPF completo e correção (`customers.view_cpf`) | Anonimizar (`customers.anonymize`) |
|---|:-:|:-:|:-:|:-:|
| Proprietário | ✅ | ✅ | ✅ | ✅ |
| Gerente | ✅ | ✅ | ✅ | — |
| Recepção | ✅ | ✅ | — (vê `123.***.***-09`) | — |
| Financeiro | 403 | — | — | — |
| Profissional | 403 (vê os próprios clientes na área dele, Fase 12.5) | — | — | — |

Outros detalhes:
- Os pontos aparecem só com `loyalty.view`.
- O link da assinatura aparece só com `subscriptions.view`.
- Os links de agendamento e atendimento aparecem só para quem pode abrir o registro (Policies).
- O menu mostra "Clientes" só para quem tem `customers.view`.

## Regras

- **Busca por CPF:** só para quem vê o CPF completo. Para os outros, a busca por CPF não acha nada, para
  não revelar o número.
- **Edição:**
  - nome com 2 a 120 caracteres;
  - celular válido, com DDD, e que não esteja em outro cadastro;
  - nascimento no passado;
  - CPF com dígitos verificadores válidos, sem repetir outro cadastro e sem ficar em branco quando já
    existe. Cliente importado sem CPF pode ficar em branco; ele informa no próximo acesso.
- **Permissão por campo:** pedido com `cpf` vindo de quem não tem `customers.view_cpf` é recusado inteiro
  (403), sem gravar nada.
- **A equipe não altera:**
  - e-mail e senha, que são o acesso do cliente. A troca de e-mail é feita por ele, com confirmação no
    endereço novo;
  - consentimentos de novidades e lembretes, cuja prova é do cliente;
  - situação e mesclagem.

  Campos com esses nomes enviados no formulário são ignorados.
- **Cadastro anonimizado ou mesclado** não pode ser editado nem anonimizado de novo (`CustomerPolicy`). A
  ficha continua consultável, com o histórico sem nome.
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
- `tests/e2e/clientes.spec.js`: recepção, financeiro e proprietário, no celular e no desktop.
- `tests/e2e/temas.spec.js`: lista, ficha e edição nos 8 temas, no celular e no desktop.
