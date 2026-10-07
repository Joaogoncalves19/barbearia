#!/usr/bin/env bash
# Popula a COPIA DE ENSAIO do sistema antigo com dados FICTICIOS, usando as
# proprias acoes do sistema antigo (admin_actions.php, registro.php), para que
# os registros tenham exatamente o formato que o codigo antigo grava.
# Uso: popular-antigo.sh <url-base> <usuario-admin> <senha-admin> <senha-ficticia-contas> <banco.sqlite>
set -euo pipefail
B="$1"; ADM="$2"; SENHA="$3"; SENHA_CONTAS="$4"; DB="$5"
PHP=/c/xampp/php/php.exe
J=$(mktemp -d)
trap 'rm -rf "$J"' EXIT

q() { "$PHP" -r '$p=new PDO("sqlite:".$argv[1]); $r=$p->query($argv[2])->fetchColumn(); echo $r===false?"":$r;' "$DB" "$1"; }
token() { curl -s -b "$J/c" -c "$J/c" "$B/$1" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
acao() { # acao <nome> [campos curl...]
  local nome="$1"; shift
  local t; t=$(token admin.php)
  local loc
  loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J/c" -c "$J/c" "$B/admin_actions.php" --data-urlencode "action=$nome" --data-urlencode "csrf_token=$t" "$@")
  case "$loc" in *error=*) echo "  ! $nome: $(printf '%b' "${loc//%/\\x}" | sed 's/.*error=//')"; return 1;; esac
  echo "  ok $nome"
}

echo "== login admin"
t=$(token login.php)
curl -s -o /dev/null -b "$J/c" -c "$J/c" "$B/login.php" --data-urlencode "csrf_token=$t" --data-urlencode "login_type=admin" --data-urlencode "username=$ADM" --data-urlencode "password=$SENHA"

HOJE=$(date +%F); AMANHA=$(date -d tomorrow +%F); ONTEM=$(date -d yesterday +%F)

echo "== catalogo"
acao salvar_categoria --data-urlencode "nome=Cabelo" --data-urlencode "ordem=1"
CAT=$(q "select id from categorias where nome='Cabelo'")
acao salvar_servico --data-urlencode "nome=Corte Ensaio" --data-urlencode "valor=45,00" --data-urlencode "slots=1" --data-urlencode "categoria_id=$CAT" --data-urlencode "descricao=Corte de cabelo (ficticio)"
acao salvar_servico --data-urlencode "nome=Barba Ensaio" --data-urlencode "valor=35.50" --data-urlencode "slots=1" --data-urlencode "categoria_id=$CAT" --data-urlencode "descricao="
SV1=$(q "select id from servicos where nome='Corte Ensaio'"); SV2=$(q "select id from servicos where nome='Barba Ensaio'")
acao salvar_combo --data-urlencode "nome=Corte + Barba Ensaio" --data-urlencode "combo_servicos_ids=$SV1,$SV2" --data-urlencode "valor=70,00" --data-urlencode "categoria_id=$CAT"
acao salvar_produto --data-urlencode "nome=Pomada Ensaio" --data-urlencode "valor=29,90" --data-urlencode "quantidade=10" --data-urlencode "categoria_id=" --data-urlencode "estoque_minimo=2" --data-urlencode "custo=12,00"
PR=$(q "select id from produtos where nome='Pomada Ensaio'")
acao movimentar_estoque --data-urlencode "produto_id=$PR" --data-urlencode "tipo_movimentacao=entrada" --data-urlencode "quantidade_mov=5" --data-urlencode "motivo=Compra ficticia"
acao salvar_plano --data-urlencode "nome=Plano Ensaio" --data-urlencode "valor=99,90" --data-urlencode "plano_servicos_ids[]=$SV1"
PL=$(q "select id from planos where nome='Plano Ensaio'")

echo "== equipe"
acao salvar_barbeiro --data-urlencode "nome=Barbeiro Ensaio" --data-urlencode "username=barbeiro.ensaio" --data-urlencode "password=$SENHA_CONTAS" --data-urlencode "status=ativo" --data-urlencode "comissao=40" --data-urlencode "comissao_produtos=10" --data-urlencode "comissao_assinatura_tipo=padrao" --data-urlencode "comissao_assinatura_valor=0" --data-urlencode "especialidades_ids[]=$SV1" --data-urlencode "especialidades_ids[]=$SV2"
BB=$(q "select id from barbeiros where username='barbeiro.ensaio'")
args=(); for d in 0 1 2 3 4 5 6; do args+=(--data-urlencode "horarios[$d][ativo]=1" --data-urlencode "horarios[$d][inicio]=08:00" --data-urlencode "horarios[$d][fim]=21:00"); done
acao salvar_semana_horarios --data-urlencode "barbeiro_id=$BB" "${args[@]}"
acao salvar_ausencia --data-urlencode "ausencia_barbeiro_id=$BB" --data-urlencode "ausencia_data_inicio=$(date -d '+20 days' +%F)" --data-urlencode "ausencia_data_fim=$(date -d '+21 days' +%F)" --data-urlencode "ausencia_tipo=folga" --data-urlencode "ausencia_motivo=Folga ficticia"

echo "== clientes"
acao salvar_cliente --data-urlencode "nome=Cliente Ensaio Um" --data-urlencode "email=cliente.um@ensaio.test" --data-urlencode "telefone=(11) 91111-1111" --data-urlencode "cpf=529.982.247-25" --data-urlencode "data_nascimento=1990-05-10" --data-urlencode "nova_senha=$SENHA_CONTAS"
acao salvar_cliente --data-urlencode "nome=Cliente Ensaio Dois" --data-urlencode "email=cliente.dois@ensaio.test" --data-urlencode "telefone=(11) 92222-2222" --data-urlencode "cpf=111.444.777-35" --data-urlencode "data_nascimento=1985-12-01" --data-urlencode "nova_senha=$SENHA_CONTAS"
C1=$(q "select id from clientes where email='cliente.um@ensaio.test'"); C2=$(q "select id from clientes where email='cliente.dois@ensaio.test'")
acao salvar_anotacao --data-urlencode "cliente_id=$C1" --data-urlencode "anotacao=Prefere maquina 2 nas laterais (ficticio)"
acao ativar_assinatura --data-urlencode "cliente_id=$C2" --data-urlencode "plano_id=$PL" --data-urlencode "dias_validade=30" --data-urlencode "status_assinatura=ativo"

echo "== promocoes"
acao salvar_cupom --data-urlencode "codigo=ENSAIO10" --data-urlencode "tipo_desconto=percentual" --data-urlencode "desconto_percentual=10" --data-urlencode "valor_desconto=0" --data-urlencode "ativo=1" --data-urlencode "usos_maximos=50" --data-urlencode "data_validade=$(date -d '+60 days' +%F)"
acao gerar_voucher --data-urlencode "valor_voucher=50,00" --data-urlencode "data_validade_voucher=$(date -d '+90 days' +%F)" --data-urlencode "comprador=Comprador Ficticio"
acao salvar_config_fidelidade --data-urlencode "fidelidade_ativado=1" --data-urlencode "modo_ganho=visita" --data-urlencode "pontos_por_visita=1" --data-urlencode "pontos_necessarios=10" --data-urlencode "tipo_recompensa=percentual" --data-urlencode "desconto_percentual=20"
acao ajustar_pontos --data-urlencode "cliente_id=$C1" --data-urlencode "delta=3"

echo "== agenda"
acao salvar_agendamento_manual --data-urlencode "manual_cliente_id=$C1" --data-urlencode "manual_servicos=$SV1,$SV2" --data-urlencode "manual_data=$HOJE" --data-urlencode "manual_horario=20:00" --data-urlencode "manual_barbeiro=$BB"
acao salvar_agendamento_manual --data-urlencode "manual_cliente_id=$C2" --data-urlencode "manual_servicos=$SV1" --data-urlencode "manual_data=$AMANHA" --data-urlencode "manual_horario=10:00" --data-urlencode "manual_barbeiro=$BB"
acao salvar_agendamento_manual --data-urlencode "manual_cliente_id=" --data-urlencode "manual_nome=Avulso Ensaio" --data-urlencode "manual_telefone=(11) 93333-3333" --data-urlencode "manual_servicos=$SV2" --data-urlencode "manual_data=$AMANHA" --data-urlencode "manual_horario=11:00" --data-urlencode "manual_barbeiro=$BB"
acao salvar_agendamento_manual --data-urlencode "manual_cliente_id=$C1" --data-urlencode "manual_servicos=$SV2" --data-urlencode "manual_data=$AMANHA" --data-urlencode "manual_horario=15:00" --data-urlencode "manual_barbeiro=$BB"
A1=$(q "select id from agendamentos where data='$HOJE' and hora='20:00'")
A3=$(q "select id from agendamentos where data='$AMANHA' and hora='15:00'")
acao adicionar_produto --data-urlencode "agendamento_id=$A1" --data-urlencode "produto_id=$PR" --data-urlencode "qtd_vendida=2"
acao fechar_comanda --data-urlencode "agendamento_id=$A1" --data-urlencode "gorjeta=10,00" --data-urlencode "forma_pagamento=pix"
t=$(token admin.php)
curl -s -o /dev/null -b "$J/c" -c "$J/c" "$B/admin_actions.php?action=cancelar&id=$A3&csrf_token=$t" && echo "  ok cancelar"

echo "== financeiro"
acao salvar_despesa --data-urlencode "descricao=Aluguel ficticio" --data-urlencode "valor=1.500,00" --data-urlencode "data_vencimento=$HOJE" --data-urlencode "categoria=Aluguel" --data-urlencode "status=pago"
acao salvar_vale --data-urlencode "barbeiro_id=$BB" --data-urlencode "valor=50,00" --data-urlencode "data_vale=$HOJE" --data-urlencode "descricao=Vale ficticio"
acao pagar_comissao --data-urlencode "barbeiro_id=$BB" --data-urlencode "mes_ano=$(date +%Y-%m)" --data-urlencode "valor_total_servicos=80.50" --data-urlencode "valor_comissao=32.20" --data-urlencode "valor_gorjeta=10.00"

echo "== resumo"
for t in categorias servicos combos produtos estoque_logs planos barbeiros horarios_trabalho barbeiro_ausencias clientes anotacoes_clientes clientes_assinaturas cupoes vouchers fidelidade fidelidade_historico agendamentos agenda_historico despesas vales comissoes_pagas notificacoes; do
  printf '  %-22s %s\n' "$t" "$(q "select count(*) from $t" 2>/dev/null || echo '-')"
done
