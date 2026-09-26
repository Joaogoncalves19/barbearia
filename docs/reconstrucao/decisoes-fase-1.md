# Decisões da Fase 1

Revisão de [decisoes-pendentes.md](decisoes-pendentes.md). Para cada item: se era necessário
agora, o que foi feito e quem decide. **Nenhuma escolha de produto foi feita no lugar do dono.**

Legenda: ✅ decidido (técnico) · 🟡 provisório (técnico, reversível, precisa de confirmação) ·
⏳ pendente do dono · ➖ fica para fase posterior.

## 1. Decisões exigidas pela Fase 1

| ID | Tema | Situação | Decisão / encaminhamento |
|----|------|----------|--------------------------|
| D-01 | Hospedagem | ⏳ **Pendente do dono** (bloqueia só o deploy) | A arquitetura exige PHP 8.3+, Composer, acesso SSH e **1 cron por minuto**. A hospedagem gratuita atual não atende. O código foi feito para rodar tanto em **hospedagem compartilhada paga com SSH/cron** (fila processada pelo cron, `QUEUE_WORK_VIA_SCHEDULER=true`) quanto em **VPS** (worker supervisionado). Sem essa escolha, não há deploy automatizado de homologação; todo o resto da fase foi feito. **Recomendação:** hospedagem PHP paga com SSH e cron (menor custo e manutenção), ou VPS gerenciada se o dono quiser controle total. |
| D-02 | SQLite × MySQL | 🟡 **Provisório: SQLite (WAL)** | Decisão técnica que não compromete o produto: é suficiente para uma unidade, dispensa servidor de banco, o backup é cópia de arquivo e o banco antigo também é SQLite (migração mais simples). O código usa só recursos portáveis; trocar para MySQL é configuração (`DB_CONNECTION`). **Revisar** se a hospedagem escolhida oferecer MySQL gerenciado com backup ou se houver plano de várias unidades. |
| D-07 | Identidade (direção, cores, fontes) | ⏳ **Pendente do dono** — duas opções prontas | É preferência de marca. Foram criadas **duas direções completas** (A "Ofício contemporâneo", B "Urbano gráfico"), alternáveis nas telas de referência. **Recomendação: A.** Paleta e tipografia de cada uma estão justificadas e com contraste verificado em [identidade-visual.md](identidade-visual.md). |
| D-07 | Logo e nome de exibição | ⏳ **Pendente do dono** | Monograma e "Barbearia Exemplo" provisórios. O site definitivo (Fase 11) não será publicado com logo provisório. |
| D-08 | Fotografia | ⏳ **Pendente do dono** | Marcadores identificados em todas as posições de foto, com a especificação de cada uma. Recomenda-se agendar a sessão antes da Fase 11. |
| D-10 | Login da equipe | 🟡 **Provisório: e-mail + senha** | Necessário para a fundação de autenticação. É o padrão recomendado e permite recuperação de senha na Fase 3. Confirmar se todos os membros da equipe têm e-mail. |
| D-18 | Rastreamento de erros | ➖ Fase 2 | Logs estruturados por canal já configurados. Integração com Sentry (ou similar) quando houver ambiente de homologação. |

## 2. Decisões técnicas tomadas na fase (reversíveis e documentadas)

| # | Decisão | Motivo | Alternativa considerada |
|---|---------|--------|-------------------------|
| T-01 | **Laravel 13.33** (versão estável atual) em `novo-sistema/`, separado do sistema antigo | Base limpa; o antigo continua intocado em produção | Migração gradual dos arquivos antigos (rejeitada no briefing) |
| T-02 | Módulos em `app/Modules/<Módulo>`; controllers por área em `app/Http/Controllers/<Área>` | Fronteiras de domínio claras sem microserviços | Estrutura padrão do Laravel sem módulos (mistura domínios com o tempo) |
| T-03 | Tabela `users` = **equipe**; clientes terão tabela e *guard* próprios | Convenção do Laravel para a equipe; separação de riscos entre equipe e clientes | Uma tabela única para todos (permissões mais frágeis) |
| T-04 | Permissões por **matriz em config + Gates**, negar por padrão, teste de inventário de rotas | Simples, testável e suficiente até a Fase 3; perfis editáveis pelo dono entram na Fase 3 | Pacote de permissões (ex.: spatie) — reavaliar na Fase 3 se o dono quiser editar perfis |
| T-05 | **Alpine.js build CSP** em vez do build padrão | Permite CSP sem `unsafe-eval`. Custo: diretivas só chamam métodos registrados | Build padrão + `unsafe-eval` (enfraquece a CSP) |
| T-06 | **CSS próprio com tokens**, sem Tailwind (removido do template) | Controle total da identidade, CSS pequeno, sem classes utilitárias no HTML, alinhado à proposta | Tailwind (produtivo, mas contraria a proposta e aumenta o acoplamento visual ao HTML) |
| T-07 | Fontes via `@fontsource` (Inter, Fraunces, Bricolage Grotesque), locais | Desempenho, licença OFL, sem Google Fonts (LGPD) | CDN do Google Fonts |
| T-08 | Ícones Lucide copiados como SVG por script | Sem fonte de ícones pesada; licença ISC registrada | Font Awesome via CDN (usado no antigo) |
| T-09 | Horários em **UTC** no banco e exibição em `America/Sao_Paulo` | Evita os erros de fuso do sistema antigo | Gravar hora local |
| T-10 | Dinheiro em **centavos** (`Money`) | Princípio de dados históricos; nada de float | Decimal/float |
| T-11 | Fila e cache no **banco** | Sem Redis; adequado ao porte | Redis (mais uma peça para operar) |
| T-12 | Worker da fila **acionado pelo cron** (configurável) | Funciona em hospedagem compartilhada | Exigir supervisor (só VPS) |
| T-13 | Protótipos atrás da flag `BARBEARIA_PROTOTYPES` (404 em produção) | Permite validar o visual em homologação sem risco | Protótipos em ferramenta externa (Figma): menos fiel à implementação real |
| T-14 | Disco `local` sem URLs públicas (`serve=false`) | Deny by default; o Laravel 13 liga isso por padrão | Manter o padrão |
| T-15 | PHPUnit (padrão do template) + Playwright + axe-core | Menos dependências; E2E cobre CSP, acessibilidade e responsividade | Pest (pode ser adotado depois sem reescrever) |

## 3. Pendências que continuam para fases posteriores

Sem mudança: D-03 (assinaturas), D-04 (chatbot/IA), D-05 (provedor de e-mail), D-06
(aprovação de agendamento), D-09 ("barbeiro em destaque" — as telas de referência já
seguem a recomendação de **não** destacar por nota), D-11 (funcionalidades abandonadas),
D-12 (login do cliente), D-13 (política de cancelamento), D-14 (indicação), D-15 (PWA),
D-17, D-19 a D-23.

## 4. D-16 — correções emergenciais no sistema atual

**Autorizada** pelo briefing da Fase 1 e **executada** (Etapa B). Ver
[seguranca-correcoes.md](seguranca-correcoes.md). As mudanças estão no repositório, mas
**não foram publicadas na hospedagem**: publicar é decisão do dono (copiar os 6 arquivos
alterados; sem mudança de banco). **Recomendação:** publicar o quanto antes e rotacionar as
credenciais que ficam em texto puro no banco (SMTP, Stripe, IA).

## 5. Bloqueios encontrados

| Bloqueio | Impacto | Encaminhamento |
|---|---|---|
| Download de pacotes pelo GitHub bloqueado no ambiente da sessão (HTTP 403 no proxy) | PHPStan/Larastan não instalados | Instalar no início da Fase 2, a partir de um ambiente com acesso (a máquina local do dono ou o CI) |
| Hospedagem não definida (D-01) | Sem deploy de homologação | CI pronto; falta só o passo de publicação |
