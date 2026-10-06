# Acessibilidade (site público, Fase 11)

> Mesmo padrão das fases anteriores ([design-system.md](design-system.md)): WCAG 2.1 AA, verificado com axe
> em toda tela nos testes de navegador (celular e desktop), sem violação grave ou crítica.

| Item | Como |
|---|---|
| Teclado | Ordem natural; "Pular para o conteúdo" é o primeiro Tab (testado); menu do celular abre/fecha com teclado e Esc; nenhum controle só de mouse |
| Foco | Anel de foco visível em todos os links e botões (cor de foco com contraste) |
| Contraste | Texto e acento sobre o fundo escuro com contraste AA (tokens da direção A); acento como texto só na variante clara; ornamentos decorativos são `aria-hidden` e ficam fracos atrás do texto no celular |
| Estrutura | Um `h1` por página; seções com `aria-labelledby`; listas com `role="list"`; `nav` com nome |
| Imagens | Texto alternativo obrigatório no envio; decorativas com `alt=""`; ornamentos SVG `aria-hidden` |
| Links | Texto que faz sentido sozinho ("Ver horários de Corte com João" para leitor de tela); links externos avisam "abre em outra aba" |
| Estrelas | `role="img"` com "N de 5 estrelas" |
| Movimento | Só o pulso do "aberto agora" e o zoom leve do retrato, desligados com `prefers-reduced-motion` |
| Celular | Alvos de toque de 44 px, barra inferior fixa com "Agendar horário", sem rolagem lateral (testado) |
| Idioma | `lang="pt-BR"` |

**Não executado nesta fase:** teste com pessoas usuárias de leitor de tela e o "teste com 5 pessoas" do
roadmap (precisam de homologação e de participantes).
