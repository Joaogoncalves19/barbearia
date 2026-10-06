# Retenção de dados e LGPD

> Política de retenção do novo sistema, reunida num lugar só. Princípio do dono: **não guardar dados pessoais
> indefinidamente por conveniência**; guardar o necessário para auditoria técnica, financeira ou obrigação
> legal. Toda rotina é **automática, idempotente e auditável**. Exclusão de conta e "exportar meus dados" são
> da Fase 12.

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

## 4. Pendências (Fase 12)

- Exclusão de conta (anonimização do cliente), incluindo `email_messages.to_email/to_name` do cliente.
- Exportar meus dados.
- Política de privacidade publicada no site: o texto é do dono (página `/privacidade` só aparece quando
  preenchida no painel; ver [relatorio-fase-11.md](relatorio-fase-11.md#3-decisões)).
