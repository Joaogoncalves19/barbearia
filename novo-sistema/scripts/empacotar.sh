#!/usr/bin/env bash
# Gera o PACOTE DE INSTALACAO de uma barbearia (Fase 13; instalacao.md):
# codigo de um commit + dependencias PHP de producao (sem as de
# desenvolvimento) + CSS/JS ja compilados. O servidor da barbearia nao precisa
# de Node nem de Composer para instalar.
#
# Uso (na raiz do repositorio):
#   bash novo-sistema/scripts/empacotar.sh [commit] [pasta-de-saida]
#
# Variaveis: COMPOSER_BIN (padrao: composer; aceita um .phar) e PHP (padrao: php).
# Nunca entra no pacote: .env, banco, storage com dados, testes, node_modules.
set -euo pipefail

REF="${1:-HEAD}"
SAIDA="${2:-pacotes}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
PHP="${PHP:-php}"

RAIZ="$(git rev-parse --show-toplevel)"
COMMIT="$(git -C "$RAIZ" rev-parse --short "$REF")"
VERSAO="$(git -C "$RAIZ" show -s --format=%cd --date=format:%Y.%m.%d "$REF")-$COMMIT"
TRAB="$(mktemp -d)"
trap 'rm -rf "$TRAB"' EXIT

echo "== Pacote $VERSAO (commit $COMMIT)"
git -C "$RAIZ" archive "$REF" novo-sistema | tar -x -C "$TRAB"
APP="$TRAB/novo-sistema"

echo "== Dependencias PHP de producao"
composer_cmd() { case "$COMPOSER_BIN" in *.phar) "$PHP" "$COMPOSER_BIN" "$@" ;; *) "$COMPOSER_BIN" "$@" ;; esac; }
(cd "$APP" && composer_cmd install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet)

echo "== CSS e JS compilados"
(cd "$APP" && npm ci --no-audit --no-fund --loglevel=error && npm run build --silent && rm -rf node_modules)

echo "== Limpeza"
rm -rf "$APP/tests" "$APP/playwright.config.js" "$APP/playwright.visual.config.js" "$APP/playwright.homologacao.config.js" "$APP/phpunit.xml" "$APP/phpstan.neon" \
       "$APP/scripts/copiar-icones.mjs" "$APP/scripts/ensaio" "$APP/.env" "$APP/database/database.sqlite"
echo "$VERSAO" > "$APP/VERSION"

mkdir -p "$SAIDA"
ARQ="$(cd "$SAIDA" && pwd)/barbearia-$VERSAO.zip"
# PHP nativo do Windows (Git Bash) nao entende caminhos /tmp/...: converte.
APP_N="$APP"; ARQ_N="$ARQ"
if command -v cygpath >/dev/null 2>&1; then APP_N="$(cygpath -w "$APP")"; ARQ_N="$(cygpath -w "$ARQ")"; fi
(cd "$TRAB" && "$PHP" -r '
    $raiz = $argv[1]; $zip = new ZipArchive;
    $zip->open($argv[2], ZipArchive::CREATE | ZipArchive::OVERWRITE) === true || exit(1);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { $zip->addFile($f->getPathname(), "barbearia/".str_replace("\\", "/", substr($f->getPathname(), strlen($raiz) + 1))); }
    $zip->close() || exit(1);
' "$APP_N" "$ARQ_N")
(cd "$(dirname "$ARQ")" && sha256sum "$(basename "$ARQ")" > "$(basename "$ARQ").sha256")
echo "== Pronto: $ARQ"
cat "$ARQ.sha256"
