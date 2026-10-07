#!/usr/bin/env bash
# ENSAIO DA VIRADA — passo 1: copia final do sistema antigo (como em producao:
# pelo botao de backup do painel antigo) + pasta uploads/, com hash.
set -euo pipefail
F13="$(cd "$(dirname "$0")" && pwd)"
V="$F13/virada"; mkdir -p "$V"
B=http://127.0.0.1:8300
J=$(mktemp -d); trap 'rm -rf "$J"' EXIT
SENHA="$(sed -n 3p "$F13/acessos-ensaio.txt" | sed 's/senha: //')"
tok() { curl -s -b "$J/c" -c "$J/c" "$B/$1" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'; }

date -u +%FT%TZ > "$V/T0-utc.txt"; php -r '$t=new DateTime("now", new DateTimeZone("America/Sao_Paulo")); echo $t->format("Y-m-d H:i");' > "$V/T0-local.txt"
echo "== T0 (inicio da virada, hora da barbearia): $(cat "$V/T0-local.txt")"
t=$(tok login.php)
curl -s -o /dev/null -b "$J/c" -c "$J/c" "$B/login.php" --data-urlencode "csrf_token=$t" --data-urlencode login_type=admin --data-urlencode username=ensaio-admin --data-urlencode "password=$SENHA"
t=$(tok admin.php)
curl -s -b "$J/c" -o "$V/copia-final.sqlite" "$B/admin_actions.php?action=backup_sqlite&csrf_token=$t"
(cd "$V" && sha256sum copia-final.sqlite | tee copia-final.sqlite.sha256)
php -r '$p=new PDO("sqlite:".$argv[1]); echo "   quick_check: ", $p->query("PRAGMA quick_check")->fetchColumn(), " | agendamentos: ", $p->query("select count(*) from agendamentos")->fetchColumn(), " | clientes: ", $p->query("select count(*) from clientes")->fetchColumn(), "\n";' "$(cygpath -w "$V/copia-final.sqlite")"
rm -rf "$V/uploads-copia" && cp -r "$F13/antigo/uploads" "$V/uploads-copia"
echo "   uploads/: $(find "$V/uploads-copia" -type f | wc -l) arquivos"
