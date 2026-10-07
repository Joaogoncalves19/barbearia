# Relatório — refinamento visual e temas

> Etapa extraordinária entre o redesign "Ofício" (aprovado) e a Fase 13. Branch
> `claude/refinamento-visual-temas`, criada a partir de `claude/redesign-visual`. O roadmap não foi alterado
> e a Fase 13 não foi iniciada.
>
> Documentação dos temas: [temas-visuais.md](temas-visuais.md).
> Capturas: `img/redesign-temas/`.
>
> Legenda: **PASSOU** · **NÃO EXECUTADO** · **PENDENTE** · **FALHOU** · **DECISÕES NECESSÁRIAS**.

**Sem mudança funcional.** Não mudaram:
- regras de negócio, banco (nenhuma migração), permissões, autenticação;
- agenda, preços, comissão, caixa, estoque, assinaturas, Stripe;
- e-mails e importador.

**Onde fica o tema.** A escolha do tema usa a tabela de configurações que já existia (chave
`appearance`). A tela usa a permissão `settings.manage`, que já existia e é só do proprietário.

## 1. Telas revisadas

**Quantas.** 72 telas, cada uma no desktop (1440 px) e no celular (Pixel 7): 144 capturas revisadas uma a
uma no tema Ofício, depois do refinamento.

| Área | Telas |
|---|---|
| Site público | Início, Serviços, Equipe, Perfil do profissional, Assinatura, Agendamento (serviço, profissional, dia e horário), Erro 404 |
| Acesso | Entrar e cadastro do cliente (com erro), Esqueci a senha (cliente e equipe), Link de acesso, Entrar da equipe |
| Painel — dia | Hoje |
| Painel — agenda | Agenda do dia, Agendamento, Novo agendamento, Funcionamento, Folgas, Bloqueios |
| Painel — atendimento e caixa | Atendimentos, Comanda, Encaixe, Caixa, Sessão de caixa |
| Painel — catálogo e equipe | Serviços, Editar serviço, Categorias, Produtos, Estoque, Profissionais, Ficha do profissional |
| Painel — financeiro | Comissões, Comissão do profissional, Regras, Repasses, Histórico |
| Painel — promoções | Cupons, Novo cupom, Vales-presente, Fidelidade e aniversário, Pontos de clientes |
| Painel — assinaturas | Assinaturas, Planos |
| Painel — comunicação | Campanhas, Nova campanha, E-mails enviados, Avaliações, Lembretes e avisos |
| Painel — configurações | Usuários, Novo usuário, Auditoria, Conteúdo do site, Imagens do site, Minha conta, **Aparência** e **Prévia** (novas) |
| Área do cliente | Início, Agendamentos, Horário, Remarcar, Comprovantes, Comprovante, Benefícios, Assinatura, Avaliações, Avisos, Meus dados, Privacidade, Senha, Confirmar senha |

**Fora das capturas.** Também foram revisadas, pelo código e pelo navegador:
- os modais de concluir, cancelar e estornar;
- as páginas 403 e 500, que usam o mesmo layout da 404;
- as páginas legais, que só são publicadas com texto preenchido (P11-01).

**Nos 8 temas.** Além disso, 18 telas foram verificadas **em cada um dos 8 temas**, no desktop e no
celular, pelo teste automático (§7):
- site: início, serviços, equipe, agendar;
- acesso: entrar do cliente e da equipe;
- painel: Hoje, agenda, caixa, serviços, aparência, prévia;
- conta: início, agendamentos, benefícios;
- página de erro.

**Comparação visual.** Para Início, Hoje, Agenda, Comanda e Área do cliente:
- `img/redesign/antes/` (antes do redesign);
- `img/redesign/depois/` (redesign aprovado);
- `img/redesign-temas/oficio/` (refinamento).

## 2. Problemas encontrados

| # | Problema | Onde | Tipo |
|---|---|---|---|
| 1 | A comanda era uma pilha de cartões (itens, material, pagamentos, estoque, histórico, desconto, atendimento): o briefing pede que ela não vire "um cartão por informação" | Comanda | Composição |
| 2 | No celular, a comanda mostrava desconto e dados do atendimento **antes** dos itens da conta | Comanda | Responsividade |
| 3 | Explicações de regra longas (desconto, valores registrados, baixa de estoque) ocupavam a tela de trabalho o tempo todo | Comanda | Densidade |
| 4 | "Fechar caixa" em vermelho sólido: uma rotina diária tratada como ação destrutiva | Caixa | Hierarquia |
| 5 | 15 selos verdes "Sem diferença" repetidos no histórico de caixas | Caixa | Ruído visual |
| 6 | Valores quebravam em duas linhas em tabelas estreitas ("R$ / 45,00") | Conta, painel | Tabela |
| 7 | Botões de perigo em vermelho claro sólido no escuro pareciam salmão | Conta (cancelar horário, excluir conta) | Cor |
| 8 | Conteúdo da conta do cliente numa coluna estreita (46 rem), com 40% da tela vazia no desktop | Área do cliente | Proporção |
| 9 | Botão "Agendar horário" colado ao título | Agendamentos do cliente | Alinhamento |
| 10 | "O que seu perfil pode fazer": mais de cem permissões numa coluna estreita (página com metros de rolagem) | Minha conta (equipe) | Densidade |
| 11 | Listas vazias só com uma linha de texto cinza, fora do componente de estado vazio | 12 telas (repasses, folgas, bloqueios, caixas, movimentações, atendimentos do dia, pontos, conta) | Consistência |
| 12 | O "Hoje" já listava quem estava atrasado, mas sem nenhum sinal; encaixe também sem sinal | Hoje | Informação |
| 13 | Página de erro com o nome do sistema ("Barbearia") em vez do nome da casa | Erros 404/403/500 | Marca |
| 14 | Classe `.h4` usada sem estilo definido | Comanda | Componente |
| 15 | CSS morto da Fase 11: 48 regras sem uso (barber pole, hero antigo, cardápio antigo, ornamentos) e o componente de tesoura/navalha/pente sem uso | `site-publico.css`, `site.css`, `components/site/ornament` | Sobra |
| 16 | Primitivos de cor usados direto em componentes (placa sem foto, bloco "em atendimento", conta da comanda, faixa de protótipo) | `site-marca.css`, `panel.css`, `brand.css` | Arquitetura (quebraria num painel escuro) |
| 17 | Pesos e larguras de fonte escritos à mão em 34 lugares | CSS de componentes e áreas | Arquitetura |
| 18 | Os dados de demonstração não montavam o dia quando rodados de madrugada (agenda vazia) | `app:demo-data` (só local) | Ferramenta de revisão |

**Funcionais.** Nenhum problema funcional (regra, dado, permissão) apareceu nesta etapa. Uma
observação de exibição ficou registrada como pendência (§9): o telefone do cliente aparece sem máscara
na comanda.

## 3. Problemas corrigidos

Todos os itens do §2:

1. **Comanda (1, 2, 3, 14).** O trabalho virou **uma folha** com seções separadas por fio: itens,
   material, pagamentos, estoque e histórico.
   - A conta fica à direita, com os ajustes logo abaixo.
   - No celular, a ordem do HTML é conta, itens, ajustes.
   - As regras ficam em "Como funciona" recolhível. O texto continua na página, para o leitor de tela e
     para os testes.
   - O bloco de desconto some quando não há o que fazer.
2. **Caixa (4, 5).** "Fechar caixa" virou a ação principal (grafite) e "Sem diferença" virou texto
   discreto. O selo ficou só para quando há diferença.
3. **Tabelas (6).** Números não quebram. Tabelas dentro de uma folha alinham com o texto.
4. **Botões de perigo (7).** No escuro viram contorno, em todos os temas.
5. **Conta do cliente (8, 9).** A coluna passou para até 60 rem e o cabeçalho da página tem a ação à
   direita.
6. **Minha conta (10).** A lista virou "Ver a lista completa", em colunas.
7. **Estados vazios (11).** Variante compacta do estado vazio, aplicada às 12 listas.
8. **Hoje (12).** Sinais "Atrasado" e "Encaixe" na lista do dia. São só leitura do que já existe, sem
   regra nova.
9. **Erros (13).** O nome da barbearia aparece quando o banco responde; se o banco estiver fora, entra o
   nome do sistema.
10. **CSS morto (15).** Removido, junto com o componente de ornamentos.
11. **Arquitetura (16, 17).** Tudo virou token.
12. **Demonstração (18).** Fora do expediente, a grade do dia fica dentro do horário de funcionamento,
    com um cliente na cadeira. O comando continua só local e só para banco vazio.

## 4. Componentes consolidados

**Componentes Blade.** 26 componentes revisados em `components/ui`:
- **Novos:** `x-ui.hint` ("Como funciona", `details/summary` nativo, sem JS).
- **Alterados:** `x-ui.card` (nova variante `section`) e `x-ui.empty-state` (variante `compact`, texto
  de apoio opcional).

**CSS de componentes.** 10 arquivos revisados:

| Componente | O que mudou |
|---|---|
| Folha (`.sheet`) | Uma superfície com seções separadas por fio, em vez de um cartão por bloco |
| Botão | Caixa e espaçamento do rótulo pelo tema; perigo em contorno no escuro |
| Tabela | Números sem quebra; tabela em seção alinhada ao texto |
| Lista com marca (`.check-list`) | Lista em colunas |
| Estado vazio | Variante compacta |
| Tipografia | `.h4`; números por `--font-figure` |
| Motivo gráfico | `.ruler`, pé do topo, placa sem foto, estado vazio, por token |
| Fio dos rótulos | `.eyebrow`, índice dos capítulos |

**Peças de área.** `.next-item__flags` (Hoje), `.add-grid` (comanda) e `.theme-*` (Aparência).

**Regra do produto.** O mesmo componente tem a mesma estrutura em qualquer tela e em qualquer tema; o
tema só troca a aparência.

## 5. Arquitetura dos temas

Resumo; o detalhe está em [temas-visuais.md](temas-visuais.md) §1 e §7.

- **Camadas.** Primitivos (`--p-*`) → semânticos por superfície (`--c-*`) → componentes.
- **Arquivos.** Cada tema é um arquivo CSS que troca primitivos, tipografia, cantos, botões e o desenho
  do motivo, mais uma entrada no catálogo `Theme.php` (nome, amostras, superfície de cada parte da
  interface).
- **Superfície por papel.** As telas pedem uma parte da interface (`site`, `panel`, `sidebar`...) e o
  tema decide se ela é clara ou escura.
- **Sem vazamento.** Todo elemento com `data-tema` recomeça do conjunto completo, então a miniatura de
  um tema dentro de outro não herda nada.
- **Gravação.** O tema fica em `settings.appearance` como objeto, pronto para a personalização futura
  entrar por cima. A troca é auditada (tema anterior e novo).
- **Prévia.** Liga o tema só naquela requisição e não grava nada.
- **Novo tema.** Uma entrada no catálogo, um arquivo CSS e uma linha de importação, sem tocar em tela.

## 6. Os 8 temas

| Tema | Para quem | Como se reconhece |
|---|---|---|
| **Ofício** (padrão) | Barbearia contemporânea, editorial | Grafite e osso, latão só na ação, Archivo condensada em caixa alta, régua técnica |
| **Black Label** | Barbearia de luxo, de noite | Tudo preto, inclusive o painel; Cormorant alta em caixa alta; ouro só num fio curto e na ação; botões em caixa alta espaçada; cantos retos |
| **Gentleman's Club** | Barbearia tradicional e elegante | Verde garrafa e creme, Newsreader em caixa normal, filete duplo, placa castanha (a madeira sem textura), botão principal verde |
| **Urban Barber** | Barbearia jovem de cidade grande | Petróleo e areia, Archivo expandida e pesada, blocos geométricos, cantos retos |
| **Old School** | Barbearia retrô e artesanal | Site claro como papel, Roboto Slab de cartaz em caixa alta, filete de gráfica, terracota com texto creme, faixa marrom |
| **Red Barber** | Barbearia ousada | Preto com vinho só na ação, cobre nos rótulos, Archivo extra-condensada e pesada, traço forte |
| **Minimal** | Barbearia minimalista | Tudo claro, Inter também nos títulos, quase nenhum elemento gráfico, champanhe discreto, cantos mais suaves |
| **Copper Club** | Barbearia artesanal e quente | Café e cobre, Roboto Slab em caixa normal, costura tracejada, placa em carvão |

**Um produto, oito identidades.** Os oito usam exatamente as mesmas telas e componentes. A diferença
está em paleta, tipografia, caixa, cantos, superfícies (claro/escuro), motivo e tratamento dos botões.

## 7. Testes realizados

| Item | Resultado |
|---|---|
| PHP (PHPUnit) | **PASSOU** — 800 testes (789 anteriores + 11 novos de tema), 0 falhas |
| Testes novos (PHP): `AppearanceThemesTest` | Cobre: <ol><li>tema padrão no site, acesso, painel e conta;</li><li>os 8 temas selecionáveis e aplicados em site, painel, conta e página de erro;</li><li>superfícies seguem o tema;</li><li>tema continua depois de sair e entrar de novo (login real), para toda a equipe;</li><li>só o proprietário vê e troca (gerente, recepção, financeiro, profissional: 403; cliente e visitante: login);</li><li>tema inventado recusado e valor estranho no banco volta ao padrão;</li><li>troca auditada;</li><li>prévia não grava;</li><li>tela Aparência com os 8 cartões e miniaturas;</li><li>trocar o tema não muda **nenhuma** tabela do banco, nenhuma outra configuração, os horários livres da agenda nem as permissões;</li><li>cada tema tem CSS importado e amostras que existem no CSS.</li></ol> |
| PHPStan (nível 6) | **PASSOU** — 0 erros |
| Pint | **PASSOU** |
| Build | **PASSOU** |
| Playwright + axe | **PASSOU** — **133 passaram e 3 pulados**, nas **duas** rodadas finais seguidas em banco novo (14,1 min cada; 3 workers). Os 133 são os 125 anteriores (`celular` e `desktop`) mais os 8 do projeto `temas`. Os 3 pulados são os mesmos de antes, testes que só valem para um tamanho de tela |
| Teste novo (navegador): `tests/e2e/temas.spec.js` | Projeto próprio (`temas`), que roda sozinho depois dos outros, porque o tema é global. Para cada tema, escolhido pela tela Aparência: <ul><li>18 telas no desktop e no celular com axe sem violação grave;</li><li>sem rolagem lateral;</li><li>tema certo no `<html>`;</li><li>fundo pintado igual ao do tema ativo (nada do tema anterior);</li><li>nenhum elemento marcado com o tema anterior;</li><li>contraste **medido** de 20 pares de tokens nas duas superfícies;</li><li>prévia de outro tema sem mudar o tema em uso.</li></ul> No fim, volta ao Ofício |
| Testes ajustados (a tela mudou, a regra não) | <ul><li>`identidade.spec`: a lista de permissões da "Minha conta" agora fica recolhida, então o teste abre "Ver a lista completa" antes de conferir a mesma permissão.</li><li>`caixa.spec`: a mensagem de falha do axe passou a mostrar as cores medidas (só diagnóstico; asserção igual).</li><li>`playwright.config.js`: novo projeto `temas`; os outros ignoram esse arquivo.</li><li>`global-setup`: contas fictícias também para o projeto `temas`.</li></ul> Nenhum teste foi desligado ou afrouxado |
| CI (GitHub Actions) | **PASSOU** — run 50 (commit d04a2f2) verde: testes PHP, PHPStan, Pint, build e navegador (com o projeto `temas`) |
| Lighthouse | **NÃO EXECUTADO** (pendência de homologação, como antes) |

**Desempenho.**
- **Fontes.** Cada página baixa só a família do tema ativo. O Ofício continua com Inter + Archivo,
  como antes. Os outros temas trocam a Archivo pela fonte deles; o Black Label carrega as duas
  (Archivo nos números).
- **CSS.** `app.css` tem 11,6 kB comprimidos com os 8 temas incluídos. Os arquivos dos temas somam
  ~17 kB sem compressão. `panel.css` tem 5,4 kB comprimidos e `site.css` 6,4 kB.
- **JavaScript.** Nenhum novo.

## 8. Capturas

Em `docs/reconstrucao/img/redesign-temas/`:

| Pasta | Conteúdo |
|---|---|
| `<tema>/` (8 pastas) | `01-inicio-do-site`, `02-hoje`, `03-agenda`, `04-comanda`, `05-area-do-cliente`, cada uma no desktop e no celular (80 imagens) |
| `aparencia/` | `01-escolha-do-tema` e `02-previa-gentlemans-club`, no desktop e no celular |
| `refinamento-oficio/<area>/` | As 72 telas do §1 no Ofício refinado, no desktop e no celular, com os mesmos nomes de `img/redesign/depois/` para comparar lado a lado |

Gerar de novo: `php artisan app:demo-data` num banco vazio, depois `tests/visual/capturas.spec.js`:
- `CAPTURA_TEMAS=1` para os temas;
- `CAPTURA=refinamento-oficio CAPTURA_RAIZ=../docs/reconstrucao/img/redesign-temas` para o conjunto
  completo.

## 9. Pontos para fases futuras

- **Personalização sobre o tema.** Cores próprias, fontes, favicon. Estrutura pronta (temas-visuais.md
  §6), não implementada, como pedido.
- **E-mails.** Continuam no visual fixo (grafite, osso e latão), sem seguir o tema. "Não alterar e-mails"
  nesta etapa (ver T-02).
- **Comprovante impresso.** Continua neutro (preto no branco) em qualquer tema, de propósito, pela
  impressão.
- **Telefone com máscara.** Na comanda e no agendamento, o telefone aparece cru (`+5511988118785`).
  Formatação só de exibição; não foi mexida para não misturar com dado.
- **Tabelas longas no celular.** O histórico de caixas fechados vira uma pilha de cartões longa. Uma
  paginação ou "ver mais" mudaria o que o controlador entrega, então ficou para depois.
- **Prévia do site público.** A prévia mostra um pedaço do site dentro do painel. Uma prévia do site
  inteiro num tema não escolhido exigiria um parâmetro público, que não foi feito por segurança e
  simplicidade.
- **Pendências de homologação anteriores, ainda abertas:**
  - Resend real e aprovação visual dos e-mails;
  - Stripe em modo teste e webhook real;
  - teste com usuários reais;
  - Lighthouse;
  - fotos e logo reais.

## PASSOU

- Testes PHP, PHPStan, Pint e build.
- Testes de navegador nos projetos `celular`, `desktop` e `temas`.
- Contraste medido e axe nos 8 temas.
- Rolagem lateral (nenhuma).
- Revisão visual das 72 telas × 2 aparelhos.
- Revisão de permissões (só o proprietário troca o tema).
- Capturas e documentação.

## NÃO EXECUTADO

- Lighthouse.
- Teste com donos de barbearia reais escolhendo tema.
- Revisão visual com fotos e logo reais (ainda não fornecidos).

## PENDENTE

- Avaliação visual e aprovação do dono (pedido do briefing).
- Pendências de homologação anteriores (§9).

## FALHOU

Nada na entrega final.

Durante o trabalho falharam e foram corrigidos:
- **Rodadas intermediárias de navegador.**
  - O teste do proprietário procurava uma permissão que passou a ficar recolhida; o teste foi ajustado
    para abrir a lista.
  - Com os muitos planos que os outros testes criam, a grade de planos do início **estourava a largura**
    em 5 temas (letras mais largas que a Archivo condensada). O preço não podia quebrar e o cartão não
    encolhia. Corrigido no CSS: o cartão pode encolher e o preço pode ir para duas linhas.
- **Testes PHP novos.** Na primeira versão havia nomes de rota errados e um `actingAs` que herdava a
  guarda do cliente. Os erros eram do teste, não do sistema.

Observado uma vez, não reproduzido:
- **O quê.** Numa rodada intermediária, o axe acusou contraste na barra lateral do painel logo depois de
  fechar o caixa (`caixa.spec`, desktop). É a mesma ocorrência intermitente registrada no redesign.
- **Verificações.** Rodando o teste do caixa 4 vezes, cada uma em banco novo, as 4 passaram; as duas
  rodadas completas finais também passaram.
- **O que ficou.** A mensagem do teste agora mostra as cores medidas, para diagnosticar se voltar.

## DECISÕES NECESSÁRIAS

| ID | Ponto | Implementado / recomendação |
|---|---|---|
| T-01 | Quem troca o tema | Só o **proprietário** (`settings.manage`, que já existia). O gerente não troca. Recomendo manter: é decisão de marca |
| T-02 | E-mails seguem o tema? | Hoje **não** (o briefing proíbe mexer em e-mails nesta etapa). Recomendo fazer numa fase de comunicação, com o mesmo catálogo de cores |
| T-03 | Black Label com o **painel escuro** | Implementado assim ("noite", tudo preto), com contraste e axe conferidos. Se a equipe preferir trabalhar no claro, basta mudar a superfície `panel` do tema para `clara` |
| T-04 | Três famílias de fonte novas (Cormorant, Newsreader, Roboto Slab; licenças OFL e Apache 2.0, auto-hospedadas) | Mantidas: só a do tema ativo é baixada |
| T-05 | Nomes dos temas em inglês, como no briefing | Mantidos ("Black Label", "Gentleman's Club"...). Podem ganhar nome em português sem mexer em nada além do catálogo |
| T-06 | Tema padrão de uma barbearia nova | Ofício |
