# Importador do sistema atual

`php artisan legacy:import <cópia-do-banco.sqlite> [--dry-run] [--force]`

Transforma o banco SQLite do sistema atual no modelo novo ([modelo-dados.md](modelo-dados.md)),
de acordo com o [mapa](mapa-banco-antigo-novo.md). **Nesta fase ele foi validado só com dados fictícios.**
Nenhum dado real foi lido e nenhum banco de produção foi acessado.

Código: `novo-sistema/app/Modules/LegacyImport/`

| Peça | Papel |
|---|---|
| `Source/LegacyDatabase` | Abre a origem **somente leitura** (`SQLITE_OPEN_READONLY` + `PRAGMA query_only`), confere `quick_check`, lê em streaming por ordem de inserção, entrega tudo como texto (dinheiro nunca vira float) e tolera colunas ausentes |
| `Support/LegacyValue` | Leitura segura de dinheiro, percentual, datas, horas, booleanos, CSV e hash de senha |
| `Support/ImportContext` | Mapa id antigo → id novo, idempotência, contadores e pendências |
| `Steps/*` | Uma etapa por grupo de tabelas (14 etapas, na ordem das dependências) |
| `Support/Reconciler` | Conciliação origem × destino, calculada de forma independente das etapas |
| `System/Integrity/IntegrityChecker` | 24 verificações de integridade no banco (ver [regras-dados.md](regras-dados.md)) |
| `Reporting/ReportWriter` | Relatórios JSON + Markdown + pendências |
| `LegacyImporter` | Orquestra: transação única, simulação, falha segura |
| `Testing/FictitiousLegacyDatabase` + `legacy:fixture` | Banco antigo **fictício** para testes e medição de desempenho |

## Como usar

```bash
# 1. Fazer uma CÓPIA do banco (nunca apontar para o arquivo em uso)
sqlite3 _dados/database.sqlite ".backup /caminho/copia.sqlite"   # ou cópia do arquivo fora do horário

# 2. Simular: executa tudo e desfaz; grava só os relatórios
php artisan legacy:import /caminho/copia.sqlite --dry-run

# 3. Ler o relatório e as pendências (storage/app/private/legacy-import/reports/)
# 4. Importar de verdade (em produção exige --force)
php artisan legacy:import /caminho/copia.sqlite

# Teste local com dados fictícios
php artisan legacy:fixture storage/legado-ficticio.sqlite --customers=500 --appointments=3000
```

A saída mostra os contadores por tabela, a conciliação, a integridade, se a origem ficou inalterada e onde
estão os relatórios. O código de saída é diferente de zero se algo falhar.

## Garantias

1. **Origem intocada.** Aberta em modo somente leitura; o SHA-256 do arquivo é conferido antes e depois
   (`source_unchanged` no relatório). Teste: `ImportRunTest::test_origem_nunca_e_alterada`.
2. **Simulação fiel.** `--dry-run` percorre exatamente o mesmo código dentro da mesma transação e a desfaz;
   só os relatórios são gravados (arquivo, fora do banco). O arquivo morto também não é gerado.
   Testes: `test_simulacao_nao_grava_nada_e_gera_relatorio`, `test_simulacao_e_importacao_real_tem_o_mesmo_resultado`.
3. **Tudo ou nada.** A importação real roda numa única transação; qualquer erro, conciliação que não fecha ou
   violação de integridade desfaz tudo. A execução fica registrada em `import_runs` como `failed`.
   Teste: `test_falha_no_meio_desfaz_tudo`.
4. **Idempotência.** Cada linha importada é registrada em `legacy_references` (tabela antiga, id antigo, entidade
   nova, id novo, checksum). Rodar de novo não duplica nada: a linha já importada é pulada. Se o conteúdo
   mudou na origem, ela **não** é sobrescrita e vira pendência `source_changed`.
   Tabelas antigas sem chave primária usam como chave o hash do conteúdo mais o número da ocorrência.
   Testes: `test_segunda_execucao_nao_duplica_nada`, `test_registro_alterado_na_origem_nao_e_sobrescrito`.
5. **Nada inventado.** Valor ilegível vira nulo com pendência; status desconhecido não é adivinhado (a linha não
   entra e vira pendência); preço desconhecido fica nulo (`legacy_unknown`) e o pagamento não é criado.
6. **Nada perdido sem registro.** Toda linha lida termina como importada, já importada, não importada (com
   pendência), arquivada ou ignorada (efêmeras), e o relatório mostra os contadores por tabela.
7. **Segredos não migram.** Seções sensíveis da configuração são descartadas; campos com nome de segredo são
   removidos das demais; o relatório não contém hashes nem credenciais.

## Etapas

Ordem: Configurações → Equipe → Catálogo → Profissionais e agenda → Clientes → Planos e assinaturas →
Cupons e vales → Agendamentos → Avaliações → Fidelidade e usos de cupom → Estoque → Financeiro →
Campanhas e auditoria → Arquivo morto.

## Status

| Antigo | Novo | Observação |
|---|---|---|
| `pendente` | `pending` | no passado: pendência `past_not_completed` |
| `aguardando_pagamento` | `awaiting_payment` | criado há mais de 15 min ou no passado → `cancelled` (sistema, `payment_expired`), como o sistema atual faria |
| `aprovado` | `confirmed` | no passado: mantido, pendência D-17 |
| `concluido` | `completed` | cria o pagamento quando o valor é conhecido |
| `cancelado` | `cancelled` | por `staff` |
| `cancelado_pelo_cliente` | `cancelled` | por `customer` |
| `rejeitado` | `cancelled` | por `staff`, motivo `rejected` |
| outro | — | não importado, pendência `unknown_appointment_status` |

## Dinheiro

- Texto convertido para centavos por aritmética de string (`Decimal`), nunca por float.
- O sistema atual lê valores com `(float)`, que interpreta `"30,00"` como 30 e `"1.234,56"` como 1,234. O
  importador lê o valor que a pessoa digitou e registra a pendência `money_format_divergent`, porque o
  sistema antigo exibia outro número.
- Mais de 2 casas decimais: arredonda meio para cima e registra `money_rounded`.
- `REAL` do SQLite chega como texto (`ATTR_STRINGIFY_FETCHES`): `99.9` vira 9990.
- **O preço cobrado não existe no banco antigo.** O item de agendamento recebe o preço **atual** do catálogo,
  marcado como `legacy_catalog_estimate`, que é exatamente o que o relatório financeiro antigo usa. Por isso a
  conciliação de faturamento mensal fecha centavo por centavo.

## Senhas

- O sistema atual usa `password_hash(PASSWORD_DEFAULT)` (bcrypt, custo 10). O hash é copiado **como está**:
  não é convertido nem "re-hasheado" na importação, e nenhuma senha é descoberta.
- No primeiro login bem-sucedido o Laravel refaz o hash com a configuração atual (`hashing.rehash_on_login`,
  bcrypt custo 12): migração gradual, sem rebaixar a segurança. O guard `customer` já existe para isso.
- Hash em qualquer outro formato (md5, texto puro…): a conta é importada **sem senha** e com pendência
  `unsupported_password_hash`. A pessoa redefine a senha (Fase 3). Membro da equipe nessa situação fica inativo.
- A equipe entra por **usuário** até a decisão D-10. Usuários repetidos (inclusive só por maiúsculas) ficam
  sem usuário e inativos, com pendência, para ninguém herdar o acesso de outra pessoa.
- Teste: `ImportScenariosTest::test_senhas_antigas_continuam_funcionando_e_sao_rehasheadas`.

## LGPD

- `marketing_email_consent` começa `unknown` para todo cliente. O sistema atual não registra aceite, só opt-out.
- Cada linha de `email_optout` vira: supressão do e-mail (mesmo sem cadastro, mesmo com e-mail inválido),
  prova em `consent_records` e `revoked` no cliente, encontrado pelo `cliente_id` ou pelo e-mail.
- CPF não aparece em relatórios (`"(omitido)"`) e é mascarado na auditoria.

## Relatórios

Em `storage/app/private/legacy-import/reports/` (disco privado, nunca servido por URL):

| Arquivo | Conteúdo |
|---|---|
| `<data>-simulacao.json` / `<data>-importacao-<n>.json` | tudo: contadores, classificação, conciliação, integridade, pendências, tempos, memória |
| `….md` | resumo legível |
| `…-pendencias.md` | só o que precisa de decisão, agrupado por código, com o registro antigo |

### Classificação dos dados

Todo registro é classificado. **Válido** = importado sem pendência. Os demais recebem uma das classes:
**potencialmente válido** (ex.: nome vazio, assinatura ativa vencida), **inconsistente** (formato inválido),
**duplicado**, **órfão** (referência a registro que não existe), **legado** (estrutura antiga arquivada) e
**desconhecido** (status/tabela não mapeados). O relatório traz a tabela *tabela × classe*.

### Principais códigos de pendência

| Código | Significado | Decisão? |
|---|---|---|
| `duplicate_customer` | e-mail/telefone/CPF igual ao de outro cliente; par em `customer_merge_candidates` | sim (D-21) |
| `past_not_completed` | agendamento passado nunca concluído | sim (D-17) |
| `future_overlap` | dois agendamentos futuros no mesmo horário do mesmo profissional | sim, antes da virada |
| `orphan_professional` | barbeiro removido; vinculado a "Profissional removido (id)" | sim |
| `orphan_subscription` | assinatura (com IDs do Stripe) de cliente inexistente | sim, conferir no Stripe |
| `unknown_catalog_item` / `payment_amount_unknown` | item que saiu do catálogo; valor desconhecido | sim |
| `money_format_divergent` | valor em formato brasileiro que o sistema antigo lia diferente | sim |
| `unsupported_password_hash` | conta sem senha, exige redefinição | não (redefinição) |
| `duplicate_username` / `unknown_role` | membro da equipe sem acesso até decisão | sim |
| `duplicate_coupon_code` / `duplicate_voucher_code` | código repetido; só o primeiro importado | sim |
| `secret_section_not_imported` | credencial a recadastrar no `.env` (rotacionar) | sim |
| `archived_not_migrated` | tabela abandonada exportada para o arquivo morto | sim (D-11/D-20) |
| `source_changed` | registro já importado mudou na origem | sim |

## Conciliação (a importação real falha se qualquer item não fechar)

| Verificação | Como |
|---|---|
| Saldo de fidelidade | por cliente com saldo antigo: soma dos lançamentos = `fidelidade.pontos` |
| Saldo de estoque | por produto: soma das movimentações = `produtos.quantidade` |
| Opt-outs | todo e-mail de `email_optout` está em `email_suppressions` |
| Assinaturas | mesmas datas de fim e IDs de gateway; mesmo número de vigentes |
| Faturamento mensal | regra do financeiro antigo (`max(0, serviços − desconto) + produtos` por mês) = soma dos totais importados |
| Somas financeiras | despesas, comissões pagas, vales e pagamentos de assinatura: mesmo total em centavos |

Além disso: contagem por tabela no relatório e as 24 verificações do `IntegrityChecker`.

## Resultado com o banco fictício

Banco gerado por `FictitiousLegacyDatabase` (dados inventados, e-mails `@exemplo.test`), com volume normal
configurável e ~80 casos problemáticos fixos: duplicidades de e-mail/telefone/CPF/código, órfãos em todas as
relações, datas e valores inválidos, status desconhecidos, JSON quebrado, senhas não-bcrypt, usuários repetidos,
cupons e vales repetidos, assinatura sem cliente, saldo de pontos divergente, estoque negativo, tabela
desconhecida, seções de configuração com credenciais falsas e uma variante de schema antigo (sem as colunas
acrescentadas depois).

| Volume (clientes / agendamentos) | Simulação | Importação | Reexecução | Memória de pico | Conciliação e integridade |
|---:|---:|---:|---:|---:|---|
| 100 / 500 | 0,4 s | 0,3 s | 0,07 s | 30 MB | ✅ |
| 1.000 / 5.000 | 2,9 s | 2,9 s | 0,3 s | 34 MB | ✅ |
| 5.000 / 20.000 | 11,9 s | 11,9 s | 1,4 s | 46 MB | ✅ |
| 10.000 / 50.000 | 29,0 s | 29,4 s | 3,3 s | 70 MB | ✅ |

Medido neste ambiente (PHP 8.4, SQLite, disco local). O tempo cresce de forma linear (~0,6 ms por
agendamento com seus itens, pagamento, lembretes e eventos). Uma versão anterior usava uma auto-junção
para detectar sobreposição de horários e levava 97 s nos 50 mil; foi trocada por uma varredura ordenada.
**Inserções em lote não foram usadas** de propósito: cada registro precisa do id novo para
`legacy_references` e para os filhos, e ganhar poucos segundos numa operação feita uma vez não justifica o
risco. A transação única é o que torna a gravação rápida no SQLite.

## Limitações conhecidas

- **Validado só com dados fictícios.** O primeiro ensaio com a cópia real vai revelar formatos que o
  fictício não previu. A etapa rejeita o que não entende (pendência), mas a lista de pendências pode ser longa.
- Mudança numa tabela filha sem mudança na linha principal (ex.: evento novo de agendamento já importado) não é
  detectada na reexecução. Para os ensaios, recomenda-se importar num banco novo vazio a cada vez; a
  idempotência protege contra execução dupla por engano.
- Fotos (`uploads/`) não são copiadas: os caminhos são preservados e a cópia é feita na virada.
- O relatório de pendências é por execução; a tabela `import_issues` acumula todas as execuções reais.
