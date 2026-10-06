# Área do cliente

> Fase 12. A conta do cliente (`/minha-conta`) usa o **mesmo domínio** do resto do sistema: agenda
> (`BookingService`, `Availability`), atendimento e comprovantes (`Receipts`), promoções e fidelidade
> (`PromotionEngine`, `LoyaltyLedger`), assinatura (`SubscriptionManager`, `SubscriptionBenefits`), avaliações
> (`Reviews`), comunicação (`CommunicationPreferences`, avisos). Nenhuma regra é repetida na tela ou no
> JavaScript: a conta só mostra e chama os serviços. Relatório: [relatorio-fase-12.md](relatorio-fase-12.md).

## 1. Telas

| Tela | Rota | O que mostra / faz |
|---|---|---|
| Início | `/minha-conta` | Próximos horários (até 3) e um resumo de cada parte: avisos não lidos, avaliações a fazer, benefícios valendo hoje, assinatura, último comprovante |
| Agendamentos | `/minha-conta/agendamentos` | **Próximos** (abertos e ainda não terminados) e **histórico** paginado: data, serviço, profissional, valor gravado, situação |
| Detalhe do horário | `/minha-conta/agendamentos/{código}` | Itens e preço **registrados no agendamento**, descontos, total, situação, prazos de cancelar/remarcar e quantas remarcações já foram usadas; botões só quando a ação é permitida |
| Remarcar | `/minha-conta/agendamentos/{código}/remarcar` | Horários livres pela `Availability` (canal do cliente) só com profissionais publicados (P11-03) |
| Comprovantes | `/minha-conta/comprovantes` | Atendimentos concluídos do cliente (com ou sem agendamento) → comprovante, impressão e envio ao e-mail da própria conta |
| Benefícios | `/minha-conta/fidelidade` | "Vale para você hoje" (assinatura, aniversário, indicação, pontos para resgate), saldo e extrato de pontos, código de indicação |
| Assinatura | `/minha-conta/assinatura` | Plano, situação, benefício hoje, período atual, **próxima cobrança** (quando renova), serviços incluídos, histórico relevante, pagamentos e reembolsos, cancelar renovação e reativar |
| Avaliações | `/minha-conta/avaliacoes` | Atendimentos que ainda podem ser avaliados e as avaliações feitas, com a situação e a resposta da barbearia |
| Avisos | `/minha-conta/avisos` | Avisos do serviço (lembrete, avaliação, assinatura), marcar como lidos |
| Meus dados | `/minha-conta/dados` | Nome, celular, nascimento; preferências de e-mail; acesso (e-mail, CPF mascarado, senha), trocar e-mail, alterar/criar senha |
| Privacidade | `/minha-conta/privacidade` | O que a barbearia guarda e por quanto tempo, histórico das escolhas de e-mail, **baixar meus dados**, **excluir a conta** |

Menu: no desktop, lateral; no celular, uma faixa de atalhos que rola **só dentro dela** (a página nunca rola
de lado) e já abre mostrando o item atual.

## 2. Dados pessoais

- O cliente altera **nome, celular e nascimento** (`ProfileController`), sempre no próprio cadastro (nunca
  um id enviado). Celular de outro cadastro é recusado.
- **CPF**: sempre mascarado em toda tela da conta e na exportação; não muda pela conta (correção só pela
  equipe). Testado em todas as telas (`test_cpf_nunca_aparece_inteiro_em_tela_da_conta`).
- **E-mail** ([§6](#6-ações-sensíveis)): troca própria, confirmada pelo link no endereço novo.
- Fora do alcance do cliente, por construção: status, consentimento (só pelas preferências, com prova),
  identificadores, histórico, valores, comissões, atendimentos, auditoria, dados da equipe.
- **Foto do cliente**: não implementada (minimização; decisão P12-02 em aberto).

## 3. Agendamentos, cancelamento e remarcação

Nada de regra nova: cancelar e remarcar chamam o `BookingService` com o canal do cliente, que aplica a
política existente (cancelar até 2 h antes, remarcar até 2 h antes, no máximo 2 remarcações por horário,
disponibilidade, conflitos). A `AppointmentPolicy` diz **quem** (só o dono; horário alheio = 404; já
passado = 403).

**P11-03:** a lista de profissionais da remarcação vem de `ProfessionalDirectory::customerBookableFor`
(serviço e profissional publicados no site). Se o profissional do horário saiu do site, a tela avisa e
oferece só os outros; enviar o slug dele dá 404, e a `Availability` recusaria de qualquer jeito. A equipe
continua remarcando com qualquer profissional ativo. Retirar o profissional do site não toca em nenhum
agendamento, atendimento, pagamento, avaliação ou comissão (teste compara as tabelas antes/depois).

## 4. Comprovantes, assinatura, benefícios

- **Comprovantes**: os mesmos do balcão (`Receipts`, Fase 8). O envio por e-mail vai só para o endereço da
  própria conta, com chave de idempotência e limite por hora.
- **Assinatura**: nenhuma mudança direta de situação; cancelar e reativar chamam o `SubscriptionManager`
  (Stripe quando a assinatura é do Stripe). "Próxima cobrança" aparece só quando a assinatura renova (ativa
  ou em atraso, sem cancelamento agendado), com a data do Stripe quando houver. O histórico esconde eventos
  técnicos (sincronização, início de checkout) e nunca mostra observações da equipe.
- **Benefícios**: `PromotionEngine::entitlements` responde, com as mesmas regras do orçamento, o que vale
  hoje para o cliente. A confirmação do agendamento continua recalculando tudo no servidor e aplicando **um**
  desconto, o maior; valores enviados pelo navegador (pontos, total esperado) só servem para conferir.
  Cupons são códigos divulgados (conferidos quando informados) e vales-presente não são ligados a uma conta:
  por isso não há lista de "meus cupons/vales".

## 5. Avaliações e avisos

- **Avaliações** (sem mudança de regra, Fase 10): só atendimento concluído do próprio cliente, uma por
  atendimento, no prazo; comentário sempre texto (escapado). Lista de pendentes = `Reviews::pendingFor`
  (a mesma no início e na tela de avaliações).
- **Avisos**: só do serviço (transacionais). Marketing nunca vira aviso: novidades e promoções chegam só por
  e-mail, com consentimento, e a tela diz isso. Abrir qualquer tela não muda consentimento nem preferência
  (`test_abrir_as_telas_nao_muda_consentimento_nem_preferencias`).

## 6. Ações sensíveis

Exportar os dados, excluir a conta e pedir a troca de e-mail passam pelo middleware `customer.reauth`
(`EnsureCustomerRecentlyConfirmed`): pede a senha de novo se a última confirmação tem mais de 15 min
(`AUTH_CUSTOMER_REAUTH_MINUTES`). Cliente sem senha (só link mágico) cria uma antes. Tentativas limitadas
(`password-check`).

### Baixar meus dados (LGPD)

`CustomerDataExport`: um JSON gerado na hora (nada fica salvo) com cadastro, preferências e prova de
consentimento, agendamentos, atendimentos e pagamentos, fidelidade, assinaturas e pagamentos, avaliações,
avisos e o registro dos e-mails (sem corpo, que nunca é guardado). Fica de fora o que é da barbearia ou de
outras pessoas: custos, anotações internas, quem da equipe recebeu, dados de quem o cliente indicou (só a
quantidade), ids internos. CPF mascarado (P12-01). Auditoria `customer.data_exported`; limite de 5 por hora.

### Excluir a conta

`CustomerErasure` (detalhes em [retencao-lgpd.md](retencao-lgpd.md#5-exclusão-de-conta)): anonimiza numa
transação, com o cadastro travado; bloqueia com horário marcado, atendimento aberto ou assinatura vigente;
pede a palavra **EXCLUIR**; encerra a sessão; sessões em outros aparelhos caem na requisição seguinte
(`customer.active`). Idempotente; prova em `customer_erasures` e na auditoria, sem dado pessoal.
A equipe pode fazer o mesmo por pedido na barbearia (habilidade `customers.anonymize`, só o proprietário):
o serviço aceita o usuário da equipe como autor, mas **a tela do painel não existe** (não há tela de
clientes no painel no roadmap; decisão P12-03).

### Trocar o e-mail

`CustomerEmailChange`: o pedido grava o endereço pendente e o **hash** de um token; o link vai para o
endereço novo e vale 60 min (`AUTH_EMAIL_CHANGE_MINUTES`), uma vez, só na mesma conta conectada. Abrir o
link mostra; confirmar é um POST (leitores de e-mail que "visitam" links não trocam nada). Endereço de outro
cadastro: mesma resposta, nada enviado (sem revelar que existe); a unicidade é conferida de novo ao
confirmar. Depois da troca: e-mail já confirmado, links de acesso e pedidos de senha antigos invalidados,
aviso ao endereço antigo (sem mostrar o novo), auditoria `customer.email_changed`.

## 7. Segurança (resumo)

- **Nega por padrão**: todas as rotas em `auth:customer` + `customer.active` + `auth.session` +
  `can:account.access` + `no-store`; ações com registro na URL têm `can:` com a Policy (alheio = 404).
- **IDOR**: `CustomerAreaAccessTest` percorre **toda** rota `account.*` com parâmetro com uma cliente que não
  é a dona (404 e nada muda) e falha se uma rota nova com parâmetro não entrar na lista.
- **IDs previsíveis**: agendamento e atendimento aparecem na URL pelo código público aleatório
  (`AG-…`, `AT-…`), nunca pelo id; o id numérico dá 404.
- **Nada vem do navegador**: preços, descontos, pontos, elegibilidade e situação são recalculados.
- Limites: reservar/remarcar/cancelar (`booking`), comprovante por e-mail (`receipts`), preferências e
  avaliações (`account-actions`), exportar (`data-export`), troca de e-mail (`email-requests`), link do
  e-mail (`token-use`), senha (`password-check`).
- Ver também [seguranca.md](seguranca.md) e [papeis-permissoes.md](papeis-permissoes.md).

## 8. Testes

PHP (`tests/Feature/Account`): `CustomerAreaAccessTest` (IDOR, listas, consentimento, CPF, preço
registrado), `AccountErasureTest` (anonimização, financeiro mantido, bloqueios, senha de novo, sessão,
auditoria sem nome), `EmailChangeAndExportTest`, `CustomerAreaFeaturesTest` (P11-03, histórico intacto,
benefícios, assinatura, avisos, listas). Navegador (`tests/e2e/area-cliente.spec.js`, celular e desktop):
início, agendamentos, remarcar e cancelar, comprovante, todas as telas com axe e sem rolagem lateral, horário
alheio pela URL (404), baixar os dados e excluir a conta.
