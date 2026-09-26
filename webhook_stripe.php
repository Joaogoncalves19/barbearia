<?php
// webhook_stripe.php
require_once 'functions.php';

/**
 * Resolve a assinatura local a partir de um objeto subscription do Stripe.
 * Tenta primeiro pelo gateway_subscription_id; se falhar (ex.: campo vazio no
 * banco), resolve pelo customer do Stripe -> e-mail -> cliente e, ao achar,
 * faz backfill do gateway_subscription_id para os próximos eventos casarem
 * direto. Assim o webhook não depende de um único campo estar preenchido.
 */
function resolverAssinaturaLocalStripe(array $subscription, string $secret_key) {
    $subscriptionId = (string)($subscription['id'] ?? '');
    $customerId = '';
    if (!empty($subscription['customer'])) {
        $customerId = is_array($subscription['customer'])
            ? (string)($subscription['customer']['id'] ?? '')
            : (string)$subscription['customer'];
    }

    // 1) Casamento direto pelo id da subscription.
    if ($subscriptionId !== '') {
        $local = getAssinaturaPorGateway('stripe', $subscriptionId);
        if ($local) { return _backfillIdsAssinaturaStripe($local, $subscriptionId, $customerId); }
    }

    // 2) Redundância: casamento pelo customer do Stripe (cus_...).
    if ($customerId !== '' && function_exists('getAssinaturaPorCustomerStripe')) {
        $local = getAssinaturaPorCustomerStripe($customerId);
        if ($local) { return _backfillIdsAssinaturaStripe($local, $subscriptionId, $customerId); }
    }

    // 3) Último recurso: customer -> e-mail -> cliente.
    $email = (string)($subscription['customer_email'] ?? '');
    if ($email === '' && $customerId !== '' && $secret_key !== '') {
        $ch = curl_init("https://api.stripe.com/v1/customers/" . urlencode($customerId));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $secret_key]);
        $custJson = curl_exec($ch);
        curl_close($ch);
        $cust = json_decode($custJson, true);
        $email = (string)($cust['email'] ?? '');
    }

    if ($email === '' || !function_exists('getClientePorEmailTelefoneOuCPF')) {
        return null;
    }
    $cliente = getClientePorEmailTelefoneOuCPF($email);
    if (!$cliente) { return null; }

    $local = getAssinaturaClienteQualquerStatus($cliente['id']);
    if (!$local) { return null; }

    return _backfillIdsAssinaturaStripe($local, $subscriptionId, $customerId);
}

/**
 * Preenche gateway_subscription_id / gateway_customer_id que estejam vazios no
 * registro local, para os próximos eventos casarem de imediato (self-healing).
 */
function _backfillIdsAssinaturaStripe(array $local, string $subscriptionId, string $customerId) {
    $sets = [];
    $params = [];
    if ($subscriptionId !== '' && (string)($local['gateway_subscription_id'] ?? '') === '') {
        $sets[] = "gateway = 'stripe'";
        $sets[] = "gateway_subscription_id = ?";
        $params[] = $subscriptionId;
        $local['gateway'] = 'stripe';
        $local['gateway_subscription_id'] = $subscriptionId;
    }
    if ($customerId !== '' && (string)($local['gateway_customer_id'] ?? '') === '') {
        $sets[] = "gateway_customer_id = ?";
        $params[] = $customerId;
        $local['gateway_customer_id'] = $customerId;
    }
    if (!empty($sets)) {
        $params[] = $local['cliente_id'];
        $pdo = getDB();
        $stmt = $pdo->prepare("UPDATE clientes_assinaturas SET " . implode(', ', $sets) . " WHERE cliente_id = ?");
        $stmt->execute($params);
        if (function_exists('log_activity')) {
            log_activity("Stripe: ids backfillados (sub=$subscriptionId cus=$customerId) para cliente " . $local['cliente_id'] . ".");
        }
    }
    return $local;
}

$payload = @file_get_contents('php://input');
$configStripe = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('config_stripe', ['secret_key' => '', 'webhook_secret' => '']) : [];
$webhookSecret = trim($configStripe['webhook_secret'] ?? '');

// Sem segredo de webhook não há como distinguir um evento do Stripe de um POST
// forjado por qualquer pessoa na internet — e este endpoint ativa assinaturas,
// estende validade e libera agendamentos pagos. Antes, o segredo vazio PULAVA a
// verificação e o evento era processado assim mesmo. Agora recusa.
if ($webhookSecret === '') {
    log_activity('Webhook Stripe RECUSADO: webhook_secret nao configurado. '
        . 'Configure em Configuracoes -> Stripe (Signing secret, whsec_...) — '
        . 'sem ele nenhuma ativacao/renovacao automatica sera aceita.');
    http_response_code(401);
    exit;
}

{
    $signatureHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $timestamp = '';
    $assinaturaRecebida = '';
    foreach (explode(',', $signatureHeader) as $parte) {
        [$chave, $valor] = array_pad(explode('=', trim($parte), 2), 2, '');
        if ($chave === 't') {
            $timestamp = $valor;
        } elseif ($chave === 'v1') {
            $assinaturaRecebida = $valor;
        }
    }

    $assinaturaEsperada = hash_hmac('sha256', $timestamp . '.' . $payload, $webhookSecret);
    if ($timestamp === '' || abs(time() - (int)$timestamp) > 300 || $assinaturaRecebida === '' || !hash_equals($assinaturaEsperada, $assinaturaRecebida)) {
        log_activity('Webhook Stripe rejeitado por assinatura invalida.');
        http_response_code(401);
        exit;
    }
}

$event = json_decode($payload, true);

if (function_exists('log_activity')) {
    log_activity("Webhook Stripe Recebido. Tipo: " . ($event['type'] ?? 'Desconhecido'));
}

if(!$event || !isset($event['id']) || !isset($event['type'])) { 
    http_response_code(400); 
    exit; 
}

http_response_code(200);

$secret_key = $configStripe['secret_key'] ?? '';

if (empty($secret_key)) {
    log_activity("Erro Webhook Stripe: Secret Key não configurada.");
    exit;
}

$ch = curl_init("https://api.stripe.com/v1/events/" . $event['id']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [ "Authorization: Bearer " . $secret_key ]);
$verified_event_json = curl_exec($ch);
curl_close($ch);

$verified_event = json_decode($verified_event_json, true);

if(!isset($verified_event['type'])) {
    log_activity("Webhook Stripe Inválido: Evento " . $event['id'] . " não encontrado.");
    exit;
}

// Trava de reentrega: o Stripe reenvia o mesmo evento (retentativa, "Resend" no
// painel, entrega duplicada). Reprocessar uma adesão reescrevia a validade para
// "hoje + 30 dias" e encurtava a assinatura de quem já tinha renovado.
if (!reservarEventoWebhook('stripe', (string)$event['id'], (string)$verified_event['type'])) {
    log_activity('Webhook Stripe ignorado (evento ja processado): ' . $event['id'] . ' / ' . $verified_event['type']);
    exit;
}

// =========================================================================
// 1. PRIMEIRA ATIVAÇÃO (Aparece no Painel, Ativa Plano e Envia E-mail)
// =========================================================================
if ($verified_event['type'] == 'checkout.session.completed') {
    
    $session = $verified_event['data']['object'];
    
    if (($session['mode'] ?? '') === 'subscription' && ($session['payment_status'] ?? '') === 'paid') {
        
        $ref = $session['client_reference_id'] ?? '';
        
        if ($ref && strpos($ref, '||') !== false) {
            $parts = explode('||', $ref);
            $cliente_id = $parts[0] ?? '';
            $plano_id = $parts[1] ?? '';
            $ag_id = $parts[2] ?? '';
            $stripe_subscription_id = (string)($session['subscription'] ?? '');
            $stripe_customer_id = (string)($session['customer'] ?? '');
            $stripe_payment_id = (string)($session['payment_intent'] ?? $session['id'] ?? '');

            // A adesão nunca pode ENCURTAR uma assinatura que já vale mais: se
            // o cliente ainda tem dias pagos (renovação já aplicada, ou adesão
            // feita com saldo restante), a validade maior prevalece. A trava de
            // evento acima já barra a reentrega; isto é a segunda linha de
            // defesa sobre o dado que representa dinheiro.
            $assinaturaAntes = getAssinaturaClienteQualquerStatus($cliente_id);
            $fimNovo = date('Y-m-d', strtotime('+30 days'));
            $fimAtual = (string)($assinaturaAntes['data_fim'] ?? '');
            if ($fimAtual > $fimNovo) {
                $fimNovo = $fimAtual;
                log_activity("Stripe adesao: validade existente ($fimAtual) preservada para o cliente $cliente_id.");
            }
            $diasAdesao = max(1, (int)round((strtotime($fimNovo) - strtotime(date('Y-m-d'))) / 86400));

            // Ativa o plano no banco de dados
            salvarAssinaturaCliente(
                $cliente_id,
                $plano_id,
                '+' . $diasAdesao . ' days',
                date('Y-m-d'),
                'ativo',
                [
                    'gateway' => 'stripe',
                    'gateway_subscription_id' => $stripe_subscription_id,
                    'gateway_customer_id' => $stripe_customer_id,
                    'gateway_status' => 'active',
                    'ultimo_pagamento_id' => $stripe_payment_id,
                    'cancelamento_em' => ''
                ]
            );
            $planosStripe = lerDados('planos', ['id', 'nome', 'valor', 'servicos_ids']);
            registrarPagamentoAssinatura(
                $stripe_payment_id,
                $cliente_id,
                $plano_id,
                'stripe',
                (float)($planosStripe[$plano_id]['valor'] ?? 0),
                !empty($session['created']) ? date('Y-m-d H:i:s', (int)$session['created']) : date('Y-m-d H:i:s'),
                'adesao',
                $stripe_subscription_id,
                $ag_id
            );
            criarNotificacao($cliente_id, "Seu pagamento foi confirmado pelo Stripe! Sua Barbearia por Assinatura está ATIVA.");
            
            // Reverte a invisibilidade (Libera o agendamento no painel aprovando-o)
            if (!empty($ag_id)) {
                $pdo = getDB();
                
                // Como a opção de aprovar manual não existe mais, todos os agendamentos pagos vão direto para aprovado.
                $novoStatusAg = 'aprovado';
                
                // Pega os dados do agendamento para poder disparar o email
                $stmtGet = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ?");
                $stmtGet->execute([$ag_id]);
                $ag = $stmtGet->fetch();
                
                if ($ag !== false) {
                    $obs = $ag['observacoes'] . ' [Pago com Sucesso via Stripe]';
                    $stmtUp = $pdo->prepare("UPDATE agendamentos SET status = ?, observacoes = ? WHERE id = ?");
                    $stmtUp->execute([$novoStatusAg, $obs, $ag_id]);

                    // ==================================================================
                    // DISPARA O E-MAIL DE CONFIRMAÇÃO AGORA QUE A STRIPE APROVOU!
                    // ==================================================================
                    $configAgendamentoEmail = function_exists('carregarConfigAgendamento') ? carregarConfigAgendamento() : [];
                    if (($configAgendamentoEmail['notif_confirmacao'] ?? 0) == 1) {
                        
                        // Carrega os dados de apoio
                        $barbeirosArr = lerDados('barbeiros', ['id', 'nome']);
                        $servicosArr = lerDados('servicos', ['id', 'nome']);
                        $combosArr = lerDados('combos', ['id', 'nome']);

                        $nomes_servicos = array_map(function($sid) use ($servicosArr, $combosArr) {
                            $sid = trim($sid);
                            if (isset($servicosArr[$sid])) return $servicosArr[$sid]['nome'];
                            if (isset($combosArr[$sid])) return $combosArr[$sid]['nome'] . " (Combo)";
                            return 'Serviço/Combo não encontrado';
                        }, explode(',', $ag['servicos_ids']));

                        $dados_email = [
                            'nome_cliente' => $ag['nome'],
                            'data_agendamento' => date('d/m/Y', strtotime($ag['data'])),
                            'hora_agendamento' => $ag['hora'],
                            'status' => $novoStatusAg,
                            'servicos' => $nomes_servicos,
                            'barbeiro' => $barbeirosArr[$ag['barbeiro_id']]['nome'] ?? 'Não especificado',
                            'tipo_desconto' => 'adesao_plano'
                        ];
                        
                        enviarEmail($ag['email'], 'Confirmação de Agendamento - Barbearia por Assinatura', 'confirmacao', $dados_email);
                    }
                }
            }
            
            log_activity("Stripe Ativação: Cliente $cliente_id ativou o Plano $plano_id. Agendamento $ag_id Liberado e E-mail Enviado.");
        }
    }
}

// =========================================================================
// 2. RENOVAÇÃO AUTOMÁTICA NO MÊS SEGUINTE
// =========================================================================
elseif ($verified_event['type'] == 'invoice.paid') {
    
    $invoice = $verified_event['data']['object'];
    
    if (($invoice['billing_reason'] ?? '') === 'subscription_cycle') {
        
        // Renovacao resolvida pelos IDs do Stripe (subscription -> customer),
        // com o e-mail so como ultimo recurso -- e o MESMO resolvedor que os
        // eventos de customer.subscription.* ja usavam.
        //
        // Antes isto dependia unicamente de $invoice['customer_email']: se o
        // admin trocasse o e-mail do cliente (ou o e-mail da conta Stripe fosse
        // outro), o cliente pagava a renovacao e a assinatura NAO era estendida,
        // em silencio. O ID da assinatura nao muda; o e-mail muda.
        $assinaturaAtual = resolverAssinaturaLocalStripe([
            'id'             => (string)($invoice['subscription'] ?? ''),
            'customer'       => $invoice['customer'] ?? '',
            'customer_email' => (string)($invoice['customer_email'] ?? ''),
        ], (string)$secret_key);

        if ($assinaturaAtual && !empty($assinaturaAtual['cliente_id'])) {
            $cliente_id = $assinaturaAtual['cliente_id'];

            // Idempotencia: o Stripe reenvia webhooks, entao a mesma fatura nao
            // pode estender a assinatura duas vezes.
            if (($assinaturaAtual['ultimo_pagamento_id'] ?? '') !== (string)($invoice['id'] ?? '')) {
                $plano_id = $assinaturaAtual['plano_id'];
                $data_base = date('Y-m-d');
                $data_inicio_base = ($assinaturaAtual['data_fim'] > $data_base) ? $assinaturaAtual['data_fim'] : $data_base;
                
                salvarAssinaturaCliente(
                    $cliente_id,
                    $plano_id,
                    '+30 days',
                    $data_inicio_base,
                    'ativo',
                    [
                        'gateway' => 'stripe',
                        'gateway_subscription_id' => (string)($invoice['subscription'] ?? ($assinaturaAtual['gateway_subscription_id'] ?? '')),
                        'gateway_customer_id' => (string)($invoice['customer'] ?? ($assinaturaAtual['gateway_customer_id'] ?? '')),
                        'gateway_status' => 'active',
                        'ultimo_pagamento_id' => (string)($invoice['id'] ?? ''),
                        'cancelamento_em' => ''
                    ]
                );
                $planosStripe = lerDados('planos', ['id', 'nome', 'valor', 'servicos_ids']);
                $valorRecebido = isset($invoice['amount_paid'])
                    ? ((float)$invoice['amount_paid'] / 100)
                    : (float)($planosStripe[$plano_id]['valor'] ?? 0);
                registrarPagamentoAssinatura(
                    (string)($invoice['id'] ?? ''),
                    $cliente_id,
                    $plano_id,
                    'stripe',
                    $valorRecebido,
                    !empty($invoice['status_transitions']['paid_at'])
                        ? date('Y-m-d H:i:s', (int)$invoice['status_transitions']['paid_at'])
                        : date('Y-m-d H:i:s'),
                    'renovacao',
                    (string)($invoice['subscription'] ?? ($assinaturaAtual['gateway_subscription_id'] ?? '')),
                    (string)($invoice['number'] ?? '')
                );
                criarNotificacao($cliente_id, "Sua assinatura foi renovada automaticamente com sucesso por mais um mês!");
                
                log_activity("Stripe Renovação Automática: Cliente $cliente_id renovou.");
            }
        } else {
            log_activity('Stripe invoice.paid: assinatura local nao encontrada para a fatura '
                . (string)($invoice['id'] ?? '?') . ' (subscription: ' . (string)($invoice['subscription'] ?? '?') . ').');
        }
    }
}
// =========================================================================
// 3. CANCELAMENTO AGENDADO / REATIVAÇÃO / MUDANÇA DE STATUS
//    (Disparado quando o cliente cancela pelo portal do Stripe ou o admin
//     agenda o cancelamento: o Stripe manda "updated" com cancel_at_period_end,
//     NÃO "deleted" — este só chega quando o período realmente termina.)
// =========================================================================
elseif ($verified_event['type'] == 'customer.subscription.updated') {
    $subscription = $verified_event['data']['object'] ?? [];
    $subscriptionId = (string)($subscription['id'] ?? '');
    $assinaturaLocal = resolverAssinaturaLocalStripe($subscription, (string)$secret_key);

    if ($assinaturaLocal) {
        // Toda a decisão (encerrada / cancelamento agendado / ativa-reativada)
        // vive em processarSubscriptionUpdatedStripe() para ser testável.
        processarSubscriptionUpdatedStripe($subscription, $assinaturaLocal);
    } else {
        log_activity("Stripe: evento subscription.updated sem assinatura local para $subscriptionId.");
    }
}
elseif ($verified_event['type'] == 'customer.subscription.deleted') {
    $subscription = $verified_event['data']['object'] ?? [];
    $subscriptionId = (string)($subscription['id'] ?? '');
    $assinaturaLocal = resolverAssinaturaLocalStripe($subscription, (string)$secret_key);

    if ($assinaturaLocal) {
        $fimPeriodoStripe = !empty($subscription['current_period_end'])
            ? date('Y-m-d', (int)$subscription['current_period_end'])
            : ($assinaturaLocal['data_fim'] ?? date('Y-m-d'));
        $statusLocal = $fimPeriodoStripe >= date('Y-m-d') ? 'cancelamento_agendado' : 'cancelado';

        $pdo = getDB();
        $stmt = $pdo->prepare("UPDATE clientes_assinaturas SET
            data_fim = ?, status = ?, gateway_status = 'canceled', cancelamento_em = ?
            WHERE cliente_id = ?");
        $stmt->execute([$fimPeriodoStripe, $statusLocal, date('Y-m-d H:i:s'), $assinaturaLocal['cliente_id']]);
        criarNotificacao($assinaturaLocal['cliente_id'], 'Sua assinatura Stripe foi cancelada. Não haverá novas cobranças.');
        log_activity('Stripe: assinatura ' . $subscriptionId . ' cancelada.');
    }
}
?>
