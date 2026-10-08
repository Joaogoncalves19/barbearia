# Sistema da Barbearia — nova versão

Reconstrução do sistema da barbearia em **Laravel 13 / PHP 8.4+**, com páginas
renderizadas no servidor (Blade), Alpine.js (build CSP) e SQLite.

> **Estado:** Fases 1 a 12.5 concluídas; Fase 13 (homologação, migração e virada) encerrada no
> desenvolvimento em 2026-10-08 (escopo corrigido, tela de Clientes com cadastro pelo balcão). O produto ainda não foi instalado
> em nenhuma barbearia; virada e migração reais são procedimento da instalação de cada comprador.
> Instalação de uma barbearia: [`instalacao.md`](../docs/reconstrucao/instalacao.md); operação:
> [`operacao.md`](../docs/reconstrucao/operacao.md). Documentação completa:
> [`../docs/reconstrucao/`](../docs/reconstrucao/README.md).

⚠️ **Não envie esta pasta para a hospedagem atual.** O sistema antigo é servido a partir
da raiz do repositório. Esta aplicação precisa de um host próprio cuja raiz pública seja
`novo-sistema/public`.

## Requisitos

- PHP 8.4+ com `pdo_sqlite`, `mbstring`, `fileinfo`, `openssl`
- Composer 2
- Node.js 22+ (só para compilar CSS/JS e rodar os testes de navegador)

## Primeira execução (desenvolvimento)

```bash
cd novo-sistema
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed          # cria 1 usuário de cada papel; a senha aparece no terminal
npm install
npm run build                # ou: npm run dev (recarrega ao salvar)
php artisan serve
```

- Telas de referência: <http://localhost:8000/prototipos> (use `?direcao=a` ou `?direcao=b`)
- Catálogo de componentes: <http://localhost:8000/design-system>
- Login da equipe: <http://localhost:8000/entrar> (`owner@barbearia.test`, `reception@barbearia.test`…)

## Testes e qualidade

```bash
php artisan test                        # unitários + integração (banco em memória)
php artisan test --testsuite=Legado     # correções de segurança do sistema antigo
vendor/bin/pint --test                  # estilo de código
npm run test:e2e                        # navegador: console, CSP, responsividade, acessibilidade
php artisan app:diagnose                # checagem do ambiente (rodar após cada deploy)
```

## Operação (Fase 13)

```bash
bash scripts/empacotar.sh <commit> <pasta>     # pacote de instalação (na raiz do repositório)
php artisan app:backup                         # cópia de segurança conferida (diária pelo agendador)
php artisan app:backup-verify                  # confere a mais recente
php artisan app:backup-restore <zip> --to=<pasta-nova>      # ensaio de restauração
php artisan legacy:import <copia.sqlite> --dry-run          # migração (ver importador.md)
php artisan legacy:import-photos <copia/uploads>            # fotos do sistema antigo
php artisan app:rollback-export --since="AAAA-MM-DD HH:MM"  # plano de retorno
```

## Tarefas agendadas e fila

Um único cron no servidor:

```
* * * * * cd /caminho/novo-sistema && php artisan schedule:run >> /dev/null 2>&1
```

Com `QUEUE_WORK_VIA_SCHEDULER=true` (padrão), o próprio agendador processa a fila a cada
minuto, o que serve para hospedagem sem supervisor de processos. Em VPS, use um worker
supervisionado (`php artisan queue:work`) e defina a variável como `false`.

## Onde fica cada coisa

| Pasta | Conteúdo |
|---|---|
| `app/Modules/<Módulo>` | Regras de negócio por domínio (modelos, serviços, jobs, policies) |
| `app/Modules/Shared` | Peças comuns: `Money` (centavos), `BaseJob` |
| `app/Http/Controllers/<Área>` | Controllers finos por área: Site, Auth, Panel, Prototypes |
| `app/Http/Middleware` | Cabeçalhos de segurança, usuário ativo, trava dos protótipos |
| `config/permissions.php` | Matriz de permissões (negar por padrão) |
| `config/barbearia.php` | Configuração de infraestrutura do sistema |
| `resources/css` | `tokens.css`, `base.css`, `components/`, `areas/site.css`, `areas/panel.css` |
| `resources/views/components/ui` | Componentes Blade do design system |
| `resources/icons` | Ícones SVG (Lucide, ISC). Adicione com `node scripts/copiar-icones.mjs` |
| `tests/` | `Unit`, `Feature`, `Legacy` (PHPUnit) e `e2e` (Playwright) |

As convenções detalhadas estão em [`docs/reconstrucao/arquitetura-nova.md`](../docs/reconstrucao/arquitetura-nova.md)
e [`docs/reconstrucao/design-system.md`](../docs/reconstrucao/design-system.md).
