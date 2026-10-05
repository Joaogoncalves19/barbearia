# Comprovantes impressos e por e-mail (Fase 8)

Pedido do dono depois da aprovação da Fase 7 (D-40 revista): comprovantes **impressos** e com **envio por
e-mail** para quatro documentos.

| Documento | Onde abrir | Quem vê / envia | Condição |
|---|---|---|---|
| Atendimento do cliente | ficha do atendimento › "Comprovante"; Minha conta › atendimento › "Imprimir" | quem vê o atendimento (policy do atendimento); o próprio cliente | só atendimento **concluído** |
| Repasse ao profissional (com linhas de assinatura) | ficha do repasse › "Recibo" | quem vê o repasse (`payouts.view`, ou o próprio profissional); alheio = 404 | — |
| Vale-presente | ficha do vale › "Imprimir ou enviar" | `gift_cards.view` | — |
| Fechamento de caixa | caixa fechado › "Comprovante" | `cash.view` | só caixa **fechado** |

## 1. Como funciona

- `app/Modules/Receipts/Services/Receipts.php` monta os dados de cada documento a partir do registro
  gravado (valores históricos, nunca recalculados). O cabeçalho usa os dados da barbearia já importados
  (`legacy.config_geral`: nome, endereço, telefone).
- A **mesma** parte de tela (`resources/views/receipts/{tipo}.blade.php`) é usada na página de impressão e
  no e-mail: o que se imprime é o que se envia.
- Página de impressão: botão "Imprimir" (`window.print`, sem script inline) e formulário de e-mail. Na
  impressão (`@media print`) só o comprovante aparece.
- E-mail: `ReceiptMail` vai para a **fila** (não segura a tela) e, ao ser enviado, reconstrói os dados a
  partir do tipo e do número do documento (a fila não guarda dados pessoais).

## 2. Envio por e-mail

- Equipe: informa o endereço (sugestão: e-mail do cliente, do profissional, do presenteado ou de quem fechou
  o caixa).
- Cliente: envia **só para o e-mail da própria conta** (o endereço não vem do formulário).
- Cada envio fica registrado em `receipt_deliveries` (documento, endereço, quem pediu) e auditado
  (`receipt.emailed`) com o endereço **mascarado** (`jo***@exemplo.test`).
- Chave de requisição única: duplo clique = um envio.
- Limite `throttle:receipts`: 10 por minuto e 60 por hora por usuário (e IP).
- Entrega de verdade depende do provedor de e-mail (D-05, Fase 10). Em desenvolvimento, `MAIL_MAILER=log`.

## 3. Segurança

Sem dados de outros clientes (a policy da rota decide antes do controlador); documento aberto (atendimento
em andamento, caixa aberto) = 404; nenhum segredo nem dado de cartão no comprovante; CSP sem estilo ou
script inline nas páginas (o e-mail usa `<style>` próprio porque leitores de e-mail não carregam CSS).

## 4. Testes

`ReceiptsTest` (7): os quatro documentos imprimem; só documento encerrado; permissão igual à da tela do
documento; envio em fila, registrado, auditado com endereço mascarado e idempotente; e-mail igual ao
comprovante; cliente só para o próprio e-mail (alheio = 404); limite de envios. E2E: comprovante do vale e
impressão sem a barra de ações.
