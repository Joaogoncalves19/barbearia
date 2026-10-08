# Treinamento — Recepção

**Você usa:** Hoje, Agenda, Atendimentos, Caixa, Clientes, Pontos de clientes, Vales-presente, cupons e serviços (só
consulta) e o link de pagamento de assinatura.
**Você não vê:** configurações, regras de comissão, repasses, relatórios financeiros, usuários. Desconto
manual e estorno também não: chame o gerente/proprietário (desconto) ou o financeiro (estorno).

## Rotina do dia

1. **Abrir o caixa** — *Caixa → Abrir caixa*, informe o troco inicial. ✔
2. **Conferir o dia** — *Hoje* mostra quem chega, quem está atrasado e o que falta confirmar.
3. **Atender** (abaixo).
4. **Fechar o caixa** — *Caixa → Fechar*, conte o dinheiro e informe o valor contado; a diferença fica
   registrada. Imprima ou envie o comprovante do fechamento. ✔

## Agendar

- *Agenda → Novo agendamento*: serviço → profissional → horário livre → cliente (busque o cadastrado pelo
  nome, e-mail ou telefone; quem não tem conta vai só com nome e telefone). ✔
- **Clientes** — *Clientes e vendas → Clientes*: busque pelo nome, e-mail ou celular e abra a ficha
  (contato, próximos horários, anotações dos barbeiros, assinatura, pontos e histórico). *Editar* corrige
  nome, celular e nascimento. ✔ O CPF aparece mascarado; e-mail e senha são do cliente (ele troca pela
  conta).
- **Cadastrar cliente no balcão** — *Clientes → Novo cliente*: nome e **CPF (obrigatório)**; celular,
  nascimento e e-mail se o cliente quiser. ✔ Você **não** cria senha nem confirma o e-mail: se informar o
  e-mail, o cliente recebe um link para confirmar e cria a própria senha pelo site. Sem e-mail, ele é
  atendido só no balcão. Se aparecer "já está em outro cadastro", busque o cliente na lista em vez de criar
  outro.
- **Desativar / reativar** — na ficha, *Situação da conta → Desativar* (o cliente não entra mais no site e
  some da busca; o histórico fica). *Reativar* desfaz. ✔
- **Remarcar / cancelar**: abra o agendamento na agenda e use *Remarcar* ou *Cancelar* (informe o motivo).
  O cliente recebe o e-mail sozinho. ✔
- **Faltou?** No agendamento, *Não compareceu*. ✔
- O sistema não deixa marcar dois clientes no mesmo horário do mesmo profissional, nem fora do expediente.

## Atender (comanda)

1. Cliente chegou: no agendamento, *Cliente chegou: abrir atendimento*. Sem agendamento: *Atendimentos →
   Novo* (encaixe; ocupa a agenda do profissional). Busque o cliente **antes** de escolher o serviço (a
   busca recarrega a tela). ✔
2. *Iniciar* quando ele sentar na cadeira.
3. Acrescente serviços, produtos vendidos e materiais usados, se for o caso.
4. **Cupom ou pontos**: *Aplicar promoção* (vale um desconto só, o maior).
5. *Concluir e receber*: escolha a forma de pagamento (pode dividir em mais de uma) e a gorjeta, se houver.
   Os valores precisam fechar com o total. ✔
6. Comprovante: *Imprimir* ou *Enviar por e-mail*.

Errou um item? Antes de concluir, remova o item. Depois de concluído, só o financeiro/proprietário estorna.

## Outras tarefas

- **Vender vale-presente** — *Vales-presente → Vender*; o valor entra no caixa e o código sai no
  comprovante. Na hora de usar, ele é uma forma de pagamento. ✔
- **Assinatura** — *Assinaturas → Gerar link de assinatura*, busque o cliente; o link pode ir por e-mail. A assinatura só fica
  ativa quando o Stripe confirma o pagamento (a tela mostra a situação).
- **Folga ou bloqueio de horário** — *Folgas* / *Bloqueios*.

## Se algo der errado

- "Caixa fechado" ao concluir: abra o caixa primeiro.
- Horário não aparece: o profissional está de folga, em pausa, fora do expediente ou já ocupado.
- Cliente diz que não recebeu o e-mail: o gerente vê em *E-mails enviados* o que saiu e o motivo.
