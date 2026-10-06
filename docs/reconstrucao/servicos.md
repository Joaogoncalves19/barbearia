# Serviços e categorias (Fase 4)

Como o catálogo funciona no sistema novo. Preços têm documento próprio ([precos.md](precos.md)); quem executa
cada serviço está em [relacao-profissional-servico.md](relacao-profissional-servico.md).

## 1. Uma fonte de verdade

No sistema antigo havia várias formas de calcular valor e duração de um serviço. No novo, cada conceito tem
**um lugar só**:

| Conceito | Onde vive | Quem usa |
|---|---|---|
| Serviço (nome, descrição, categoria) | `services` / model `Service` | todas as telas, a agenda, o site |
| Preço atual | `services.price_cents` (centavos) | agendamentos **novos** ([precos.md](precos.md)) |
| Duração | `services.duration_minutes` (minutos inteiros) | a agenda, direto, sem conversão |
| Regra de duração válida e formatação ("1 h 15 min") | `App\Modules\Shared\Support\Duration` | validação, model, telas |
| "Pode ser agendado?" | escopo `Service::bookable()` | agenda (Fase 5), `ServiceCatalog` |
| "Aparece no site?" | escopo `Service::shownPublicly()` | site (Fase 11), `ServiceCatalog` |
| Catálogo agrupado por categoria, na ordem | `App\Modules\Catalog\Services\ServiceCatalog` | painel, agenda, site |
| Gravação (criar, editar, ativar, ordenar, excluir) | `App\Modules\Catalog\Services\ServiceAdmin` / `CategoryAdmin` | só os controllers do painel |

Controllers só validam e chamam o serviço; views e JavaScript não calculam preço, duração nem disponibilidade.

## 2. Categorias

| Campo | Regra |
|---|---|
| `name` | Obrigatório, 2–80 caracteres, único sem diferenciar maiúsculas |
| `slug` | Identificação **estável** (URLs do site). Gerado na criação e **não muda** ao renomear |
| `description` | Opcional, aparece no site |
| `sort_order` | Controlado pelo sistema (subir/descer; renumera 10, 20, 30...) |
| `is_active` | Categoria **inativa tira os serviços dela de novos agendamentos e do site**, sem mudar os serviços nem o histórico |

**Exclusão:** só para categoria que **nunca** teve serviço, combo ou produto (nem excluído). Com histórico, a
única opção é desativar. O botão "Excluir" só aparece quando a exclusão é possível e sempre pede confirmação.

## 3. Serviços

| Campo | Regra |
|---|---|
| `name` | Obrigatório, 2–120 caracteres, único sem diferenciar maiúsculas |
| `category_id` | Obrigatório; categoria existente e **ativa** (a atual continua aceita se for desativada depois) |
| `description` | Opcional (até 1000 caracteres), pública |
| `price_cents` | Preço **atual**, em centavos, entre R$ 1,00 e R$ 10.000,00. Digitado como dinheiro ("45,00", "1.250,90"), convertido uma vez pelo `Money` (sem float) |
| `duration_minutes` | Minutos inteiros, **múltiplo de 5**, entre 5 min e 8 h |
| `is_active` | Oferecido para novos agendamentos |
| `is_public` / `is_featured` | Aparece / tem destaque no site. Serviço ativo e fora do site ainda pode ser agendado pela equipe |
| `image_path` | Opcional, no disco de mídia (seção 5) |
| `slug` | Estável, como na categoria |
| `sort_order` | Ordem **dentro da categoria** |
| `lock_version` | Controle de concorrência (seção 6) |

As regras de preço e duração estão também no model: valem para qualquer gravação que altere esses campos,
não só para o formulário. Registros antigos importados com valores fora da regra continuam como estão até
alguém alterar o campo.

## 4. Estados: ativo × histórico

| Situação | Novos agendamentos | Site | Histórico |
|---|---|---|---|
| Serviço ativo, categoria ativa | sim | se `is_public` | — |
| Serviço ativo, fora do site | sim (**só pela equipe**; Fase 11: o canal do cliente recusa, regra na `Availability`) | não | — |
| Serviço **inativo** | não | não | intacto: agendamentos antigos continuam apontando para ele, com o nome, o preço e a duração **fotografados no item** |
| Categoria inativa | não (para todos os serviços dela) | não | intacto |
| Serviço **excluído** (só se nunca usado) | não | não | não havia histórico |

**Serviço histórico** é qualquer serviço que já apareceu num agendamento, combo ou plano: nunca é apagado,
só desativado. O agendamento não depende do catálogo para mostrar o que foi feito (snapshot em
`appointment_items`, regra 19 de [regras-dados.md](regras-dados.md)).

## 5. Imagens

- Disco configurável `MEDIA_DISK` (padrão `public` = `storage/app/public`, servido em `/storage` depois de
  `php artisan storage:link`). Em produção pode ser outro disco (ex.: S3) sem mudar código.
- O banco guarda só o caminho. O nome do arquivo é sempre gerado, nunca o enviado pelo usuário.
- JPEG, PNG ou WebP, até 3 MB, de 200×200 a 6000×6000 px. **SVG não é aceito** (pode conter script).
- Ao trocar a imagem, a antiga é apagada, e só depois de a gravação no banco dar certo.
- Nada de imagem no Git (`storage/app/public` está fora do versionamento).
- Pendente (Fase 11): gerar WebP/AVIF e tamanhos responsivos, e remover metadados EXIF das fotos.

## 6. Concorrência

- **Edição:** o formulário leva a versão (`lock_version`) que a pessoa viu. Se outra pessoa salvou antes, nada
  é gravado e aparece: "Outra pessoa alterou este cadastro enquanto você editava". Resolve, por exemplo, dois
  administradores alterando o preço ao mesmo tempo.
- **Ordem:** subir/descer renumera o grupo inteiro numa transação com bloqueio. Duas pessoas movendo ao mesmo
  tempo nunca deixam posições repetidas.
- **Exclusão:** a verificação de "nunca usado" e a exclusão acontecem na mesma transação.

## 7. Auditoria

Criação, edição (inclusive **preço** e **duração**, com antes/depois), ativação/desativação e exclusão de
serviços e categorias vão para `audit_logs` pela trait `Auditable` (mesmo mecanismo da Fase 3), com quem,
quando e IP. A tela `/painel/auditoria` mostra o resumo das mudanças ("price_cents: 4500 → 5000").

## 8. Telas

`/painel/categorias` e `/painel/servicos` (lista por categoria, filtros ativos/inativos/todos, ordem, ativar
com um clique, desativar com confirmação), `/novo` e `/{id}/editar` (com histórico de preço e exclusão
quando possível). Permissões em [papeis-permissoes.md](papeis-permissoes.md).

## 9. Fora desta fase

- **Combos (`packages`) e produtos/estoque:** as tabelas existem desde a Fase 2 (e recebem os dados do
  importador), mas não têm tela nesta fase, porque o briefing da Fase 4 não os incluiu. Ver a pendência
  no [relatório](relatorio-fase-4.md).
- Disponibilidade de horários, agenda e agendamento: Fase 5.
