#!/usr/bin/env bash
# Parte do CLIENTE no sistema antigo (copia de ensaio, dados ficticios):
# login, avaliacao do atendimento concluido e descadastro de marketing.
# Uso: popular-antigo-cliente.sh <url-base> <pasta-do-antigo> <senha-ficticia-contas> <banco.sqlite>
set -euo pipefail
B="$1"; RAIZ="$2"; SENHA_CONTAS="$3"; DB="$4"
PHP=/c/xampp/php/php.exe
J=$(mktemp -d)
trap 'rm -rf "$J"' EXIT
q() { "$PHP" -r '$p=new PDO("sqlite:".$argv[1]); $r=$p->query($argv[2])->fetchColumn(); echo $r===false?"":$r;' "$DB" "$1"; }
token() { curl -s -b "$J/c" -c "$J/c" "$B/$1" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'; }

echo "== login cliente.um"
t=$(token login_cliente.php)
curl -s -o /dev/null -b "$J/c" -c "$J/c" "$B/login_cliente.php" --data-urlencode "csrf_token=$t" --data-urlencode "identificador=cliente.um@ensaio.test" --data-urlencode "senha=$SENHA_CONTAS"
PG=$(curl -s -b "$J/c" "$B/cliente.php"); [[ "$PG" == *"Cliente Ensaio Um"* ]] && echo "  ok entrou" || { echo "  ! login do cliente falhou"; exit 1; }

echo "== avaliacao"
A1=$(q "select id from agendamentos where status='concluido' limit 1")
BB=$(q "select barbeiro_id from agendamentos where id='$A1'")
t=$(token cliente.php)
curl -s -o /dev/null -b "$J/c" -c "$J/c" "$B/salvar_avaliacao.php" --data-urlencode "csrf_token=$t" --data-urlencode "agendamento_id=$A1" --data-urlencode "barbeiro_id=$BB" --data-urlencode "rating=5" --data-urlencode "comment=Atendimento ficticio otimo"
echo "  avaliacoes: $(q 'select count(*) from avaliacoes')"

echo "== descadastro (cliente.dois)"
E=cliente.dois@ensaio.test
TK=$(cd "$RAIZ" && HTTP_HOST=127.0.0.1 "$PHP" -r 'require "functions.php"; require_once "lib/marketing_functions.php"; echo marketingTokenDescadastro($argv[1]);' "$E" 2>/dev/null | tail -c 20)
curl -s -o /dev/null -b "$J/c" -c "$J/c" "$B/descadastrar.php?e=$E&t=$TK" --data-urlencode "confirmar=1"
echo "  email_optout: $(q 'select count(*) from email_optout' 2>/dev/null || echo 0)"
