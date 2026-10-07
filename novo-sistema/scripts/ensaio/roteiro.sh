#!/usr/bin/env bash
# Roda o roteiro de homologacao (tests/homologacao) contra a homologacao local,
# lendo as senhas de ensaio do arquivo local (nunca no codigo nem no chat).
# Uso: roteiro.sh [argumentos do playwright]
F13="$(cd "$(dirname "$0")" && pwd)"
A="$F13/acessos-ensaio.txt"
export HOMOLOG_SENHA_ADMIN_ANTIGA="$(sed -n 3p "$A" | sed 's/senha: //')"
export HOMOLOG_SENHA_CONTAS_ANTIGAS="$(sed -n 5p "$A" | sed 's/.*: //')"
export HOMOLOG_SENHA_PROVISORIA="$(sed -n 7p "$A" | sed 's/.*: //')"
cd /c/xampp/htdocs/barbearia/novo-sistema
npx playwright test -c playwright.homologacao.config.js "$@" 2>&1 | grep -v "^\s*$" | grep -v "npm notice" | grep -v "^\s*\[3[12]m"
