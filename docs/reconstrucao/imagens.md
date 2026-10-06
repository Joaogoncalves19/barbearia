# Imagens (Fase 11)

> Fotos dos profissionais, imagens dos serviços e imagens do site (início, a barbearia, galeria, logo).

## 1. Onde ficam

| Tipo | Onde se envia | Pasta no disco de mídia | Maior largura gravada |
|---|---|---|---|
| Foto do profissional | Painel → Equipe → profissional (`professionals.display`) | `professionals/` | 1200 px |
| Imagem do serviço | Painel → Serviços → serviço (`services.display`) | `services/` | 1200 px |
| Início (foto principal) | Painel → Site → Imagens (`site.manage`), até 3 | `site/hero/` | 2400 px |
| A barbearia (ambiente) | idem, até 2 | `site/about/` | 1600 px |
| Galeria | idem, até 24 (aparece a partir de 3) | `site/gallery/` | 1600 px |
| Logo | idem, 1 | `site/logo/` | 600 px |

Disco: `MEDIA_DISK` (padrão `public`, servido em `/storage`). O banco guarda só o caminho; nunca o arquivo,
nunca no Git. Se o disco virar externo (ex.: S3), a CSP (`img-src`) precisa incluir o domínio dele.

## 2. Envio seguro

Todo arquivo enviado é **reprocessado** no servidor (`ImageStore` + `ImageProcessor`, GD); o arquivo original
**nunca** é publicado.

| Conferência | Como |
|---|---|
| Formato | JPEG, PNG ou WebP, pelo **conteúdo** (MIME real via `finfo` + `getimagesize` coerentes), não pela extensão nem pelo tipo informado pelo navegador. SVG recusado (pode conter script) |
| Decodificação | A imagem precisa ser lida de verdade pelo GD: arquivo "poliglota" (cabeçalho de imagem + script), texto com extensão `.jpg` ou imagem corrompida é recusado |
| Tamanho | Até 8 MB, mínimo 200 × 200 px, máximo 6000 × 6000 px e 36 megapixels (protege a memória do servidor) |
| Nome | Sempre gerado (ULID); o nome enviado é descartado |
| Extensão | Sempre `.webp` (nada enviado vira arquivo executável no servidor) |
| Metadados | EXIF/GPS/aparelho descartados no reencode; orientação das fotos de celular aplicada antes |
| Acesso | Envio só com a permissão do cadastro (`site.manage`, `services.display`, `professionals.display`); imagens do site com limite de 10 envios/min e 100/h por pessoa (`throttle:uploads`) e auditoria |

## 3. Tamanhos e carregamento

- Saída WebP qualidade 80, sem cortar (o enquadramento é do CSS), transparência preservada (logo).
- Variantes de 480, 960, 1600 e 2400 px (só as menores que a original), para `srcset`/`sizes`.
- O caminho guarda as dimensões (`pasta/ID.LxA.webp`): a página escreve `width`/`height` (sem salto de
  layout) sem consultar o disco.
- Só a foto do topo carrega na hora (`fetchpriority="high"`); todas as outras são preguiçosas
  (`loading="lazy"`, `decoding="async"`).
- Imagens enviadas antes da Fase 11 continuam funcionando (sem variantes) até serem trocadas.

## 4. Texto alternativo

Obrigatório nas imagens do site (3 a 160 caracteres, editável depois). Retratos usam "Retrato de {nome}";
miniaturas decorativas dentro de links já descritos usam `alt=""`. Logo: o nome da barbearia.

## 5. Remover

Remover uma imagem do site apaga o arquivo e as variantes (conteúdo do site, não histórico) e grava
`site.image_deleted` na auditoria. Desativar só tira do site, sem apagar.
