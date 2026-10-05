# Relatório da Fase 8 — Promoções, fidelidade, vale-presente e comprovantes

> **Status: concluída, aguardando aprovação do dono para a Fase 9.** Branch `claude/fase-8-promocoes-fidelidade`,
> criada a partir de `claude/fase-7-comissao-repasses`. Só dados fictícios; nenhum banco de produção ou dado
> real acessado; nenhuma migração real executada; sistema antigo não alterado; nenhum segredo no repositório.
>
> Legenda: **PASSOU** · **NÃO EXECUTADO** · **PENDENTE** · **FALHOU**.

## 1. Escopo e critérios de aceite

Escopo do roadmap: cupons, vale-presente (com impressão), fidelidade (ganho, resgate, extrato, ajuste),
aniversário, indicação (D-14), precedência de descontos (R-10 a R-16), aplicação no agendamento e na comanda.
Acrescentado pelo dono durante a fase: **comprovantes impressos e por e-mail** de quatro documentos (D-40
revista).

| Critério | Situação | Evidência |
|---|---|---|
| Tabela de casos de desconto (todas as combinações) | **PASSOU** | `DiscountCasesTest`: 32 combinações de cupom (nenhum, 10%, R$ 15, R$ 60), pontos, aniversário e indicação |
| Orçamento exibido = valor gravado = valor cobrado | **PASSOU** | cada caso confere prévia do motor, agendamento e atendimento concluído; valor visto ≠ gravado recusa a reserva (`expected_total`) |
| Um desconto só, o maior (R-10, D-42), inclusive o manual | **PASSOU** | `PromotionEngine` único; `test_manual_maior_substitui_e_libera_o_cupom_menor_e_recusado`, `test_promocao_no_balcao_so_se_for_maior` |
| Cupom (R-12): validade, limite, ativo, 1 uso por cliente | **PASSOU** | `PromotionRulesTest` (cupom), sentinela no banco, concorrência com processos reais |
| Vale-presente com impressão (D-41: forma de pagamento) | **PASSOU** | `GiftCardsTest` (7), comprovante do vale, E2E |
| Fidelidade: ganho, resgate, extrato, ajuste (D-43: baixa na conclusão) | **PASSOU** | `PromotionRulesTest` (pontos), telas do painel e da conta |
| Aniversário (R-14) e indicação (R-15, D-14) | **PASSOU** | `test_aniversario_uma_vez_no_mes`, `test_indicacao_desconto_no_primeiro_e_pontos_para_quem_indicou_uma_vez` |
| Nunca negativo, nunca sobre produto (R-16) | **PASSOU** | `test_desconto_nunca_incide_em_produto_nem_deixa_total_negativo` |
| Aplicação no agendamento e na comanda | **PASSOU** | site (confirmação com cupom/pontos), balcão ("Aplicar promoção"), E2E |
| Comprovantes impressos e por e-mail (4 documentos) | **PASSOU** | `ReceiptsTest` (7), E2E |
| Regras centralizadas, histórico imutável, centavos, transações, idempotência, concorrência, sem duplicação | **PASSOU** | §3, §6, §7 |
| Permissões específicas, sem acesso amplo | **PASSOU** | 9 habilidades novas (§5); `PromotionsPanelTest::test_quem_acessa_cada_tela` (9 telas × 5 papéis) |
| Testes PHP / PHPStan / build / navegador / CI | ver §8 e §9 | |

## 2. Arquitetura

```text
                         PromotionEngine::quote()   (único; não grava)
   prévia (site, balcão) ───────┤
                                ├── candidatos: atual, aniversário, indicação, cupom, pontos, manual
                                └── vence o maior (PriceBreakdown, só serviços, nunca negativo)
   gravação ── PromotionService (mesma função, dentro da transação)
      agendamento: reserva cupom/pontos ─▶ appointment_adjustments (regra + reserva)
      atendimento: copia o desconto ─────▶ attendance_discounts
      conclusão:  cupom usado, pontos debitados, reserva sobrando liberada, pontos ganhos, bônus de indicação
      cancelamento / falta / troca por maior: reserva liberada

   Vale-presente (forma de pagamento): venda ─▶ caixa (gift_card_sale); uso ─▶ payments.gift_card_id (fora da gaveta)
   Comprovantes: Receipts::data(tipo, id) ─▶ mesma parte de tela na impressão e no e-mail (fila, registrado)
```

| Peça | Arquivo |
|---|---|
| Motor | `app/Modules/Loyalty/Pricing/{PromotionEngine,PromotionRequest,PromotionCandidate,PromotionQuote}.php` |
| Reserva, uso, liberação | `app/Modules/Loyalty/Services/PromotionService.php` |
| Pontos (razão, disponível, ajuste, ganho) | `app/Modules/Loyalty/Services/LoyaltyLedger.php` |
| Configuração | `app/Modules/Loyalty/Support/PromotionPolicy.php` (`settings.promotions.policy`) |
| Vale-presente | `app/Modules/Loyalty/Services/GiftCards.php` |
| Integração | `BookingService::book/cancel/markNoShow`, `AttendanceService` (desconto, `applyPromotion`, `complete`), `AttendanceCorrections::refund` |
| Comprovantes | `app/Modules/Receipts/{Services/Receipts,Mail/ReceiptMail,Models/ReceiptDelivery,Enums/ReceiptType}.php`, `resources/views/receipts/*` |
| Telas | `Panel/Promotions/{CouponController,GiftCardController,PromotionSettingsController,CustomerLoyaltyController}`, `Panel/ReceiptController`, `Account/{LoyaltyController,ReceiptController,BookingController}` |

**Modelo** (migration `2026_10_04_000100_create_promotion_engine_tables`, sem editar as anteriores; subir,
descer e subir testado em banco temporário): `coupon_redemptions` ganha estado e sentinela `active_key` (no
lugar do único `coupon_id`+`customer_id`, que não permitia liberar uma reserva); `loyalty_redemptions` e
`receipt_deliveries` novas; `loyalty_entries`, `gift_cards`, `coupons`, `customers`, `appointment_adjustments`,
`attendance_discounts`, `payments` e `cash_movements` ganham as colunas da fase. Detalhes em
[modelo-dados.md](modelo-dados.md) §2.7.

**O modelo existente foi validado e corrigido:**

- O único (`coupon_id`, `customer_id`) virou sentinela `active_key`: o uso reservado precisa poder ser
  liberado (cancelamento) sem apagar histórico, e o banco continua garantindo um uso por cliente.
- Vale-presente deixou de ser "desconto no agendamento" (sistema antigo) e virou forma de pagamento (D-41);
  vales importados ficam como `is_legacy` (a venda não foi registrada aqui, então o cancelamento não devolve
  pelo caixa).
- Desconto do agendamento e do atendimento passou a guardar a **regra** e a **reserva**, não só o valor:
  sem isso, o desconto percentual não acompanharia a troca de serviço e o cupom não seria liberado.

## 3. Estratégias (resumo; detalhes nos documentos)

- **Um motor, prévia = gravação:** a tela e a gravação chamam `quote()`; a gravação compara com o total
  visto e recusa se mudou. Ninguém confirma um valor e recebe outro.
- **Reserva → uso na conclusão:** cupom e pontos são reservados ao agendar (o limite e o disponível já
  contam) e só consumidos na conclusão; cancelamento, falta ou troca liberam.
- **Travas:** agenda → cliente (`loyalty_version`) → cupom (`version`); conclusão: atendimento → caixa →
  produtos → vale-presente → cliente.
- **Idempotência:** ajuste de pontos, venda de vale e envio de comprovante por `request_key`; ganho de pontos
  único por atendimento; bônus de indicação único por indicado; pagamento único por vale.
- **Histórico:** usos e resgates só avançam de estado; pontos só por inclusão; vale imutável fora do
  serviço; cupom nunca apagado.

## 4. Decisões

| # | Decisão | Quem / motivo |
|---|---|---|
| D-14 | Indicação: **implementar** | **Dono** (início da fase) |
| D-41 | Vale-presente é **forma de pagamento**: venda entra no caixa, comissão sobre o valor cheio | **Dono** |
| D-42 | Um desconto só, **vale o maior**, inclusive o manual | **Dono** |
| D-43 | Resgate de pontos ao agendar ou no balcão; pontos saem do saldo **só na conclusão** | **Dono** |
| D-40 revista | Comprovantes impressos e por e-mail: atendimento, repasse, vale-presente, fechamento de caixa | **Dono** |
| T8-01 | Desempate: o atual, depois aniversário, indicação, cupom, pontos, manual | Não consumir cupom/pontos à toa |
| T8-02 | Valor visto ≠ gravado (ou cupom inválido) recusa a reserva inteira, com o motivo | Critério de aceite; substitui o "segue sem desconto" do sistema antigo |
| T8-03 | Promoção exige cliente cadastrado | Cupom e pontos são por cliente |
| T8-04 | Configuração do programa só pelo proprietário; cupom pelo proprietário e gerente | Mesmo critério das regras de comissão (D-38) |
| T8-05 | Cancelar vale (devolve dinheiro) só proprietário e financeiro | Mesmo critério do estorno (D-30) |
| T8-06 | Comprovante usa a permissão da tela do documento; cliente envia só para o próprio e-mail | Nenhum acesso novo; não vira disparador de e-mail |
| T8-07 | Configuração antiga de fidelidade/aniversário/indicação convertida na primeira importação | Dono não precisa reconfigurar |

**PRECISA DE DECISÃO** (implementado da forma mais conservadora; nada irreversível):

| # | Ponto | Implementado |
|---|---|---|
| P8-01 | Pontos ganhos num atendimento depois estornado | Não são retirados automaticamente; ajuste manual com motivo |
| P8-02 | Agendamento pela equipe sem campo de cupom/pontos | Aplicados no balcão; aniversário e indicação automáticos |
| P8-03 | "Primeiro agendamento do mês" do aniversário | Uma vez no mês (agendamento ou atendimento que usar); cancelado/falta devolve |
| P8-04 | "Serviço grátis" com vários serviços | O mais caro sai de graça |
| P8-05 | Percentual no serviço mais barato/caro | Valor fixado ao aplicar |
| P8-06 | Vale maior que o atendimento | Uso único: a sobra é perdida |
| P8-07 | Estorno de atendimento pago com vale | Bloqueado |

## 5. Permissões

| Habilidade | Proprietário | Gerente | Recepção | Financeiro | Profissional |
|---|:-:|:-:|:-:|:-:|:-:|
| `coupons.view` | ✓ | ✓ | ✓ | ✓ | |
| `coupons.manage` | ✓ | ✓ | | | |
| `promotions.apply` (balcão; também precisa poder editar o atendimento) | ✓ | ✓ | ✓ | | ✓ |
| `promotions.configure` | ✓ | | | | |
| `loyalty.view` | ✓ | ✓ | ✓ | ✓ | |
| `loyalty.adjust` | ✓ | ✓ | | | |
| `gift_cards.view` | ✓ | ✓ | ✓ | ✓ | |
| `gift_cards.sell` | ✓ | ✓ | ✓ | | |
| `gift_cards.cancel` | ✓ | | | ✓ | |

`marketing.manage` ficou só para campanhas (Fase 10). Três camadas, como na Fase 7: rota (`can:` +
`throttle:money` / `throttle:receipts` / `throttle:coupon-check`), policy do registro (comprovante de
atendimento, repasse e caixa usa a policy/permissão da própria tela; alheio = 404) e serviço.

## 6. Revisão de segurança, permissões e auditoria

| Item | Resultado |
|---|---|
| Toda rota nova com `can:` específico | **PASSOU** — conferido na lista de rotas e em `RouteAuthorizationTest` |
| Gravações com limite | **PASSOU** — `throttle:money` (ajuste, venda/cancelamento de vale, configuração, promoção no balcão), `throttle:receipts` (e-mail), `throttle:booking` (código de indicação, reserva) |
| Testar códigos de cupom em série | **Corrigido nesta revisão:** a prévia da confirmação não tinha limite; agora `throttle:coupon-check` (10/min e 60/h por conta, só quando há cupom). Teste `test_previa_com_cupom_tem_limite_e_sem_cupom_nao` |
| Código de indicação inválido | Ignorado em silêncio no cadastro (não revela se existe) |
| E-mail do comprovante | Cliente: só o da conta (o formulário não decide). Equipe: qualquer endereço, registrado em `receipt_deliveries` e auditado com o endereço mascarado |
| Dados no comprovante | Sem CPF, sem dado de cartão; saída escapada (nenhum `{!! !!}` nas telas novas) |
| Atribuição em massa | Controladores passam só campos validados; preço, contador, estado e código do vale vêm do servidor |
| Auditoria | `promotion.applied`, `promotions.policy_changed`, `loyalty.adjusted`, `gift_card.sold`, `gift_card.cancelled`, `receipt.emailed`; `Auditable` em cupom (criar, editar, pausar) |
| Verificador de integridade | 4 regras novas: R39 uso de cupom, R40 resgate de pontos, R41 vale-presente, R42 um desconto; teste grava por fora e confirma que o verificador acusa |

## 7. Concorrência

| Cenário | Resultado |
|---|---|
| Cupom de 1 uso disputado por 5 processos PHP ao mesmo tempo (`PromotionConcurrencyTest`) | 1 agendamento; 4 recusas "limite de usos"; contador = 1; verificador limpo |
| Os mesmos 15 pontos pedidos em 3 agendamentos ao mesmo tempo | 1 reserva; 2 recusas "pontos insuficientes"; disponível = 5 |
| Dois atendimentos pagando com o mesmo vale | trava do vale + `payments.gift_card_id` único: um só |
| Duplo clique (ajuste, venda de vale, envio de comprovante) | uma gravação |

Contraprova com a trava desligada: **NÃO EXECUTADO** (no SQLite a transação `IMMEDIATE` já serializa; faz
sentido no MySQL, D-02).

## 8. Testes

| Verificação | Resultado |
|---|---|
| PHPUnit | **PASSOU** — 607 testes (eram 565), 0 falhas, 0 pulados |
| — novos na Fase 8 | `Promotions/DiscountCasesTest` (3), `PromotionRulesTest` (14), `GiftCardsTest` (7), `ReceiptsTest` (7), `PromotionsPanelTest` (9), `PromotionConcurrencyTest` (2) |
| — ajustados (regra mudou, nenhum desativado) | `SchemaTest` e `ConstraintsTest` (um uso por cliente agora pela sentinela `active_key`), `ImportScenariosTest` (configuração convertida), `PermissionMatrixTest` (9 habilidades) |
| Larastan nível 6 | **PASSOU** — 0 erros |
| Pint | **PASSOU** |
| Build (Vite) | **PASSOU** |
| Migration (subir, descer e subir) | **PASSOU** (banco temporário) |
| Playwright + axe | **PASSOU** — 95 passando (eram 91; +4 da Fase 8, celular e desktop), 3 ignorados de propósito (os mesmos das fases anteriores), em **duas execuções seguidas** num banco SQLite novo, como no CI |
| — novos na Fase 8 | `promocoes.spec.js` (celular e desktop): dono cria cupom (valor inválido recusado), cliente agenda com o cupom (total da confirmação = total gravado no agendamento), fidelidade na conta; venda de vale-presente e comprovante para imprimir (a barra de ações some na impressão), configuração. Axe e rolagem lateral em 7 telas |
| — ajustados | `agenda.spec.js`: a confirmação agora mostra valor e total (mesmo texto duas vezes); o teste passou a conferir o total (`[data-total]`). `comissao.spec.js`: o teste do profissional ganhou `test.slow()` como os demais (passava perto do limite de 30 s com a fila do servidor embutido) |

## 9. CI

**PENDENTE** — preenchido depois do push (ver o commit seguinte).

## 10. Problemas encontrados

1. **Rótulos com "(opcional)" no E2E:** `getByLabel` exato não achava o campo; o teste passou a usar o nome
   acessível completo.
2. **Página do agendamento do cliente sem o desconto:** mostrava só o preço dos itens; agora mostra o
   desconto e o total gravados (pego ao escrever o E2E).
3. **Limite de tentativas da prévia do cupom** (§6), corrigido.
4. **Testes do painel trocando de usuário na mesma sessão:** a sessão anterior redirecionava; os testes
   passaram a limpar a sessão entre usuários (comportamento correto do `auth.session`).
5. **Banco local de desenvolvimento:** continua vazio desde a Fase 7 (repovoar com `php artisan db:seed`);
   todo E2E e migration desta fase rodou em banco temporário.

## 11. Pendências

1. **P8-01 a P8-07** (§4): decisões do dono.
2. **Assinatura × fidelidade** (R-11, R-19: assinante não acumula; benefício × resgate, vale o maior): Fase 9.
3. **Provedor de e-mail** (D-05): os comprovantes vão para a fila; entrega real depende da Fase 10.
4. **Concorrência no MySQL** e contraprova: D-02.
5. **Repovoar o banco local de desenvolvimento** (`php artisan db:seed`).

## 12. Documentação

Novos: [promocoes.md](promocoes.md), [fidelidade.md](fidelidade.md), [vale-presente.md](vale-presente.md),
[comprovantes.md](comprovantes.md). Atualizados: [pagamentos.md](pagamentos.md), [caixa.md](caixa.md),
[atendimento.md](atendimento.md), [agendamento.md](agendamento.md), [papeis-permissoes.md](papeis-permissoes.md),
[modelo-dados.md](modelo-dados.md) (seção 2.7 e apêndice regenerado, 62 tabelas),
[regras-dados.md](regras-dados.md) (regra 29 revista, regras 73 a 78), [mapa-banco-antigo-novo.md](mapa-banco-antigo-novo.md),
[decisoes-pendentes.md](decisoes-pendentes.md) (D-14, D-41 a D-43), [roadmap.md](roadmap.md), [README.md](README.md).

## 13. Riscos

| Risco | Mitigação |
|---|---|
| Cupom divulgado demais esgota o limite com reservas que não comparecem | Falta e cancelamento liberam a reserva; o dono vê usos reservados × usados |
| Desconto alto configurado por engano | Só o proprietário configura; tudo auditado; limites por campo |
| Vale vendido sem caixa aberto | Recusado; venda sempre registrada no caixa |
| E-mail de comprovante para endereço errado | Registro e auditoria do envio; limite por conta |
| SQLite × MySQL | Travas por escrita na linha funcionam nos dois; repetir a concorrência no banco escolhido |

## 14. Recomendações para a Fase 9

1. O benefício da assinatura deve entrar como mais um candidato do `PromotionEngine` (R-11: entre benefício e
   resgate de pontos, vale o maior), não como regra paralela.
2. Assinante ativo não acumula pontos (R-19): condição no `LoyaltyLedger::recordCompletion`.
3. Comissão de assinatura (colunas `subscription_commission_*` do legado) vira regra de comissão versionada.

---

**Aguardando aprovação explícita do dono para a Fase 9.**
