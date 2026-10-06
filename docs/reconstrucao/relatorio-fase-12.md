# Relatório da Fase 12 — Área do cliente

> **Status: concluída, aguardando aprovação explícita do dono para a Fase 13.** Branch
> `claude/fase-12-area-cliente`, criada a partir de `claude/fase-11-site-publico`. Só dados fictícios; nenhum
> banco real; nenhuma credencial (Resend e Stripe só por variável de ambiente, vazias aqui); nenhuma migração
> real; sistema antigo não alterado (lido só como referência das funções da área do cliente).
>
> Legenda: **PASSOU** · **NÃO EXECUTADO** · **PENDENTE** · **FALHOU** · **DECISÕES NECESSÁRIAS**.

## 1. Escopo e critérios

Roadmap: "meus agendamentos (remarcar/cancelar conforme política), histórico e comprovantes, fidelidade,
indicação, assinatura, avaliações, perfil, preferências de comunicação, exportar meus dados e excluir conta
(LGPD)"; testes de IDOR e E2E 3, 5 e 15; critério: todas as funções atuais da área do cliente disponíveis ou
substituídas conforme decisões. Briefing do dono: mesma área, mesmo domínio, nenhum sistema paralelo.

| Função do sistema antigo ([funcionalidades.md §2.3](funcionalidades.md)) | Situação |
|---|---|
| Cadastro com confirmação, login, esqueci a senha | Já existia (Fase 3); sem mudança |
| Próximos agendamentos, cancelar, reagendar | **Feito**: lista própria (próximos + histórico), detalhe com preço registrado, descontos e prazos; política de sempre (`BookingService`); P11-03 |
| Histórico e comprovante | **Feito**: lista de comprovantes (com ou sem agendamento); impressão e envio ao e-mail da conta (Fase 8) |
| Avaliar + ver resposta | Já existia (Fase 10); resumo no início |
| Fidelidade + indicação | **Feito**: "Vale para você hoje" pelo motor único; saldo, extrato, código |
| Editar perfil | Nome, celular, nascimento (Fase 3) + **troca de e-mail confirmada** (nova). **Foto: não** (P12-02) |
| Alterar senha | Já existia; agora também conta como confirmação recente |
| Excluir conta (anonimiza) | **Feito**: `CustomerErasure` |
| Exportação de dados (recomendada no inventário) | **Feito**: `CustomerDataExport` |
| Notificações no app | Já existia (Fase 10); explicadas como só transacionais |
| Confirmar presença / descadastro por link | Já existiam (Fase 10) |

## 2. Decisões da aprovação da Fase 11

| Decisão | O que foi feito |
|---|---|
| P11-01 páginas legais | Nada mudou no código (já só aparecem preenchidas). A conta mostra o link da política só quando existe. Registrado que o texto precisa estar revisado antes da publicação definitiva |
| P11-02 fotos e logo reais | Estrutura do painel mantida; estados sem imagem continuam; nada inventado |
| P11-03 profissional fora do site | **Implementado e testado**: a remarcação pela conta usa `ProfessionalDirectory::customerBookableFor` (a mesma lista do site), avisa quando o profissional do horário saiu do site e recusa o slug dele (404); a equipe remarca normalmente; teste compara agendamentos, itens, atendimentos, pagamentos, avaliações e comissões antes e depois de retirar o profissional do site (iguais) |
| P11-04 mínimos | Mantidos; nada mudou |
| P11-05 barbeiro em destaque | Não implementado; registrado como item futuro no roadmap (Fase 14) com os critérios pedidos |

## 3. O que foi feito

- **Telas** ([area-do-cliente.md](area-do-cliente.md)): início com próximos horários e resumo; agendamentos;
  detalhe; remarcar; comprovantes; benefícios; assinatura (período, **próxima cobrança**, histórico
  relevante); avaliações; avisos; meus dados; privacidade. Menu lateral no desktop e faixa de atalhos no
  celular (rola só dentro dela e abre no item atual).
- **Ações sensíveis** pedem a senha de novo (`customer.reauth`, 15 min): exportar, excluir conta, trocar
  e-mail. Cliente só com link mágico cria uma senha antes.
- **Exportar meus dados**: JSON gerado na hora (sem guardar), só do próprio cliente, CPF mascarado, sem dados
  internos nem de outras pessoas; auditoria; 5 por hora.
- **Excluir conta**: anonimização em transação (R-33), mantendo financeiro e histórico sem o nome,
  avaliações anônimas, prova do opt-out; limpa nome/e-mail/celular/CPF/observações também dos valores da
  auditoria do cadastro, dos agendamentos e dos atendimentos; bloqueios (horário marcado, atendimento aberto,
  assinatura vigente); palavra EXCLUIR; idempotente; prova em `customer_erasures`.
- **Troca de e-mail**: link no endereço novo (hash do token, 60 min, uso único, mesma conta, POST), sem
  revelar endereço de outro cadastro, aviso ao endereço antigo.
- **Benefícios**: `PromotionEngine::entitlements` (mesmas regras do orçamento); confirmação continua
  recalculando tudo no servidor.
- **Avisos**: tipo de cada aviso e explicação de que marketing só vai por e-mail; a fila não envia nada para
  conta excluída (`Outbox::blockReason`).
- **Pequenas correções no caminho**: lista de avaliações pendentes num lugar só (`Reviews::pendingFor`);
  recado de status no site (após excluir a conta); avaliação de conta excluída aparece como "Cliente".
- **Banco** (migration `2026_10_08_000100`): `customers.pending_email`, `pending_email_token` (hash),
  `pending_email_expires_at`; tabela `customer_erasures`. Up/down/up testado num SQLite temporário.

## 4. Revisões

| Revisão | Resultado |
|---|---|
| Segurança | **PASSOU** — tabela em [seguranca.md §6.5](seguranca.md#65-área-do-cliente-fase-12). Corrigido durante a revisão: a volta depois da senha usava o endereço anterior (que pode vir do cabeçalho Referer); agora só aceita endereço do próprio site (teste `test_volta_depois_da_senha_nunca_leva_para_fora_do_site`) |
| IDOR | **PASSOU** — toda rota `account.*` com registro na URL testada com outra cliente (404, nada muda); o teste falha se aparecer rota nova com parâmetro fora da lista; listas, exportação e assinatura partem do cliente logado; navegador confirma 404 pela URL |
| IDs previsíveis | **PASSOU** — URLs pelo código aleatório; id numérico = 404 |
| Permissões | **PASSOU** — matriz de rotas e permissões sem mudança de papel; `customers.anonymize` só do proprietário (serviço; sem tela, P12-03); nenhuma ação por GET (`RouteAuthorizationTest`) |
| Privacidade | **PASSOU** — CPF mascarado em todas as telas e na exportação; abrir telas não muda consentimento; minimização na exportação; exclusão e retenção documentadas ([retencao-lgpd.md](retencao-lgpd.md)) |
| Auditoria | **PASSOU** — `customer.data_exported`, `customer.email_change_requested/refused`, `customer.email_changed`, `customer.anonymized` (só quantidades); a trilha não guarda o nome nem o e-mail de quem excluiu a conta (teste) |
| Acessibilidade e celular | **PASSOU** — axe sem violações graves em 14 telas (celular e desktop), sem rolagem lateral; menu da conta corrigido no celular (a coluna crescia com a faixa e cortava os atalhos) |
| Documentação | **PASSOU** — ver §8 |

## 5. Testes

| Item | Resultado |
|---|---|
| PHP (PHPUnit) | **PASSOU** — 789 testes (eram 752; +37 da Fase 12), 0 falhas, 0 pulados |
| PHPStan (Larastan nível 6) | **PASSOU** — 0 erros (pegou dois erros reais antes: `whereKey` no query builder) |
| Pint | **PASSOU** |
| Build (Vite) | **PASSOU** |
| Playwright + axe | **PASSOU** — 119 passando (eram 109; +10 da Fase 12, celular e desktop), 3 ignorados de propósito (os mesmos de antes), duas rodadas completas seguidas em banco SQLite novo. Antes disso, três rodadas completas tiveram falhas, todas registradas em §7: (1) `identidade.spec` procurava o CPF no início da conta (a tela mudou; a verificação foi para "Meus dados", mesma regra); (2) `agenda.spec` no celular: o teste novo remarcava no mesmo dia e barbeiro do teste da agenda (os testes da conta ganharam um profissional próprio); (3) tempo esgotado (90 s) nos testes mais longos de caixa e catálogo, que passaram rodando sozinhos: a suíte maior com 4 navegadores sobrecarregava o servidor embutido do PHP; com 3 navegadores locais (no CI nada muda) as duas rodadas passaram e ficaram mais rápidas (7,3 e 7,5 min contra 13–14). Nenhum teste removido, desativado ou com verificação relaxada |
| Migration up/down/up | **PASSOU** (SQLite temporário) |
| Lighthouse | **NÃO EXECUTADO** (ferramenta indisponível neste ambiente; pendência de homologação) |
| Teste com usuários reais | **NÃO EXECUTADO** (pendência de homologação) |

Novos arquivos de teste: `tests/Feature/Account/{CustomerAreaAccessTest, AccountErasureTest,
EmailChangeAndExportTest, CustomerAreaFeaturesTest}.php`, `tests/e2e/area-cliente.spec.js`. Ajustados (a
tela mudou, a regra não): `PreferencesAndPanelTest` (contador de avisos no menu novo) e `HorizontalAccessTest`
(CPF agora em "Meus dados"; nenhuma tela da conta mostra o número inteiro).

## 6. CI

**PASSOU.** Run nº 45 (commit `f27e960`, https://github.com/Joaogoncalves19/barbearia/actions/runs/37543866177),
PHP 8.4, os dois jobs verdes em todos os passos:

- **Novo sistema (Laravel):** dependências, Pint, Larastan nível 6, auditoria de dependências, build, testes
  PHP (inclusive os de concorrência com processos reais), importador com banco fictício e Playwright + axe.
  Nenhuma chave do Resend ou do Stripe no CI.
- **Sistema atual:** regressão de segurança S-01 a S-04.

O run nº 44 é o primeiro envio da branch, ainda com o commit da Fase 11 (`d068aa9`). O commit seguinte só
atualiza este relatório (documentação).

## 7. Problemas encontrados

- **Menu da conta no celular** (achado no teste de navegador): a coluna do grid crescia com a faixa de atalhos,
  que ficava cortada em vez de rolar; o "scroll-snap" também puxava a faixa de volta. Corrigido
  (`minmax(0, 1fr)`, sem snap, faixa abre no item atual).
- **Auditoria com o nome do cliente**: o teste mais rígido (texto sem escapar acentos) mostrou que a trilha
  dos agendamentos e atendimentos guardava o nome; a exclusão passou a limpar esses valores e o autor da
  própria exclusão.
- **Rota GET "excluir-conta"**: o teste de "nenhuma ação por GET" recusou o nome; a tela de confirmação
  virou `/minha-conta/encerrar-conta` (a exclusão é `DELETE`).
- **Tempo dos testes de navegador**: o teste longo novo estourava 90 s; dividido em três (nenhuma verificação
  removida). Na suíte inteira, os testes mais longos de caixa e catálogo também estouravam com 4 navegadores
  (passavam sozinhos): `playwright.config.js` passou a usar 3 navegadores localmente (CI sem mudança).
- **Interferência entre testes de navegador**: o teste novo de remarcação usava o mesmo barbeiro e o mesmo dia
  do teste da agenda; as contas de teste da área do cliente ganharam um profissional próprio.
- **Teste antigo de navegador procurando o CPF no início da conta**: a verificação foi para "Meus dados", onde
  o CPF (mascarado) agora fica.

## 8. Documentação

Nova: [area-do-cliente.md](area-do-cliente.md), este relatório. Atualizadas:
[retencao-lgpd.md](retencao-lgpd.md) (§5 exclusão, §6 exportação), [seguranca.md](seguranca.md) (§6.5),
[papeis-permissoes.md](papeis-permissoes.md), [consentimento.md](consentimento.md),
[assinaturas.md](assinaturas.md), [avaliacoes.md](avaliacoes.md), [emails.md](emails.md) (avisos),
[agendamento-publico.md](agendamento-publico.md) (P11-03), [modelo-dados.md](modelo-dados.md) (apêndice
regenerado, 69 tabelas), [regras-dados.md](regras-dados.md) (95–98),
[decisoes-pendentes.md](decisoes-pendentes.md), [roadmap.md](roadmap.md), [README.md](README.md),
[relatorio-fase-11.md](relatorio-fase-11.md) (aprovada).

## PASSOU

Testes PHP, PHPStan, Pint, build, migration up/down/up, testes de navegador (ver §5), revisões de segurança,
IDOR, permissões, privacidade, auditoria, acessibilidade e celular, documentação.

## NÃO EXECUTADO

- Lighthouse (sem ferramenta neste ambiente).
- Teste com usuários reais.
- Envio real dos e-mails de troca de e-mail e do aviso ao endereço antigo (sem provedor configurado aqui; as
  notificações são verificadas nos testes).

## PENDENTE (homologação, mantidas da Fase 11)

1. Teste real de entrega pelo Resend. 2. Aprovação visual dos e-mails reais (agora também "Confirme seu novo
e-mail" e "O e-mail da sua conta foi alterado"). 3. Ciclo completo do Stripe em modo teste. 4. Webhook real
do Stripe. 5. Teste com usuários reais. 6. Lighthouse. Sem credenciais reais.
Também: textos da política de privacidade e dos termos revisados antes da publicação definitiva (P11-01).

## FALHOU

Nada na entrega final. Durante o desenvolvimento falharam (e foram corrigidos, ver §7) os testes do menu no
celular, da auditoria sem o nome e da regra "nenhuma ação por GET".

## DECISÕES NECESSÁRIAS

| ID | Ponto | Como ficou | Recomendação |
|---|---|---|---|
| P12-01 | CPF na exportação "meus dados" | **Mascarado**, como em toda a interface | Manter mascarado (o cliente sabe o próprio CPF; o arquivo pode ficar num aparelho compartilhado). Se o dono preferir o número completo, é uma linha |
| P12-02 | Foto do cliente (o sistema antigo tinha, com recorte) | **Não implementada** (a coluna existe, vazia) | Não implementar: não serve ao atendimento e é dado pessoal a mais (minimização) |
| P12-03 | Exclusão/anonimização pedida na barbearia (`customers.anonymize`) | Serviço pronto e testado com a equipe como autora; **sem tela** (o painel não tem tela de clientes no roadmap) | Incluir uma ação "Anonimizar (LGPD)" quando houver a tela de clientes do painel; até lá, o dono decide se quer um comando de terminal |
| P12-04 | Dados no Stripe ao excluir a conta | Ficam no Stripe (cliente, faturas); só o corpo dos eventos guardados aqui é apagado | Decidir na homologação do Stripe se o cliente do Stripe deve ser anonimizado também (as faturas continuam por obrigação fiscal) |
| P12-05 | Exclusão com assinatura vigente | **Bloqueada**: cancelar a renovação e esperar o fim do período pago (ou falar com a barbearia) | Manter: evita cobrança ou benefício órfão no Stripe |
| P12-06 | O que fica de quem excluiu | Avaliação **com nota e comentário**, anônima (R-33); pontos de fidelidade **perdidos** | Manter como R-33; confirmar que o comentário pode ficar |
| P12-07 | Limpeza da trilha de auditoria | A trilha é "só inclusão", mas a exclusão de conta **remove os dados pessoais** dos valores (o registro de quem fez o quê fica) | Manter: é a única exceção, feita por um serviço auditado, e segue o princípio do dono de não guardar dado pessoal sem necessidade |
| P12-08 | Prazos de segurança | Senha de novo após 15 min; link de troca de e-mail vale 60 min | Manter (configuráveis por variável de ambiente) |

## 9. Riscos

- Os e-mails da troca de e-mail usam o canal de notificações de conta (como link mágico e nova senha), não a
  fila central de comunicação; dependem do mesmo provedor e entram na aprovação visual da homologação.
- A anonimização apaga dados de verdade: em produção, um pedido feito por engano não volta (por isso a senha
  de novo, a palavra EXCLUIR e os bloqueios).
