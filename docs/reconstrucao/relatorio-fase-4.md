# Relatório da Fase 4 — Serviços, categorias, profissionais e equipe

> **Status: concluída em 2026-09-30, aguardando aprovação explícita do dono para iniciar a Fase 5.**
> Branch `claude/fase-4-catalogo-equipe`, criada a partir de `claude/fase-3-identidade` (a Fase 3 ainda não
> está na `main`). Só dados fictícios; nenhum banco de produção acessado; sistema antigo não alterado; nenhum
> segredo no repositório.
>
> Legenda: **PASSOU** · **NÃO EXECUTADO** · **PENDENTE** · **FALHOU**.

## 1. Critérios de aceite (item 27 do briefing)

| Critério | Situação | Evidência |
|---|---|---|
| Categorias funcionando | PASSOU | `CategoryAdminTest` (8), E2E |
| Serviços funcionando | PASSOU | `ServiceAdminTest` (17), E2E |
| Preços em centavos | PASSOU | `price_cents` + `Money` (sem float), `test_preco_aceita_formatos_brasileiros_sem_float` |
| Duração padronizada | PASSOU | minutos inteiros, múltiplo de 5, regra única em `Duration` (`DurationTest`) |
| Ativação/desativação | PASSOU | categoria, serviço e profissional; desativar pede confirmação (E2E) |
| Ordenação | PASSOU | subir/descer controlado pelo sistema, sem posição repetida; pelo teclado (E2E) |
| Histórico preservado | PASSOU | preço/nome/duração fotografados no agendamento; desativar não apaga; exclusão só do que nunca foi usado |
| Cadastro/edição de profissional | PASSOU | `ProfessionalAdminTest` (15), E2E com foto |
| Ativação/desativação de profissional | PASSOU | `test_desativar_preserva_historico_e_nao_mexe_na_conta` |
| Vínculo opcional com conta | PASSOU | `test_cadastra_profissional_sem_conta_de_acesso`, `test_vincula_conta_existente_e_nao_deixa_a_mesma_conta_em_dois` |
| Vínculo com serviços | PASSOU | `test_define_os_servicos_que_o_profissional_executa_e_audita`, E2E |
| Permissões aplicadas / autorização no servidor | PASSOU | 12 habilidades novas, rota + campo + policy (`CatalogAuthorizationTest`, 10) |
| IDOR testado | PASSOU | `test_ids_manipulados_nao_dao_acesso_indevido`, `test_campos_fora_do_formulario_sao_ignorados`, ficha de outro profissional (Fase 3) |
| Auditoria funcionando | PASSOU | preço/duração antes→depois, ativação, vínculos (`professional.services_changed`) |
| Design System oficial / desktop / celular / acessibilidade / sem rolagem lateral | PASSOU | Direção A; axe sem violação séria/crítica em 9 telas novas, nos dois tamanhos |
| Testes passando / PHPStan sem erros | PASSOU | 310 PHPUnit; Larastan nível 6, 0 erros |
| CI verde | PASSOU | run nº 21, os dois jobs (§9) |
| Nenhum secret no código | PASSOU | `ConfigurationTest`; senhas de E2E aleatórias por execução |
| Documentação e roadmap | PASSOU | 4 documentos novos + roadmap, README, modelo, regras, permissões, autorização, decisões |

## 2. O que foi implementado

**Modelo** (migration `2026_09_30_000100_add_catalog_and_team_admin_columns`, sem editar as anteriores):

- **Categorias:** `slug`, `description`, `is_active`, `lock_version`.
- **Serviços:** `slug`, `is_public`, `is_featured`, `image_path`, `lock_version`.
- **Profissionais:** `slug`, `headline`, `bio`, `is_public`, `is_featured`, `lock_version` e um índice.
- **Registros existentes:** recebem slug na própria migration. O importador passou a gerar slug pela mesma
  regra (`UniqueSlug`).

**Domínio:**

- **Serviços que gravam:** `CategoryAdmin`, `ServiceAdmin` e `ProfessionalAdmin`. São a única porta de
  gravação.
- **Consultas de leitura prontas para a agenda e o site:** `ServiceCatalog` e `ProfessionalDirectory`.
- **Utilitários compartilhados:**
  - `Duration`: regra e formatação da duração.
  - `Ordering`: ordem, com transação e bloqueio.
  - `UniqueSlug`.
  - `ImageStore`: disco de mídia configurável.
  - `StaleRecord`: concorrência otimista.
  - `Money::toInput`.
- **Policies:** `ServicePolicy` e `ServiceCategoryPolicy`, para a exclusão, que depende do histórico. A
  `ProfessionalPolicy` foi atualizada.

**Telas (painel, direção A):**

- Categorias: lista, nova, editar e excluir (só vazia).
- Serviços: lista por categoria com filtros, novo e editar. A edição mostra o **histórico de preço** e
  permite excluir o que nunca foi usado.
- Profissionais: lista com filtros, novo, ficha, editar (conta vinculada, apresentação, foto) e serviços
  executados.
- Em todas as listas:
  - ordem por subir/descer;
  - desativar com modal de confirmação;
  - ativar com um clique.
- Menu "Cadastros" por permissão.
- A auditoria passou a mostrar o que mudou (antes → depois).
- Componente novo `x-ui.file`, `x-ui.confirm` com campos ocultos, e 6 ícones.

## 3. Estratégias

**Preço** ([precos.md](precos.md)): o preço **atual** (`services.price_cents`) vale para agendamentos novos. O
preço **registrado** no item do agendamento é histórico imutável. **Não foi criada tabela de histórico de
preço:**

- o snapshot já responde "quanto foi cobrado";
- a auditoria já responde "quem mudou, quando, de quanto para quanto" (e alimenta a tela de histórico);
- uma tabela extra seria uma segunda verdade.

Reavaliar se o dono quiser **preço com data futura**.

**Histórico:**

- **Serviço:** usado nunca é apagado, só desativado. Exclusão só se nunca apareceu em agendamento, combo ou
  plano.
- **Categoria:** excluída só se nunca teve nada.
- **Profissional:** nunca é excluído. Agendamentos guardam o nome fotografado.
- **Desativar** tira de novos agendamentos e do site, sem tocar no passado.

**Uma fonte de verdade para cada conceito:**

| Conceito | Onde vive |
|---|---|
| Serviço, preço atual e duração | `Service` |
| Regra de duração | `Duration` |
| "Agendável" e "no site" | escopos do model |
| "Quem faz este serviço" | `ProfessionalDirectory` |
| Agrupamento por categoria | `ServiceCatalog` |

**Concorrência:**

- **Edição:** `lock_version`. O segundo a salvar recebe aviso, sem sobrescrever em silêncio.
- **Ordem e vínculo profissional × serviço:** transação com bloqueio. Um serviço desativado no meio do
  vínculo faz a gravação ser recusada inteira.

## 4. Decisões

| # | Decisão | Motivo |
|---|---|---|
| T4-01 | Duração = minutos inteiros, múltiplo de 5, de 5 a 480 | Unidade que a agenda usa direto; grade de 5 min comporta 15/30 |
| T4-02 | Preço atual entre R$ 1,00 e R$ 10.000,00 | "Serviço sem preço válido" não é aceito; cortesia será desconto (Fase 8) |
| T4-03 | Sem tabela de histórico de preço (§3) | Snapshot + auditoria já atendem |
| T4-04 | Estados booleanos independentes (`is_active`, `is_bookable`, `is_public`, `is_featured`), combinados só nos escopos | Poucos estados, cada um com significado claro |
| T4-05 | Permissões granulares substituem `team.*`/`catalog.manage` (que ainda não tinham tela); preço e exibição conferidos também por campo | Pedido do briefing (item 16) |
| T4-06 | Recepção consulta catálogo e equipe, sem alterar | Precisa saber preço/duração/quem faz; menor privilégio |
| T4-07 | Profissional não edita a própria ficha nem os próprios serviços | Serviços e apresentação são decisão da gestão |
| T4-08 | Vínculo com serviço que ficou inativo é preservado | Reativar o serviço restaura quem o fazia |
| T4-09 | `slug` gerado na criação e nunca alterado ao renomear | "Identificação estável" (links do site) |
| T4-10 | Imagens no disco `MEDIA_DISK` (padrão `public`); só JPEG/PNG/WebP; nome gerado | Nada no banco nem no Git; SVG pode conter script |
| T4-11 | **CPF obrigatório garantido no model** para todo cliente novo (site, balcão, qualquer canal futuro); CPF não pode ser removido | Decisão do dono; só o importador (legado) grava sem CPF |
| T4-12 | Expediente, combos, produtos e configurações sem tela nesta fase | Briefing excluiu "disponibilidade de horários" e não pediu os demais (§10) |

**Pendente do dono — D-24 (combos):** o sistema antigo tinha combos (preço próprio, duração = soma dos
serviços). O modelo novo os mantém (`packages`) e o importador os traz, mas não há tela.

- **Opção A (recomendada):** tratar "Corte + Barba" como **serviço comum** (preço e duração próprios).
  - Fica uma definição só de serviço, preço e duração, e a agenda fica mais simples.
  - Os combos importados seriam convertidos em serviços na migração real.
- **Opção B:** tela de combos na Fase 5, e a agenda lidando com dois tipos de item.

## 5. Permissões

Ver [papeis-permissoes.md](papeis-permissoes.md):

| Habilidades | Quem tem |
|---|---|
| `services.view`, `professionals.view` | Proprietário, gerente, recepção |
| `services.create/update/toggle/price/display`, `professionals.create/update/toggle/services/display` | Proprietário, gerente |
| Nenhuma do catálogo ou da equipe | Financeiro, profissional, cliente |

Proteção em três camadas:

- **Rota:** uma habilidade por ação.
- **Campo:** preço e exibição, com formulário adulterado respondendo 403.
- **Policy:** exclusão só do que nunca foi usado.

## 6. Auditoria

Mesmo mecanismo da Fase 3 (`Auditable` + `AuditTrail`, tabela `audit_logs`, só inclusão). Registra:

- criação e edição de categoria, serviço e profissional, com antes/depois de **preço**, **duração** e
  **conta vinculada**;
- ativação e desativação;
- exclusão;
- alteração dos serviços executados (`professional.services_changed`, com incluídos e retirados).

Reordenar não é auditado: só troca a posição de exibição, sem alterar o cadastro.

## 7. Testes

| Verificação | Resultado |
|---|---|
| PHPUnit | **PASSOU** — 310 testes (eram 252), 0 falhas, 0 pulados |
| — novos na Fase 4 | `Catalog/CategoryAdminTest` (8), `Catalog/ServiceAdminTest` (17), `Catalog/ServiceCatalogTest` (1), `Team/ProfessionalAdminTest` (15), `Security/CatalogAuthorizationTest` (10), `Unit/DurationTest` (4), `Data/ConstraintsTest` (+2, CPF), `LegacyImport/ImportScenariosTest` (+1, slug) |
| — ajustados | `PermissionMatrixTest` (matriz nova); `ConstraintsTest::test_valores_invalidos_viram_nulo_e_nao_colidem`: CPF inválido deixou de "virar nulo" e passou a ser **recusado** (regra nova, com teste próprio). O teste não foi desativado |
| Larastan nível 6 | **PASSOU** — 0 erros (9 apontados durante a fase, todos corrigidos na causa: tipos do `AuditLog`, agrupamento por categoria duplicado em 2 controllers → centralizado no `ServiceCatalog`, genéricos) |
| Pint | **PASSOU** |
| Build (Vite) | **PASSOU** |
| Importador com banco fictício (simulação, importação, reexecução) | **PASSOU** (local, numa cópia separada do banco) |
| Playwright + axe | **PASSOU** — 73 passando, 3 ignorados de propósito (o da Fase 1 e os 2 testes "só teclado" no celular) |
| — novos na Fase 4 | categoria e serviço (cadastro, erro de validação ligado ao campo sem perder o digitado, alteração de preço, histórico de preço, desativar com modal, Esc cancela e devolve o foco, filtro, ativar); profissional (cadastro sem conta, foto enviada e exibida, vínculo com serviço, ficha, desativar com confirmação); ordem pelo teclado. Axe e rolagem lateral em 9 telas novas, celular e desktop |

## 8. Problemas encontrados

1. **Extensão GD** ausente no PHP local (imagens de teste do Laravel). Foi ativada no `php.ini` local e
   declarada no CI.
2. **Factories de catálogo e equipe** não recarregavam o registro, então `lock_version` ficava vazio em teste.
   Foram corrigidas como na Fase 3.
3. **Testes de navegador em paralelo:**
   - dependiam de dados criados por outro teste (categoria);
   - no servidor embutido do PHP, a foto demorava a carregar.

   Os testes passaram a criar os próprios dados e a esperar o carregamento. Nenhum teste foi ignorado para
   passar.
4. **O modal de confirmação** só enviava um POST vazio. Ganhou campos ocultos (para ativar/desativar).
5. **Imagens precisam de `php artisan storage:link`.** O comando foi acrescentado ao CI e documentado no
   `.env.example`. **Atenção no deploy:** rodar `storage:link` no servidor (ou usar um disco externo).

## 9. CI

**PASSOU.** Run nº 21 (commit `fbf3287`,
https://github.com/Joaogoncalves19/barbearia/actions/runs/36756497695), PHP 8.4, os dois jobs verdes em todos
os passos:

- **Novo sistema (Laravel):** dependências, Pint, Larastan nível 6, auditoria de dependências, build, testes
  PHP, importador com banco fictício e Playwright + axe (com `storage:link` e GD).
- **Sistema atual:** regressão de segurança S-01 a S-04.

O commit seguinte só atualiza este relatório (documentação).

## 10. Pendências

1. **D-24 — combos:** decisão do dono (§4).
2. **Expediente, pausas, folgas e bloqueios:** a tela e as regras entram com a agenda (Fase 5). O modelo existe
   e o importador o preenche. Atenção para a Fase 5: hoje há **duas formas** de representar o almoço (vários
   intervalos em `working_hours` ou uma pausa em `schedule_breaks`, que o importador usa). A Fase 5 deve
   escolher uma só.
3. **Configurações do estabelecimento e regras de agenda** (antecedência, intervalo, políticas): Fase 5.
4. **Produtos e estoque:** tela na Fase 6 (caixa), com habilidade própria.
5. **Imagens:** WebP/AVIF, tamanhos responsivos e remoção de metadados EXIF (ex.: localização em foto de
   celular) na Fase 11. Até lá, a imagem é servida como foi enviada.
6. **Tela de clientes (cadastro pelo balcão):** não pedida nesta fase. A regra de CPF obrigatório já está no
   model e vai valer quando a tela existir.
7. **Hospedagem (D-01)** e provedor de e-mail (D-05): sem mudança.

## 11. Riscos

| Risco | Mitigação |
|---|---|
| Esquecer `storage:link` no servidor: fotos quebradas | Documentado; CI roda o comando; disco configurável |
| Fotos com metadados de localização | Tratamento previsto na Fase 11; hoje só a gestão envia fotos |
| Catálogo importado com duração fora da regra nova (ex.: 10 h) | Continua como está; a regra vale ao alterar o campo. Revisar no ensaio da migração |
| Combos sem tela até a decisão D-24 | Os dados continuam preservados; a Fase 5 depende da decisão |
| Matriz de permissões proposta pela equipe técnica | Conservadora; o dono revisa [papeis-permissoes.md](papeis-permissoes.md) |

## 12. Recomendações para a Fase 5 (motor de agenda)

1. **Decidir D-24** (combos) e **D-13** (política de cancelamento/remarcação) antes de começar.
2. **Usar só** `ProfessionalDirectory::bookableFor()` / `bookableServicesOf()` e
   `ServiceCatalog::bookableByCategory()` para oferecer serviços e profissionais. Não repetir a regra.
3. **Criar o item do agendamento copiando** nome, preço (`price_source = catalog_at_booking`) e duração do
   serviço **no momento**, uma única vez, num serviço de domínio (`BookingService`).
4. **Escolher uma forma só de representar pausas** (§10.2) e construir a tela de expediente/folgas com
   `professionals.*` ou uma habilidade nova de agenda.
5. **Manter a grade da agenda em múltiplos de `Duration::STEP_MINUTES`.**

---

**Aguardando aprovação explícita para iniciar a Fase 5.** Silêncio não é autorização.
