# Treinamento — Financeiro

**Você usa:** Atendimentos (consulta), Caixa (consulta), Comissões, Repasses, Histórico financeiro,
Relatórios, Assinaturas (pagamentos e reembolsos), estorno de pagamento e cancelamento de vale-presente.
**Você não vê:** agenda e cadastro de clientes (não precisa deles para fechar as contas).

## Rotina

1. **Conferir o caixa do dia** — *Caixa*: abertura, entradas por forma de pagamento, suprimentos,
   sangrias e a diferença do fechamento (quem fechou e quanto contou). ✔
2. **Comissões** — *Comissões* mostra o saldo de cada profissional (comissão + gorjeta − vales − repasses).
   Abra o profissional para ver o extrato linha a linha. ✔
3. **Repasse** — no profissional, *Registrar repasse*: valor e forma. Repasse em dinheiro sai do caixa
   aberto. O profissional vê o recibo na área dele. ✔
4. **Vale (adiantamento)** — no profissional, *Lançar vale*; é abatido no próximo repasse.
5. **Ajuste** — correção de comissão ou gorjeta sempre com motivo; fica no histórico.

## Estornos

- **Atendimento**: abra o atendimento concluído → no pagamento, *Estornar*. Informe quanto da devolução
  era gorjeta; o resto reduz a comissão proporcionalmente (automático). ✔
- **Assinatura**: *Assinaturas → pagamento → Reembolsar*; o pedido vai ao Stripe e a tela mostra quando
  ele confirma.

## Regras que valem sempre

- A taxa da maquininha **nunca** é descontada da comissão nem da gorjeta.
- Gorjeta e comissão são valores separados.
- Nada é apagado: estorno e correção criam um lançamento novo, com quem fez e por quê.
