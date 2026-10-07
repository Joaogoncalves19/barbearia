#!/usr/bin/env bash
# Instala a HOMOLOGACAO a partir do pacote, como na instalacao de uma barbearia
# (instalacao.md), e importa a copia do banco antigo baixada pelo painel antigo.
set -euo pipefail
F13="$(cd "$(dirname "$0")" && pwd)"
PHP=php
PACOTE="$(ls -t "$F13"/pacotes/barbearia-*.zip | head -1)"
DEST="${DEST:-$F13/homolog}"
URL_APP="${URL_APP:-http://127.0.0.1:8302}"
COPIA="${COPIA:-$F13/copia-pelo-painel.sqlite}"
UPLOADS="${UPLOADS:-$F13/antigo/uploads}"
WHSEC_FILE="${WHSEC_FILE:-$F13/whsec-homolog.txt}"
win() { cygpath -w "$1"; }

echo "== 1. Pacote: $(basename "$PACOTE")"
(cd "$(dirname "$PACOTE")" && sha256sum -c "$(basename "$PACOTE").sha256")
rm -rf "$DEST" && mkdir -p "$DEST"
$PHP -r '$z=new ZipArchive; $z->open($argv[1])===true||exit(1); $z->extractTo($argv[2])||exit(1);' "$(win "$PACOTE")" "$(win "$DEST")"
APP="$DEST/barbearia"
cd "$APP"
echo "   versao $(cat VERSION)"

echo "== 2. .env de homologacao (segredos gerados aqui, so de ensaio)"
cp .env.example .env
rnd() { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }
WHSEC="whsec_sim$(rnd 16)"
definir() { # troca KEY=... (ou "# KEY=...") no .env; acrescenta se nao existir
  "$PHP" -r '$f=".env"; $s=file_get_contents($f); $k=$argv[1]; $l=$k."=".$argv[2];
    $n=preg_replace("/^#? ?".preg_quote($k,"/")."=.*$/m", str_replace(["\\","$"],["\\\\","\$"],$l), $s, 1, $c);
    file_put_contents($f, $c ? $n : rtrim($s).PHP_EOL.$l.PHP_EOL);' "$1" "$2"
}
definir APP_ENV homologacao
definir APP_DEBUG false
definir APP_URL "$URL_APP"
definir BARBEARIA_PROTOTYPES false
definir LOG_LEVEL info
definir DB_DATABASE "$(win "$APP/database/database.sqlite")"
definir SESSION_SECURE_COOKIE false
definir EMAIL_PROVIDER mailer
definir MAIL_MAILER smtp
definir MAIL_HOST 127.0.0.1
definir MAIL_PORT 2525
definir MAIL_FROM_ADDRESS nao-responda@barbearia-ensaio.test
definir STRIPE_SECRET "sk_test_sim$(rnd 12)"
definir STRIPE_WEBHOOK_SECRET "$WHSEC"
definir STRIPE_API_BASE http://127.0.0.1:8303
definir BACKUP_PATH "$(win "$DEST/copias")"
definir BACKUP_PASSWORD "$(rnd 16)"
definir MONITOR_EMAIL monitor@barbearia-ensaio.test
echo "$WHSEC" > "$WHSEC_FILE"
$PHP artisan key:generate --force --no-interaction | tail -1

echo "== 3. Banco e tabelas"
touch database/database.sqlite
$PHP artisan migrate --force --no-interaction | tail -2

echo "== 4. Arquivos publicos"
$PHP artisan storage:link --no-interaction | tail -1

echo "== 5. Simulacao da importacao (nada gravado)"
$PHP artisan legacy:import "$(win "$COPIA")" --dry-run --no-interaction | tee "$F13/import-simulacao.txt" | tail -12

echo "== 6. Importacao"
$PHP artisan legacy:import "$(win "$COPIA")" --no-interaction | tee "$F13/import-real.txt" | tail -12

echo "== 7. Reexecucao (idempotencia)"
$PHP artisan legacy:import "$(win "$COPIA")" --no-interaction | tee "$F13/import-reexecucao.txt" | tail -6

echo "== 7b. Fotos do sistema antigo (copia da pasta uploads/)"
$PHP artisan legacy:import-photos "$(win "$UPLOADS")" --no-interaction | tail -8

echo "== 8. Caches de producao"
$PHP artisan optimize --no-interaction | tail -3

echo "== 9. Diagnostico"
$PHP artisan app:diagnose --no-interaction || true
