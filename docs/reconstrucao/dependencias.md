# 7. Dependências

> Nada foi atualizado nesta fase. Abaixo, o uso **real** verificado de cada dependência.

## 7.1 Backend (PHP)

| Dependência | Versão | Como entra | Uso real | Situação | Recomendação |
|---|---|---|---|---|---|
| PHP | ≥ 7.3 (`composer.json`) / ≥ 7.4 (`install.php`) | servidor | Todo o sistema. Roda sem erros de sintaxe no PHP 8.4 (`php -l`) | Requisito inconsistente | Novo sistema: PHP 8.3+ |
| `ext-pdo_sqlite` | — | servidor | Banco | Crítica | — |
| `ext-gd` | — | servidor | Ícones do PWA, validação de imagem | Usada | — |
| `ext-curl` | — | servidor | Stripe e IA | **Usada, mas não declarada** no `composer.json` nem checada no instalador | Declarar |
| `ext-fileinfo` | — | servidor | Validação de upload | **Usada, não declarada** | Declarar |
| `ext-mbstring`, `ext-json`, `ext-openssl`, `ext-zip` | — | servidor | Texto, config, SMTP TLS, backup | Usadas | — |
| **PHPMailer** | 6.10.0 | Cópia manual em `PHPMailer/` (o `composer.json` declara `^6.10`, mas não há `vendor/`) | Todos os e-mails | Crítica. Versão recente, sem vulnerabilidade conhecida nesta versão (precisa de validação no momento da Fase 1) | Gerenciar pelo Composer (ou usar o componente de e-mail do framework escolhido) |

Não há outras bibliotecas PHP. Tudo o mais (roteamento, validação, migrations, CSRF,
throttle, templates, filas) é código próprio.

## 7.2 Frontend (via CDN)

| Biblioteca | Versão | Páginas | Uso real | Situação |
|---|---|---|---|---|
| Font Awesome | 6.4.0 | 19 páginas | Ícones em todo o sistema | Crítica para a aparência. Sem SRI |
| Google Fonts (Inter, Outfit) | — | 18 páginas | Tipografia | Terceiro. Implicação LGPD (IP do visitante enviado ao Google) |
| Chart.js | **sem versão** (`/npm/chart.js`) | `admin.php` | Gráficos do dashboard e relatórios (`js/admin_charts.js`) | **Risco:** a próxima versão maior pode quebrar os gráficos sem aviso |
| SweetAlert2 | `@11` | `admin.php` | Diálogos do painel (marketing, avaliações) | Usada no admin; `js/painel_barbeiro.js` a usa, mas `barbeiro.php` **não a carrega** |
| flatpickr | **sem versão** + l10n via `npmcdn.com` | `agendamento.php`, `cliente.php` | Seletor de data | Domínio legado; versão flutuante |
| Cropper.js | 1.5.13 | `cliente.php` | Recorte da foto de perfil | Usada |
| html2pdf.js | 0.10.1 | `imprimir_comprovativo_cliente.php` | Gerar PDF do comprovante | Usada |
| Som do Mixkit | — | admin, barbeiro | Toque de novo agendamento | Terceiro sem garantia |

## 7.3 Serviços externos

| Serviço | Criticidade | Observação |
|---|---|---|
| Servidor SMTP (padrão Gmail) | Alta | Confirmações, lembretes, reset de senha. Gmail tem limites diários de envio. Para campanhas, usar um provedor transacional (decisão pendente) |
| Stripe | Média (opcional) | Só assinaturas. Integração por cURL puro, sem SDK |
| Groq / Gemini | Baixa (opcional) | IA do chatbot e do painel. Modelos configuráveis |
| Google Maps (iframe) | Baixa | Mapa |

## 7.4 Não utilizadas, duplicadas ou legadas

| Item | Situação |
|---|---|
| `composer.json` | Declara PHPMailer, mas o Composer não é usado (sem `vendor/`, sem `composer.lock`). `optimize-autoloader` sem autoload definido |
| Chave legada `config_gemini.api_key` | Mantida como alternativa de IA; duplica `config_chatbot.gemini_keys` |
| "Cerebras" | Citado em comentários, inexistente no código |
| `npmcdn.com` | Alias legado |
| `unpkg.com` no CSP | Liberado no CSP, mas nenhum script é carregado de lá |

## 7.5 Dependências críticas (se falharem, o sistema para)

1. PHP + `pdo_sqlite` + permissão de escrita em `_dados/`.
2. SMTP (sem ele não há cadastro confirmado nem redefinição de senha).
3. Font Awesome por CDN (sem ele, a interface fica sem ícones; confirmado na captura local,
   onde o CDN estava bloqueado).

## 7.6 Recomendações para o sistema novo

1. **Gerenciar tudo por gerenciador de pacotes** (Composer + npm), com arquivos de trava
   (`composer.lock`, `package-lock.json`) versionados.
2. **Empacotar os assets** no build (sem CDN em tempo de execução). Resolve SRI, versões
   flutuantes, LGPD das fontes e disponibilidade.
3. **Ícones:** usar um conjunto em SVG só com os ícones utilizados, em vez da fonte inteira
   do Font Awesome (~70 KB+ de CSS e fontes).
4. **Fontes:** hospedar localmente só os pesos usados.
5. **Gráficos:** manter Chart.js com versão fixa, carregado só nas telas que o usam.
6. **Diálogos:** usar o elemento nativo `<dialog>` + componente próprio, eliminando o SweetAlert2.
7. **Data:** `<input type="date">` nativo nos formulários do painel. No agendamento, a
   seleção de data será um componente próprio (calendário de dias disponíveis), dispensando o flatpickr.
8. **PDF:** preferir impressão via CSS (`@media print`). Gerar PDF no servidor só se necessário.
9. **Stripe:** usar o SDK oficial em PHP, com versão da API fixada.
10. **Auditoria contínua:** `composer audit` e `npm audit` no CI.
