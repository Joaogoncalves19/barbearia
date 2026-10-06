# Consentimento, preferências, descadastro e LGPD (Fase 10)

> O que o cliente escolhe receber, como isso é provado e como é respeitado em cada envio.

## 1. Estado do consentimento

| Finalidade | Onde fica | Estados | Quem muda |
|---|---|---|---|
| Marketing por e-mail | `customers.marketing_email_consent` | desconhecido · concedido · revogado | só o próprio cliente (conta, link de descadastro) |
| Lembretes por e-mail | `customers.email_reminders_enabled` | ligado (padrão) · desligado | só o próprio cliente (conta) |
| Supressão do endereço | `email_suppressions` | descadastro de marketing · devolução · reclamação | descadastro; provedor (D-05) |

- **"Desconhecido" nunca vira "concedido" sozinho**, nem "revogado": salvar "Meus dados" sem escolher nada
  não muda o marketing (`test_salvar_sem_escolher_nao_inventa_consentimento`). Nada é presumido.
- A equipe **não** muda consentimento de cliente.
- Toda mudança gera prova em `consent_records` (só inclusão): finalidade (`marketing_email` ou
  `reminder_email`), ação, origem (`account_preferences`, `unsubscribe_link`), data e evidência (IP na conta;
  "link" ou "one_click" no descadastro).

## 2. Preferências na conta

**Minha conta → Meus dados → E-mails que você recebe**:

- **Lembretes do meu horário por e-mail** (liga/desliga).
- **Novidades e promoções por e-mail**: "Quero receber" / "Não quero receber" (sem escolha marcada enquanto
  for desconhecido).
- O texto deixa claro que confirmação, remarcação, cancelamento, comprovantes e avisos da assinatura sempre
  chegam (são necessários ao serviço).

Aceitar de novo remove só a supressão por descadastro (nunca a de devolução ou reclamação).

## 3. Descadastro

| Caminho | Como |
|---|---|
| Link "Não quero mais receber" do e-mail de campanha | Página assinada (`/descadastro/{id público}?signature=…`). Abrir só mostra; o botão (POST com CSRF) descadastra |
| Um clique do leitor de e-mail (Gmail, Outlook…) | `List-Unsubscribe` + `List-Unsubscribe-Post`: POST assinado em `/descadastro/{id}/um-clique`, fora do grupo web (sem sessão nem CSRF; a assinatura é a garantia) |
| Conta | "Não quero receber" em Meus dados |

Os três revogam o consentimento, põem o e-mail na supressão e registram a prova. Repetir não registra de novo.
A URL leva o **identificador público** do cliente (nunca o id interno nem o e-mail) e é assinada com a
`APP_KEY`: trocar o identificador dá 403. Limite por IP (`throttle:email-links`).

**Descadastro do marketing não bloqueia e-mail necessário ao serviço** (testado).

## 4. Respeitado em cada envio

| Momento | Conferência |
|---|---|
| Público da campanha | só concedido e fora da supressão |
| Lote da campanha | de novo, cliente por cliente |
| Envio (fila central) | de novo: marketing precisa de concedido, mesmo e-mail e nenhuma supressão; transacional só é bloqueado por devolução/reclamação; lembrete respeita a preferência |

## 5. LGPD e retenção

| Dado | Regra |
|---|---|
| Registro de e-mails | Guarda endereço, nome, modelo e **parâmetros** (identificadores). Nunca o corpo, senha, token ou link de acesso. Painel mostra o endereço mascarado |
| Erros do provedor | Credenciais mascaradas antes de gravar e de ir para o log |
| Prova de consentimento | Guardada (só inclusão) |
| Eventos do Stripe | **12 meses** com o corpo; depois a rotina diária remove o corpo e mantém o necessário (P9-10, [assinaturas.md §11](assinaturas.md#11-retenção-dos-eventos-do-stripe-decisão-do-dono-p9-10)) |
| Registro de e-mails, destinatários de campanha e avisos | **12 meses** (P10-03, decisão do dono); depois a rotina diária anonimiza o e-mail (endereço, nome, assunto, erro) e o destinatário, e apaga os avisos. Fica o necessário para auditoria |

A política completa de retenção está em [retencao-lgpd.md](retencao-lgpd.md). A **anonimização do cliente**
(exclusão de conta, Fase 12, `CustomerErasure`) anonimiza também `email_messages.to_email/to_name` dele,
mantém a prova de consentimento e acrescenta uma revogação "exclusão da conta" sem o endereço (o opt-out da
R-33). Na conta, **Privacidade** mostra o histórico das escolhas do cliente; abrir qualquer tela nunca muda
consentimento (testado).
