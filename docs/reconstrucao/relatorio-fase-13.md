# Relatório — Fase 13: homologação final, migração e virada

> **Situação: entregue no escopo corrigido pelo dono (§0), aguardando aprovação.** O produto ainda não foi
> instalado em nenhuma barbearia. A preparação e os ensaios foram feitos de ponta a ponta, só com dados
> fictícios e serviços simulados localmente. A tela de Clientes (P13-01), que bloqueava a virada, foi
> implementada. O que depende de uma instalação real (hospedagem, Stripe e e-mail do comprador, aceite da
> equipe, virada e 30 dias) virou **procedimento da instalação de cada comprador** e não bloqueia o roadmap.
>
> A Fase 14 **não** foi iniciada.

## 0. Correção de escopo (2026-10-08)

Depois da primeira entrega, o dono corrigiu uma premissa:
- o sistema novo **nunca** foi colocado em produção;
- o sistema antigo **nunca** foi usado por uma barbearia real, e o banco dele está vazio;
- não existem clientes, agendamentos, faturamento, profissionais, servidor, operação ou equipe reais;
- o banco fictício é o cenário de desenvolvimento e homologação do produto, que será **vendido** depois.

Consequências:
- **não há migração real nem virada real para fazer agora.** Não se pede banco real, credencial real nem
  autorização de acesso a produção;
- instalação, migração, virada, retorno e operação continuam documentados, como **procedimento para quando
  um comprador instalar o sistema** e, se tiver, importar dados de um sistema legado;
- todo o trabalho já feito nesta fase continua válido e **não foi refeito**;
- **P13-01 (tela de Clientes)** passou a ser requisito real do produto e foi implementada nesta fase
  (§18);
- as demais decisões P13 viraram decisões futuras ou foram mantidas como estão (§9);
- Stripe real, webhook real, SPF/DKIM/DMARC e entrega real de e-mail são configurados pelo instalador na
  homologação de cada comprador ([instalacao.md](instalacao.md) §4). Os simuladores já provaram a lógica;
- o critério de **30 dias** continua no roadmap, para a **primeira operação real**. Nenhuma contagem foi
  iniciada nem simulada, e a falta de operação real não conta como falha.

Branch `claude/fase-13-homologacao-virada`, criada a partir de `claude/fase-12-5-painel-profissional`.
O produto é uma **instalação independente por barbearia**: nada de multiempresa, tenants ou cobrança da
plataforma.

## 1. Resumo

| Item do roadmap / briefing | Situação |
|---|---|
| Ensaio geral da migração (cópia segura, importador, relatórios, conferência, problemáticos e duplicidades) | ✅ com dados fictícios: 3 rodadas com dados gravados pelo próprio sistema antigo + 1 em volume. 6 problemas reais achados e corrigidos. Com um legado real de comprador: repetir com a cópia dele |
| Homologação (todos os perfis e áreas, responsividade, Lighthouse) | ✅ automatizada na instalação feita pelo pacote, inclusive a nova tela de Clientes. Aceite da equipe e teste com 5 pessoas: na instalação de cada comprador |
| Treinamento por perfil | ✅ material ([treinamento/](treinamento/README.md)); sessões na instalação de cada comprador |
| Plano de virada | ✅ escrito e **ensaiado** ([plano-virada.md](plano-virada.md)) |
| Plano de retorno | ✅ escrito e **ensaiado de verdade**, inclusive o relançamento no antigo ([plano-retorno.md](plano-retorno.md)) |
| Segurança e operação (segredos, backup automático, restauração, monitoramento, logs, fila, agendador) | ✅ cópia diária conferida e cifrada, restauração (pasta nova e no lugar), monitoramento com aviso por e-mail, `/up` com banco, segredos fora do Git |
| Stripe: ciclo completo | ✅ no **simulador** (todas as transições, reenvio, duplicado, fora de ordem). Conta do comprador: na instalação |
| E-mail: envio real e visual | ✅ por **SMTP** para um receptor local, com capturas de todos os modelos. Entrega real e DNS: na instalação |
| Migração: corrigir só o necessário, repetir, não destruir | ✅ 6 correções, ensaio repetido; o antigo nunca foi alterado (hash conferido) |
| 30 dias | ✅ critério e métricas registrados ([roadmap.md](roadmap.md), [operacao.md](operacao.md) §5), para a primeira operação real |
| Tela de Clientes (P13-01) | ✅ implementada (§18) |

## 2. O que foi executado

1. **Sistema antigo de ensaio.** Uma cópia do código antigo, nunca o original, foi instalada numa pasta
   temporária com o PHP 8.2 do XAMPP. Ela passou pelo `install.php` do próprio antigo e recebeu dados
   fictícios pelas ações do painel e da área do cliente antigos (`scripts/ensaio/popular-antigo*.sh`):
   - catálogo e estoque;
   - barbeiro com login e expediente;
   - clientes;
   - assinatura manual;
   - cupom, vale-presente e fidelidade;
   - agendamentos, incluindo um manual sem cliente;
   - comanda com produto, gorjeta e pix;
   - despesa, vale e repasse;
   - avaliação e descadastro.

   O banco real (`_dados/`) **não existe** nesta máquina; nenhum dado real foi lido.
2. **Schema real × importador.** O schema criado pelo `install.php` e pelas migrações do antigo foi
   comparado com o banco fictício do importador. Nenhuma coluna do antigo fica sem tratamento.
3. **Cópia segura.** O banco foi baixado pelo botão de backup do painel antigo, como o dono fará, e o
   SHA-256 da cópia foi conferido.
4. **Pacote de instalação** (`scripts/empacotar.sh`): código, dependências PHP de produção e CSS/JS
   compilados, com SHA-256. Foram gerados cinco pacotes, um a cada rodada de correções; o último é o do
   commit `032fc78`.
5. **Homologação** instalada **a partir do pacote** (`instalar-homolog.sh`):
   - `.env` de homologação (`APP_DEBUG=false`, sem protótipos);
   - migrations e `storage:link`;
   - importação: simulação, importação real e reexecução;
   - fotos do antigo;
   - `optimize` e diagnóstico.

   Depois de instalada, ficaram rodando o agendador, a fila, o receptor SMTP e o simulador do Stripe.
6. **Roteiros de homologação no navegador** (`tests/homologacao`), por perfil, no desktop e no celular,
   contra a instalação com os dados migrados.
7. **Stripe** pelo simulador e **e-mails** pelo receptor SMTP, com captura visual.
8. **Lighthouse** nas páginas públicas e de login, no celular e no desktop.
9. **Backup:** cópia, conferência, restauração numa pasta nova e restauração no lugar, com o sistema no ar.
10. **Virada ensaiada** e **retorno ensaiado** numa porta que fazia o papel do domínio.
11. **Treinamento** por perfil, conferido contra o menu real.
12. **Segurança:** varredura do histórico inteiro do Git e auditoria de dependências.
13. **Documentação** nova e atualizada (§14).

## 3. Migração

| Rodada | Fonte | Resultado |
|---|---|---|
| 1 | Cópia do antigo de ensaio (pacote `80c2d78`) | Conciliação 6/6 e integridade OK, mas **despesa de R$ 1.500,00 recusada** (H1) |
| 2 | Mesma cópia (pacote `86a5e80`) | H1 corrigido; homologação revelou H2–H5 |
| Volume | `legacy:fixture` 3.000 clientes / 20.000 agendamentos + ~80 casos problemáticos | 3.016 clientes e 20.019 agendamentos em **38 s**, pico de 50 MB. Conciliação 6/6, integridade OK, origem inalterada. Reexecução: tudo "já importado", nada duplicado. 1.836 pendências, nada descartado sem registro (abaixo) |
| 3 e final | Mesma cópia (pacotes `dd5f9ba`, `f5e84c3`, `032fc78`) | Tudo OK de primeira, sem ajuste à mão. **Amostra 34/34** conferida registro a registro |
| Virada | Cópia nova baixada no início da virada | Conciliação 6/6; a operação seguiu no sistema novo |

**Problemáticos e duplicidades (volume).** Nenhum registro foi descartado sem pendência: todos foram
importados com aviso ou ficaram registrados para decisão.
- **Duplicidades:** clientes com o mesmo e-mail, telefone ou CPF (3, **não mesclados**; ficam para revisão
  em `customer_merge_candidates`), usuários, cupons, vales, avaliações e expedientes.
- **Órfãos:** profissional, cliente, assinatura e itens.
- **Inválidos:** datas, valores, JSON, CPF, e-mail e telefone.
- **Status desconhecidos** e **senhas fora do padrão** bcrypt (a conta fica sem senha, e a pessoa redefine).
- **Seções com segredo:** não importadas, ficam para recadastrar.
- **1.740 sobreposições futuras:** efeito do gerador aleatório. Com dados reais, a recepção resolve antes
  da virada.

**Pendências da cópia de ensaio** (rodada final):
- `money_format_divergent`: a despesa que o antigo exibia como R$ 1,50;
- `secret_section_not_imported` (3): `config_email`, `config_stripe` e `config_chatbot`, a recadastrar com
  credenciais novas;
- `business_hours_derived`: funcionamento deduzido do expediente, para conferir em *Funcionamento*.

## 4. Homologação

Ver [homologacao.md](homologacao.md). Validado na instalação feita pelo pacote, com os dados migrados:

| Área | Validado |
|---|---|
| Site público | Agendar, remarcar e cancelar pelo site (cliente migrado); cupom do antigo; horários pelo funcionamento |
| Painel administrativo | Perfis com o menu de cada papel; URLs proibidas → **403** |
| Área do profissional | Barbeiro migrado com a senha antiga: Hoje, Agenda, Atendimentos, Ganhos (repasse migrado), Perfil; painel administrativo → 403 |
| Área do cliente | Cliente migrado com a senha antiga: agendamentos, comprovante do antigo, pontos, assinatura, preferências, troca de e-mail |
| Agenda | Agendamento pela equipe e pelo site; encaixe recusado com o profissional ocupado (D-32) e próximo horário sugerido |
| Atendimento e comanda | Encaixe, produto, pix com gorjeta, comprovante por e-mail; atendimento aberto a partir do agendamento |
| Caixa | Abertura, entradas e consulta pelo financeiro |
| Estoque | Saldo migrado (10 + 5 − 2 = 13) e entrada pela tela |
| Comissão e repasses | Extrato do barbeiro migrado; repasse; repasse migrado na área do profissional |
| Promoções e fidelidade | Cupom migrado (10 %, um uso por cliente); vale-presente migrado pagando no balcão; pontos migrados (3 + 1) e ganho na conclusão; regra de fidelidade do antigo (10 pontos = 20 %) |
| Assinaturas | Manual migrada ativa (corte de graça); link → checkout → ativa; cancelar no fim, reativar, reembolso, cancelar agora |
| E-mails | Todos os modelos (§6) |
| Permissões | 403 por URL para gerente, recepção, financeiro e profissional |
| Responsividade e acessibilidade | Em cada tela dos roteiros: sem rolagem lateral, axe sem violação grave, sem erro de console ou CSP, no desktop e no celular (Pixel 7) |
| Lighthouse | Desempenho de 90 a 100, acessibilidade de 98 a 100, boas práticas 100. O SEO baixo é proposital fora de produção (`robots.txt` bloqueia) |
| Testes de usuário | **PENDENTE:** roteiro em homologacao.md §7 (dono, equipe e 5 clientes) |

## 5. Stripe

Com o simulador (`STRIPE_API_BASE`), cada chamada levou `Idempotency-Key` e `Stripe-Version`, e os webhooks
chegaram assinados por HTTP, depois da resposta, como no Stripe real. Foram ensaiados:
- criação: link, checkout e pagamento, até a assinatura ficar **ativa**;
- renovação: novo período, 2 pagamentos;
- falha: em atraso, com o e-mail de falha;
- recuperação: ativa de novo;
- reenvio e evento duplicado: `duplicate`, sem pagamento a mais;
- fotografia fora de ordem: `stale`, ignorada;
- cancelamento no fim do período, reativação, reembolso parcial e cancelamento imediato pelo painel;
- expiração do link anterior quando se gera um novo.

A aplicação ficou consistente em todos os passos: histórico da assinatura, pagamentos e eventos guardados.

**Achado do ensaio, no simulador e não no sistema:** entregar o webhook enquanto a chamada de API ainda
estava aberta travava o SQLite. O Stripe real é assíncrono, e o simulador foi corrigido para se comportar
igual. O sistema não faz chamada ao Stripe dentro de transação (conferido).

**PENDENTE:** o ciclo na conta do dono em modo teste, com webhook de teste. Sem as chaves, ele não pode ser
feito aqui.

## 6. E-mail (Resend / SMTP)

Todos os modelos saíram pela fila real, por SMTP, para um receptor local:
- agendamento: confirmação, remarcação, cancelamento e lembrete da véspera;
- comprovante;
- assinatura: ativada, falha, cancelamento agendado e encerrada;
- pedido de avaliação;
- conta: confirmação de e-mail, troca de e-mail, redefinição de senha e link mágico;
- campanha, com `List-Unsubscribe` de um clique (descadastro conferido no banco);
- aviso de monitoramento.

O que "não se aplica mais" não saiu, conforme a Fase 10. As capturas no celular e no desktop estão em
[img/homologacao/emails/](img/homologacao/emails/).

**PENDENTE:** a entrega real e o provedor (P13-03; você prefere o SMTP do servidor, como o PHPMailer
antigo, e isso já funciona sem código), SPF/DKIM/DMARC e a aprovação visual no cliente de e-mail real.

## 7. Backup, restauração, cron, filas, webhook e monitoramento

| Item | Entregue nesta fase | Ensaio |
|---|---|---|
| Backup automático | `app:backup` diário às 02:40, com cópia **consistente** do SQLite, arquivos e manifesto (SHA-256 e contagens); **cifrado** com AES-256 (`BACKUP_PASSWORD`); rotação das 14 mais recentes **depois** da conferência; `.env` fora | Cópia de 78 tabelas e 9 arquivos em < 1 s; sem senha não abre nem o manifesto |
| Conferência | `app:backup-verify` | Aprovada; um arquivo adulterado é reprovado (teste) |
| Restauração | `app:backup-restore --to` (pasta nova) e `--replace-current` (manutenção, cópia do estado atual, troca, migrations, integridade, volta ao ar) | Pasta nova: contagens idênticas. No lugar: ~1 s com o sistema no ar; o registro gravado depois da cópia sumiu e o estado anterior foi guardado |
| Cron e agendador | Uma linha de cron; batimento; tudo idempotente | `schedule:work` na homologação; lembretes, avaliações, campanhas e fila funcionaram |
| Fila | Pelo agendador (sem supervisor) | E-mails entregues e "não se aplica" registrados |
| Webhook | `/webhooks/stripe` | Assinatura, reenvio, duplicado e fora de ordem conferidos |
| Monitoramento | `app:diagnose --alert` de hora em hora: e-mail para `MONITOR_EMAIL`, no máximo 1 a cada 6 h por problema. Novas verificações: cópia recente, e-mails parados ou com falha, transporte de e-mail. `/up` com o banco | Aviso recebido na homologação; `/up` responde 500 sem banco (teste) |
| Exportação de retorno | `app:rollback-export --since` (CSV para o Excel, pasta privada, sem CPF, proteção contra fórmula) | Usada no ensaio de retorno |
| Fotos do antigo | `legacy:import-photos` (WebP, sem metadados, só dentro da pasta) | Testado (real, genérica, ausente, corrompida, fora da pasta) |

## 8. Segurança

- **Segredos no Git:** nenhum. O histórico inteiro foi varrido atrás de chaves do Stripe, do Resend e do
  Google, de `APP_KEY` e de chave privada; as duas ocorrências encontradas são falsos positivos (nome de
  teste e expressão de teste). O único `.env*` versionado é o `.env.example`, sem valores.
- **Segredos na instalação:** o `.env` nasce com `APP_KEY` nova. As chaves do Stripe e do e-mail são
  **novas** (rotacionadas na virada, porque as antigas ficaram em texto puro no banco antigo). O importador
  descarta as seções com segredo. A cópia de segurança não leva o `.env`. As senhas de pessoas continuam
  como hash bcrypt e são refeitas no primeiro login.
- **Credenciais de ensaio:** foram geradas localmente, guardadas num arquivo fora do repositório e nunca
  escritas no chat nem no código.
- **Dependências:** `composer audit` e `npm audit` sem vulnerabilidades.
- **Privacidade:** o e-mail marcador do antigo deixou de virar destinatário (H5). A exportação de retorno
  não leva CPF. O `robots.txt` bloqueia a homologação.
- **Diagnóstico:** reprova produção com depuração ligada, sem HTTPS, sem cookie seguro, com protótipos
  ligados ou com e-mail em log.

## 9. Decisões P13 (decididas pelo dono em 2026-10-08)

| ID | Assunto | Decisão |
|---|---|---|
| **P13-01** | Tela de Clientes no painel | **Requisito do produto. Implementada** nesta fase (§18) |
| P13-02 | Relatórios gerais e despesas/DRE | **Funcionalidade futura.** Não implementar agora: o roadmap não a coloca numa fase atual. Não bloqueia o encerramento do roadmap |
| P13-03 | Provedor de e-mail | Manter a arquitetura de provedor. Cada instalação escolhe por ambiente (`EMAIL_PROVIDER`, `MAIL_MAILER`): Resend ou SMTP. O produto não fica preso a um fornecedor |
| P13-04 | Hospedagem | Não escolher agora. O produto segue pronto para hospedagens com os requisitos de [instalacao.md](instalacao.md) §1; a escolha é feita na primeira instalação real |
| P13-05 | Funcionamento deduzido na importação | Manter a regra implementada e documentada |
| P13-06 | Cliente migrado ativo entra com e-mail confirmado | Manter a regra implementada |
| P13-07 | Janela da virada | Não há virada agora. O procedimento ([plano-virada.md](plano-virada.md)) fica para uso futuro |
| P13-08 | Guarda do sistema antigo | Manter a política documentada como procedimento futuro de instalação e migração. Nada é apagado nem alterado agora |

Continuam abertas as decisões de fases anteriores que você ainda não fechou: P12-01 a P12-08, T-01 a T-06,
P12.5-01 a P12.5-07 e o conteúdo pendente (D-07, D-08, P11-01).

## 10. Treinamento

[treinamento/](treinamento/README.md): roteiros curtos para proprietário, gerente, recepção, financeiro e
profissional, só com o que cada perfil usa.
- Foram conferidos contra o menu real. A primeira versão citava "Clientes" e "Relatórios", que não
  existiam no painel. Com a tela de Clientes (P13-01), os roteiros de proprietário, gerente e recepção
  passaram a ensiná-la. "Relatórios" continua fora (P13-02, futura).
- Cada roteiro marca com ✔ as tarefas que a pessoa precisa conseguir fazer sozinha.
- As sessões com a equipe acontecem na instalação de cada comprador.

## 11. Plano de virada e plano de retorno

- **Virada** ([plano-virada.md](plano-virada.md)): pré-requisitos, janela, 14 passos com conferência,
  `.env`, segredos, Stripe, DNS, cron, monitor, checklist final e gatilhos de aborto. **Ensaiada:** backup
  pelo painel antigo (4 s), antigo desligado, instalação, importação e fotos (79 s), cópia "depois da
  virada" e operação real no novo (equipe criada, senhas antigas, caixa, encaixe pago, agendamento pelo
  site).
- **Retorno** ([plano-retorno.md](plano-retorno.md)): 10 passos, quando decidir e o que não volta sozinho.
  **Executado no ensaio:**
  - manutenção no novo (503);
  - cópia do novo;
  - exportação: o achado H6 foi corrigido no próprio ensaio;
  - novo desligado;
  - banco do antigo **intacto**, com o mesmo SHA-256 do desligamento;
  - antigo de volta no domínio;
  - relançamento no antigo pelas ações do painel antigo: 1 agendamento e 1 atendimento com produto, pix e
    gorjeta.

## 12. Sugestões para refinamento pós-roadmap (NÃO implementadas)

1. Encaixe: a busca de cliente recarrega a tela e perde o serviço escolhido (o treinamento orienta a
   buscar antes).
2. Link de assinatura gerado: o campo tem o rótulo "Link (opcional)", e deveria ser só leitura com
   "copiar".
3. `/agendar` sem descrição para buscadores; `/equipe` com a ordem dos títulos fora do padrão
   (acessibilidade 98).
4. E-mails de conta usam o `APP_NAME`: poderiam usar o nome cadastrado da barbearia, sem depender do `.env`.
5. Exportação de retorno: cliente que só entrou no sistema (senha refeita, último acesso) aparece como
   "alterado"; distinguir alteração de cadastro de login.
6. Fotos de clientes do antigo são guardadas mas não aparecem no sistema novo: avaliar não importá-las
   (minimizar dados, LGPD).
7. Telefone exibido sem máscara (vem da Fase 12.5).
8. Valor `1.500` (milhar sem decimais) gravado pelo antigo é ambíguo (o antigo exibia 1,50): avaliar uma
   pendência própria quando aparecer na cópia real.
9. Data de fim da assinatura gravada como `AAAA-MM-DD` na importação e `AAAA-MM-DD 00:00:00` pelo
   sistema. As comparações estão corretas (conferido), mas convém padronizar.
10. Monitoramento: avisar também o proprietário por aviso no painel (hoje só e-mail e log).

## 13. Problemas encontrados e correções

| # | Problema | Onde apareceu | Correção | Teste |
|---|---|---|---|---|
| H1 | "1.500,00" gravado pelo antigo como `1.500.00` era recusado | Ensaio da migração | `LegacyValue::money` | `DecimalAndLegacyValueTest`, `ImportScenariosTest` (caso `desp-6` no banco fictício) |
| H2 | Foto genérica importada e fotos reais com caminho antigo (imagem quebrada) | Homologação: 404 na área do profissional | `LegacyValue::photoPath` + `legacy:import-photos` | `ImportPhotosTest` |
| H3 | Cliente migrado preso na confirmação de e-mail | Homologação: login do cliente | `email_verified_at` para cliente ativo | `ImportScenariosTest` (login **pela tela**) |
| H4 | Sem funcionamento depois da migração: ninguém agendava | Homologação: "Sem dias disponíveis" | Funcionamento deduzido + `agenda.policy` do antigo | `ImportScenariosTest` |
| H5 | `manual@admin.com` virava e-mail do cliente (lembrete para terceiros) | Homologação: receptor SMTP | `LegacyValue::contactEmail` | `ImportScenariosTest` (caso `AG-MANUAL`) |
| H6 | Exportação de retorno trazia o que veio da importação (duplicaria no antigo) | Ensaio do retorno | Corte no fim da última importação | `RollbackExportTest` |
| H7 | Cópia de segurança corrompida fazia `app:backup-verify` sair com "Conferência falhou" (exceção da libzip/SQLite) em vez de **REPROVADA** | CI (Linux; no Windows a libzip devolvia erro sem exceção) | Exceção na leitura vira reprovação com o motivo | `BackupTest` (caso novo com banco ilegível; falha sem a correção) |

Na CI também foram ajustados:
- as extensões `sqlite3` e `zip` declaradas no passo do PHP (já exigidas em [instalacao.md](instalacao.md) §1);
- o passo do importador, que passou a usar um banco próprio: ele gravava no mesmo banco dos testes de
  navegador, e o funcionamento e as regras da agenda trazidos do sistema antigo (H4) mudavam a agenda desses
  testes;
- as falhas de teste (PHPUnit e Playwright), que passaram a aparecer como anotações da execução, porque o
  log do job exige login.

Fora do sistema, também foram ajustados:
- o simulador do Stripe, que passou a entregar webhooks depois de responder;
- o `artisan serve`, que falhava com o acento no caminho do usuário do Windows. A homologação passou a
  usar `php -S` com o roteador do Laravel; isso não afeta servidores reais;
- o Lighthouse, que passou a usar o Chromium do Playwright.

## 14. Testes realizados e resultados

| Verificação | Resultado |
|---|---|
| PHPUnit (suíte inteira) | **847 passaram** (818 + 29 novos: backup 10, exportação de retorno 3, fotos 2, importador 4, tela de Clientes 10) |
| PHPStan | 0 erros |
| Pint | OK |
| Build (Vite) | OK (dentro de cada pacote) |
| E2E (suíte do projeto, banco temporário) | Antes da tela de Clientes: **148 passaram**, 4 pulados (os mesmos das fases anteriores), 0 falhas (19,4 min). Com a tela de Clientes: **154 passaram** (+6), 4 pulados, 0 falhas (19,4 min), incluindo os 8 temas com lista, ficha e edição de cliente |
| Roteiros de homologação (pacote `032fc78`, dados migrados) | Desktop: 14 roteiros OK (10 do roteiro principal, incluindo promoções migradas, 1 da assinatura pelo painel e 3 de e-mail). Celular: perfis, profissional e cliente OK. Os que gravam dados compartilhados rodam só no desktop |
| Amostra da migração | 34/34 |
| Volume | 3.016 / 20.019 em 38 s, conciliação e integridade OK, reexecução idempotente |
| Stripe (simulador) | Todas as transições, reenvio, duplicado e fora de ordem OK |
| E-mails | Todos os modelos recebidos e capturados |
| Backup e restauração | Cópia, conferência, pasta nova e no lugar OK |
| Virada e retorno | Executados; antigo intacto; relançamento OK |
| Lighthouse | Desempenho 90–100 / acessibilidade 98–100 / boas práticas 100 |
| Segredos e dependências | Nenhum segredo; 0 vulnerabilidades |

Documentos:
- **novos:** [instalacao.md](instalacao.md), [operacao.md](operacao.md), [homologacao.md](homologacao.md),
  [plano-virada.md](plano-virada.md), [plano-retorno.md](plano-retorno.md),
  [treinamento/](treinamento/README.md) e [clientes.md](clientes.md);
- **atualizados:** [roadmap.md](roadmap.md), [importador.md](importador.md),
  [estrategia-migracao.md](estrategia-migracao.md) (§12.8), [mapa-banco-antigo-novo.md](mapa-banco-antigo-novo.md),
  [stripe.md](stripe.md), [emails.md](emails.md), [decisoes-pendentes.md](decisoes-pendentes.md),
  [papeis-permissoes.md](papeis-permissoes.md), [retencao-lgpd.md](retencao-lgpd.md), [homologacao.md](homologacao.md) e o README
  do sistema.

O modelo de dados **não mudou** (nenhuma migration nova).

## 15. Checklist de aceite da Fase 13 (escopo corrigido, §0)

| Critério | Situação |
|---|---|
| Ensaio geral da migração (dados fictícios) | ✅ (§3) |
| Homologação de todos os perfis e áreas, desktop e celular | ✅ (§4) |
| Tela de Clientes no painel (P13-01) com permissões, LGPD, auditoria, 8 temas e celular | ✅ (§18) |
| Treinamento por perfil (material) | ✅ |
| Plano de virada e plano de retorno escritos e ensaiados | ✅ |
| Backup automático, restauração validada, monitoramento, logs, fila, agendador | ✅ |
| Segredos fora do Git; procedimento de troca de segredos | ✅ |
| Stripe: ciclo completo | ✅ no simulador. A conta do comprador é configurada na instalação dele ([instalacao.md](instalacao.md) §4) |
| E-mail: todos os modelos e descadastro | ✅ por SMTP local. Entrega real, SPF/DKIM/DMARC: na instalação de cada comprador |
| Decisões P13-01 a P13-08 | ✅ decididas (§9) |
| CI verde | ✅ run 59 (commit `8894ad8`, com a tela de Clientes): Pint, Larastan, auditoria, PHPUnit, importador e E2E com os 8 temas. Antes: run 57 |
| 30 dias de operação estável | Critério da **primeira operação real** ([roadmap.md](roadmap.md)); não se aplica enquanto não houver produção |

## 16. O que fica para a instalação de cada comprador

Nada disto bloqueia o roadmap. É o roteiro do instalador ([instalacao.md](instalacao.md),
[plano-virada.md](plano-virada.md), [operacao.md](operacao.md)):
1. Hospedagem e domínio com os requisitos de [instalacao.md](instalacao.md) §1.
2. Se houver sistema legado: cópia autorizada pelo comprador, ensaio com ela e revisão das pendências.
3. Conta Stripe do comprador: chaves de teste, webhook de teste, ciclo completo; depois as chaves reais.
4. E-mail: Resend ou SMTP, DNS do domínio (SPF/DKIM/DMARC) e envio para uma lista-semente.
5. Testes de aceite e treinamento com a equipe da barbearia.
6. Textos legais, logo e fotos (P11-01, D-07, D-08).
7. Virada (se houver legado) e os 30 dias de acompanhamento da primeira operação real.

## 17. Riscos

| Risco | Mitigação |
|---|---|
| Dados de um legado real terem formatos que os fictícios não cobriram (como H1) | Ensaio obrigatório com a cópia do comprador antes da virada; o importador recusa o que não entende (pendência), tudo ou nada |
| Hospedagem do comprador sem cron ou SSH | Requisito documentado; sem cron não há lembrete, fila, cópia nem monitor |
| Entregabilidade de e-mail | SPF/DKIM/DMARC; teste com lista-semente; o monitor avisa e-mails parados |
| Diferenças do Stripe real em relação ao simulador (3DS, pagamento assíncrono) | Ciclo na conta de teste do comprador antes de ativar as chaves reais |
| Cópia só no servidor | Rotina semanal de cópia externa; senha e `.env` no gerenciador de senhas |
| SQLite com muitos acessos simultâneos | WAL + espera (Fases 5 a 7 testaram concorrência); acompanhar nos 30 dias da primeira operação |

## 18. Tela de Clientes no painel (P13-01)

Documentação: [clientes.md](clientes.md).

**O que faz**
- **Lista** com busca por nome, e-mail ou celular (e CPF, só para quem vê o CPF completo), filtro de
  situação (ativos, inativos, anonimizados, todos), último atendimento e paginação. Cadastros mesclados em
  outro não aparecem.
- **Ficha:**
  - contato e cadastro, com CPF mascarado para quem não tem `customers.view_cpf`;
  - situação da conta: cadastro, acesso pelo site, último acesso, lembretes e consentimento de novidades;
  - para o atendimento: próximos horários, profissionais favoritos e anotações;
  - benefícios: assinatura vigente, pontos (com `loyalty.view`), o que vale hoje e o código de indicação;
  - histórico paginado de agendamentos e de atendimentos, com link para quem pode abrir cada registro.
- **Edição** de nome, celular e nascimento (com `customers.update`). O CPF só é corrigido por quem vê o
  CPF completo: é validado e não pode repetir nem ficar em branco. Se o pedido trouxer o CPF sem essa
  permissão, ele é recusado inteiro (403).
- **Anonimização (LGPD)**, só para o proprietário (`customers.anonymize`):
  - abre uma página própria, com senha reconfirmada;
  - exige digitar ANONIMIZAR;
  - usa o mesmo `CustomerErasure` da conta do cliente;
  - os bloqueios (horário marcado, comanda aberta, assinatura vigente) aparecem com texto para a equipe.

**O que a equipe não altera, de propósito:**
- e-mail e senha: são o acesso do cliente; a troca de e-mail é dele, com confirmação;
- consentimentos: a prova é do cliente;
- situação e mesclagem.

**Nada paralelo.** A tela usa o que já existia:
- a matriz `customers.*` e a `CustomerPolicy`;
- a busca do balcão (`CustomerLookup`, que ganhou a versão paginada);
- o extrato de pontos e o motor de promoções;
- o `CustomerErasure`;
- a auditoria do model, com CPF mascarado.

**Mudanças de regra (pequenas):**
- a `CustomerPolicy` passou a negar edição e anonimização de cadastro já anonimizado ou mesclado;
- o `CustomerErasure::blockers` ganhou o texto para a equipe.

Nenhuma migration nova.

**Permissões** (sem mudança na matriz):

| Papel | Lista e ficha | Editar | CPF completo e correção | Anonimizar |
|---|:-:|:-:|:-:|:-:|
| Proprietário | ✅ | ✅ | ✅ | ✅ (senha reconfirmada) |
| Gerente | ✅ | ✅ | ✅ | — |
| Recepção | ✅ | ✅ | — (mascarado) | — |
| Financeiro | — (403) | — | — | — |
| Profissional | — (403; vê os próprios clientes pela área dele) | — | — | — |

**Testes**
- PHP (`PanelCustomersTest`, 10 testes):
  - acesso por papel e menu;
  - busca, com CPF só para quem pode;
  - filtro e mesclados;
  - ficha com histórico, benefícios e anotação;
  - CPF mascarado × completo;
  - edição auditada, com e-mail, situação e consentimento intocáveis;
  - CPF recusado sem permissão;
  - CPF validado e sem duplicar;
  - celular validado;
  - anonimização só do dono, com senha e palavra, auditada e sem desfazer;
  - bloqueio com horário marcado.
- Navegador (`tests/e2e/clientes.spec.js`, celular e desktop):
  - a recepção busca, abre a ficha (CPF mascarado, anotação, atendimento) e edita;
  - o financeiro não vê a tela;
  - o dono anonimiza com senha reconfirmada.
- Temas: a varredura dos 8 temas (`temas.spec.js`) passou a incluir a lista, a ficha e a edição, no
  desktop e no celular. Ela confere tema, contraste, ausência de rolagem lateral e axe.

**Resultados**
- PHPUnit: 847 passaram (837 + 10).
- PHPStan: 0 erros. Pint: OK.
- E2E completo: 154 passaram, 4 pulados, 0 falhas (19,4 min).

Na primeira rodada completa, um teste antigo da Fase 12.5 falhou uma vez: `profissional.spec.js`, no
celular. O axe mediu o contraste do menu fixo do profissional com o fundo da página, e não com o fundo
grafite do menu. Repetido isolado, passou 3 de 3 vezes. Na segunda rodada completa, passou junto com tudo.
Não tem relação com a tela de Clientes e fica registrado como intermitente.

**Capturas:** `storage/e2e/telas/{celular,desktop}-clientes-*.png`, geradas pelos testes e fora do Git.
