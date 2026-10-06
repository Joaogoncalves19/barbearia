# SEO do site (Fase 11)

> O básico bem feito, sem inventar nada.

## 1. Cada página

| Item | Como |
|---|---|
| `<title>` | "Página · Nome da barbearia" (o início só o nome) |
| `<meta name="description">` | Subtítulo do início ou texto próprio da página |
| Títulos | Um `h1` por página; seções com `h2` |
| URL | Amigáveis e em português: `/servicos`, `/equipe/{nome}`, `/assinatura`, `/agendar/{serviço}` |
| Canônico | `<link rel="canonical">` com a URL da própria página |
| Open Graph | `og:type`, `og:locale` (pt_BR), `og:site_name`, `og:title`, `og:description`, `og:url`, `og:image` (foto do topo/ambiente, se houver; retrato na página do profissional) e `twitter:card` |
| Ícone | `favicon.svg` (tesoura em cobre sobre tinta) + `favicon.ico` |
| Idioma | `<html lang="pt-BR">` |
| Áreas privadas | Painel, conta do cliente, login, links de e-mail e protótipos com `noindex` e fora do sitemap |

## 2. Sitemap e robots

- `/sitemap.xml`: início, serviços, equipe e cada profissional publicado, assinatura (se houver plano),
  agendar, privacidade/termos (se preenchidos). Gerado na hora a partir do conteúdo publicado.
- `/robots.txt` (rota, não arquivo): em **produção**, libera o site e bloqueia `/painel`, `/minha-conta`,
  login, cadastro, redefinição de senha, links de e-mail (`/presenca`, `/descadastro`), protótipos e webhooks,
  e aponta o sitemap. Em local/homologação: `Disallow: /` (cópia de teste nunca indexada).

## 3. Dados estruturados (JSON-LD)

Só no início, tipo `BarberShop`, com **o que existe**: nome, URL, descrição (subtítulo), endereço (se
configurado), telefone, e-mail público, foto e logo (se enviados), horário (`openingHoursSpecification`,
da agenda), redes (`sameAs`) e mapa. **Fora, de propósito:**

- `aggregateRating`/`review`: o Google não aceita a nota que o próprio negócio exibe sobre si (avaliação
  "auto-servida"); a nota real aparece na página, não nos dados estruturados;
- `priceRange` e qualquer campo sem dado real.

O JSON vai com o nonce da CSP e escapado para nunca fechar a tag `<script>`.

## 4. Pendências

- **Lighthouse CI** (meta do roadmap: SEO ≥ 95): NÃO EXECUTADO aqui (sem a ferramenta no ambiente); ver
  [relatorio-fase-11.md](relatorio-fase-11.md).
- Domínio definitivo e Search Console: na virada (Fase 13).
