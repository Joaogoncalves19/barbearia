# Relatório da Fase 11 — Site público

> **Status: concluída e APROVADA pelo dono (2026-10-06)**, com as decisões P11-01 a P11-05 registradas em
> [decisoes-pendentes.md](decisoes-pendentes.md) (P11-03 testada na Fase 12; P11-05 virou item futuro no
> roadmap). A falha única da 1ª rodada de navegador fica registrada como instabilidade não reproduzida, sem
> causa comprovada. Branch
> `claude/fase-11-site-publico`, criada a partir de `claude/fase-10-comunicacao`. Só dados fictícios; nenhum
> banco real; nenhuma credencial (Resend e Stripe só por variável de ambiente, vazias aqui); nenhuma
> migração real; sistema antigo não alterado (lido só como fonte do conteúdo e das regras).
>
> Legenda: **PASSOU** · **NÃO EXECUTADO** · **PENDENTE** · **FALHOU** · **DECISÕES NECESSÁRIAS**.

## 1. Escopo e critérios

| Pedido | Situação | Evidência |
|---|---|---|
| Início que comunica barbearia, marca, proposta, serviços, profissionais, ambiente, localização, horários, agendamento | **PASSOU** | [home.md](home.md); `SitePagesTest`, `site.spec.js` |
| Direção A, sem direção B; parecer barbearia de verdade | **PASSOU** (aprovação visual do dono **PENDENTE**) | Elementos gráficos próprios (faixa diagonal, tesoura/pente/navalha em traço, quadro de preços pontilhado, monograma); versão tipográfica sem foto; direção B só nos protótipos |
| "Agendar" sempre acessível | **PASSOU** | Cabeçalho fixo (desktop) e barra inferior fixa (celular), testado nos dois |
| Nada inventado (números, depoimentos, avaliações, prêmios, endereço, horários, preços) | **PASSOU** | Bloco sem dado real some; textos padrão do sistema antigo descartados; testes `test_inicio_mostra_...nada_inventado`, `test_conteudo_do_sistema_antigo_sem_os_textos_padrao` |
| Imagens organizadas (barbearia, profissionais, serviços, início) e validadas (tamanho, formato, MIME real, dimensões, nome, armazenamento, acesso; nada executável) | **PASSOU** | [imagens.md](imagens.md); `SiteImagesTest` (poliglota, SVG, texto como .jpg, EXIF/GPS, orientação, variantes) |
| Serviços: só ativos e publicados; preço, duração, destaque, imagem, profissional; mesmo domínio | **PASSOU** | `ServiceCatalog::publicByCategory`; `test_preco_alterado_no_painel_aparece_no_site_sem_regra_paralela` |
| Profissionais: ativos, que recebem agendamento, publicados; sem cadastro paralelo | **PASSOU** | `ProfessionalDirectory::publicTeam`; página `/equipe/{nome}` |
| Agendamento: CTA no fluxo real, mesma disponibilidade | **PASSOU** | [agendamento-publico.md](agendamento-publico.md); canal do cliente só agenda o publicado (regra na `Availability`) |
| SEO: title, description, headings, URLs, Open Graph, favicon, sitemap, robots, dados estruturados sem inventar | **PASSOU** | [seo.md](seo.md); `test_seo_*`, `test_sitemap_e_robots` |
| Acessibilidade: teclado, foco, contraste, labels, alt, mobile | **PASSOU** | [acessibilidade.md](acessibilidade.md); axe sem violação grave em todas as telas (celular e desktop) |
| Performance: home leve, sem vídeo/bibliotecas/animações desnecessárias, imagens otimizadas | **PASSOU** (medição manual) / Lighthouse **NÃO EXECUTADO** | §8 |
| Segurança e exposição de dados | **PASSOU** | §4; `test_nenhuma_pagina_publica_expoe_dados_de_clientes_ou_da_equipe` |
| Documentação | **PASSOU** | §12 |

**Não implementado, conforme o briefing:** migração real, troca definitiva, produção, chatbot, funcionalidades
fora do roadmap (PWA, D-15, não foi aprovado; galeria só com fotos reais).

## 2. Decisões da aprovação da Fase 10

Implementadas no início desta fase (mesma branch), com testes próprios:

| # | Decisão | Implementação | Testes |
|---|---|---|---|
| D-05 | Resend, sem acoplar o domínio | Interface `EmailProvider` + `MailerProvider` (padrão) + `ResendProvider` (API HTTP, `Idempotency-Key`, tempo limite, erro sem a chave), escolhido por `EMAIL_PROVIDER`; chave só em `RESEND_API_KEY` ([emails.md §7](emails.md#7-provedor-d-05-e-segredos)) | `Fase10DecisionsTest` (4) |
| P10-01 | Máx. 4 campanhas de marketing por cliente em 30 dias | No lote e no envio, com trava na linha do cliente; transacional não conta ([campanhas.md §7](campanhas.md#7-decisões-do-dono-aprovação-da-fase-10)) | `Fase10DecisionsTest` (2) |
| P10-02 | "Desconhecido" fora das campanhas | Já era assim; documentado | `CampaignsTest` |
| P10-03 | Registros de e-mail e avisos: 12 meses, depois anonimizar | `CommunicationRetention` na rotina diária, auditada, idempotente; R52 ([retencao-lgpd.md](retencao-lgpd.md)) | `Fase10DecisionsTest` (1) |
| P10-04 | Link de pagamento da assinatura por e-mail | Botão na assinatura; fila central (transacional), uma vez por link, sem link/cobrança nova, histórico e auditoria | `SubscriptionMessagesTest` (2) |

## 3. Decisões

**Técnicas:**

| # | Decisão | Motivo |
|---|---|---|
| T11-01 | Imagem enviada sempre reprocessada em WebP com variantes; dimensões no nome do arquivo | Segurança (nada publicado como veio, sem metadados), peso e `width/height` sem consultar o disco |
| T11-02 | Conteúdo do site em `settings` (`site.content`), só texto; imagens em `site_images` | Sem HTML vindo do painel (S-02); fácil de validar e auditar |
| T11-03 | Canal do cliente só agenda o publicado, na `Availability` | Regra única para lista, horários, "sem preferência" e pedidos montados à mão |
| T11-04 | Equipe do site = ativo + agendável + publicado | Pedido do dono no briefing |
| T11-05 | JSON-LD sem nota média e sem faixa de preço | Não inventar; o Google não aceita avaliação "auto-servida" |
| T11-06 | `robots.txt` como rota: produção indexa o site; homologação/local `Disallow: /` | Cópia de teste nunca indexada |
| T11-07 | Nota média só com 3+ avaliações publicadas; depoimentos só aprovados **e destacados** (primeiro nome + inicial) | Prova social real, sem expor o cliente |
| T11-08 | Galeria só com 3+ fotos ativas | Sem seção pela metade |

**DECISÕES NECESSÁRIAS (PRECISA DE DECISÃO):**

| # | Ponto | Implementado (conservador) |
|---|---|---|
| P11-01 | Política de privacidade e termos de uso (texto jurídico) | Páginas prontas, mas **só publicadas quando o dono preencher o texto** no painel; os textos padrão do antigo (HTML) não foram importados |
| P11-02 | Fotos reais (D-08) e logo/marca (D-07) | Versão tipográfica no início e monogramas na equipe até haver fotos; envio pronto no painel |
| P11-03 | Remarcação pelo cliente com profissional/serviço que saiu do site | Recusada pelo site (a equipe remarca); [agendamento-publico.md §3](agendamento-publico.md#3-remarcação-pelo-cliente) |
| P11-04 | Mínimos para exibir: nota média (3 avaliações) e galeria (3 fotos) | Valores técnicos ajustáveis (`PublicSite::MIN_*`) |
| P11-05 | "Barbeiro em destaque" por nota (D-09) | Não implementado (proposta: não comparar a equipe em público) |

## 4. Revisões

| Revisão | Resultado |
|---|---|
| Segurança | Uploads reprocessados (poliglota, SVG, texto disfarçado recusados; testado); nome e extensão gerados; sem metadados; limites de tamanho/pixels; `throttle:uploads`. Conteúdo do painel só texto (escapado; `<script>`/`<img onerror>` testados), links só https dos domínios esperados. Páginas públicas só leitura; 404 para o que não é público; `/equipe/{nome}` só aceita `[a-z0-9-]`. CSP sem exceção nova (JSON-LD com nonce). Fase 10: erro do Resend sem a chave; link de assinatura sem link novo por duplo clique |
| Exposição de dados | Nenhuma página pública mostra e-mail, CPF ou nome completo de cliente, usuário/e-mail da equipe ou dado financeiro (testado em 6 páginas e no sitemap); depoimento com primeiro nome + inicial |
| Permissões | `site.manage` (proprietário e gerente); recepção, financeiro e profissional recebem 403 (testado); serviços e equipe continuam nas habilidades da Fase 4 |
| Auditoria | `site.settings_changed` (campos), `site.image_uploaded/updated/moved/deleted`; Fase 10: `subscription.link_emailed`, `retention.communication` |
| Acessibilidade | axe sem violação grave/crítica nas telas novas (início, serviços, equipe, profissional) no celular e no desktop; teclado (pular para o conteúdo); um `h1` por página |
| Performance | §8 |
| Integridade | R52 (retenção da comunicação); R47–R51 mantidas |

## 5. Permissões

| Habilidade | Proprietário | Gerente | Recepção | Financeiro | Profissional |
|---|:-:|:-:|:-:|:-:|:-:|
| `site.manage` | ✓ | ✓ | | | |

## 6. O que veio do sistema antigo

Nome, telefone, endereço, slogan, redes e textos do "Sobre" (`config_geral`, `landing_page`) na primeira
leitura, **sem** os textos padrão (nome fictício, "Rua Exemplo", telefone zerado, estatísticas de 1500
clientes/10 anos/5000 cortes, texto padrão de "Sobre") e **sem** termos/política em HTML. Removidos em
relação ao site antigo: botões de admin, "barbeiro em destaque" por nota, contadores animados, vídeo de
fundo padrão.

## 7. Agendamento

O site não tem agenda própria: todo CTA leva ao fluxo da Fase 5 (mesma `Availability`/`BookingService`).
Novo: o canal do cliente só agenda serviço e profissional publicados (`service_not_public`,
`professional_not_public`); a equipe agenda tudo. A página do profissional leva direto aos horários dele.

## 8. Performance

Medição manual (Lighthouse CI **NÃO EXECUTADO**: ferramenta não disponível neste ambiente).

| Item | Peso (gzip/transferido) |
|---|---|
| HTML do início | 4,6 KB (31 KB sem compressão, conteúdo de exemplo) |
| CSS (app + site) | ~11,6 KB |
| JS (Alpine + componentes) | ~24,6 KB |
| Fontes usadas (Inter e Fraunces, subconjunto latino, locais) | ~115 KB |
| **Total sem foto** | **~157 KB** (meta < 1 MB) |
| Foto do topo (quando houver) | variante pelo `srcset`: ~960 px no celular, até 2400 px no desktop, WebP q80 |

Sem vídeo, sem biblioteca nova, sem script de terceiros, sem mapa embutido (link para o mapa). Só a foto do
topo carrega na hora; o resto é preguiçoso. Animações: duas, desligadas com `prefers-reduced-motion`.

## 9. Testes

| Verificação | Resultado |
|---|---|
| PHPUnit | **PASSOU** — 752 testes (eram 727), 0 falhas, 0 pulados |
| — novos | `Site/SitePagesTest` (11), `Site/SiteImagesTest` (6), `Communication/Fase10DecisionsTest` (7), `SubscriptionMessagesTest` (+2, link por e-mail) |
| — ajustados (regra mudou, nenhum desativado) | `ApplicationTest` (a raiz agora é o site real), `RouteAuthorizationTest` (8 rotas públicas do site), `PermissionMatrixTest` (`site.manage`) |
| Larastan nível 6 | **PASSOU** — 0 erros |
| Pint | **PASSOU** |
| Build (Vite) | **PASSOU** |
| Migration (subir, descer e subir) | **PASSOU** (banco temporário) |
| Playwright + axe | **PASSOU**, com uma falha intermitente registrada — 109 passando (eram 103; +6 da Fase 11, celular e desktop), 3 ignorados de propósito (os mesmos de antes), num banco SQLite novo. Três execuções completas: **1ª** 108 passando e **1 FALHOU** (`telas-referencia.spec.js` › "Design System [direção b]" no celular: "erros de console/CSP"); **2ª e 3ª** 109 passando. A falha não se repetiu em 32 execuções isoladas desse teste nem nas duas rodadas completas seguintes; a mensagem exata do console não foi guardada na 1ª rodada (o filtro do registro cortou o detalhe), então a causa **não foi identificada**. Suspeita: carga do servidor embutido do PHP (uma requisição por vez) com 4 navegadores em paralelo. Nenhum teste foi alterado ou desativado por causa disso |
| — novos | `site.spec.js` (celular e desktop): início (SEO, JSON-LD, sem número inventado, "Agendar" à mão, teclado), serviços → fluxo real, equipe → profissional → horários dele, sitemap e robots |
| Lighthouse CI / teste com 5 pessoas | **NÃO EXECUTADO** |
| Entrega real (Resend) e aprovação visual dos e-mails; ciclo do Stripe em modo teste; webhook real | **PENDENTE** (homologação) |

## 10. CI

**PASSOU.** Run nº 42 (commit `91c8697`, https://github.com/Joaogoncalves19/barbearia/actions/runs/37504278585),
PHP 8.4, os dois jobs verdes em todos os passos:

- **Novo sistema (Laravel):** dependências, Pint, Larastan nível 6, auditoria de dependências, build, testes
  PHP (inclusive os de concorrência com processos reais), importador com banco fictício e Playwright + axe.
  Nenhuma chave do Resend ou do Stripe no CI.
- **Sistema atual:** regressão de segurança S-01 a S-04.

**FALHOU antes (run nº 41, commit `57397da`), no passo "Auditoria de dependências":** um aviso de segurança
publicado nesse dia para `source-map-js` 1.2.1 (dependência de build do Vite/PostCSS, alta, negação de serviço;
GHSA-68fv-2mgg-jv7q). Não veio do código da fase. Corrigido atualizando o lockfile para 1.2.2 (`npm audit fix`,
só esse pacote), sem desligar a auditoria; build e testes do site repetidos. Os passos seguintes do run 41
nem chegaram a rodar.

O commit seguinte só atualiza este relatório (documentação).

## 11. Problemas encontrados

1. **Classe com `new` em constante** (`ImageStore::RULES` com a regra de decodificação): PHP não permite;
   virou `ImageStore::rules()`.
2. **Chave `resend` duplicada** em `config/services.php` (o Laravel já tinha uma): mescladas (PHPStan pegou).
3. **Rota do profissional** gerava a URL com o id em vez do nome: a rota passou a `{professional:slug}`.
4. **Tesoura do topo** desenhada torta na primeira versão e ornamento atrás do texto no celular: redesenhada
   com geometria calculada e mais fraca/afastada no celular (revisão visual no navegador).
5. **Monograma** pegava "(" de nomes com parênteses: só letras iniciais de palavras.
6. **Heredoc do shell** trocou `\n` por quebra real num script de edição: corrigido; edições com barra
   passaram a ser feitas por arquivo.

## 12. Documentação

Novos: [site-publico.md](site-publico.md), [home.md](home.md), [imagens.md](imagens.md), [seo.md](seo.md),
[acessibilidade.md](acessibilidade.md), [agendamento-publico.md](agendamento-publico.md),
[retencao-lgpd.md](retencao-lgpd.md). Atualizados: [emails.md](emails.md) (§7 Resend e abstração),
[campanhas.md](campanhas.md) (P10-01/02), [consentimento.md](consentimento.md) (P10-03),
[assinaturas.md](assinaturas.md) (P9-08/P10-04), [servicos.md](servicos.md), [profissionais.md](profissionais.md),
[papeis-permissoes.md](papeis-permissoes.md), [modelo-dados.md](modelo-dados.md) (apêndice regenerado, 68
tabelas), [regras-dados.md](regras-dados.md) (regras 91 a 94), [decisoes-pendentes.md](decisoes-pendentes.md),
[relatorio-fase-10.md](relatorio-fase-10.md) (aprovação), [roadmap.md](roadmap.md), [README.md](README.md).

## 13. Riscos

| Risco | Mitigação |
|---|---|
| Site sem fotos reais parece menos convincente | Versão tipográfica com identidade própria; envio de fotos pronto; D-08 |
| Conteúdo vazio (endereço, contato) no lançamento | O dono preenche no painel antes da virada; nada fictício aparece enquanto isso |
| Disco de mídia externo (S3) no futuro | CSP `img-src` precisa incluir o domínio; documentado em [imagens.md](imagens.md) |
| Política de privacidade ausente | P11-01: texto jurídico do dono antes da virada |

---

**Fase 11 concluída. Aguardando aprovação explícita do dono para a Fase 12. Não avançar automaticamente.**
