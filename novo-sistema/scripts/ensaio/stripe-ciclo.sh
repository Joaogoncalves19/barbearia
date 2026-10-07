#!/usr/bin/env bash
# Ciclo de vida da assinatura na homologacao, pelo simulador do Stripe
# (webhooks assinados por HTTP). Depois de cada passo, o estado no banco.
set -uo pipefail
F13="$(cd "$(dirname "$0")" && pwd)"
SIM=http://127.0.0.1:8303
DB="$(cygpath -w "$F13/homolog/barbearia/database/database.sqlite")"
estado() {
  php -r '$p=new PDO("sqlite:".$argv[1]); $s=$p->query("select s.id, s.status, s.ends_on, s.cancel_at_period_end, s.gateway_subscription_id g from subscriptions s join customers c on c.id=s.customer_id where c.email=\"cliente.um@ensaio.test\" and s.gateway_subscription_id is not null order by s.id desc")->fetch(PDO::FETCH_ASSOC);
    $pg=$p->query("select count(*) n, coalesce(sum(amount_cents),0) t from subscription_payments where subscription_id=".(int)$s["id"])->fetch(PDO::FETCH_ASSOC);
    $ev=$p->query("select count(*) from gateway_events")->fetchColumn();
    printf("   estado: %s | fim %s | cancela no fim %s | pagamentos %d (R$ %s) | eventos guardados %d\n", $s["status"], substr((string)$s["ends_on"],0,10), $s["cancel_at_period_end"]?"sim":"nao", $pg["n"], number_format($pg["t"]/100,2,",","."), $ev);' "$DB"
}
SUB="$(php -r '$p=new PDO("sqlite:".$argv[1]); echo $p->query("select gateway_subscription_id from subscriptions where gateway_subscription_id like \"sub_sim_%\" order by id desc limit 1")->fetchColumn();' "$DB")"
echo "== Assinatura no simulador: $SUB"; estado
passo() { echo "== $1"; curl -s -X POST "$SIM/sim/$2/$SUB" | php -r '$r=json_decode(stream_get_contents(STDIN),true); foreach((array)$r as $e) if(is_array($e)) echo "   webhook ",$e["type"]??"?"," -> HTTP ",$e["status"]," ",$e["resposta"],"\n";'; sleep 1; estado; }
passo "Renovacao paga (invoice.paid de novo periodo)" renovar
passo "Cobranca da renovacao recusada (invoice.payment_failed + past_due)" falhar
passo "Pagamento recuperado" recuperar
ULT="$(curl -s "$SIM/sim/eventos" | php -r '$r=json_decode(stream_get_contents(STDIN),true); foreach($r as $e) if($e["type"]==="invoice.paid") $u=$e["id"]; echo $u;')"
echo "== Reenvio do mesmo evento ($ULT) pelo Stripe"; curl -s -X POST "$SIM/sim/reenviar/$ULT"; echo; sleep 1; estado
echo "== Evento duplicado (duas entregas seguidas)"; curl -s -X POST "$SIM/sim/duplicar/$ULT"; echo; sleep 1; estado
passo "Fotografia antiga chegando fora de ordem (past_due de 1 h atras)" fora-de-ordem
