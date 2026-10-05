# Fidelidade, aniversário e indicação (Fase 8)

Pontos são um **razão só de inclusão** (`loyalty_entries`): saldo = soma dos lançamentos. Nada é editado ou
apagado; correção é lançamento novo com motivo e autor. A escolha do desconto (pontos × cupom × aniversário ×
indicação × manual) é do motor único: ver [promocoes.md](promocoes.md).

## 1. Ganho (R-17, R-19)

- Na **conclusão** do atendimento, na mesma transação, para cliente cadastrado e com o programa ativo.
- Modo **por atendimento**: N pontos fixos. Modo **por valor**: 1 ponto a cada R$ X do total cobrado
  (arredondado para baixo; produtos contam, descontos não).
- Um lançamento de ganho por atendimento (`unique(attendance_id, kind)`): concluir de novo não duplica.
- R-19 "assinante ativo não acumula": assinaturas são da Fase 9; a regra entra lá.

## 2. Resgate (R-18) e decisão do dono D-43

- O cliente pede o resgate **ao agendar** (site) ou **no balcão**. Os pontos ficam **reservados**
  (`loyalty_redemptions`, `reserved`): continuam no saldo, mas saem do **disponível**
  (disponível = saldo − reservados em outros agendamentos).
- Os pontos **saem do saldo só na conclusão** (lançamento `redeemed`, negativo, apontando o resgate).
- Cancelamento, falta ou troca por um desconto maior **liberam** a reserva (nenhum lançamento).
- Recompensas configuráveis: percentual sobre o serviço mais barato, o mais caro ou o total dos serviços;
  valor fixo; ou serviço grátis (o mais caro). O resgate guarda a recompensa usada (fotografia).
- Os mesmos pontos nunca são prometidos duas vezes: trava no cliente (`customers.loyalty_version`) e
  sentinela `active_key` (`a{agendamento}`) única. Testado com processos reais.

## 3. Ajuste manual

`Promoções › Pontos de clientes` → cliente → "Ajustar pontos" (`loyalty.adjust`: proprietário e gerente).
Crédito ou débito, motivo obrigatório (≥ 3 caracteres), chave de requisição única (duplo clique = um
ajuste), auditado (`loyalty.adjusted`). Débito nunca deixa o **disponível** negativo (pontos prometidos em
resgates reservados são protegidos).

## 4. Aniversário (R-14)

Desconto percentual quando a data do atendimento cai no mês do aniversário do cliente (data de nascimento do
cadastro). Uma vez no mês: o primeiro agendamento ou atendimento que usar; cancelado ou falta devolve o
direito. Desligado por padrão.

## 5. Indicação (R-15; D-14 decidida: implementar)

- Todo cliente novo ganha um **código de indicação** (8 caracteres sem ambiguidade). Clientes antigos geram
  o seu em **Minha conta › Fidelidade** (uma vez).
- Quem se cadastra com um código válido fica ligado a quem indicou (código inválido é ignorado em silêncio:
  o cadastro não falha e não revela se o código existe).
- O indicado tem desconto percentual no **primeiro** atendimento (enquanto não houver atendimento concluído
  nem outro agendamento em aberto com esse desconto).
- Quem indicou ganha os pontos de bônus quando o indicado **conclui** o primeiro atendimento, **uma vez** só
  (`loyalty_entries.referred_customer_id` único).
- Desligada por padrão.

## 6. Telas

| Tela | Quem |
|---|---|
| Minha conta › Fidelidade (saldo, disponível, extrato, como ganhar e resgatar, código de indicação) | o próprio cliente |
| Confirmação do agendamento: "Usar meus pontos" (mostra o disponível) | o próprio cliente |
| Promoções › Pontos de clientes (busca, saldo, extrato, reservas) | `loyalty.view`: proprietário, gerente, recepção, financeiro |
| Ajustar pontos | `loyalty.adjust`: proprietário, gerente |
| Promoções › Fidelidade e aniversário (configuração) | `promotions.configure`: só proprietário |

## 7. Importação

Pontos do sistema antigo continuam como na Fase 2 (histórico + abertura conciliada com o saldo antigo).
As configurações antigas viram a `promotions.policy` na primeira importação.

## 8. Testes

`PromotionRulesTest` (reserva, dupla reserva, débito na conclusão, ganho por valor, ajuste, aniversário,
indicação), `DiscountCasesTest` (saldo esperado em cada uma das 32 combinações),
`PromotionConcurrencyTest::test_os_mesmos_pontos_pedidos_em_tres_agendamentos_ao_mesmo_tempo`,
`PromotionsPanelTest` (ajuste pela tela, conta do cliente, cadastro por indicação).
