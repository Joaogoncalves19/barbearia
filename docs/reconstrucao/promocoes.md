# Promoções e descontos (Fase 8)

Cupom, pontos de fidelidade, aniversário, indicação e desconto manual passam por **um único motor**
(`app/Modules/Loyalty/Pricing/PromotionEngine.php`). A prévia que o cliente ou a recepção vê e a gravação
usam a mesma função (`quote()`). Assim, **orçamento exibido = valor gravado = valor cobrado**, que é o
critério de aceite da Fase 8.

Documentos relacionados: [fidelidade.md](fidelidade.md), [vale-presente.md](vale-presente.md),
[comprovantes.md](comprovantes.md), [pagamentos.md](pagamentos.md), [comissoes.md](comissoes.md).

## 1. Regras de negócio

| Regra | Como ficou |
|---|---|
| R-10 Um desconto por agendamento | **Um só desconto por agendamento e por atendimento, vale o maior** (decisão do dono, D-42), incluindo o desconto manual do balcão |
| R-12 Cupom | Percentual ou valor fixo; validade; limite de usos; ativo/inativo; **1 uso por cliente**; exige cliente cadastrado |
| R-13 Vale-presente | Virou **forma de pagamento** (decisão do dono, D-41): ver [vale-presente.md](vale-presente.md). Não é desconto e não disputa com as promoções |
| R-14 Aniversário | Percentual configurável, quando a data do atendimento cai no mês do aniversário; **uma vez no mês** (o primeiro agendamento ou atendimento que usar) |
| R-15 Indicação | Percentual no **primeiro** atendimento do indicado; quem indicou ganha pontos quando o indicado conclui o primeiro atendimento (uma vez só). Implementada (D-14 decidida: implementar) |
| R-16 Nunca negativo | O desconto nunca passa da soma dos serviços; produto é sempre cobrado inteiro |
| R-17 a R-19 Fidelidade | Ver [fidelidade.md](fidelidade.md). Pontos reservados ao agendar ou no balcão; **saem do saldo só na conclusão** (D-43) |

### 1.1 Precedência ("vale o maior")

1. O motor calcula, para o pedido, cada candidato possível: o desconto já aplicado (se houver), aniversário,
   indicação, cupom informado, resgate de pontos pedido e desconto manual (balcão).
2. Cada valor é calculado pelo `PriceBreakdown`, o mesmo cálculo dos totais. Incide só sobre serviços,
   nunca sobre produtos, e nunca passa da base.
3. Vence o **maior valor em centavos**. Empate: o já aplicado; depois os que não gastam nada (aniversário,
   indicação); depois cupom, pontos e manual. Nada é consumido à toa no empate.
4. Se o pedido do cliente perde (ex.: cupom de 10% num mês de aniversário com 15%), a tela mostra o aviso
   "vale só um desconto, o maior" e o cupom **não** é reservado.

### 1.2 Quando cada coisa é gravada

| Momento | Cupom | Pontos | Aniversário / indicação |
|---|---|---|---|
| Prévia (tela de confirmação, balcão) | conferido, nada gravado | conferido, nada gravado | conferido, nada gravado |
| Agendamento confirmado | **reservado** (conta no limite de usos) | **reservados** (saem do disponível, não do saldo) | gravado no agendamento |
| Desconto trocado por um maior | reserva **liberada** | reserva **liberada** | removido |
| Agendamento cancelado ou falta | reserva liberada (o limite volta) | reserva liberada | removido do mês |
| Atendimento concluído | **usado** | **debitados do saldo** (lançamento de resgate) | — |

O agendamento guarda a **regra** do desconto (tipo, percentual ou valor e a reserva). Se o dono mudar o cupom
depois, o agendamento mantém o desconto combinado com o cliente.

### 1.3 Valor visto × valor gravado

A tela de confirmação envia o total que a pessoa viu (`expected_total`). Se, ao gravar, o total for
diferente (o preço mudou, o cupom acabou, outro desconto ficou maior), a reserva é **recusada inteira**,
nada é gravado e a pessoa volta para a confirmação com o motivo. Isso substitui o "segue sem desconto" do
sistema antigo (R-13): ninguém confirma um valor e recebe outro.

Cupom inválido no agendamento também recusa a reserva inteira, com o motivo (vencido, inativo, limite de
usos, já usado por este cliente, não encontrado). A prévia mostra o problema antes de confirmar.

## 2. Onde se aplica

| Fluxo | O que o usuário faz | Serviço |
|---|---|---|
| Agendamento pelo site | Informa o cupom e/ou marca "usar meus pontos" na confirmação; "Atualizar valor" mostra o novo total | `BookingService::book` → `PromotionService::applyToAppointment` |
| Agendamento pela equipe | Aniversário e indicação entram sozinhos. Cupom e pontos são aplicados no balcão (ver PRECISA DE DECISÃO em §6) | idem |
| Balcão (comanda) | "Aplicar promoção" (cupom ou pontos) e desconto manual; só entra se for maior que o atual | `AttendanceService::applyPromotion` / `applyDiscount` |
| Encaixe com cliente cadastrado | Aniversário e indicação no agendamento do encaixe; cupom e pontos no balcão | idem |

O atendimento aberto a partir do agendamento copia o desconto com a mesma regra e a mesma reserva.

## 3. Modelo

| Tabela | Uso |
|---|---|
| `coupons` | código (maiúsculo, único), tipo, percentual ou valor, validade, limite, contador de usos, ativo, descrição, `version` (trava), autor |
| `coupon_redemptions` | uso do cupom: `reserved` → `redeemed` ou `released`; agendamento, atendimento, valor do desconto; `active_key` único (`c{cupom}\|u{cliente}`) garante 1 uso por cliente no banco |
| `appointment_adjustments` / `attendance_discounts` | o desconto (um só) com tipo, regra (`discount_type`, `percent_bp`, `fixed_cents`), valor e a reserva (`coupon_redemption_id` ou `loyalty_redemption_id`) |
| `loyalty_redemptions` / `loyalty_entries` | ver [fidelidade.md](fidelidade.md) |
| `settings` (`promotions.policy`) | fidelidade, aniversário e indicação (§5) |

## 4. Concorrência e idempotência

- **Trava por escrita na linha:** cupom (`coupons.version`), pontos do cliente (`customers.loyalty_version`),
  agenda (`professionals.schedule_version`). Ordem: agenda → cliente → cupom; na conclusão: atendimento →
  caixa → produtos → vale-presente → cliente.
- **Sentinela no banco:** `coupon_redemptions.active_key` e `loyalty_redemptions.active_key` (`a{agendamento}`)
  são únicos; uso liberado zera a sentinela.
- **Testes com processos reais** (`PromotionConcurrencyTest`): cupom de 1 uso disputado por 5 processos →
  1 agendamento, 4 recusas "limite de usos", contador = 1; os mesmos 15 pontos pedidos em 3 agendamentos ao
  mesmo tempo → 1 reserva, 2 recusas, disponível = 5.
- Ajuste de pontos e venda de vale têm `request_key` único (duplo clique = uma gravação).

## 5. Configuração (só o proprietário)

Tela **Promoções › Fidelidade e aniversário** (`/painel/fidelidade`, `promotions.configure`). Valores com limites
(`PromotionPolicy::FIELDS`), gravados em `settings.promotions.policy`, auditados (`promotions.policy_changed`).

| Campo | Padrão |
|---|---|
| Fidelidade ativa / modo de ganho | ativa / por atendimento |
| Pontos por atendimento / valor gasto por ponto | 1 / R$ 10,00 |
| Pontos para um resgate / recompensa | 10 / percentual |
| Onde o percentual incide / percentual / valor fixo | serviço mais barato / 50% / R$ 10,00 |
| Aniversário ativo / percentual | não / 15% |
| Indicação ativa / desconto do indicado / pontos de quem indicou | não / 10% / 1 |

A importação converte `fidelidade_config`, `config_aniversario` e `config_indicacao` do sistema antigo
(só na primeira importação; valor fora do limite vira o padrão e gera pendência).

Cupons: **Promoções › Cupons** (`/painel/cupons`): criar, editar, pausar e reativar. Cupom nunca é apagado
(o histórico de usos aponta para ele).

## 6. PRECISA DE DECISÃO

| # | Ponto | Implementado (mais conservador) |
|---|---|---|
| P8-01 | Pontos ganhos num atendimento que depois é **estornado** | Não são retirados automaticamente; o gerente ajusta com motivo, se quiser |
| P8-02 | Formulário de agendamento da **equipe** sem campo de cupom/pontos | Aplicados no balcão (mesmo motor); aniversário e indicação são automáticos |
| P8-03 | Aniversário "no primeiro agendamento do mês" | Uma vez no mês: o primeiro agendamento **ou** atendimento que usar; cancelado/falta devolve o direito |
| P8-04 | Recompensa "serviço grátis" com vários serviços | O serviço mais caro do atendimento sai de graça |
| P8-05 | Desconto percentual no serviço mais barato/caro | Valor fixado quando o desconto é aplicado (incluir outro serviço depois não muda o valor) |

## 7. Testes

`tests/Feature/Promotions`: `DiscountCasesTest` (tabela com as 32 combinações de cupom, pontos, aniversário e
indicação: prévia = agendamento = cobrado), `PromotionRulesTest` (14), `PromotionsPanelTest` (8),
`PromotionConcurrencyTest` (2, processos reais). E2E: `tests/e2e/promocoes.spec.js`.
