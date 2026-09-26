# 9. Proposta do novo produto

> Divisão proposta a partir da auditoria. Módulos marcados **[decisão]** dependem do dono do
> produto (ver [decisoes-pendentes.md](decisoes-pendentes.md)).

## 9.1 Princípios

1. **Uma fonte de verdade por conceito:** agenda, preço, permissão e cliente têm um único
   serviço responsável, usado por todos os canais (site, painel, barbeiro, chatbot).
2. **O site vende, o painel opera:** duas experiências visuais, uma marca.
3. **Menos, porém completo:** cada tela resolve uma tarefa. Nada de telas com seis assuntos.
4. **Histórico imutável:** o que aconteceu (preço cobrado, comissão, pagamento) não muda
   quando o catálogo muda.
5. **Configurável sem código, dentro de limites:** regras de agenda, fidelidade e
   comunicação ficam em configurações tipadas, não em JSON livre.

## 9.2 Perfis de usuário

| Perfil | Quem é | Acessa |
|--------|--------|--------|
| Visitante | Qualquer pessoa | Site público; consulta horários livres |
| Cliente | Pessoa com cadastro | Agendar, área do cliente |
| Profissional | Barbeiro | Painel do profissional (a própria agenda e os próprios ganhos) |
| Recepção | Atendente | Agenda de todos, clientes, caixa |
| Gerente | Gerente da unidade | Operação completa, equipe, catálogo, relatórios, promoções |
| Financeiro | Contador/sócio | Caixa, financeiro, relatórios, assinaturas |
| Proprietário | Dono | Tudo, inclusive configurações, usuários, backup e exclusões |

Os perfis passam a ser **conjuntos de permissões** (ex.: `agenda.ver_todos`,
`financeiro.editar`, `config.pagamentos`). Os 4 perfis atuais de admin viram perfis
pré-configurados editáveis pelo proprietário. O profissional deixa de ser uma tabela
separada de login: é um usuário da equipe com um perfil de profissional vinculado.

## 9.3 Mapa do produto

```text
SITE PÚBLICO  (identidade de barbearia; foco em desejo e agendamento)
├── Home (página única com seções; ver proposta-design)
├── Serviços            — menu completo com preço e duração
├── Equipe              — perfis dos profissionais (foto, especialidade, trabalhos)
├── Galeria             — [depende de conteúdo: fotos reais]
├── Assinatura          — [decisão] planos mensais
├── Avaliações          — depoimentos reais
├── Localização/Contato — endereço, mapa, horários, WhatsApp
├── Agendar             — fluxo sem cadastro prévio (login/cadastro só na confirmação)
└── Páginas legais      — termos, privacidade (LGPD)

CLIENTE  (área do cliente, mobile-first)
├── Entrar / Criar conta / Recuperar senha
├── Meus agendamentos   — próximos: remarcar, cancelar (conforme política), confirmar presença
├── Histórico           — atendimentos anteriores, comprovante, "agendar de novo"
├── Fidelidade          — saldo, extrato, recompensas
├── Indicação           — [decisão] código e link
├── Assinatura          — [decisão] plano, validade, cancelar
├── Avaliações          — avaliar atendimentos, ver respostas
└── Perfil e privacidade — dados, foto, senha, preferências de comunicação,
                           exportar dados, excluir conta

PROFISSIONAL  (painel do barbeiro, mobile-first)
├── Hoje                — próximo cliente, agenda do dia, metas
├── Agenda              — dia/semana; novo agendamento; remarcar; cancelar
├── Atendimento         — abrir/fechar comanda, produtos, forma de pagamento, gorjeta
├── Clientes            — ficha dos clientes atendidos + anotações
├── Meus horários       — expediente, intervalos, ausências (editar ou solicitar: [decisão])
├── Meus ganhos         — comissões, gorjetas, vales, extrato por período
└── Perfil

ADMINISTRAÇÃO  (painel; foco em clareza e produtividade)
├── Hoje (dashboard)    — agenda do dia, caixa do dia, alertas, poucos KPIs
├── Agenda              — visão por profissional (dia/semana), lista, ações
├── Clientes            — ficha, histórico, anotações, fidelidade, assinatura
├── Equipe              — profissionais, serviços que realizam, comissões,
│                         expediente, intervalos, ausências, bloqueios
├── Catálogo            — serviços, categorias, combos, produtos e estoque
├── Caixa               — comandas, pagamentos, fechamento do dia
├── Financeiro          — despesas (recorrentes), comissões a pagar/pagas, vales, DRE
├── Relatórios          — faturamento, equipe, serviços, clientes, ocupação, descontos
├── Promoções           — cupons, vale-presente, fidelidade, indicação, aniversário
├── Assinaturas         — [decisão] planos, assinantes, pagamentos
├── Comunicação         — campanhas, lembretes automáticos, modelos de e-mail
├── Avaliações          — moderação, resposta, destaque no site
├── Site                — conteúdo da home, galeria, textos legais
├── Configurações       — estabelecimento, regras de agenda, pagamentos, e-mail,
│                         usuários e permissões, integrações, backup
└── Auditoria           — quem fez o quê e quando

OPCIONAIS  [decisão]
├── Lista de espera     — avisar quando abrir horário (valor real para barbearias)
├── Assistente (chatbot)— usa os mesmos serviços do sistema; sem login pelo chat
└── IA no painel        — textos de campanha e resumos (tratada como opcional)
```

## 9.4 O que muda em relação ao sistema atual

| Tema | Hoje | Novo |
|------|------|------|
| Agendamento público | Exige login antes de ver horários | Vê serviços, profissionais e horários livres; identifica-se só para confirmar |
| Caminhos de agendamento | 3 implementações | 1 serviço de agenda usado por todos |
| Reagendar/cancelar | 4 implementações, a do cliente sem regras | 1 política configurável (prazo, status permitidos) |
| Duração de serviço | Blocos fixos de 30 min | Minutos, com grade configurável (ex.: 15 min) e intervalo entre atendimentos opcional |
| Preço histórico | Recalculado pelo preço atual | Congelado no agendamento (itens + descontos) |
| Pagamento | Colunas soltas no agendamento | Entidade "pagamento" (forma, valor, gorjeta), permitindo pagamento dividido |
| Descontos | Regras espalhadas em 3 arquivos | Motor de preço único com precedência documentada |
| Barbeiro | Tabela própria com login | Usuário da equipe + perfil profissional |
| Permissões | Por aba, falha aberta | Por permissão, nega por padrão |
| Anotações de cliente | 3 lugares | 1 (com autor e data) |
| Configurações | JSON livre + segredos no banco | Configurações tipadas + segredos no ambiente |
| Lembretes/campanhas | Dependem de cron opcional ou da aba aberta | Agendador + fila |
| Chatbot | Cria contas e agenda com lógica própria | [decisão] Se mantido, só consome os serviços |

## 9.5 Regras de negócio atuais a preservar (fonte: código)

Extraídas do sistema atual e **mantidas como requisito**, salvo decisão em contrário. Cada
regra vira um teste automatizado no sistema novo.

### Agenda

- R-01 Antecedência mínima (minutos) e máxima (dias) configuráveis. Hoje o padrão é 120 min e 30 dias.
- R-02 Máximo de itens por agendamento configurável (padrão 4).
- R-03 O profissional precisa realizar **todos** os itens escolhidos.
- R-04 O horário precisa estar dentro do expediente do dia, fora de intervalos, bloqueios e ausências.
- R-05 Não pode haver sobreposição com outro atendimento do mesmo profissional (considerando a duração total).
- R-06 O agendamento nasce **confirmado** (não há aprovação manual hoje). [decisão: manter?]
- R-07 Com adesão a plano, o agendamento fica "aguardando pagamento" e o horário fica reservado por
  **15 minutos** (`PAGAMENTO_PENDENTE_MINUTOS`). Depois disso, é liberado.
- R-08 Lembretes: véspera (a partir de uma hora configurada) e "X horas antes" (1–24 h),
  sem reenvio duplicado, com link de confirmação de presença.
- R-09 Todo evento do agendamento entra no histórico (quem, quando, o quê).

### Preço e descontos

- R-10 **Um desconto por agendamento**; entre os aplicáveis, vale o **maior** (cupom, voucher,
  fidelidade, aniversário, indicação).
- R-11 Assinante: serviços inclusos no plano custam zero. Num combo com parte coberta, o cliente paga
  `min(preço do combo, soma dos itens não cobertos)`. Entre o benefício da assinatura e o resgate de
  fidelidade, vale o maior.
- R-12 Cupom: percentual ou valor fixo, validade, limite de usos, ativo/inativo, **1 uso por cliente**.
- R-13 Vale-presente (voucher): uso único, resgate atômico; se falhar, o agendamento segue sem desconto.
- R-14 Aniversário: desconto percentual no mês do aniversário, no **primeiro** agendamento do mês.
- R-15 Indicação: desconto percentual no **primeiro** agendamento de quem foi indicado; o indicador
  ganha pontos quando o indicado é atendido.
- R-16 O desconto nunca deixa o valor negativo.

### Fidelidade

- R-17 Ganho por visita (pontos fixos) **ou** por valor gasto (1 ponto a cada R$ X).
- R-18 Resgate ao atingir N pontos: percentual (sobre o item mais barato, o mais caro ou o total),
  valor fixo ou serviço grátis.
- R-19 Pontos creditados na **conclusão** do atendimento. Assinante ativo não acumula pontos.

### Equipe e financeiro

- R-20 Comissão do profissional: % sobre serviços, % sobre produtos; atendimento de assinante com
  regra própria (padrão, valor fixo ou percentual).
- R-21 Gorjeta registrada no fechamento e atribuída ao profissional.
- R-22 Vales (adiantamentos) descontados no pagamento de comissão.
- R-23 Despesas com vencimento, pagamento e recorrência mensal.
- R-24 Venda de produto baixa estoque e registra movimentação (quem, motivo).

### Assinaturas [decisão]

- R-25 Assinatura só com pagamento online; sem pagamento online configurado, a opção não aparece.
- R-26 Uma assinatura vigente por cliente; ciclo de 30 dias; renovação estende a partir do fim atual.
- R-27 Cancelamento agendado mantém o benefício até o fim do período.
- R-28 Tolerância de **1 dia** após o vencimento antes de expirar.
- R-29 Eventos do gateway processados uma única vez.

### Clientes e privacidade

- R-30 Cadastro com confirmação por e-mail válida por 48 h; reenvio limitado.
- R-31 Senha com no mínimo 8 caracteres, contendo letra e número.
- R-32 E-mail, telefone e CPF únicos (quando preenchidos).
- R-33 Excluir conta: apaga dados pessoais, anonimiza agendamentos (mantém o financeiro), mantém
  avaliações anônimas e registra opt-out.
- R-34 Opt-out de marketing vale pelo ID do cliente e pelo e-mail.
- R-35 Limites de tentativa: login, cadastro por IP, reset de senha (resposta sempre neutra), chatbot.

## 9.6 Critério para incluir algo novo

Qualquer funcionalidade nova (ex.: pagamento avulso por Pix, WhatsApp oficial, várias
unidades) entra no roadmap só com: problema real descrito pelo dono, dono da decisão e
critério de aceite. Até lá, a arquitetura apenas **não impede** essas evoluções.
