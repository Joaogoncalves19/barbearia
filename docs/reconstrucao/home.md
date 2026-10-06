# Início do site (Fase 11)

> Em três segundos: **é uma barbearia**, qual é, onde fica, e o botão de agendar. Base: estrutura proposta em
> [proposta-design.md §11.3](proposta-design.md#113-nova-landing-page--estrutura-proposta), com só o que é real.

## 1. Ordem (pensada para o celular)

| # | Bloco | Aparece quando | Conteúdo |
|---|---|---|---|
| 1 | Cabeçalho fixo | Sempre | Logo (ou nome), Serviços, Equipe, Assinatura, Como chegar, Entrar/Minha conta, **Agendar horário** |
| 2 | Topo | Sempre | Rótulo "Barbearia · bairro", frase de marca (ou nome), nome, subtítulo, **Agendar horário** + "Ver serviços e preços". Com foto real do ambiente: foto em tela cheia (carregada primeiro); sem foto: **versão tipográfica** com a tesoura e o pente em traço fino |
| 3 | Faixa diagonal | Sempre | Elemento gráfico da marca (cobre, papel e tinta, referência ao poste de barbeiro) |
| 4 | Informações rápidas | Se houver horário, endereço ou nota | "Aberto agora até 20h" / "Abre amanhã às 9h" (da agenda), endereço + mapa, nota média real |
| 5 | Serviços | Sempre | Quadro de preços com pontilhado (destaques ou os 6 primeiros), duração, categoria, "Agendar" leva ao fluxo real |
| 6 | Assinatura | Com plano ativo | Planos, preço, serviços incluídos |
| 7 | Equipe | Com profissional publicado | Retrato 4:5 (ou monograma), especialidade, "Agendar com…" |
| 8 | A barbearia | Com texto, diferenciais ou foto | Superfície clara (respiro), texto do dono, diferenciais, fotos do ambiente |
| 9 | Galeria | Com 3+ fotos | Trabalhos da casa |
| 10 | Avaliações | Com avaliação aprovada e destacada | Até 3, primeiro nome + inicial, estrelas |
| 11 | Como chegar | Sempre (horário da agenda) | Endereço, referência, Mapa / WhatsApp / Ligar, tabela da semana com "hoje" |
| 12 | Chamada final | Sempre | "Sua cadeira está livre?" → Ver horários |
| 13 | Rodapé | Sempre | Marca, contatos, situação de agora, navegação, redes, links legais (se houver), CNPJ (se houver). **Sem link para o painel** |

No celular, a **barra inferior fixa** mantém "Agendar horário" (e WhatsApp, se configurado) na zona do polegar
em todas as páginas do site; no computador, o botão fica no cabeçalho fixo.

## 2. Identidade (direção A)

- Fundo escuro e quente, um acento (cobre), títulos em Fraunces e texto em Inter (fontes locais).
- Elementos gráficos próprios, não copiados de outros sites: faixa diagonal, mini poste no rótulo, tesoura,
  pente e navalha em traço fino (SVG, decorativos), quadro de preços pontilhado, monograma com textura
  listrada para profissional sem foto.
- Movimento mínimo: só o pulso do "aberto agora" e o leve zoom do retrato, desligados com
  `prefers-reduced-motion`.

## 3. Sem foto ainda

A fotografia é o maior ganho visual (D-08). Até haver fotos reais, o início usa a versão tipográfica (que já
parece uma barbearia, sem foto genérica). Com uma foto em **Painel → Site → Imagens → Início**, o topo vira
foto em tela cheia automaticamente.

## 4. Conteúdo que o dono precisa preencher

Painel → Site → Conteúdo: frase de marca, subtítulo, bairro/cidade, endereço e referência, telefone/WhatsApp,
redes, texto "A barbearia" e diferenciais, política de privacidade e termos. Painel → Site → Imagens: foto do
ambiente, logo, galeria. Fotos dos profissionais e dos serviços: nos próprios cadastros.
