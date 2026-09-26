# Reconstrução do Sistema da Barbearia

> **Status:** Fase 0 concluída. **Fase 1 concluída, aguardando aprovação** para a Fase 2.
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
