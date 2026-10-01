# Reconstrução do Sistema da Barbearia

> **Status:** Fases 0 a 6 concluídas e aprovadas ([relatorio-fase-6.md](relatorio-fase-6.md)). **Fase 7 em
> andamento** (comissão, gorjeta e repasses).
> Na Fase 1 o sistema atual recebeu **apenas** as 4 correções de segurança críticas
> ([seguranca-correcoes.md](seguranca-correcoes.md)). O novo sistema está em `novo-sistema/`.

Esta pasta reúne o diagnóstico do sistema atual ("Sistema Barbearia 3.0") e o
plano para reconstruí-lo do zero, por etapas.

## Como ler

| # | Documento | Pergunta que responde |
|---|-----------|-----------------------|
| 1 | [auditoria.md](auditoria.md) | O que existe hoje? (inventário completo) |
| 2 | [funcionalidades.md](funcionalidades.md) | Quais funcionalidades existem e em que estado estão? |
| 3 | [arquitetura-atual.md](arquitetura-atual.md) | Como o sistema funciona e onde está acoplado? |
| 4 | [banco-atual.md](banco-atual.md) | Como os dados estão guardados hoje? |
| 5 | [legado-e-codigo-morto.md](legado-e-codigo-morto.md) | O que está morto, duplicado ou abandonado? |
| 6 | [seguranca.md](seguranca.md) | Quais riscos de segurança existem? |
| 7 | [dependencias.md](dependencias.md) | De quais bibliotecas e serviços o sistema depende? |
| 8 | [ux-ui-atual.md](ux-ui-atual.md) | Como está a experiência visual hoje (site e painel)? |
| 9 | [proposta-produto.md](proposta-produto.md) | Como o novo produto deve ser dividido? |
| 10 | [proposta-arquitetura.md](proposta-arquitetura.md) | Como o novo sistema deve ser construído? |
| 11 | [proposta-design.md](proposta-design.md) | Como o novo site e o novo painel devem parecer? |
| 12 | [estrategia-migracao.md](estrategia-migracao.md) | Como levar os dados atuais para o sistema novo? |
| 13 | [estrategia-testes.md](estrategia-testes.md) | Como garantir que cada fase está correta? |
| 14 | [roadmap.md](roadmap.md) | Em que ordem reconstruir e quando cada fase acaba? |
| 15 | [decisoes-pendentes.md](decisoes-pendentes.md) | O que precisa ser decidido pelo dono do produto? |

### Fase 1

| Documento | Conteúdo |
|-----------|----------|
| [seguranca-correcoes.md](seguranca-correcoes.md) | Correções S-01 a S-04 no sistema atual e seus testes |
| [decisoes-fase-1.md](decisoes-fase-1.md) | Decisões tomadas, provisórias e pendentes |
| [arquitetura-nova.md](arquitetura-nova.md) | Estrutura, camadas, deny by default, dados históricos, ambientes, filas, convenções |
| [identidade-visual.md](identidade-visual.md) | Duas direções visuais, paleta com contraste, tipografia, espaço, ícones, fotografia |
| [design-system.md](design-system.md) | Componentes, estados, acessibilidade, mobile, desempenho, telas de referência |
| [relatorio-fase-1.md](relatorio-fase-1.md) | Relatório final da Fase 1 |

### Fase 2

| Documento | Conteúdo |
|-----------|----------|
| [modelo-dados.md](modelo-dados.md) | Modelagem conceitual, entidades, relacionamentos e esquema físico gerado |
| [regras-dados.md](regras-dados.md) | Regras de dados (1–35 da Fase 2, 36–43 da Fase 4, 44–51 da Fase 5, 52–64 da Fase 6): onde são garantidas e qual teste as prova |
| [mapa-banco-antigo-novo.md](mapa-banco-antigo-novo.md) | Destino de cada tabela e campo antigo; as 6 tabelas abandonadas |
| [importador.md](importador.md) | Como usar e o que garante o `legacy:import`; resultados e desempenho |
| [estrategia-duplicidades.md](estrategia-duplicidades.md) | Duplicidades: detectar, registrar, nunca mesclar sozinho |
| [estrategia-historico.md](estrategia-historico.md) | O que é imutável, snapshot, razão ou soft delete |
| [relatorio-fase-2.md](relatorio-fase-2.md) | Relatório final da Fase 2 |

### Fase 3

| Documento | Conteúdo |
|-----------|----------|
| [autenticacao.md](autenticacao.md) | Login da equipe e do cliente, link mágico, cadastro, senhas (inclusive legadas), sessões, primeiro proprietário |
| [autorizacao.md](autorizacao.md) | Deny by default, Gates × Policies, proteções contra IDOR e escalada, receita para telas novas |
| [papeis-permissoes.md](papeis-permissoes.md) | Papéis, habilidades e a matriz papel × habilidade |
| [seguranca-contas.md](seguranca-contas.md) | CSRF, cookies, expiração, rate limit, enumeração, segredos, riscos aceitos |
| [relatorio-fase-3.md](relatorio-fase-3.md) | Relatório final da Fase 3 |

### Fase 4

| Documento | Conteúdo |
|-----------|----------|
| [servicos.md](servicos.md) | Categorias e serviços: fonte única, campos, ativo × histórico, imagens, concorrência |
| [precos.md](precos.md) | Preço atual × preço registrado; decisão sobre histórico de preços |
| [profissionais.md](profissionais.md) | User × Professional × Customer, estados, histórico, ficha própria |
| [relacao-profissional-servico.md](relacao-profissional-servico.md) | Quem executa o quê; a consulta única que a agenda vai usar |
| [relatorio-fase-4.md](relatorio-fase-4.md) | Relatório final da Fase 4 |

### Fase 5

| Documento | Conteúdo |
|-----------|----------|
| [agenda.md](agenda.md) | Arquitetura da agenda, telas e permissões |
| [horarios.md](horarios.md) | Fuso horário, funcionamento, expediente, pausas, folgas, bloqueios, regras configuráveis |
| [disponibilidade.md](disponibilidade.md) | A regra única de disponibilidade, conflitos e duração |
| [agendamento.md](agendamento.md) | Criação, snapshot, status, concorrência (dupla reserva), cliente, equipe, auditoria |
| [regras-cancelamento.md](regras-cancelamento.md) | Quem, quando e o que acontece ao cancelar |
| [regras-reagendamento.md](regras-reagendamento.md) | Os passos da remarcação e o que não muda |
| [relatorio-fase-5.md](relatorio-fase-5.md) | Relatório final da Fase 5 |

### Fase 6

| Documento | Conteúdo |
|-----------|----------|
| [atendimento.md](atendimento.md) | Atendimento × agendamento, snapshots, estados, conclusão transacional, idempotência, concorrência, telas |
| [pagamentos.md](pagamentos.md) | Formas de pagamento, pagamento dividido, gorjeta, descontos, arredondamento, estorno |
| [caixa.md](caixa.md) | Abertura, movimentações, fechamento, diferença, um caixa por barbearia |
| [produtos.md](produtos.md) | Cadastro de produtos (venda e insumo), ativação, exclusão |
| [estoque.md](estoque.md) | Estoque como razão: entrada, saída, venda, consumo, ajuste, estorno, mínimo |
| [relatorio-fase-6.md](relatorio-fase-6.md) | Relatório final da Fase 6 |

## Legenda usada em todos os documentos

**Grau de certeza** (regra 20 do briefing):

- **Confirmado** — verificado lendo o código e/ou executando uma cópia local do sistema.
- **Provável** — forte indício no código, mas não executado de ponta a ponta.
- **Desconhecido** — não há como saber sem acesso a produção ou ao dono do negócio.
- **Precisa de validação** — depende de um dado externo (hospedagem, banco real, uso real).

**Estado da funcionalidade:** ATIVA · PARCIALMENTE ATIVA · LEGADA · ABANDONADA ·
QUEBRADA · DUPLICADA · DESCONHECIDA.

**Severidade de segurança:** Crítica · Alta · Média · Baixa · Informativa.

## Como a auditoria foi feita

1. Leitura do código-fonte do commit `4ee4b82` (branch `main`).
2. Buscas estáticas: referências de funções, arquivos, tabelas, rotas e assets.
3. `php -l` em todos os arquivos PHP (nenhum erro de sintaxe).
4. Execução de uma **cópia descartável** do sistema, fora do repositório, com
   `php -S` e um banco SQLite novo e vazio (instalado pelo `install.php` e populado
   com 1 barbeiro e 3 serviços fictícios). Serviu para gerar o schema real
   resultante e capturas de tela. **O repositório e o banco de produção não foram tocados.**
5. Pesquisa de referências de mercado para sites de barbearia (ver
   [ux-ui-atual.md](ux-ui-atual.md)).

**Limitação importante:** não houve acesso ao banco de produção nem aos logs
reais. Volume de dados, duplicidades reais, registros órfãos e uso efetivo de cada
funcionalidade estão marcados como **precisa de validação**. O script de
diagnóstico sugerido em [estrategia-migracao.md](estrategia-migracao.md#123-diagnóstico-inicial-somente-leitura) resolve isso
sem alterar nada.

## Resumo executivo

Ver a seção final do [roadmap.md](roadmap.md#resumo-executivo).
