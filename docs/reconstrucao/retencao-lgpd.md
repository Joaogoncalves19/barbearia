# Retenção de dados e LGPD

> Política de retenção do novo sistema, reunida num lugar só. Princípio do dono: **não guardar dados pessoais
> indefinidamente por conveniência**; guardar o necessário para auditoria técnica, financeira ou obrigação
> legal. Toda rotina é **automática, idempotente e auditável**. Exclusão de conta e "exportar meus dados":
> §5 e §6 (Fase 12).

## 1. Prazos

| Dado | Prazo com conteúdo pessoal | Depois do prazo | Rotina | Decisão |
|---|---|---|---|---|
| Eventos do Stripe (`gateway_events`) | 12 meses | O corpo do evento (nome, e-mail, endereço de cobrança) é apagado; ficam ID do evento (reenvio continua reconhecido), tipo, situação, resultado, erro técnico, datas, tentativas, assinatura local | `app:communication retention` (diária, 03:30) | P9-10 |
| Registro de e-mails (`email_messages`) | 12 meses | Endereço vira `[removido]`; nome, assunto e erro técnico apagados. Ficam modelo, tipo, situação, datas, tentativas, provedor, vínculos (cliente, agendamento, campanha) e parâmetros (só identificadores) — prova, por exemplo, de que uma campanha só saiu com consentimento | idem | P10-03 |
| Destinatários de campanha (`campaign_recipients`) | 12 meses | Endereço vira `[removido]`; ficam situação e motivo | idem | P10-03 |
| Avisos na conta (`customer_notifications`) | 12 meses | Apagados (sem valor de auditoria; o acontecimento continua no histórico de origem) | idem | P10-03 |
| Prova de consentimento (`consent_records`) | **Mantida** | — (obrigação legal: provar o aceite e a revogação) | — | LGPD |
| Histórico financeiro (pagamentos, comissões, caixa, assinaturas) | **Mantido** | — (obrigação fiscal/contábil) | — | estrategia-historico.md |
| Trilha de auditoria (`audit_logs`) | Mantida | Campos sensíveis já gravados mascarados | — | auditoria.md |
| Imagens do site | Enquanto publicadas | Remover no painel apaga o arquivo (sem metadados desde o envio) | manual | imagens.md |

Cada execução que altera algo grava na auditoria: `retention.gateway_events` e `retention.communication`
(quantidades e a data limite, nunca os dados). O verificador de integridade acusa o que passou do prazo sem
ser tratado (R51 e R52, com um dia de folga).

## 2. O que nunca é guardado

- Senha, token, chave de API ou link de acesso no registro de e-mails, no banco de configurações ou no log
  (varredura de segredos em `settings`; mascaramento em `Outbox::safeError` e no log de falhas da fila).
- Corpo do e-mail (montado na hora do envio a partir dos parâmetros).
- Metadados de foto (EXIF/GPS): descartados no reprocessamento de toda imagem enviada.
- Dados de cliente nas páginas públicas (testado: nome completo, e-mail, CPF, usuários da equipe).

## 3. Consentimento

Ver [consentimento.md](consentimento.md): "desconhecido" nunca vira "concedido"; marketing só com
consentimento concedido; descadastro em um clique; prova de cada mudança.

## 4. Pendências

- Política de privacidade publicada no site: o texto é do dono e precisa estar preenchido e revisado antes
  da publicação definitiva (P11-01); a página `/privacidade` só aparece quando preenchida no painel.
- Dados no Stripe (cliente, cartão, faturas) ficam com o Stripe: a exclusão de conta aqui não apaga lá
  (P12-04).

## 5. Exclusão de conta

Fase 12, `CustomerErasure` (R-33; telas em [area-do-cliente.md](area-do-cliente.md#excluir-a-conta)). É uma
**anonimização**: o cadastro continua existindo, sem nada que identifique a pessoa, para que o histórico
financeiro continue ligado a ele.

| Onde | O que acontece |
|---|---|
| Cadastro (`customers`) | Nome vira "Cliente removido"; e-mail, confirmação, celular, CPF, nascimento, foto (arquivo apagado), senha, "lembrar-me", código de indicação e troca de e-mail pendente apagados; marketing revogado, lembretes desligados, inativo, `anonymized_at` |
| Agendamentos e atendimentos | Ficam (agenda, caixa, comissão, relatórios), com o nome trocado, sem e-mail, celular e observações; no histórico de eventos, o autor vira "Cliente" |
| Pagamentos, comissões, assinaturas e pagamentos da assinatura | **Mantidos** sem alteração (obrigação fiscal; não têm o nome) |
| Avaliações | Ficam, **anônimas** (no site o autor aparece como "Cliente"), com nota e comentário (R-33) |
| E-mails (`email_messages`), destinatários de campanha, comprovantes enviados | Endereço vira `[removido]`; nome, assunto e erro apagados; os que estavam na fila não saem |
| Eventos do Stripe das assinaturas dele | Corpo apagado (como na retenção P9-10) |
| Avisos, anotações da equipe, favoritos, links de acesso, pedidos de nova senha, candidatos a mesclagem pendentes | Apagados |
| Auditoria | Os registros ficam (quem fez o quê e quando); saem nome, e-mail, celular, CPF mascarado, nascimento e observações dos valores do cadastro, dos agendamentos e dos atendimentos dele; nas ações feitas por ele, o autor vira "Cliente" e o IP sai |
| Prova de consentimento | Mantida (obrigação legal) + uma revogação "exclusão da conta", sem o endereço |
| Pessoas que ele indicou | Nada muda nelas |

Bloqueia enquanto houver horário marcado, atendimento aberto ou assinatura vigente (inclusive aguardando
pagamento). Prova do pedido em `customer_erasures` (quem pediu: cliente ou equipe; quantidades) e na
auditoria (`customer.anonymized`), sem dado pessoal. Idempotente.

**Pedido feito na barbearia (Fase 13, P13-01):**
- quem anonimiza é o proprietário, pela ficha do cliente no painel (*Clientes → Anonimizar cadastro*);
- o fluxo pede a senha reconfirmada e a palavra ANONIMIZAR, e usa o mesmo serviço e as mesmas regras;
- os bloqueios aparecem com texto para a equipe;
- no painel, quem não tem `customers.view_cpf` vê o CPF mascarado ([clientes.md](clientes.md)).

## 6. Exportar meus dados

Fase 12, `CustomerDataExport`: JSON gerado na hora e entregue para download (nada fica no servidor),
pedido com a senha de novo, no máximo 5 por hora, registrado na auditoria (`customer.data_exported`, só
quantidades). Conteúdo e o que fica de fora: [area-do-cliente.md](area-do-cliente.md#baixar-meus-dados-lgpd).
