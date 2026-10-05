# Benefício da assinatura (Fase 9)

## 1. O que o assinante ganha (D-44)

| Item | Regra |
|---|---|
| Serviços com benefício | Os incluídos na **versão contratada** do plano |
| Desconto | 100% do serviço incluído (sai de graça) |
| Limite | **Sem limite** de uso |
| Periodicidade | Enquanto houver direito (data paga; ver [assinaturas.md §4](assinaturas.md#4-direito-ao-benefício)) |
| Validade | Na data do serviço (agendamento: a data do horário; balcão: hoje) |
| Acúmulo | Não acumula de um mês para o outro (não há saldo de usos) |
| Serviço não incluído | Cobrado normalmente |
| Combo | Serviço comum (D-24): só sai de graça se estiver incluído (P9-03) |
| Produto | Nunca tem benefício (como todo desconto) |

## 2. Combinação com outros descontos (D-45)

O benefício é **mais um candidato** do motor único de promoções (Fase 8, [promocoes.md](promocoes.md)): vale
**um desconto só, o maior**.

| Junto com | Resultado |
|---|---|
| Cupom | Vale o maior. Ex.: Corte incluído (R$ 50) × cupom 50% (R$ 25): vale a assinatura; o cupom não é reservado |
| Pontos de fidelidade | Vale o maior; os pontos não são reservados se a assinatura vencer (R-11) |
| Aniversário / indicação | Vale o maior |
| Desconto manual | Vale o maior (D-42) |
| Vale-presente | Não concorre: é forma de pagamento (D-41) e paga o que sobrar |
| Empate | Vale a assinatura (não gasta nada), depois aniversário, indicação, cupom, pontos, manual |

Ponto em aberto: um atendimento com serviço incluído e serviço não incluído recebe **um** desconto só; se a
assinatura vencer, o não incluído paga cheio, mesmo que exista um cupom. É a regra decidida (um desconto só);
registrado para o dono confirmar no uso real.

## 3. Onde é aplicado

- **Agendamento** (site e equipe): automático, como aniversário e indicação; a confirmação mostra o desconto e o
  total, o mesmo valor gravado (critério da Fase 8).
- **Adesão no agendamento**: entra quando o Stripe confirma o pagamento.
- **Abertura do atendimento**: se o direito começou depois do agendamento (adesão, renovação), o benefício é
  reavaliado (vale o maior). Se o direito acabou (cancelamento imediato), o benefício sai, com registro.
- **Comanda**: serviço incluído acrescentado no balcão também sai de graça (o desconto acompanha os itens
  enquanto o atendimento está aberto, como no sistema antigo).
- **Conclusão**: benefício sem direito hoje é recusado com o motivo (a equipe retira e o cliente vê o novo
  total); nunca é cobrado diferente em silêncio.

## 4. Comissão e fidelidade

- **Comissão (D-46):** serviço coberto tem comissão sobre o **preço de tabela**: regra de assinante do
  profissional (percentual, valor fixo por atendimento, sem comissão) ou, sem regra de assinante, a regra
  normal do serviço. O desconto da assinatura é atribuído só aos serviços cobertos; os outros itens têm
  comissão sobre o cobrado. A regra se configura em **Comissões › Regras** ("Atendimento de assinante"). Ver
  [comissoes.md](comissoes.md).
- **Fidelidade (R-19):** assinante com direito na data **não acumula pontos**; o bônus de indicação de quem o
  indicou continua.

## 5. Testes

`SubscriptionBenefitTest` (13): grátis com prévia = gravado = cobrado; sem limite; vale o maior com cupom;
serviço não incluído cobrado; comissão na tabela (normal, percentual, fixa por atendimento, sem comissão);
sem pontos; benefício acompanha a comanda; cancelada na hora sai do atendimento; sem direito na conclusão é
recusado; adesão no agendamento; adesão pelo site; sem Stripe a adesão não aparece; depois do fim pago não vale.
