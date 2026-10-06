# Site público — arquitetura (Fase 11)

> A vitrine da barbearia. Direção visual oficial **A — Ofício contemporâneo** ([identidade-visual.md](identidade-visual.md));
> a direção B não existe no produto. Documentos irmãos: [home.md](home.md), [imagens.md](imagens.md),
> [seo.md](seo.md), [acessibilidade.md](acessibilidade.md), [agendamento-publico.md](agendamento-publico.md).

## 1. Princípios

1. **Nada inventado.** Sem números de clientes, anos de experiência, prêmios, depoimentos, avaliações,
   endereço, horário ou preço fictícios. Bloco sem dado real **não aparece** (nem com espaço vazio).
2. **Um domínio só.** O site lê os mesmos cadastros e regras do painel e da agenda: serviços
   (`ServiceCatalog`), equipe (`ProfessionalDirectory`), disponibilidade (`Availability`), planos, avaliações
   aprovadas, horário de funcionamento (`business_hours`). Nenhum cadastro paralelo para o site.
3. **Só leitura.** As páginas públicas não gravam nada; agendar usa o fluxo real (Fase 5) e o login só na
   confirmação.
4. **Leve.** CSS e JS já existentes (Vite), fontes locais, imagens WebP em tamanhos certos, sem vídeo, sem
   biblioteca nova, sem rastreador de terceiros.

## 2. Páginas

| URL | Rota | Mostra | Sem conteúdo |
|---|---|---|---|
| `/` | `home` | Início ([home.md](home.md)) | Sempre existe |
| `/servicos` | `site.services` | Catálogo publicado por categoria, preço, duração, imagem; "Ver horários" | Mensagem "em breve" |
| `/equipe` | `site.team` | Profissionais publicados | Mensagem "em breve" |
| `/equipe/{slug}` | `site.professional` | Retrato, especialidade, apresentação, serviços que ele faz → horários dele | 404 |
| `/assinatura` | `site.plans` | Planos ativos (preço, serviços incluídos) e como assinar | 404 |
| `/privacidade`, `/termos` | `site.privacy`, `site.terms` | Texto do dono (texto, nunca HTML) | 404 (e some do rodapé) |
| `/sitemap.xml`, `/robots.txt` | `sitemap`, `robots` | [seo.md](seo.md) | — |
| `/agendar…` | `booking.*` | Fluxo de agendamento (Fase 5) | — |

As páginas de referência da Fase 1 continuam em `/prototipos` (só com `BARBEARIA_PROTOTYPES=true`, sem
indexação); a raiz agora é sempre o site real.

## 3. O que aparece, de onde vem

| Bloco | Fonte | Regra |
|---|---|---|
| Serviços | `services` | Ativo, categoria ativa (ou sem categoria) e **publicado** (`is_public`). Destaque (`is_featured`) no início; sem destaque, os 6 primeiros |
| Equipe | `professionals` | **Ativo, recebe agendamento e publicado** (decisão do dono na Fase 11; antes bastava ativo + publicado) |
| Assinatura | `plans` + versão atual | Plano ativo com versão atual |
| Horário e "aberto agora" | `business_hours` + bloqueio da barbearia inteira | Mesma configuração da agenda |
| Nota média | `reviews` aprovadas | Só com 3 ou mais avaliações publicadas |
| Depoimentos | `reviews` aprovadas **e destacadas** pela equipe, com comentário | Até 3; primeiro nome + inicial; texto escapado |
| Textos, contatos, legal | `settings` (`site.content`), painel → Site → Conteúdo | Vazio = não aparece |
| Fotos (início, a barbearia, galeria, logo) | `site_images`, painel → Site → Imagens | Galeria só com 3+ fotos ativas |

**Conteúdo do sistema antigo:** na primeira leitura, nome, telefone, endereço, slogan, redes e textos do
"Sobre" vêm de `legacy.config_geral`/`legacy.landing_page`, **descartando os textos padrão** dele ("Sua
Barbearia", "Rua Exemplo, 123", "(00) 00000-0000", estatísticas, texto padrão de "Sobre") e nunca os termos e
a política antigos em HTML. Ao salvar no painel, vale o que foi salvo.

## 4. Painel (`site.manage`: proprietário e gerente)

- **Conteúdo do site:** nome, frase de marca, subtítulo, bairro/cidade, "A barbearia" (texto e até 4
  diferenciais "Título: explicação"), endereço, referência, link do mapa, telefone, WhatsApp, e-mail público,
  Instagram/Facebook (só links https desses domínios), CNPJ, política de privacidade e termos. Tudo texto
  (sem HTML), tamanhos limitados, auditado (`site.settings_changed` com os campos alterados).
- **Imagens do site:** [imagens.md](imagens.md).
- Serviços, preços, fotos de serviço, equipe e fotos dos profissionais continuam nos próprios cadastros
  (Fase 4), com as próprias permissões.

## 5. Segurança e dados

- Nenhuma página pública expõe CPF, e-mail ou nome completo de cliente, usuário/e-mail da equipe, dado
  financeiro ou de outro cliente (testado em `SitePagesTest::test_nenhuma_pagina_publica_expoe...`).
- Profissional, plano ou página legal inexistente/fora do site: 404 (sem dizer o motivo).
- Agendar pelo site só o que é publicado: a regra está na `Availability` (canal do cliente), não na tela —
  um pedido montado à mão para um serviço ou profissional fora do site é recusado
  ([agendamento-publico.md](agendamento-publico.md)).
- Links externos com `rel="noopener"`; links de Instagram/Facebook/mapa só dos domínios esperados.
- CSP igual ao resto do sistema (sem script de terceiros; dados estruturados com o nonce da requisição).
- Envio de imagens: [imagens.md §2](imagens.md#2-envio-seguro).

## 6. Código

`app/Modules/SiteContent`: `Services/PublicSite` (leituras), `Services/SiteImages`, `Support/SiteSettings`,
`Support/OpeningHours`, `Support/StructuredData`, `Support/Brand`, `Models/SiteImage`. Controllers
`Site\HomeController`, `Site\PagesController`, `Panel\Site\SiteContentController`. Views `resources/views/site`,
componentes `x-site.page`, `x-site.img`, `x-site.ornament`; estilo em `resources/css/areas/site-publico.css`.
