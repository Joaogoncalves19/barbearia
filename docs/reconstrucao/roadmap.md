# 14. Roadmap da reconstrução

## 14.1 Como as fases funcionam

- Cada fase entrega um **incremento completo e testado** de um domínio: modelo → regras →
  persistência → testes → interface daquele domínio. Não se faz "um pouco de tudo" para
  organizar depois.
- O **modelo de dados inteiro** é desenhado e validado contra os dados reais antes de
  qualquer tela (Fase 2). As fases seguintes só implementam o que já foi modelado.
- **Portão de fase:** uma fase só começa quando a anterior cumpriu todos os critérios de aceite,
  o CI está verde e o dono aprovou o roteiro de aceite.
- O sistema atual continua em produção, **intocado**, até a Fase 13.
- Estimativas de esforço serão feitas no início de cada fase (dependem de D-01).

```text
F1 Fundação ─► F2 Dados ─► F3 Identidade ─► F4 Catálogo/Equipe ─► F5 Agenda ─► F6 Atendimento/Caixa
                                                                                    │
      F12 Área do cliente ◄─ F11 Site público ◄─ F10 Comunicação/Avaliações ◄─ F9 Assinaturas* ◄─ F8 Promoções ◄─ F7 Financeiro/Relatórios
                 │
                 ▼
      F13 Homologação + migração + virada ─► F14 Opcionais* (chatbot/IA, lista de espera…)
(* dependem de decisão)
```

---

## Fase 0 — Auditoria e planejamento ✅

- **Objetivo:** entender o sistema atual e planejar a reconstrução.
- **Entregáveis:** esta pasta `docs/reconstrucao/`.
- **Critério de aceite:** todos os itens da condição de conclusão do briefing atendidos
  (ver 14.3); dono leu e respondeu às decisões pendentes necessárias para a Fase 1 (D-01, D-02, D-07).

---

## Fase 1 — Fundação técnica e design system ✅

> **Concluída e aprovada em 2026-09-26.** Direção visual A aprovada no início da Fase 2.
> Correção feita na Fase 2: o CI falhava desde a Fase 1 no `composer install` (runner com PHP 8.3; as
> dependências travadas exigem 8.4.1) e, depois, por rodar o build do Vite após os testes. Requisito passou a
> ser **PHP 8.4+**.
> Entregue: correção das 4 vulnerabilidades críticas no sistema atual (com testes);
> `novo-sistema/` em Laravel 13 com autenticação da equipe, permissões deny-by-default,
> CSP estrita, filas/agendador, diagnóstico e `Money`; design system com duas direções
> visuais e 5 telas de referência; CI; documentação
> ([arquitetura-nova.md](arquitetura-nova.md), [design-system.md](design-system.md),
> [identidade-visual.md](identidade-visual.md), [seguranca-correcoes.md](seguranca-correcoes.md),
> [decisoes-fase-1.md](decisoes-fase-1.md)).
> **Não entregue (bloqueado):** deploy automatizado em homologação (depende de D-01) e
> análise estática PHPStan (download bloqueado no ambiente; vai para o início da Fase 2).
> **Pendente do dono:** logo, fotos e hospedagem.


- **Objetivo:** ter o esqueleto do novo sistema rodando em homologação, com qualidade automatizada.
- **Escopo:** repositório/pasta do novo sistema; Laravel + PHP 8.4; estrutura de módulos;
  `.env` e segredos; CI (lint, análise estática, testes, build, auditoria de dependências);
  deploy automatizado em **homologação**; tokens de design, componentes base e página viva de
  componentes; layout base do site e do painel (casca vazia); página de saúde; política de CSP;
  convenções (código, commits, branches, ADRs).
- **Dependências:** D-01 (hospedagem/stack), D-02 (banco), D-07 (marca: logo, cores, fontes).
- **Módulos envolvidos:** infraestrutura, `resources/css`, `resources/views/components`.
- **Entregáveis:** app "olá mundo" em homologação com login de desenvolvedor; design system
  navegável; pipeline de CI; documento de convenções.
- **Testes:** smoke test de rotas; teste de CSP e cabeçalhos; axe nos componentes.
- **Critérios de aceite:** push na branch principal → CI verde → deploy em homologação
  automático; componentes base com contraste AA e navegação por teclado; zero segredos no repositório.
- **Riscos:** hospedagem sem SSH/cron (mitigação: decidir D-01 antes); escolha de marca atrasada
  (mitigação: tokens permitem trocar cores/fontes depois).
- **Condição para avançar:** critérios atendidos e ambiente de homologação estável.

---

## Fase 2 — Modelo de dados e importador ✅

> **Concluída em 2026-09-26 e aprovada** (início da Fase 3 autorizado). Relatório:
> [relatorio-fase-2.md](relatorio-fase-2.md).
> Entregue: modelo de dados definitivo (49 tabelas de domínio, [modelo-dados.md](modelo-dados.md)),
> 35 regras com implementação e teste ([regras-dados.md](regras-dados.md)), importador `legacy:import`
> com simulação, idempotência, conciliação e relatórios ([importador.md](importador.md)), banco antigo
> fictício para validação, mapa antigo → novo, estratégias de histórico e duplicidades, Larastan nível 6
> sem erros e importador no CI.
> **Diferença em relação ao plano abaixo:** o importador foi validado **só com dados fictícios** (o briefing
> da fase proibiu dados reais); a importação do banco real, o anonimizador de homologação e a aprovação das
> anomalias pelo dono passam para o primeiro ensaio com a cópia real (antes ou durante a Fase 3). O
> importador ficou em `app/Modules/LegacyImport` (não em `tools/`).

- **Objetivo:** schema novo completo + importador que prova que os dados reais cabem nele.
- **Escopo:** migrations de **todas** as entidades aprovadas (ver proposta-arquitetura 10.4);
  factories e seeders de desenvolvimento; importador `tools/legacy-import` (extrair,
  transformar, carregar, conciliar); anonimizador para homologação; relatório de qualidade de dados.
- **Dependências:** Fase 1; cópia do banco de produção (somente leitura); decisões sobre
  funcionalidades "A decidir" que têm tabela (D-03, D-11).
- **Entregáveis:** schema versionado e documentado (diagrama ER); importador idempotente;
  relatório de conciliação "limpo"; lista de anomalias com decisão do dono.
- **Testes:** unitários de cada transformação; teste de conciliação sobre banco de amostra;
  teste de restrições (FK, únicos).
- **Critérios de aceite:** importação completa do banco real em homologação sem erros;
  contagens, somas financeiras por mês e saldos de fidelidade idênticos (12.8); dono aprovou
  o tratamento de órfãos e duplicatas.
- **Riscos:** dados sujos acima do esperado (mitigação: diagnóstico da 12.3 **antes** de desenhar);
  schema que precise mudar depois (mitigação: revisão do modelo com os fluxos das fases 3–12).
- **Condição para avançar:** conciliação 100% e modelo congelado (mudanças futuras só por nova migration revisada).

---

## Fase 3 — Identidade, acesso e auditoria ✅

> **Concluída em 2026-09-30 e aprovada** (início da Fase 4 autorizado). Relatório:
> [relatorio-fase-3.md](relatorio-fase-3.md).
> Decisões do dono: equipe entra por **usuário ou e-mail** (D-10); cliente entra com **senha e link mágico**
> (D-12); **CPF obrigatório** para o cliente; o sistema ainda não está em produção (não há dados reais a migrar).
> Entregue: login/logout/sessão da equipe e do cliente, cadastro com confirmação de e-mail, link mágico,
> recuperação/redefinição/troca de senha, senha provisória com troca obrigatória, rate limits, auditoria de
> acesso, 5 papéis + 24 habilidades declaradas (deny by default, **sem curinga nem bypass de administrador**),
> Policies de usuário, cliente, profissional e agendamento, gestão de usuários pelo proprietário com
> reconfirmação de senha, comando `app:create-owner`, fundação da área do cliente
> ([autenticacao.md](autenticacao.md), [autorizacao.md](autorizacao.md),
> [papeis-permissoes.md](papeis-permissoes.md), [seguranca-contas.md](seguranca-contas.md)).
> **Diferenças em relação ao plano abaixo:** perfis editáveis pelo dono na tela ficaram para depois (a matriz
> é configuração versionada e testada); as senhas antigas foram validadas só com dados fictícios, porque
> não há produção.

- **Objetivo:** contas e permissões corretas antes de qualquer funcionalidade.
- **Escopo:** login da equipe; perfis e permissões (negar por padrão), incluindo o de
  profissional; cadastro de cliente com confirmação de e-mail; login de cliente; esqueci/redefinir
  senha; "lembrar-me"; rate limits; revalidação de sessão; registro de auditoria; gestão de
  usuários pelo proprietário.
- **Dependências:** Fase 2; D-10 (identificador de login da equipe), D-12 (login do cliente: senha e/ou link mágico).
- **Módulos:** Identity, Audit.
- **Entregáveis:** telas de acesso (site/cliente e equipe); gestão de usuários e perfis; trilha de auditoria.
- **Testes:** matriz rota × perfil; senhas antigas (bcrypt) funcionando com dados importados;
  rate limit; reset com `APP_URL` fixo (regressão de S-06); sessão invalidada ao desativar usuário (S-09).
- **Critérios de aceite:** todos os perfis atuais recriados; nenhum acesso por URL direta sem
  permissão; login com senha antiga validado para cliente, profissional e admin.
- **Riscos:** usuários da equipe sem e-mail (mitigação: D-10).
- **Condição para avançar:** matriz de autorização 100% verde.

---

## Fase 4 — Catálogo e equipe ✅

> **Concluída em 2026-09-30 e aprovada** (início da Fase 5 autorizado; D-24 decidida: opção A). Relatório:
> [relatorio-fase-4.md](relatorio-fase-4.md). Branch `claude/fase-4-catalogo-equipe` (a partir da Fase 3).
> Entregue: categorias e serviços (preço em centavos, duração em minutos com regra única, ativo/inativo,
> ordem, visibilidade/destaque no site, imagem, exclusão só do que nunca foi usado); profissionais (conta de
> acesso opcional e separada, apresentação pública, foto, ativo/agendável/público, ordem); vínculo
> profissional × serviço; consultas únicas para a agenda (`ProfessionalDirectory`, `ServiceCatalog`); 12
> permissões granulares (inclusive preço e exibição por campo); concorrência otimista; auditoria; CPF
> obrigatório garantido no model para todo cliente novo
> ([servicos.md](servicos.md), [precos.md](precos.md), [profissionais.md](profissionais.md),
> [relacao-profissional-servico.md](relacao-profissional-servico.md)).
> **Diferenças em relação ao plano abaixo (o briefing da fase restringiu o escopo):** combos, produtos/estoque,
> expediente/pausas/folgas/bloqueios e configurações do estabelecimento **não** ganharam tela. O modelo de
> todos existe desde a Fase 2. Expediente e configurações de agenda vão para a **Fase 5** (são
> "disponibilidade de horários"); produtos/estoque para a **Fase 6** (caixa); combos dependem de decisão (ver
> o relatório).

- **Objetivo:** cadastros que alimentam a agenda.
- **Escopo:** categorias, serviços (duração em minutos, preço em centavos), combos, produtos e
  estoque (movimentações); profissionais (perfil, serviços que realizam, regras de comissão);
  expediente semanal com intervalos, ausências e bloqueios; configurações do estabelecimento e
  regras de agenda.
- **Dependências:** Fase 3.
- **Módulos:** Catalog, Team, configurações.
- **Entregáveis:** telas do admin para esses cadastros; dados importados visíveis e editáveis.
- **Testes:** validações; estoque = soma de movimentos; permissões.
- **Critérios de aceite:** dono consegue reproduzir a configuração atual da barbearia só pelo painel novo.
- **Riscos:** conversão de "slots" para minutos (mitigação: conferência com o dono).
- **Condição para avançar:** cadastros conferidos com a produção.

---

## Fase 5 — Motor de agenda e agendamento ✅

> **Concluída em 2026-09-30 e aprovada** (início da Fase 6 autorizado). Relatório:
> [relatorio-fase-5.md](relatorio-fase-5.md). Branch `claude/fase-5-agenda` (a partir da Fase 4).
> Entregue: horário de funcionamento (vários períodos por dia), expediente e pausas por profissional, folgas,
> bloqueios (do profissional ou da barbearia), regras configuráveis (antecedência, alcance, grade, prazos de
> cancelamento/remarcação, confirmação manual), **uma** regra de disponibilidade (`Availability`), **uma** de
> criação/remarcação/cancelamento (`BookingService`), proteção estrutural contra dupla reserva com teste de
> concorrência real (4 processos), preço/duração/nomes congelados, fuso horário centralizado, agendamento pelo
> site sem login prévio (login só na confirmação), conta do cliente (ver, remarcar, cancelar), agenda da equipe
> (dia por profissional; profissional só a própria), 5 permissões novas, auditoria
> ([agenda.md](agenda.md), [horarios.md](horarios.md), [disponibilidade.md](disponibilidade.md),
> [agendamento.md](agendamento.md), [regras-cancelamento.md](regras-cancelamento.md),
> [regras-reagendamento.md](regras-reagendamento.md)).
> **Diferenças em relação ao plano abaixo:** e-mails de confirmação e lembretes ficaram para a Fase 10
> (Comunicação; dependem do provedor de e-mail, D-05). Não há visão de semana. "Concluir atendimento" fica
> com o caixa (Fase 6). "Teste com 5 pessoas / < 90 s" e "teste de carga" não foram executados (não há
> ambiente de homologação, D-01).

- **Objetivo:** a peça central: disponibilidade e agendamentos consistentes em todos os canais.
- **Escopo:** `Availability`; `BookingService` (criar, remarcar, cancelar, concluir, falta);
  políticas configuráveis; histórico do agendamento; agenda do admin/recepção (dia por profissional,
  semana, lista); agenda do profissional (mobile); agendamento manual; **fluxo público de
  agendamento** (sem login prévio) com o orçamento de preço **sem descontos promocionais** (eles
  entram na F8); confirmação por e-mail; lembretes + confirmação de presença (agendador + fila).
- **Dependências:** Fase 4; D-13 (políticas de cancelamento/remarcação), D-06 (aprovação manual ou automática).
- **Módulos:** Scheduling, Pricing (básico), Communication (e-mails transacionais da agenda).
- **Entregáveis:** agendar de ponta a ponta em homologação, pelos três canais.
- **Testes:** todos os cenários de 13.3; E2E 1, 3, 7, 12; teste de concorrência.
- **Critérios de aceite:** zero sobreposição em teste de carga; agendamento completo em < 90 s
  (teste com 5 pessoas); agenda importada exibida corretamente.
- **Riscos:** regras de agenda não documentadas pelo dono (mitigação: sessão de validação das regras R-01 a R-09).
- **Condição para avançar:** motor aprovado pelo dono em homologação.

---

## Fase 6 — Atendimento e caixa ✅

> **Concluída em 2026-09-30 e aprovada**, com D-29 a D-31 decididos e uma correção exigida antes da Fase 7:
> **o encaixe passou a ocupar a agenda** (agendamento de origem `walk_in`, mesma regra de disponibilidade e
> conflito; ver [relatório §15](relatorio-fase-6.md#15-correção-antes-da-fase-7-o-encaixe-ocupa-a-agenda)).
> D-33 decidida (serviço a mais não estende a agenda). **Fase 6 encerrada em 2026-10-01; Fase 7 autorizada.** Relatório:
> [relatorio-fase-6.md](relatorio-fase-6.md). Branch `claude/fase-6-atendimento-caixa` (a partir da Fase 5).
> Entregue: atendimento separado do agendamento (abrir do agendamento ou encaixe), estados
> aberto/em atendimento/concluído/cancelado, preço e nomes fotografados, **uma** regra de desconto e de
> arredondamento (`Discount`/`PriceBreakdown`, também usada pelo agendamento), conclusão transacional
> (pagamento dividido, gorjeta, caixa, estoque, agendamento, tudo ou nada), idempotência por chave,
> estorno sem apagar, caixa (um por barbearia: abertura, suprimento, sangria, fechamento com diferença e
> justificativa), produtos (venda e insumo) e estoque como razão (entrada, saída, perda, venda, consumo,
> ajuste, estorno, mínimo), teste de concorrência com processos reais, 20 permissões novas, comprovante do
> cliente, auditoria ([atendimento.md](atendimento.md), [pagamentos.md](pagamentos.md),
> [caixa.md](caixa.md), [produtos.md](produtos.md), [estoque.md](estoque.md)).
> **Diferenças em relação ao plano abaixo:** comissão **não** foi calculada nem gravada (o briefing da Fase 6
> excluiu; vai para a Fase 7 com o financeiro); o comprovante é a tela do cliente (sem impressão); "simulação de
> um dia inteiro" em homologação não foi executada (não há ambiente, D-01).

- **Objetivo:** fechar o atendimento com valores corretos e históricos.
- **Escopo:** comanda (itens, produtos, ajuste, forma de pagamento, pagamento dividido, gorjeta);
  baixa de estoque; cálculo e gravação de comissão no fechamento; fechamento de caixa do dia;
  comprovante para o cliente.
- **Dependências:** Fase 5.
- **Módulos:** Checkout, Finance (comissões), Catalog (estoque).
- **Entregáveis:** caixa operando em homologação no admin e no painel do profissional.
- **Testes:** comissão por tipo; gorjeta; estoque; E2E 6.
- **Critérios de aceite:** valores do fechamento = orçamento do agendamento + extras; comissão gravada.
- **Condição para avançar:** simulação de um dia inteiro de atendimento aprovada.

---

## Fase 7 — Financeiro e relatórios (parte 1: comissão, gorjeta e repasse ✅)

> **Parte 1 concluída e aprovada em 2026-10-01** (D-38 a D-40 decididos). Relatório:
> [relatorio-fase-7.md](relatorio-fase-7.md). Branch `claude/fase-7-comissao-repasses` (a partir da Fase 6).
> O dono restringiu o escopo desta fase a: comissão, regras de comissão, gorjeta, vales, valores devidos aos
> profissionais, repasse, histórico, auditoria e integração com o caixa. Entregue: regras de comissão
> versionadas (profissional, serviço, profissional + serviço, padrão; percentual, fixo, sem comissão;
> produtos com percentual próprio), comissão calculada e gravada **na conclusão** do atendimento (mesma
> transação, regra fotografada, desconto rateado pelo maior resto), gorjeta em razão próprio, estorno de
> pagamento ajustando comissão e gorjeta, ajuste manual, vales, repasse com fotografia e estorno, saída em
> dinheiro pelo caixa, 10 permissões por ação, extrato do profissional, auditoria, 5 regras novas no
> verificador e concorrência com processos reais ([comissoes.md](comissoes.md), [repasses.md](repasses.md)).
> Também cumpre o critério "comissão gravada" da Fase 6. **Fica para depois (não iniciado, por decisão do
> dono):** despesas, meta, DRE, relatórios gerais, exportação e dashboard "Hoje". O recibo impresso e por
> e-mail foi pedido pelo dono depois da aprovação e entra na Fase 8.

- **Objetivo:** gestão financeira confiável.
- **Escopo:** despesas (recorrência via agendador), vales, pagamento de comissões com recibo,
  meta, DRE; relatórios (faturamento, equipe, serviços, clientes, ocupação, descontos, CMV) com
  exportação CSV e impressão; dashboard "Hoje".
- **Dependências:** Fase 6.
- **Módulos:** Finance, Reporting.
- **Testes:** relatórios sobre dados importados = números do sistema atual (mesma base); E2E 8, 9.
- **Critérios de aceite:** dono confere 3 meses de relatório contra o sistema atual e aprova.
- **Condição para avançar:** relatórios aprovados.

---

## Fase 8 — Promoções e fidelidade ✅

> **Concluída e aprovada em 2026-10-05** (P8-01 a P8-07 registradas como implementadas). Relatório: [relatorio-fase-8.md](relatorio-fase-8.md).
> Branch `claude/fase-8-promocoes-fidelidade` (a partir da Fase 7). Entregue: motor único de promoções (um
> desconto só, o maior, inclusive o manual), cupons com reserva e uso na conclusão, fidelidade (ganho,
> reserva, resgate na conclusão, extrato, ajuste), aniversário, indicação (D-14), vale-presente como forma de
> pagamento com impressão, comprovantes impressos e por e-mail (atendimento, repasse, vale-presente,
> fechamento de caixa), 9 permissões novas, 4 regras novas no verificador (R39–R42), concorrência com
> processos reais. Tabela de casos de desconto (32 combinações) 100% verde: orçamento exibido = valor
> gravado = valor cobrado. Docs: [promocoes.md](promocoes.md), [fidelidade.md](fidelidade.md),
> [vale-presente.md](vale-presente.md), [comprovantes.md](comprovantes.md).

- **Objetivo:** motor de preço completo.
- **Escopo:** cupons, vale-presente (com impressão), fidelidade (ganho, resgate, extrato, ajuste),
  aniversário, indicação (se aprovada, D-14); precedência de descontos (R-10 a R-16); aplicação no
  fluxo de agendamento e na comanda.
- **Dependências:** Fase 7.
- **Módulos:** Loyalty, Pricing.
- **Testes:** tabela de casos de desconto (todas as combinações); E2E 2.
- **Critérios de aceite:** orçamento exibido = valor gravado = valor cobrado, em todos os casos da tabela.
- **Condição para avançar:** tabela de casos 100% verde.

---

## Fase 9 — Assinaturas (se aprovada, D-03) ✅

> **Concluída e aprovada em 2026-10-05.** Relatório: [relatorio-fase-9.md](relatorio-fase-9.md). O ciclo real em
> modo teste do Stripe fica para a homologação, antes de qualquer uso real.
> Branch `claude/fase-9-assinaturas` (a partir da Fase 8). Entregue: planos versionados, adesão no agendamento e
> por link do painel (Stripe Checkout), webhooks assinados, idempotentes, transacionais e à prova de ordem,
> estados e transições documentados, direito ao benefício separado do estado, cancelamento (fim do período e
> imediato), reativação, reembolso parcial e total, benefício no motor único (vale o maior), comissão de
> assinante sobre a tabela, R-19, MRR, 8 permissões, 4 regras novas no verificador (R43–R46), concorrência com
> processos reais e continuidade das assinaturas importadas. **Pendente:** ciclo completo em modo teste do Stripe
> (depende das chaves de teste do dono). Docs: [assinaturas.md](assinaturas.md), [planos.md](planos.md),
> [beneficios.md](beneficios.md), [stripe.md](stripe.md), [webhooks.md](webhooks.md),
> [cancelamentos.md](cancelamentos.md), [reembolsos.md](reembolsos.md).

- **Objetivo:** planos mensais com cobrança recorrente confiável.
- **Escopo:** planos e serviços inclusos; adesão com Stripe Checkout; webhooks idempotentes
  e transacionais; cancelamento agendado; expiração com tolerância; benefício no preço;
  comissão de assinante; MRR; continuidade das assinaturas importadas (IDs do Stripe).
- **Dependências:** Fase 8; conta Stripe (modo teste e produção).
- **Módulos:** Subscriptions.
- **Testes:** cenários do `tests/assinaturas.php` atual portados; contrato de webhooks; E2E 13.
- **Critérios de aceite:** ciclo completo em modo teste; assinaturas importadas reconhecidas por eventos reais em homologação.
- **Riscos:** cobranças duplicadas na virada (mitigação: webhooks só apontam para o novo sistema na Fase 13).

---

## Fase 10 — Comunicação e avaliações ✅

> **Status: concluída e aprovada (2026-10-05)**; decisões da aprovação (D-05 Resend atrás de abstração,
> P10-01 a P10-04) implementadas no início da Fase 11. Situação original: ([relatorio-fase-10.md](relatorio-fase-10.md)). Fila
> central de e-mails (registro, unicidade, envio depois do commit, 4 tentativas com espera crescente, falha
> definitiva, reenvio, log sem credenciais), modelos com a nova identidade e prévia no painel, confirmação /
> remarcação / cancelamento, lembretes (D-51) com confirmação de presença por link assinado, avisos na conta,
> e-mails de assinatura pelo estado consolidado (D-50), campanhas por segmento com fila, ritmo, cancelamento,
> descadastro de um clique e consentimento conferido três vezes, avaliações com moderação auditada (D-48) e
> pedido de avaliação (D-49), preferências do cliente com prova, retenção dos eventos do Stripe (P9-10), 10
> habilidades, 5 regras novas no verificador (R47–R51) e concorrência com processos reais. **Pendente:** D-05
> (provedor): entrega real a uma lista-semente e aprovação visual no provedor. Docs: [emails.md](emails.md),
> [templates.md](templates.md), [fila.md](fila.md), [lembretes.md](lembretes.md), [campanhas.md](campanhas.md),
> [avaliacoes.md](avaliacoes.md), [consentimento.md](consentimento.md).

- **Escopo:** modelos de e-mail com a nova identidade; campanhas por segmento via fila com
  opt-out; notificações no app; avaliações (pedido, resposta, moderação, destaque).
- **Dependências:** Fase 9 (ou 8, se não houver assinatura); D-05 (provedor de e-mail).
- **Módulos:** Communication, Reviews.
- **Testes:** opt-out sempre respeitado; idempotência de envios; XSS em comentários (regressão S-02); E2E 4, 14.
- **Critérios de aceite:** campanha de teste entregue a uma lista-semente sem quem fez opt-out; e-mails aprovados visualmente.

---

## Fase 11 — Site público ✅

> **Status: concluída e aprovada (2026-10-06)**; decisões P11-01 a P11-05 em
> [decisoes-pendentes.md](decisoes-pendentes.md). Situação original
> ([relatorio-fase-11.md](relatorio-fase-11.md)): início,
> serviços, equipe, página do profissional, assinatura e páginas legais com conteúdo real (nada inventado),
> direção A com elementos gráficos próprios e versão tipográfica sem foto; conteúdo e imagens no painel
> (`site.manage`); imagens reprocessadas (WebP, tamanhos, sem metadados, tipo pelo conteúdo); SEO básico
> (título, descrição, canônico, Open Graph, JSON-LD sem dado inventado, sitemap, robots); o canal do cliente
> só agenda o que é publicado (regra na `Availability`); testes de navegador no celular e no desktop.
> **Não executado:** Lighthouse CI e teste com 5 pessoas. **Pendente:** fotos reais (D-08), logo/marca (D-07),
> textos do dono e política de privacidade. Docs: [site-publico.md](site-publico.md), [home.md](home.md),
> [imagens.md](imagens.md), [seo.md](seo.md), [acessibilidade.md](acessibilidade.md),
> [agendamento-publico.md](agendamento-publico.md), [retencao-lgpd.md](retencao-lgpd.md).

- **Objetivo:** a nova vitrine da barbearia.
- **Escopo:** home conforme [proposta-design.md](proposta-design.md#113-nova-landing-page--estrutura-proposta);
  páginas de serviços, equipe, galeria (se houver conteúdo), assinatura (se aprovada), páginas legais;
  gestão de conteúdo e galeria no painel; SEO (metadados, JSON-LD, sitemap); PWA (se aprovado, D-15).
- **Dependências:** Fase 10; **conteúdo**: fotos, textos, logo (D-07, D-08).
- **Módulos:** SiteContent, views do site.
- **Testes:** Lighthouse CI; axe; E2E 16; teste com 5 pessoas.
- **Critérios de aceite:** metas de 11.7 atingidas; dono aprova o visual; nenhum dado fictício exibido.
- **Riscos:** falta de fotografias (mitigação: versão tipográfica provisória, planejada desde a Fase 1).

---

## Fase 12 — Área do cliente

> **Status: concluída, aguardando aprovação do dono** ([relatorio-fase-12.md](relatorio-fase-12.md)). Conta
> do cliente sobre o mesmo domínio: início com próximos horários e resumo; agendamentos (próximos e histórico,
> remarcar/cancelar pela política de sempre; P11-03 na remarcação); comprovantes; benefícios calculados pelo
> motor único; assinatura (período, próxima cobrança, histórico); avaliações; avisos (só transacionais); dados
> pessoais e troca de e-mail confirmada; privacidade: histórico de consentimentos, **exportar meus dados** e
> **excluir a conta** (anonimização). Ações sensíveis pedem a senha de novo. IDOR testado em toda rota com
> registro. Docs: [area-do-cliente.md](area-do-cliente.md), [retencao-lgpd.md](retencao-lgpd.md).

- **Escopo:** meus agendamentos (remarcar/cancelar conforme política), histórico e
  comprovantes, fidelidade, indicação, assinatura, avaliações, perfil, preferências de
  comunicação, **exportar meus dados** e excluir conta (LGPD).
- **Dependências:** Fase 11.
- **Testes:** IDOR (cliente A não acessa nada do B); E2E 3, 5, 15.
- **Critérios de aceite:** todas as funções atuais da área do cliente disponíveis ou substituídas conforme decisões.

---

## Fase 12.5 — Painel do profissional (fase intermediária)

> **Status: concluída, aguardando aprovação do dono** ([relatorio-fase-12-5.md](relatorio-fase-12-5.md)).
> Não muda a numeração: a Fase 13 continua sendo a próxima. O sistema é vendido como **instalação
> independente por barbearia** (sem multi-tenant).

- **Objetivo:** dar ao profissional uma área própria (`/profissional`), enxuta e pensada para o celular, no
  lugar do painel administrativo com telas bloqueadas.
- **Escopo:** auditoria do painel antigo do barbeiro ([painel-profissional.md](painel-profissional.md));
  Hoje, Agenda, Agendamento, Atendimentos, Atendimento, Ganhos e Perfil; anotações do cliente; tudo sobre as
  regras atuais (agenda, atendimento, comissão, permissões) e nos 8 temas.
- **Fora do escopo:** multi-tenant, WhatsApp, chatbot, IA, lista de espera, CRM, pagamento online, regras
  novas de agenda, comissão ou finanças.
- **Testes:** isolamento entre profissionais (inclusive trocando ids na URL), negação do painel
  administrativo, fluxo do atendimento, tempo livre pela regra da agenda, 8 temas; E2E no celular, desktop e
  tablet.

---

## Fase 13 — Homologação final, migração e virada

- **Objetivo:** trocar o sistema com segurança.
- **Escopo:** ensaio geral da migração com dados reais recentes; testes de aceite completos pelo
  dono e pela equipe; treinamento rápido (recepção e profissionais); plano de virada e de rollback
  ([estrategia-migracao.md](estrategia-migracao.md#129-migração-final-e-rollback)); rotação de
  segredos; backups automáticos configurados; monitoramento.
- **Dependências:** Fases 1–12 e 12.5 concluídas.
- **Critérios de aceite:** checklist 12.8 100%; ensaio de rollback executado com sucesso em
  homologação; dono autoriza **explicitamente** a virada em produção.
- **Riscos:** divergência após a virada (mitigação: critérios de rollback definidos antes, sistema antigo intacto).
- **Condição para concluir:** 30 dias de operação estável; sistema antigo arquivado.

---

## Fase 14 — Opcionais (conforme decisões)

Itens candidatos, cada um com problema, dono e critério de aceite antes de entrar:
assistente/chatbot (usando os serviços do sistema), IA no painel, lista de espera, CRM,
WhatsApp oficial, pagamento avulso online (Pix), várias unidades.

**Item futuro registrado (P11-05, aprovação da Fase 11): "barbeiro em destaque".** Não implementado; nenhuma
regra provisória pela maior nota. Se for retomado, a escolha precisa considerar ao menos: quantidade mínima de
avaliações, nota, período das avaliações, empates, profissionais ativos, profissionais exibidos no site e
auditoria da escolha.

---

## 14.2 Critérios de aceite comuns a todas as fases

Uma fase só está concluída quando:

1. Todos os entregáveis da fase existem e estão em homologação.
2. CI verde: lint, análise estática, testes unitários, feature e E2E da fase, auditoria de dependências.
3. Nenhum teste pulado ou desativado; nenhum achado de segurança Alta/Crítica aberto na área tocada.
4. Documentação atualizada (decisões, modelo de dados, como operar).
5. Acessibilidade verificada nas telas novas (axe sem violações sérias).
6. Roteiro de aceite manual executado e **aprovado pelo dono**.
7. Nenhuma alteração no sistema de produção atual.

## 14.3 Condição de conclusão da Fase 0 (briefing, seção 24)

| Item | Onde |
|---|---|
| Projeto inventariado | [auditoria.md](auditoria.md) |
| Funcionalidades mapeadas | [funcionalidades.md](funcionalidades.md) |
| Arquitetura atual documentada | [arquitetura-atual.md](arquitetura-atual.md) |
| Banco documentado | [banco-atual.md](banco-atual.md) |
| Código legado identificado | [legado-e-codigo-morto.md](legado-e-codigo-morto.md) |
| Segurança documentada | [seguranca.md](seguranca.md) |
| Dependências analisadas | [dependencias.md](dependencias.md) |
| Site atual avaliado | [ux-ui-atual.md](ux-ui-atual.md) |
| Proposta visual definida | [proposta-design.md](proposta-design.md) |
| Nova arquitetura proposta | [proposta-arquitetura.md](proposta-arquitetura.md) |
| Estratégia de migração | [estrategia-migracao.md](estrategia-migracao.md) |
| Estratégia de testes | [estrategia-testes.md](estrategia-testes.md) |
| Roadmap completo | este documento |

---

## Resumo executivo

### 1. O que foi encontrado

Um sistema PHP procedural de ~52 mil linhas próprias, com banco SQLite em arquivo único,
rodando provavelmente em hospedagem gratuita. É **funcionalmente muito rico**: agendamento
online, área do cliente, painel do barbeiro, painel administrativo com 13 módulos (agenda,
caixa, estoque, financeiro com DRE e comissões, relatórios, marketing por campanhas, cupons,
vale-presente, fidelidade, avaliações), assinaturas com Stripe, lembretes por e-mail, PWA e um
chatbot com IA capaz de agendar. Muito do código tem cuidados de segurança bons e comentários
que explicam o porquê das regras.

### 2. Principais problemas

- **Segurança (4 achados Altos):** um cliente consegue apagar agendamento de outra pessoa; XSS
  armazenado de avaliações no painel admin; a página pública de agendamento expõe todos os
  clientes e avaliações; reagendamento pelo cliente sem nenhuma validação. Além disso, segredos
  fixos no código, segredos em texto puro no banco e autorização que falha aberta.
- **Dados:** o preço cobrado **não é guardado**. Relatórios e comissões mudam quando o
  catálogo muda. Sem chaves estrangeiras, dinheiro como texto, listas em CSV, schema que muda em
  tempo de execução.
- **Arquitetura:** regras duplicadas (3 formas de agendar, 4 de remarcar, ~6 cálculos de valor,
  2 sistemas de CSRF e de throttle), telas gigantes, todo o banco carregado em memória e enviado
  ao navegador, grade fixa de 30 minutos, fuso configurável ignorado em 17 arquivos.
- **Legado:** 19 ações do painel sem interface, 6 tabelas abandonadas, 4 arquivos JS/CSS órfãos,
  ~7 MB de imagens sem uso.
- **Site:** parece um painel escuro genérico (azul padrão, logo do produto, números fictícios,
  botão "Admin" no hero, nenhuma foto real) e exige login antes de mostrar horários.

### 3. O que será preservado

O **conhecimento**, não o código: as ~95 funcionalidades mapeadas (salvo decisões) e as 35
regras de negócio catalogadas (antecedências, descontos, fidelidade, comissão, assinatura,
LGPD), os controles de segurança que funcionam e **todos os dados** (clientes, histórico,
financeiro, fidelidade, assinaturas com os mesmos IDs do Stripe, opt-outs), com senhas
mantidas.

### 4. O que deverá ser reconstruído

Tudo: modelo de dados (com preço congelado, centavos, minutos, FKs), motor único de agenda,
motor único de preço, identidade e permissões (negar por padrão), caixa e financeiro, site
público com nova identidade, área do cliente, painéis, comunicação com fila, assinaturas.

### 5. Arquitetura proposta

Monólito modular em **PHP 8.3 + Laravel**, interface renderizada no servidor (Blade) com
Alpine.js, **SQLite (WAL)** portável para MySQL, migrations versionadas, agendador + fila para
lembretes e campanhas, segredos em ambiente, Vite com design system próprio (tokens para site e
painel), testes com Pest + Playwright e CI/CD com homologação. Requer hospedagem com SSH e cron.

### 6. Sequência recomendada

F1 Fundação e design system → F2 Modelo de dados e importador → F3 Identidade e acesso →
F4 Catálogo e equipe → F5 Motor de agenda e agendamento → F6 Atendimento e caixa →
F7 Financeiro e relatórios → F8 Promoções e fidelidade → F9 Assinaturas (se aprovada) →
F10 Comunicação e avaliações → F11 Site público → F12 Área do cliente →
F13 Homologação, migração e virada → F14 Opcionais.

**Antes da Fase 1**, o dono precisa decidir D-01 (hospedagem), D-02 (banco) e D-07 (marca).
Recomenda-se também avaliar, com prioridade, **correções emergenciais** dos quatro achados de
segurança Altos **no sistema atual** (D-16), já que ele seguirá em produção durante toda a
reconstrução. Isso exige autorização explícita, porque a Fase 0 proíbe alterações.
