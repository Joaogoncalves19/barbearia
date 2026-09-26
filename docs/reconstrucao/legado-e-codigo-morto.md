# 5. Legado, código morto e duplicado

Método: busca de referências no repositório inteiro (PHP, JS, CSS e `.htaccess`).
Um item só é marcado **morto** quando nenhuma referência de uso foi encontrada.
Mesmo assim, código morto no sistema atual **não precisa ser removido agora**. A
reconstrução simplesmente não o recria.

## 5.1 Arquivos sem nenhuma referência (confirmado)

| Arquivo | Evidência | Observação |
|---------|-----------|------------|
| `js/reagendar_script.js` | Nenhum `<script>` o inclui. Comentário cita `reagendar_barbeiro.php`, que não existe | Versão antiga do reagendamento do barbeiro |
| `js/reagendar_pc_script.js` | Nenhum `<script>` o inclui. Comentário cita `reagendar_barbeiro_pc.php`, que não existe | Idem |
| `js/barbeiro_script.js` | Nenhum `<script>` o inclui | Substituído por `js/painel_barbeiro.js` |
| `css/admin_agendamentos.css` | Nenhum `<link>` o inclui | Estilos migrados para outros CSS |
| `uploads/bg.png` (2,5 MB) | Nenhuma referência | Imagem de fundo antiga |
| `uploads/bg2.png` (2,4 MB) | Nenhuma referência | Idem |
| `uploads/minha-conta-barbearia-illustration.png` (1,9 MB) | Nenhuma referência | Ilustração antiga da área "minha conta" |

## 5.2 Referências a arquivos que não existem mais

`robots.txt` bloqueia `/painel_barbeiro`, `/minha_conta`, `/minha_conta_tabs/`,
`/agendamento_manual_barbeiro` e `/reagendar_barbeiro`. Nenhum desses arquivos existe.
São vestígios de uma estrutura anterior (a área do cliente se chamava "minha conta" e o
painel do barbeiro, "painel_barbeiro").

## 5.3 Funções PHP sem chamadas (confirmado)

| Função | Arquivo | Observação |
|--------|---------|------------|
| `_lerConfigSeguroINI()` | `lib/config_functions.php` | "Mantida por compatibilidade" com o armazenamento antigo em `.ini` |
| `carregarConfigGemini()` | `lib/config_functions.php` | Substituída por `carregarConfigChatbot()` |
| `campoCsrf()` | `functions.php` | Parte do 1º sistema de CSRF |
| `adicionarProdutoAoAgendamento()` | `lib/agendamento_functions.php` | Barbeiro e admin têm implementações inline próprias |
| `atualizarStatusAssinaturaGateway()` | `lib/marketing_functions.php` | Webhook usa outra função |
| `getPlanos()` | `lib/marketing_functions.php` | Todos usam `lerDados('planos', ...)` |
| `getPopularidadePlanos()` | `lib/relatorio_functions.php` | Relatório não exibido |
| `nomeServicosGestao()` | `lib/admin_gestao_functions.php` | |
| `valorAgendamentoGestao()` | `lib/admin_gestao_functions.php` | Mais uma versão do cálculo de valor, não usada |
| `obterOperacoesAgenda()` | `lib/admin_agenda_functions.php` | |

## 5.4 Ações do painel sem nenhuma interface (backend vivo, sem gatilho)

Todas estão registradas em `admin_actions.php` e executam normalmente se chamadas
pela URL, mas nenhuma tela, formulário ou JS as dispara:

| Ação | Arquivo | Situação |
|------|---------|----------|
| `rejeitar` | `actions/agendamentos.php` | Fluxo de aprovação manual extinto (todo agendamento nasce `aprovado`) |
| `limpar_agendamentos_antigos` | `actions/agendamentos.php` | Substituída por `limpar_por_periodo` |
| `agenda_acao_lote` | `actions/agenda_admin.php` | Ação em lote nunca exposta |
| `enviar_newsletter` | `actions/marketing.php` | Substituída por campanhas (`campanha_*`) |
| `enviar_reativacao` | `actions/marketing.php` | Substituída por `campanha_reativacao_iniciar` |
| `salvar_config_indicacao` | `actions/marketing.php` | **Configuração de indicação sem tela** |
| `enviar_cupom_aniversario` | `actions/marketing.php` | Envio manual abandonado |
| `enviar_lembretes_avaliacao` | `actions/avaliacoes.php` | Substituída por `lembrete_iniciar` |
| `salvar_config_stripe` | `actions/configuracoes.php` | Substituída por `salvar_config_pagamentos` |
| `salvar_tema` | `actions/configuracoes.php` | Tema salvo junto com a landing |
| `ativar_assinatura` | `actions/clientes.php` | Contradiz a regra "assinatura 100% online" |
| `gestao_salvar_espera`, `gestao_status_espera` | `actions/agenda_admin.php` | Lista de espera sem tela |
| `gestao_reagendar_rapido` | `actions/gestao.php` | JS procura um formulário inexistente (`js/admin_gestao.js:641,700`) |
| `gestao_salvar_crm` | `actions/gestao.php` | Grava em tabela nunca lida |
| `gestao_salvar_meta` | `actions/gestao.php` | Idem |
| `gestao_salvar_retencao` | `actions/gestao.php` | Idem |
| `gestao_conciliar_pagamento` | `actions/gestao.php` | Idem |
| `gestao_salvar_perfil_usuario` | `actions/gestao.php` | Duplica `salvar_usuario` |

## 5.5 Tabelas e configurações abandonadas

- Tabelas: `config`, `admin_crm_clientes`, `admin_metas_equipe`, `admin_retencao`,
  `admin_conciliacao`, `admin_lista_espera`, `sys_login_attempts` (duplicata).
- Coluna `users.permissions` (nunca lida).
- Configuração `config` (`aprovar => manual`), lida em `admin.php` mas sem efeito.
- Configuração `config_gemini` (substituída).
- Variável `$barbeiro_preselecionado` em `agendamento.php:755`, nunca definida.

## 5.6 Implementações duplicadas (várias versões da mesma coisa)

| Conceito | Versões |
|----------|---------|
| Token CSRF | 2 (`functions.php:254` e `:898`) |
| Throttle de login | 2 (`login.php` e `functions.php`) |
| Criação de agendamento | 3 (site, chatbot, manual) |
| Reagendamento | 4 (cliente, barbeiro, admin, agente) + 1 legada (`?reagendar_id`) |
| Valor de um atendimento | ~6 funções PHP + JS (`calcularValoresAgendamentoRelatorio`, `calcularValorAgendamentoAgenda`, `valorAgendamentoGestao`, inline no barbeiro, inline na comanda, `js/admin_detalhes.js`) |
| Duração de combo em slots | 5 lugares |
| Envio de campanha/lembrete | versão antiga + versão por lotes |
| Venda de produto no atendimento | 3 (admin, barbeiro, função não usada) |
| Anotação de cliente | 3 tabelas |
| `CREATE TABLE` da mesma tabela | até 5 definições diferentes (`agendamentos`) |

## 5.7 Soluções temporárias que ficaram permanentes

| Item | Evidência |
|------|-----------|
| `lerDados('barbeiros.txt', ...)`: a função remove `.txt` do nome da tabela | `lib/db_functions.php:47-53`. Vestígio da época em que os dados ficavam em arquivos `.txt` |
| `_lerConfigSeguroINI` | vestígio de configurações em `.ini` |
| Migrations rodando a cada requisição | `lib/db_functions.php:33-35` |
| Correção de escape duplo como migration de dados | `lib/migrations.php:248` |
| `assistente.php` não pode ter "chat" no nome por causa do firewall da hospedagem | `assistente.php:3-4` |
| Widget envia formulário em vez de JSON por causa do firewall | `assistente.php:46-48` |
| Despesas recorrentes geradas ao abrir a tela | `garantirDespesasRecorrentes` |
| Expiração de assinatura "de carona" no painel | `admin_data.php:26-29` |
| Liberação de pagamento expirado ao consultar horários | `get_horarios.php` |
| Envio de campanhas pelo navegador do admin (aba aberta) | `campanha_lote` |
| `'unsafe-inline'` no CSP "até que isso seja refatorado" | `functions.php:48-49` |

## 5.8 Código comentado e TODOs

Apenas 4 ocorrências de `TODO/FIXME/XXX/HACK`. Não há grandes blocos de código
comentado. Em compensação, há **comentários desatualizados** que descrevem um
comportamento diferente do atual:

- `assistente.php:5`: "Somente leitura do banco". Hoje grava contas e agendamentos.
- Comentários sobre "Cerebras" como alternativa de IA (`lib/config_functions.php`,
  `actions/configuracoes.php`). O código usa Gemini.
- `migracaoIndiceUnicoSlot`: o comentário descreve a condição ao contrário do código
  (precisa de validação; não afeta o resultado).

## 5.9 Recursos externos legados

- `https://npmcdn.com/flatpickr/dist/l10n/pt.js`: o domínio `npmcdn.com` é um alias
  antigo do unpkg. **Provável** risco de indisponibilidade.
- Som de notificação carregado de `assets.mixkit.co` (terceiro, sem controle).

## 5.10 Impacto na reconstrução

Nenhum item desta lista precisa ser portado. Os itens marcados **A decidir** em
[funcionalidades.md](funcionalidades.md) (lista de espera, CRM, metas, retenção,
conciliação) têm valor de negócio potencial. Se aprovados, serão **projetados do zero**
no novo sistema, não reaproveitados.
