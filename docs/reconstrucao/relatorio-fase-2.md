# Relatório da Fase 2 — Modelo de dados, domínio e importador

> **Status: concluída em 2026-09-26, aguardando aprovação explícita do dono para iniciar a Fase 3.**
> Branch `claude/oi-8lb77r`. Nenhum dado real foi usado, nenhum banco de produção foi acessado, o sistema
> antigo e o banco antigo não foram alterados, nenhum segredo foi adicionado ao repositório.

## 1. Resumo

| Entrega | Situação |
|---|---|
| Direção visual A oficial; B só como referência histórica | ✅ |
| Modelagem conceitual antes das migrations | ✅ [modelo-dados.md](modelo-dados.md) §1 |
| Modelo definitivo: 49 tabelas de domínio, FKs, únicos, índices, soft delete, snapshots | ✅ 10 migrations |
| Enums (29), models (45), value objects/normalizadores, serviços de domínio, factories | ✅ |
| 35 regras de dados com implementação e teste | ✅ [regras-dados.md](regras-dados.md) |
| Importador `legacy:import`: simulação, idempotência, logs, contadores, relatórios, pendências, duplicidades, órfãos, conciliação | ✅ [importador.md](importador.md) |
| Banco antigo fictício (normal + problemático) e `legacy:fixture` | ✅ |
| Mapa antigo → novo e decisão sobre as 6 tabelas abandonadas | ✅ [mapa-banco-antigo-novo.md](mapa-banco-antigo-novo.md) |
| Estratégias de histórico e de duplicidades | ✅ [estrategia-historico.md](estrategia-historico.md), [estrategia-duplicidades.md](estrategia-duplicidades.md) |
| Avaliação de desempenho (centenas a dezenas de milhares) | ✅ linear, 50 mil agendamentos em ~29 s |
| PHPStan | ✅ Larastan nível 6, **0 erros**, no CI |
| CI | ✅ corrigido (estava vermelho desde a Fase 1, ver §8) |

## 2. Modelo final

Módulos e tabelas ([modelo-dados.md](modelo-dados.md) tem entidades, relacionamentos e o esquema físico gerado
por `php artisan app:schema-doc`):

| Módulo | Tabelas |
|---|---|
| Identity | `users` (agora com `username` e e-mail opcional) |
| Customers | `customers`, `customer_notes`, `customer_favorite_professionals`, `customer_merge_candidates`, `consent_records`, `email_suppressions`, `customer_notifications` |
| Team | `professionals`, `professional_service`, `professional_package`, `working_hours`, `schedule_breaks`, `time_off`, `blocked_slots` |
| Catalog | `service_categories`, `services`, `packages`, `package_items`, `products`, `stock_movements` |
| Scheduling | `appointments`, `appointment_items`, `appointment_adjustments`, `appointment_events`, `appointment_reminders` |
| Finance | `payments`, `commission_entries`, `commission_payouts`, `advances`, `expenses`, `financial_goals` |
| Loyalty | `loyalty_entries`, `coupons`, `coupon_redemptions`, `gift_cards` |
| Subscriptions | `plans`, `plan_services`, `subscriptions`, `subscription_payments`, `gateway_events` |
| Reviews / Marketing / System | `reviews`, `review_replies`, `campaigns`, `settings`, `audit_logs` |
| LegacyImport | `import_runs`, `import_issues`, `legacy_references` |

Principais relacionamentos: cliente → agendamentos → itens/descontos/pagamentos/avaliação; profissional (com ou
sem login) → agenda, serviços, comissões; cliente → assinaturas (histórico, uma vigente) → pagamentos;
produtos e pontos como razões. Diagrama em [modelo-dados.md §1.2](modelo-dados.md#12-mapa-de-relacionamentos).

## 3. Decisões tomadas nesta fase

Técnicas (reversíveis, documentadas):

| # | Decisão | Motivo |
|---|---|---|
| T2-01 | Chaves inteiras; ids antigos só em `legacy_references` e em códigos públicos (`appointments.code`) | Rastreabilidade sem acoplar o modelo ao legado |
| T2-02 | Dinheiro em centavos, percentual em pontos-base, conversão de texto sem float (`Decimal`) | Exatidão; regressões de float testadas |
| T2-03 | Pontos e estoque como razões, sem coluna de saldo; ajuste de migração único | Saldo nunca diverge; saldo migrado idêntico |
| T2-04 | Itens de agendamento com snapshot e `price_source`; preço antigo = catálogo atual (`legacy_catalog_estimate`) | O banco antigo não guarda o preço cobrado; é o mesmo valor que os relatórios antigos mostram |
| T2-05 | Preço desconhecido fica nulo (`legacy_unknown`), nunca zero | Não inventar valores |
| T2-06 | Desconto só sobre serviços, limitado a eles | Regra do sistema atual |
| T2-07 | Unicidade condicional por coluna-sentinela (`active_customer_id`) | Portável SQLite/MySQL |
| T2-08 | Sem único de horário no banco; conflito é regra do serviço de agenda (Fase 5) e pendência na importação | O legado tem conflitos reais |
| T2-09 | Órfão de barbeiro → profissional inativo "Profissional removido (id)", um por id antigo | Preserva faturamento e comissões por pessoa sem juntar pessoas |
| T2-10 | Duplicidade: o primeiro cadastro fica com o dado; par registrado; nunca mescla | [estrategia-duplicidades.md](estrategia-duplicidades.md) |
| T2-11 | Consentimento `unknown` por padrão; opt-out em cliente + supressão + prova | LGPD; ausência ≠ consentimento |
| T2-12 | Senhas bcrypt copiadas como estão e re-hasheadas no login; outros formatos → sem senha | Sem rebaixar a segurança |
| T2-13 | Guard `customer` criado (sem rotas) | Senhas importadas já verificáveis/re-hasheadas |
| T2-14 | Importação numa transação única; simulação = mesma execução desfeita | Tudo ou nada; simulação fiel |
| T2-15 | `IntegrityChecker` (24 verificações) roda no fim da importação e falha se houver violação | Garante as regras também no que não passa pelos models |
| T2-16 | Segredos nunca no banco: `Setting` recusa chaves com cara de segredo; seções sensíveis descartadas | Credenciais atuais estão em texto puro no banco antigo |
| T2-17 | PHP mínimo passa a ser **8.4** | As dependências travadas exigem 8.4.1 |

Alterações em relação ao que existia: `users.email` anulável + `users.username` (D-10); direção B isolada em
`resources/css/prototypes/direcao-b.css`; `config('barbearia.design.default_direction')` removido.

## 4. Decisões

Implementadas na forma **mais conservadora**, sem fechar a decisão do dono:

| ID | Como ficou | O que falta decidir |
|---|---|---|
| D-07 | **Decidido: direção A.** | Logo, nome, fotos |
| D-10 | Equipe entra por usuário; e-mail opcional | Login por e-mail? Todos têm e-mail? |
| D-11 | 6 tabelas abandonadas arquivadas em JSON, não migradas | Aprovar lista de espera e metas? |
| D-17 | Agendamentos passados não concluídos importados como estão + pendência | Concluir, marcar falta ou revisar um a um |
| D-20 | Só o resumo das campanhas; destinatários arquivados | Confirmar |
| D-21 | Nada mesclado; pares em `customer_merge_candidates` | Aprovar a mesclagem assistida (proposta em [estrategia-duplicidades.md §4](estrategia-duplicidades.md#4-mesclagem-assistida-a-implementar-na-fase-7-com-aprovação-do-dono)) |
| D-12 | CPF mantido (existe no legado), mascarado em logs | Manter CPF? |
| Novas | Qual cupom/vale manter quando o código se repete; o que fazer com valores em formato brasileiro que o sistema antigo lia diferente (`money_format_divergent`) | Decidir no primeiro ensaio real |

## 5. Estratégia de migração

Mantida a de [estrategia-migracao.md](estrategia-migracao.md), agora com a ferramenta pronta:
cópia do banco → `legacy:import --dry-run` → revisar relatório e pendências com o dono → corrigir/decidir →
repetir → importação real em homologação → conferência → virada. A importação falha sozinha se saldo de pontos,
estoque, opt-outs, assinaturas, faturamento mensal ou somas financeiras não baterem.

## 6. Regras de histórico e duplicidades

- Histórico: fotografias imutáveis (itens, pagamentos, comissões, pontos, estoque, pagamentos de assinatura,
  eventos, consentimentos, auditoria) com a trait `AppendOnly`; snapshots de contato e profissional no
  agendamento; status só por transições; cadastro com soft delete e FK `restrict`.
  [estrategia-historico.md](estrategia-historico.md).
- Duplicidades: detectar, registrar e nunca mesclar sozinho. [estrategia-duplicidades.md](estrategia-duplicidades.md).

## 7. Testes

| Verificação | Resultado |
|---|---|
| PHPUnit (Unit + Feature) | **151 testes, 1.256 asserções, todos passando** (eram 64 na Fase 1) |
| — novos na Fase 2 | Schema (6), Constraints (11), DomainRules (15), HistoryAndMoney (7), MarketingConsent (4), ImportRun (8), ImportScenarios (13), Decimal/LegacyValue (19 casos), Normalizers (3), direção oficial (1) |
| Suíte Legado (PHPUnit) | 1/1 |
| Regressão de segurança do sistema atual (`tests/seguranca_fase1.php`) | todos passando |
| Playwright + axe (telas de referência, celular e desktop) | 41 passando, 1 ignorado (como na Fase 1) |
| Pint | sem pendências |
| Larastan nível 6 | 0 erros |
| CI | ver §8 |

Cobertura pedida pelo briefing: migrations (sobem e descem), constraints, relacionamentos, dinheiro (centavos,
sem float, regressões), histórico, unicidade, importação, duplicidades, órfãos, idempotência, simulação, IDs
externos, senhas e preferências de marketing, todos com teste nomeado em [regras-dados.md](regras-dados.md).

## 8. CI: problema encontrado e corrigido

O CI estava **vermelho desde a Fase 1** e isso não foi percebido no relatório da Fase 1. Três causas, todas corrigidas:

1. O runner usava PHP 8.3, mas o `composer.lock` exige PHP ≥ 8.4.1 (Symfony 8.1): `composer install` falhava.
   → CI em PHP 8.4 e `composer.json` com `"php": "^8.4"`.
2. O build do Vite rodava **depois** dos testes PHP; 13 testes de tela falhavam sem o manifesto.
   → build antes dos testes.
3. No E2E, o `APP_URL` do `.env.example` (porta 8000) diferia do servidor de teste: a CSP recusava as fontes.
   → o Playwright passa `APP_URL` igual ao endereço do servidor.

Com as correções, os passos Pint, Larastan, auditoria, build, testes PHP e importador fictício passaram no CI;
o resultado do último run (com a correção do E2E) está no fim deste relatório.

## 9. Resultado do importador (banco fictício padrão: 60 clientes, 300 agendamentos + casos problemáticos)

| Execução | Situação | Lidos | Importados | Já importados | Não importados | Pendências (decisão) |
|---|---|---:|---:|---:|---:|---:|
| Simulação | desfeita, 0 linhas gravadas | 542 | 500 | 0 | 33 | 96 (68) |
| Importação | concluída | 542 | 500 | 0 | 33 | 96 (68) |
| Reexecução | concluída | 542 | **0** | 490 | 33 | 49 (32) |

- Simulação e importação produziram **os mesmos contadores e as mesmas pendências**.
- Após a reexecução, **todas as tabelas de dados ficaram idênticas** (comparação por hash de cada tabela; só o
  contador interno `sqlite_sequence` avança, por causa dos registros novos em `import_runs`/`import_issues`).
- SHA-256 do banco de origem igual antes e depois de todas as execuções.
- Conciliação: saldo de fidelidade ✅, estoque ✅, opt-outs ✅, assinaturas ✅, faturamento mensal ✅ (centavo por
  centavo), somas financeiras ✅. Integridade: 0 violações.
- Classificação das pendências na importação: inconsistente 33, órfão 24, duplicado 14, legado 12,
  desconhecido 7, potencialmente válido 6. Na reexecução reaparecem só as linhas que nunca foram importadas e as
  verificações de estado (sobreposição, órfãos de indicação), como esperado.
- Desempenho: tabela em [importador.md](importador.md#resultado-com-o-banco-fictício) (linear; 10 mil clientes e
  50 mil agendamentos em ~29 s e 70 MB).

## 10. As seis tabelas abandonadas

`config`, `admin_crm_clientes`, `admin_metas_equipe`, `admin_retencao`, `admin_conciliacao`,
`admin_lista_espera`: o que representavam, se os dados aparecem em outro lugar, relevância e decisão estão em
[mapa-banco-antigo-novo.md §3](mapa-banco-antigo-novo.md#3-as-seis-tabelas-abandonadas). Todas arquivadas em JSON,
nenhuma apagada.

## 11. Pendências

1. **Primeiro ensaio com a cópia real do banco** (read-only), para medir dados sujos reais e revisar as
   pendências com o dono. Estava previsto no roadmap para esta fase, mas o briefing proibiu dados reais.
2. Decisões da §4.
3. Anonimizador para homologação (previsto no roadmap; necessário antes de usar dados reais em homologação).
4. Hospedagem (D-01) e deploy de homologação: sem mudança.
5. Cópia de `uploads/` (fotos) na virada.
6. Triggers de banco para reforçar a imutabilidade fora do Eloquent (opcional, depende de D-02).

## 12. Riscos

| Risco | Mitigação |
|---|---|
| Dados reais mais sujos que o fictício | O importador rejeita o que não entende com pendência; simulação antes de tudo |
| Preço antigo "estimado" pelo catálogo atual | É o que os relatórios antigos já fazem; marcado como `legacy_catalog_estimate`; conciliação mensal garante que bate |
| Cliente duplicado fica sem e-mail de login até a decisão | Revisar a lista de duplicidades antes da virada |
| Equipe sem e-mail (D-10) | Login por usuário mantido até decidir |
| Mudança de schema depois | Só por migration nova, revisada |

## 13. Recomendações para a Fase 3 (identidade, acesso e auditoria)

1. Decidir D-10 e D-12 antes de começar (login da equipe e do cliente).
2. Usar o guard `customer` já criado; cadastro/login/recuperação de senha do cliente, com rehash das senhas antigas.
3. Perfis editáveis pelo dono (avaliar pacote de permissões) e auditoria de acessos com o `Auditable` já pronto.
4. Anonimização LGPD do cliente (apagar dados pessoais, manter o agendamento "sem conta").
5. Rodar o primeiro ensaio de importação com a cópia real em paralelo, para que as decisões D-17 e D-21 cheguem
   com números reais.

---

**Aguardando aprovação explícita para iniciar a Fase 3.** Silêncio não é autorização.
