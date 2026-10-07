<?php

/**
 * SIMULADOR DO STRIPE para o ensaio de homologacao (Fase 13; homologacao.md).
 *
 * Responde as chamadas que o sistema faz (checkout, expirar, cancelar,
 * reembolso) e ENTREGA WEBHOOKS ASSINADOS de verdade, por HTTP, no endpoint
 * da homologacao, no formato da API fixada (2024-06-20). Serve para ensaiar o
 * ciclo inteiro sem conta no Stripe. NAO substitui o ciclo no modo teste do
 * Stripe real (pendente das chaves do dono): so prova que a instalacao,
 * configurada como em producao, conversa direito com um gateway.
 *
 * Uso (servidor embutido do PHP, como roteador):
 *   SIM_URL=http://127.0.0.1:8303 SIM_WEBHOOK=http://127.0.0.1:8302/webhooks/stripe \
 *   SIM_SECRET=whsec_... SIM_ESTADO=/pasta/estado.json php -S 127.0.0.1:8303 stripe-simulado.php
 *
 * Controle do ensaio (POST): /sim/renovar/{sub}, /sim/falhar/{sub},
 * /sim/recuperar/{sub}, /sim/encerrar/{sub}, /sim/reenviar/{evt},
 * /sim/fora-de-ordem/{sub}, /sim/duplicar/{evt}; GET /sim/eventos.
 * Nunca usar fora de um ambiente de ensaio.
 */
$SIM_URL = rtrim((string) getenv('SIM_URL'), '/');
$WEBHOOK = (string) getenv('SIM_WEBHOOK');
$SECRET = (string) getenv('SIM_SECRET');
$ESTADO = (string) getenv('SIM_ESTADO');

$metodo = $_SERVER['REQUEST_METHOD'];
$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

function estado(): array
{
    global $ESTADO;

    return is_file($ESTADO) ? (json_decode((string) file_get_contents($ESTADO), true) ?: []) : ['sessoes' => [], 'assinaturas' => [], 'eventos' => [], 'chamadas' => []];
}

function salvar(array $e): void
{
    global $ESTADO;
    file_put_contents($ESTADO, json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** Eventos que o Stripe mandaria DEPOIS de responder (como o real: assincrono). */
$DEPOIS = [];

function json(mixed $dados, int $status = 200): never
{
    responder((string) json_encode($dados, JSON_UNESCAPED_SLASHES), $status, 'application/json');
}

/** Responde, fecha a conexao e so entao entrega os webhooks pendentes. */
function responder(string $corpo, int $status = 200, string $tipo = 'text/html; charset=utf-8', ?string $local = null): never
{
    global $DEPOIS;
    ignore_user_abort(true);
    http_response_code($status);
    header('Content-Type: '.$tipo);
    if ($local !== null) {
        header('Location: '.$local);
    }
    header('Connection: close');
    header('Content-Length: '.strlen($corpo));
    echo $corpo;
    flush();
    if ($DEPOIS !== []) {
        usleep(300000);
        $e = estado();
        foreach ($DEPOIS as [$tipoEvento, $objeto]) {
            entregar($e, $tipoEvento, $objeto);
        }
        salvar($e);
    }
    exit;
}

function depois(string $tipo, array $objeto): void
{
    global $DEPOIS;
    $DEPOIS[] = [$tipo, $objeto];
}

function id(string $prefixo): string
{
    return $prefixo.bin2hex(random_bytes(8));
}

/** Entrega um evento assinado e guarda a resposta da aplicacao. */
function entregar(array &$e, string $tipo, array $objeto, ?string $idEvento = null, ?int $criado = null): array
{
    global $WEBHOOK, $SECRET;
    $evento = $idEvento !== null && isset($e['eventos'][$idEvento]) ? $e['eventos'][$idEvento]['evento'] : [
        'id' => $idEvento ?? id('evt_sim_'),
        'object' => 'event',
        'api_version' => '2024-06-20',
        'type' => $tipo,
        'created' => $criado ?? time(),
        'livemode' => false,
        'data' => ['object' => $objeto],
    ];
    $corpo = (string) json_encode($evento, JSON_UNESCAPED_SLASHES);
    $t = time();
    $assinatura = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$corpo, $SECRET);
    $ch = curl_init($WEBHOOK);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corpo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Stripe-Signature: '.$assinatura, 'User-Agent: Stripe/1.0 (+https://stripe.com/docs/webhooks) simulador-de-ensaio'],
    ]);
    $resposta = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $registro = $e['eventos'][$evento['id']] ?? ['evento' => $evento, 'entregas' => []];
    $registro['entregas'][] = ['quando' => date('c'), 'status' => $status, 'resposta' => mb_substr($resposta, 0, 200)];
    $e['eventos'][$evento['id']] = $registro;

    return ['id' => $evento['id'], 'type' => $evento['type'], 'status' => $status, 'resposta' => mb_substr($resposta, 0, 200)];
}

function assinaturaObjeto(array $s): array
{
    return [
        'id' => $s['id'], 'object' => 'subscription', 'customer' => $s['customer'], 'status' => $s['status'],
        'cancel_at_period_end' => $s['cancel_at_period_end'], 'cancel_at' => $s['cancel_at_period_end'] ? $s['current_period_end'] : null,
        'canceled_at' => $s['canceled_at'] ?? null, 'ended_at' => $s['ended_at'] ?? null,
        'current_period_start' => $s['current_period_start'], 'current_period_end' => $s['current_period_end'],
        'metadata' => ['local_subscription' => $s['local']],
        'items' => ['data' => [['price' => ['unit_amount' => $s['amount'], 'currency' => 'brl', 'recurring' => ['interval' => 'month']]]]],
    ];
}

function fatura(array $s, int $inicio, int $fim, string $motivo, bool $paga = true): array
{
    $base = $s['id'].$inicio;

    return [
        'id' => 'in_'.substr(md5($base), 0, 14), 'object' => 'invoice', 'subscription' => $s['id'], 'customer' => $s['customer'],
        'amount_paid' => $paga ? $s['amount'] : 0, 'amount_due' => $s['amount'], 'currency' => 'brl', 'billing_reason' => $motivo,
        'status' => $paga ? 'paid' : 'open', 'attempt_count' => $paga ? 1 : 2,
        'payment_intent' => 'pi_'.substr(md5($base.'pi'), 0, 14), 'charge' => 'ch_'.substr(md5($base.'ch'), 0, 14),
        'status_transitions' => ['paid_at' => $paga ? time() : null],
        'subscription_details' => ['metadata' => ['local_subscription' => $s['local']]],
        'lines' => ['data' => [['period' => ['start' => $inicio, 'end' => $fim]]]],
    ];
}

$e = estado();
if (str_starts_with($caminho, '/v1/')) {
    $e['chamadas'][] = [
        'quando' => date('c'), 'metodo' => $metodo, 'caminho' => $caminho,
        'idempotencia' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null, 'versao' => $_SERVER['HTTP_STRIPE_VERSION'] ?? null,
        'autenticada' => str_starts_with((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), 'Bearer sk_test_'),
    ];
    if (! str_starts_with((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), 'Bearer sk_test_')) {
        salvar($e);
        json(['error' => ['type' => 'invalid_request_error', 'code' => 'api_key_invalid']], 401);
    }
    // Idempotencia como no Stripe: mesma chave, mesma resposta.
    $chave = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    if ($chave !== null && isset($e['idempotencia'][$chave])) {
        salvar($e);
        json($e['idempotencia'][$chave]);
    }
}

$resposta = null;
if ($metodo === 'POST' && $caminho === '/v1/checkout/sessions') {
    $cs = id('cs_test_');
    $local = $_POST['metadata']['local_subscription'] ?? $_POST['client_reference_id'] ?? '';
    $e['sessoes'][$cs] = [
        'id' => $cs, 'local' => $local, 'status' => 'open', 'customer' => $_POST['customer'] ?? null,
        'email' => $_POST['customer_email'] ?? null, 'amount' => (int) ($_POST['line_items'][0]['price_data']['unit_amount'] ?? 0),
        'success_url' => $_POST['success_url'] ?? '', 'cancel_url' => $_POST['cancel_url'] ?? '',
    ];
    $resposta = ['id' => $cs, 'object' => 'checkout.session', 'url' => $SIM_URL.'/pay/'.$cs, 'expires_at' => time() + 86400, 'status' => 'open'];
} elseif ($metodo === 'POST' && preg_match('#^/v1/checkout/sessions/([^/]+)/expire$#', $caminho, $m)) {
    $s = $e['sessoes'][$m[1]] ?? null;
    if ($s === null) {
        salvar($e);
        json(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing']], 404);
    }
    $e['sessoes'][$m[1]]['status'] = 'expired';
    $resposta = ['id' => $m[1], 'object' => 'checkout.session', 'status' => 'expired'];
    depois('checkout.session.expired', ['id' => $m[1], 'object' => 'checkout.session', 'status' => 'expired', 'mode' => 'subscription', 'client_reference_id' => $s['local'], 'metadata' => ['local_subscription' => $s['local']]]);
} elseif ($metodo === 'POST' && preg_match('#^/v1/subscriptions/([^/]+)$#', $caminho, $m) && isset($e['assinaturas'][$m[1]])) {
    $e['assinaturas'][$m[1]]['cancel_at_period_end'] = ($_POST['cancel_at_period_end'] ?? '') === 'true';
    $resposta = assinaturaObjeto($e['assinaturas'][$m[1]]);
    depois('customer.subscription.updated', $resposta);
} elseif ($metodo === 'DELETE' && preg_match('#^/v1/subscriptions/([^/]+)$#', $caminho, $m) && isset($e['assinaturas'][$m[1]])) {
    $e['assinaturas'][$m[1]] = ['status' => 'canceled', 'canceled_at' => time(), 'ended_at' => time()] + $e['assinaturas'][$m[1]];
    $resposta = assinaturaObjeto($e['assinaturas'][$m[1]]);
    depois('customer.subscription.deleted', $resposta);
} elseif ($metodo === 'POST' && $caminho === '/v1/refunds') {
    $resposta = ['id' => id('re_sim_'), 'object' => 'refund', 'status' => 'succeeded', 'amount' => (int) ($_POST['amount'] ?? 0), 'payment_intent' => $_POST['payment_intent'] ?? null, 'metadata' => $_POST['metadata'] ?? []];
    depois('refund.created', $resposta);
} elseif ($metodo === 'GET' && preg_match('#^/pay/([^/]+)$#', $caminho, $m) && isset($e['sessoes'][$m[1]])) {
    $s = $e['sessoes'][$m[1]];
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Stripe SIMULADO (ensaio)</title><body style="font:16px system-ui;max-width:32rem;margin:3rem auto">'
        .'<h1>Checkout SIMULADO</h1><p>Ensaio de homologação: nenhum pagamento real.</p>'
        .'<p>Assinatura '.htmlspecialchars($s['local']).' · R$ '.number_format($s['amount'] / 100, 2, ',', '.').' por mês · situação '.$s['status'].'</p>'
        .'<form method="post" action="/pay/'.$m[1].'/ok"><button>Pagar (cartão de teste aprovado)</button></form>'
        .'<form method="post" action="/pay/'.$m[1].'/recusar" style="margin-top:1rem"><button>Cartão recusado</button></form></body>';
    exit;
} elseif ($metodo === 'POST' && preg_match('#^/pay/([^/]+)/(ok|recusar)$#', $caminho, $m) && isset($e['sessoes'][$m[1]])) {
    $s = $e['sessoes'][$m[1]];
    if ($m[2] === 'recusar') {
        salvar($e);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><p>Pagamento recusado (simulado). Nada foi cobrado; a sessão continua aberta.</p><p><a href="'.htmlspecialchars($s['cancel_url']).'">Voltar à barbearia</a></p>';
        exit;
    }
    if ($s['status'] !== 'open') {
        salvar($e);
        http_response_code(409);
        echo 'Sessão '.$s['status'];
        exit;
    }
    $agora = time();
    $sub = ['id' => id('sub_sim_'), 'customer' => $s['customer'] ?: id('cus_sim_'), 'status' => 'active', 'local' => $s['local'], 'amount' => $s['amount'],
        'cancel_at_period_end' => false, 'current_period_start' => $agora, 'current_period_end' => $agora + 30 * 86400];
    $e['assinaturas'][$sub['id']] = $sub;
    $e['sessoes'][$m[1]]['status'] = 'complete';
    depois('checkout.session.completed', ['id' => $m[1], 'object' => 'checkout.session', 'mode' => 'subscription', 'payment_status' => 'paid', 'status' => 'complete',
        'client_reference_id' => $s['local'], 'customer' => $sub['customer'], 'subscription' => $sub['id'], 'metadata' => ['local_subscription' => $s['local']]]);
    depois('customer.subscription.created', assinaturaObjeto($sub));
    depois('invoice.paid', fatura($sub, $agora, $sub['current_period_end'], 'subscription_create'));
    salvar($e);
    responder('', 303, 'text/plain', str_replace('{CHECKOUT_SESSION_ID}', $m[1], $s['success_url']));
} elseif ($metodo === 'POST' && preg_match('#^/sim/(renovar|falhar|recuperar|encerrar|fora-de-ordem)/([^/]+)$#', $caminho, $m) && isset($e['assinaturas'][$m[2]])) {
    $s = &$e['assinaturas'][$m[2]];
    $entregas = [];
    if ($m[1] === 'renovar' || $m[1] === 'recuperar') {
        $inicio = $m[1] === 'renovar' ? $s['current_period_end'] : $s['current_period_start'];
        $fim = $m[1] === 'renovar' ? $inicio + 30 * 86400 : $s['current_period_end'];
        [$s['current_period_start'], $s['current_period_end'], $s['status']] = [$inicio, $fim, 'active'];
        $entregas[] = entregar($e, 'invoice.paid', fatura($s, $inicio, $fim, 'subscription_cycle'));
        $entregas[] = entregar($e, 'customer.subscription.updated', assinaturaObjeto($s));
    } elseif ($m[1] === 'falhar') {
        $inicio = $s['current_period_end'];
        [$s['current_period_start'], $s['current_period_end'], $s['status']] = [$inicio, $inicio + 30 * 86400, 'past_due'];
        $entregas[] = entregar($e, 'invoice.payment_failed', fatura($s, $inicio, $s['current_period_end'], 'subscription_cycle', false));
        $entregas[] = entregar($e, 'customer.subscription.updated', assinaturaObjeto($s));
    } elseif ($m[1] === 'encerrar') {
        $s = ['status' => 'canceled', 'canceled_at' => time(), 'ended_at' => time()] + $s;
        $entregas[] = entregar($e, 'customer.subscription.deleted', assinaturaObjeto($s));
    } else {
        // Fotografia ANTIGA (criada uma hora antes) chegando depois da atual.
        $antiga = ['status' => 'past_due'] + $s;
        $entregas[] = entregar($e, 'customer.subscription.updated', assinaturaObjeto($antiga), null, time() - 3600);
    }
    unset($s);
    salvar($e);
    json($entregas);
} elseif ($metodo === 'POST' && preg_match('#^/sim/(reenviar|duplicar)/([^/]+)$#', $caminho, $m) && isset($e['eventos'][$m[2]])) {
    $r = entregar($e, '', [], $m[2]);
    if ($m[1] === 'duplicar') {
        $r = [$r, entregar($e, '', [], $m[2])];
    }
    salvar($e);
    json($r);
} elseif ($metodo === 'GET' && $caminho === '/sim/eventos') {
    json(array_map(fn ($r) => ['id' => $r['evento']['id'], 'type' => $r['evento']['type'], 'entregas' => $r['entregas']], array_values($e['eventos'])));
} elseif ($metodo === 'GET' && $caminho === '/sim/estado') {
    json($e);
} else {
    salvar($e);
    json(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'simulador: rota desconhecida '.$caminho]], 404);
}

if ($resposta !== null) {
    if (isset($chave)) {
        $e['idempotencia'][$chave] = $resposta;
    }
    salvar($e);
    json($resposta);
}
