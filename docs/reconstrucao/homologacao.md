# Homologação (Fase 13)

Instalação de homologação feita **a partir do pacote** (como numa barbearia), em modo produção
(`APP_ENV=homologacao`, `APP_DEBUG=false`, caches, protótipos desligados, cron rodando), com os dados
**migrados do sistema antigo** e somente dados fictícios. Executada três vezes, a última com o pacote
`032fc78`; o resultado abaixo é dessa rodada limpa. O que depende do dono ou de credenciais reais está
marcado **PENDENTE** e não conta como aprovado.

## 1. Ambiente

| Peça | Homologação local | Produção (a definir, D-01) |
|---|---|---|
| Aplicação | Pacote `barbearia-2026.10.07-032fc78.zip`, servidor embutido do PHP 8.4 (6 processos) | Apache/Nginx + PHP 8.4 |
| Banco | SQLite (WAL) | SQLite |
| Cron | `schedule:work` (equivalente ao cron de 1 minuto) | Cron do servidor |
| Fila | Pelo agendador (`QUEUE_WORK_VIA_SCHEDULER=true`) | Idem ou supervisor |
| E-mail | SMTP para um **receptor local de ensaio** (`scripts/ensaio/smtp-receptor.py`) | SMTP do servidor ou Resend (**PENDENTE**) |
| Stripe | **Simulador** (`scripts/ensaio/stripe-simulado.php`): mesma API, webhooks assinados por HTTP, entregues depois da resposta | Conta do dono, modo teste e depois real (**PENDENTE**) |
| HTTPS | Não (local): o diagnóstico acusa FALHA em "HTTPS" e "cookie seguro", esperado | Obrigatório |
| Dados | Sistema antigo instalado pelo `install.php` dele numa pasta de ensaio, com dados fictícios gravados pelas **ações do próprio sistema antigo**; banco baixado pelo botão de backup do painel antigo | Cópia real (**PENDENTE**, exige autorização) |

## 2. Roteiro automático (tests/homologacao)

Roteiros de navegador que rodam **contra uma instalação no ar**, fora da suíte normal
(`playwright.homologacao.config.js`; senhas só por variável de ambiente). Em cada tela: sem rolagem lateral,
sem violação grave de acessibilidade (axe) e sem erro de console/CSP. Desktop e celular (Pixel 7).

| Roteiro | O que valida | Desktop | Celular |
|---|---|:-:|:-:|
| Proprietário migrado cria a equipe | Senha **do sistema antigo** vale; reconfirmação de senha; criação de gerente, recepção e financeiro | ✅ | — |
| Primeiro acesso | Senha provisória obriga a troca | ✅ | — |
| Perfis | Menu de cada papel e **403** nas URLs proibidas (usuários, auditoria, regras de comissão, agenda para o financeiro, etc.) | ✅ | ✅ |
| Profissional migrado | Senha antiga; cai na área do profissional; Hoje, Agenda, Atendimentos, Ganhos (repasse migrado de R$ 32,20 aparece), Perfil; **403** no painel administrativo | ✅ | ✅ |
| Cliente migrado | Senha antiga; conta, agendamentos, comprovante de R$ 140,30 do antigo, pontos; **agenda, remarca e cancela pelo site** | ✅ | ✅ |
| Recepção | Abre caixa; encaixe do cliente migrado; produto; conclui com pix + gorjeta; comprovante por e-mail | ✅ | — |
| Gerente | Responde a avaliação migrada (chega publicada, como no antigo); entrada de estoque | ✅ | — |
| Financeiro | Comissões e extrato do barbeiro migrado; repasse | ✅ | — |
| Assinatura | Link gerado no painel → checkout → webhooks → **ativa** | ✅ | — |
| Assinatura pelo painel | Cancelar no fim do período, reativar, reembolso parcial, cancelar agora (cada um vira chamada à API com chave de idempotência e volta por webhook) | ✅ | — |
| Promoções migradas | Cupom do antigo no site (10 % sobre R$ 35,50 = R$ 31,95; 1 uso por cliente); vale-presente do antigo pagando no balcão | ✅ | — |
| E-mails | Aceite de novidades, troca de e-mail, remarcação, esqueci a senha, link mágico, campanha | ✅ | — |

O que grava dado compartilhado (caixa, estoque, assinatura) roda uma vez, no desktop. Antes da rodada
limpa, as rodadas parciais acharam 6 problemas reais (§5); os outros ajustes foram no próprio roteiro
(seletor, ordem dos arquivos, expectativa que mudava porque o cliente virou assinante).

## 3. Conferências fora do navegador

| Área | Como | Resultado |
|---|---|---|
| Migração (amostra) | `conferir-amostra.php`: 34 conferências registro a registro da amostra gravada pelo antigo | **34/34** |
| Migração (volume) | 3.016 clientes e 20.019 agendamentos fictícios com ~80 casos problemáticos | Conciliação 6/6, integridade OK, 38 s; reexecução sem duplicar |
| Stripe (ciclo) | Simulador: renovação, falha, recuperação, reenvio, duplicado, fora de ordem | §4 |
| E-mails | Receptor SMTP + captura visual de cada modelo (celular e desktop) | §4 |
| Cópia de segurança | `app:backup`, `-verify`, `-restore --to`, `-restore --replace-current` | [operacao.md](operacao.md) §2 |
| Monitoramento | `app:diagnose --alert` pelo agendador | E-mail "Atenção na instalação" recebido (agendador e cópia em aviso antes do 1º ciclo) |
| `/up` | Monitor externo | 200; 500 com banco inacessível (teste automatizado) |
| Lighthouse | Páginas públicas e de login, celular e desktop | §6 |
| Segredos | Histórico inteiro do Git, `composer audit`, `npm audit` | Nenhum segredo; 0 vulnerabilidades |

## 4. Stripe e e-mails

**Stripe (simulador; mesma versão de API fixada `2024-06-20`):** toda chamada levou `Idempotency-Key`
(`checkout-…`, `expire-…`, `cancel-…-end-vN`, `reactivate-…-vN`, `refund-…`, `cancel-…-now-vN`) e
`Stripe-Version`. Webhooks: `checkout.session.completed`, `customer.subscription.created/updated/deleted`,
`invoice.paid`, `invoice.payment_failed`, `checkout.session.expired`, `refund.created` → **applied**;
reenvio e duplicado do mesmo evento → **duplicate** (nenhum pagamento a mais); fotografia antiga fora de
ordem → **stale** (ignorada). Histórico da assinatura: criada → aguardando → ativa → renovada → falha →
em atraso → recuperada. **PENDENTE:** o mesmo ciclo com a conta Stripe do dono em modo teste e o webhook de
teste apontando para a homologação (o simulador não substitui isso).

**E-mails (SMTP, receptor local):** chegaram confirmação, remarcação, cancelamento, lembrete da véspera,
comprovante, assinatura ativada / falha de cobrança / cancelamento agendado / encerrada, pedido de avaliação,
confirmação de e-mail, troca de e-mail, redefinição de senha, link mágico, campanha (com `List-Unsubscribe`
e `List-Unsubscribe-Post`) e o aviso de monitoramento. Descadastro de **um clique** (POST no link do
cabeçalho) mudou o consentimento para revogado. O que "não se aplica mais" ficou registrado e não saiu
(confirmação de agendamento cancelado logo depois; aviso de falha de cobrança já recuperada), como definido
na Fase 10. Capturas: [img/homologacao/emails/](img/homologacao/emails/). **PENDENTE:** entrega real (SMTP
do servidor ou Resend) a uma lista-semente, SPF/DKIM/DMARC e aprovação visual do dono no cliente de e-mail
real.

## 5. Problemas encontrados e corrigidos (todos com teste)

| # | Problema (achado no ensaio) | Impacto se fosse para produção | Correção |
|---|---|---|---|
| H1 | O antigo grava "1.500,00" como `1.500.00`; o importador recusava a linha | Toda despesa, comissão, vale ou preço acima de R$ 999 ficava fora da migração | `LegacyValue::money` lê o formato e registra `money_format_divergent` |
| H2 | Foto genérica `uploads/default-profile.jpg` importada como foto; fotos reais só com o caminho antigo | Imagem quebrada no site e no painel para todo profissional | A genérica não é importada; `legacy:import-photos` reprocessa as fotos reais da cópia de `uploads/` |
| H3 | Cliente migrado sem e-mail confirmado | Todo cliente preso na confirmação de e-mail no 1º login | Cliente **ativo** no antigo com e-mail válido entra confirmado |
| H4 | Sem horário de funcionamento depois da migração (o antigo não tinha esse cadastro) | **Ninguém conseguia agendar** depois da virada | Funcionamento deduzido do expediente dos barbeiros + antecedência do antigo, com pendência para conferir |
| H5 | `manual@admin.com` (marcador do agendamento manual do antigo) importado como e-mail do cliente | Lembretes de todo encaixe migrado iriam para um domínio de terceiros | Marcador vira "sem e-mail" |
| H6 | Exportação do retorno trazia o que veio da importação | Relançar no antigo duplicaria registros | Corte no fim da última importação |

## 6. Lighthouse (homologação, modo produção)

| Página | Celular (perf / a11y / boas práticas / SEO) | Desktop |
|---|---|---|
| `/` | 90 / 100 / 100 / 66* | 100 / 100 / 100 / 66* |
| `/servicos`, `/assinatura` | 93 / 100 / 100 / 66* | 100 / 100 / 100 / 66* |
| `/equipe` | 93 / 98 / 100 / 66* | 100 / 98 / 100 / 66* |
| `/agendar`, `/entrar`, `/cadastro`, `/painel/entrar` | 93 / 100 / 100 / 54* | 99–100 / 100 / 100 / 54* |

\* SEO baixo **de propósito** na homologação: `robots.txt` bloqueia tudo fora de `APP_ENV=production`
("is-crawlable"). Em produção as páginas públicas liberam. Restam, como sugestão (§8): descrição em
`/agendar` e ordem de títulos em `/equipe` (acessibilidade 98; não é violação grave).

## 7. Testes de aceite do dono e da equipe (PENDENTE)

Roteiro para o dono e a equipe executarem na homologação, cada um com o seu usuário, depois do treinamento
([treinamento/](treinamento/README.md)). Marcar ✅/❌ e anotar o que estranhou.

| Perfil | Tarefas |
|---|---|
| Dono | Conferir a amostra (20 clientes, 20 agendamentos, 5 profissionais, comissões do mês, assinaturas) contra o antigo; criar e desativar um usuário; mudar uma regra de comissão; ver auditoria |
| Gerente | Desconto com motivo; serviço novo; expediente e folga; entrada de estoque; campanha de teste |
| Recepção | Abrir caixa; agendar, remarcar e cancelar; encaixe; concluir com pagamento dividido; vender vale-presente; fechar caixa |
| Financeiro | Conferir o caixa do dia; extrato de um barbeiro; repasse; estorno de um atendimento de teste |
| Barbeiro (no celular dele) | Ver o dia; abrir, iniciar e finalizar um atendimento; anotar sobre um cliente; ver ganhos |
| 5 clientes (teste com usuários, Fase 11) | Agendar pelo site sem ajuda; remarcar; cancelar; achar o comprovante. Anotar onde travou |

## 8. Sugestões para o refinamento pós-roadmap (não implementadas)

Ficam registradas em [relatorio-fase-13.md](relatorio-fase-13.md) §12.
