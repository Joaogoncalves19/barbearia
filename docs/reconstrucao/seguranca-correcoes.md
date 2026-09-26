# Correções de segurança no sistema atual (Fase 1 — Etapa B)

Intervenção **pequena e isolada** no sistema em produção, feita antes da nova
arquitetura. Corrige somente as quatro vulnerabilidades de severidade Alta da auditoria
([seguranca.md](seguranca.md)): S-01, S-02, S-03 e S-04. Nenhuma refatoração, nenhuma
dependência atualizada, nenhuma mudança de UX.

## Resumo

| ID | Vulnerabilidade | Situação | Arquivos alterados |
|----|-----------------|----------|--------------------|
| S-01 | Cliente autenticado apagava agendamento de outra pessoa (`?reagendar_id=`) | **Corrigida** | `agendamento_data.php`, `processar_agendamento.php` |
| S-02 | Conteúdo de avaliação/cliente executava código no painel (XSS armazenado) | **Corrigida** | `js/admin_detalhes.js`, `js/script.js`, `admin_tabs/avaliacoes.php` |
| S-03 | Página pública expunha clientes, avaliações com IDs e logins dos barbeiros | **Corrigida** | `agendamento_data.php` |
| S-04 | Reagendamento pelo cliente sem validação | **Corrigida** | `cliente_actions.php` |

Testes: `tests/seguranca_fase1.php` (+ `tests/seguranca_xss_navegador.mjs` para a parte de navegador).

| Execução | Resultado |
|----------|-----------|
| Antes das correções (código do commit `0ae603c`) | **17 falhas** (todas as 4 vulnerabilidades reproduzidas) |
| Depois das correções | **25/25 verificações passando**, exit 0 |
| Suíte existente `tests/assinaturas.php` | 40/40 passando (sem regressão) |

## S-01 — Exclusão de agendamento alheio

**Causa:** `agendamento_data.php` gravava `$_GET['reagendar_id']` na sessão sem conferir o
dono. No próximo agendamento concluído, `processar_agendamento.php` executava
`DELETE FROM agendamentos WHERE id = ?` com esse valor.

**Correção:**

- `agendamento_data.php`: o parâmetro deixou de ser aceito. A linha agora faz
  `unset($_SESSION['reagendar_id'])`, o que também neutraliza sessões que já estavam "armadas".
- `processar_agendamento.php`: o bloco de `DELETE` foi removido.

**Por que é seguro remover:** nenhuma tela usa esse fluxo (confirmado na Fase 0). O
reagendamento real do cliente acontece em `cliente_actions.php` (ver S-04).

**Teste:** a cliente A acessa `agendamento?reagendar_id=<agendamento do B>` e faz um
agendamento legítimo. Verifica-se que o agendamento de B continua existindo **e** que o de
A foi criado (sem regressão).

## S-02 — XSS armazenado

**Causa:** textos vindos de clientes eram inseridos com `innerHTML` por concatenação:
comentário de avaliação, nome do cliente/agendamento, horário, e-mail, telefone, anotação.
Também a resposta da IA, que recebe comentários de clientes como entrada.

**Correção (somente escape, sem mudar a interface):**

- `js/admin_detalhes.js`: nova função `escHtmlDetalhes()`, aplicada em todas as interpolações
  de dados de cliente nos modais de detalhes do barbeiro e do cliente (usados pelo painel
  admin **e** pelo painel do barbeiro), e nas respostas da IA (raio-X do cliente, parecer de RH).
- `js/script.js`: nova função `escHtmlPerfil()` no modal público de perfil do barbeiro
  (comentários de avaliação, nome e foto).
- `admin_tabs/avaliacoes.php`: resposta e mensagem de erro da IA no resumo de avaliações
  passam a ser escapadas antes do `innerHTML`.

**Teste (navegador real, Chromium):** avaliação e nome de cliente semeados com
`<img src=x onerror=...>`. O teste abre o painel admin, renderiza os detalhes do barbeiro e do
cliente, abre o perfil do barbeiro na página pública e verifica que o código **não executou**,
que nenhuma imagem foi injetada e que o comentário continua visível como texto.

**Não coberto por teste automatizado:** o escape da resposta da IA (exigiria chamar um
provedor externo). A mudança é a mesma função de escape, aplicada ao texto antes da troca de
`\n` por `<br>`.

## S-03 — Exposição pública de dados

**Causa:** `agendamento.php` (acessível sem login) serializava no JavaScript:
barbeiros com `username` (login), **todas** as avaliações com `cliente_id` e `agendamento_id`,
e **todos** os clientes (`id` + `nome`).

**Correção em `agendamento_data.php`:**

- `username` removido da consulta de barbeiros (não era usado pela página).
- Avaliações reduzidas a `barbeiro_id`, `rating`, `comment` e `timestamp`: o mínimo usado
  pelo perfil do barbeiro.
- Lista de clientes não é mais carregada (`$clientesArr = []`). A variável JS continua
  existindo, vazia, para não quebrar nenhum script.

**Teste:** um visitante anônimo abre `/agendamento` e verifica que não aparecem o username do
barbeiro, nomes de clientes, IDs de clientes nem IDs de agendamentos.

## S-04 — Reagendamento pelo cliente sem validação

**Causa:** `cliente_actions.php` conferia o dono, mas gravava data e hora sem validar nada e
forçava `status = 'aprovado'`, o que reativava agendamentos cancelados ou concluídos.

**Correção em `cliente_actions.php`:** nova função `validarReagendamentoCliente()`, que
reaplica as regras do agendamento online (`processar_agendamento.php`) usando as funções já
existentes (`getHorarioDeTrabalho`, `getHorariosOcupados`, `carregarConfigAgendamento`):

1. só agendamentos `aprovado` ou `pendente` e ainda futuros;
2. data válida (`checkdate`) e hora `HH:MM` na grade de 30 minutos;
3. horário futuro, respeitando as antecedências mínima e máxima configuradas;
4. dentro do expediente do dia (folgas e férias já retornam "sem expediente");
5. sem conflito com outros atendimentos, bloqueios e almoço, considerando a duração total e
   ignorando os slots do próprio agendamento.

Além disso:

- o `UPDATE` não altera mais o status;
- falha do índice único de horário (corrida) vira mensagem amigável, e não erro 500.

**Testes:** recusa hora com conteúdo arbitrário, fora do expediente, ocupada, fora da grade,
data no passado, data inválida, reativação de cancelado e reagendamento do agendamento de
outro cliente (IDOR). Aceita reagendamento válido e remarcar para o próprio horário (sem regressão).

## Como executar

```bash
php tests/seguranca_fase1.php     # exit 0 = passou; 1 = falhou; 2 = parte de navegador pulada
php tests/assinaturas.php <caminho-de-um-banco-sqlite>
```

A parte de navegador precisa de Node.js e Playwright com Chromium. Sem eles, o bloco S-02 é
marcado como **PULADO** e o script sai com código 2, para deixar claro que a cobertura foi parcial.

## O que **não** foi feito (fora do escopo desta etapa)

- As vulnerabilidades Médias e Baixas (S-05 a S-24) continuam abertas no sistema atual e
  serão resolvidas pela arquitetura nova. Recomendação: rotacionar a senha SMTP e as chaves do
  Stripe e de IA quando possível (S-10).
- Outros pontos com `innerHTML` alimentados por dados do **admin** (nomes de serviços e
  barbeiros) não foram alterados: não são controláveis por clientes.
- Comentário desatualizado em `processar_agendamento.php` ("fica depois do DELETE do
  reagendamento") foi mantido para não ampliar o diff.

## Implantação

As mudanças são compatíveis com o banco atual (nenhuma alteração de schema) e podem ser
publicadas copiando os 6 arquivos alterados. **Não foram publicadas em produção** nesta
fase; a publicação depende da autorização do dono (ver `decisoes-fase-1.md`).
