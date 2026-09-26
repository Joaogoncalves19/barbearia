<?php
/**
 * Suíte de verificação do sistema de assinaturas (dinheiro recorrente).
 *
 * COMO RODAR (a partir da pasta do projeto):
 *
 *     php tests/assinaturas.php
 *
 * Aceita opcionalmente o caminho de outro banco de origem, útil para conferir
 * uma instalação nova antes de colocá-la no ar:
 *
 *     php tests/assinaturas.php /caminho/database.sqlite
 *
 * Cobre vigência do benefício, expiração com tolerância, renovação, eventos do
 * Stripe (adesão, renovação, cancelamento agendado, reativação, past_due),
 * idempotência de webhook e de pagamento, unicidade da assinatura por cliente,
 * comissão do profissional e composição do MRR.
 *
 * SEGURANÇA: o teste NUNCA toca no banco real. Ele copia
 * _dados/database.sqlite para a pasta temporária do sistema e aponta a
 * aplicação para a cópia. Se por qualquer motivo o caminho apontar para o
 * banco de produção, o script aborta antes de escrever qualquer coisa.
 * A cópia é apagada no fim — e, quando o Windows ainda segura o arquivo, na
 * rodada seguinte; nunca fica mais de uma sobra.
 *
 * Sai com código 0 se tudo passar e 1 se algo falhar (dá para plugar num hook
 * de pré-commit ou numa rotina de deploy).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Esta suite roda apenas por linha de comando.\n");

}

$raiz = dirname(__DIR__);
// Por padrão parte do banco da instalação; um caminho no argumento permite
// conferir outro banco (por exemplo, um recém-criado pelo install.php).
$bancoReal  = $argv[1] ?? ($raiz . '/_dados/database.sqlite');
$moldeTemp  = sys_get_temp_dir() . '/barbearia_teste_assinaturas_';
$bancoTeste = $moldeTemp . getmypid() . '.sqlite';

// Varre sobras de execuções anteriores. No Windows o SQLite às vezes ainda
// segura o arquivo quando o PHP encerra, e o unlink do fim falha; aqui a
// limpeza acontece na rodada seguinte, então nada se acumula.
foreach ((array)glob($moldeTemp . '*.sqlite') as $sobra) {
    @unlink($sobra);
}

if (!is_file($bancoReal)) {
    fwrite(STDERR, "Banco não encontrado em $bancoReal — rode o install.php antes.\n");
    exit(1);
}
if (!copy($bancoReal, $bancoTeste)) {
    fwrite(STDERR, "Não foi possível criar a cópia de teste em $bancoTeste.\n");
    exit(1);
}
// Trava dupla: mesmo que algo acima mude, não escrevemos no banco de produção.
if (realpath($bancoTeste) === realpath($bancoReal)) {
    @unlink($bancoTeste);
    fwrite(STDERR, "ABORTADO: a cópia de teste apontou para o banco real.\n");
    exit(1);
}
register_shutdown_function(function () use ($bancoTeste) { @unlink($bancoTeste); });

define('SQLITE_DB_PATH', $bancoTeste);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
chdir($raiz);
require_once $raiz . '/functions.php';
date_default_timezone_set('America/Sao_Paulo');

// ---------------------------------------------------------------------------
// Utilitários
// ---------------------------------------------------------------------------
$ok = 0;
$falhas = [];

function checar($nome, $condicao, $detalhe = '') {
    global $ok, $falhas;
    if ($condicao) {
        $ok++;
        echo "  PASS  $nome\n";
    } else {
        $falhas[] = $nome . ($detalhe ? " -- $detalhe" : '');
        echo "  FALHA $nome" . ($detalhe ? " -- $detalhe" : '') . "\n";
    }
}

function dias($n) {
    return date('Y-m-d', strtotime("$n days"));
}

function limpar() {
    $pdo = getDB();
    $pdo->exec("DELETE FROM clientes_assinaturas");
    try { $pdo->exec("DELETE FROM assinatura_pagamentos"); } catch (Exception $e) {}
    try { $pdo->exec("DELETE FROM webhook_eventos_processados"); } catch (Exception $e) {}
}

function assinaturaDe($clienteId) {
    $st = getDB()->prepare("SELECT * FROM clientes_assinaturas WHERE cliente_id = ?");
    $st->execute([$clienteId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Reproduz o cálculo de validade que webhook_stripe.php faz na adesão:
 * 30 dias novos, mas nunca encurtando uma validade maior já paga.
 */
function adesaoComoNoWebhook($clienteId, $planoId) {
    $antes    = getAssinaturaClienteQualquerStatus($clienteId);
    $fimNovo  = date('Y-m-d', strtotime('+30 days'));
    $fimAtual = (string)($antes['data_fim'] ?? '');
    if ($fimAtual > $fimNovo) {
        $fimNovo = $fimAtual;
    }
    $qtdDias = max(1, (int)round((strtotime($fimNovo) - strtotime(date('Y-m-d'))) / 86400));
    salvarAssinaturaCliente($clienteId, $planoId, '+' . $qtdDias . ' days', date('Y-m-d'), 'ativo', [
        'gateway' => 'stripe',
        'gateway_subscription_id' => 'sub_' . $clienteId,
    ]);
}

garantirEstruturaAssinaturas();
garantirEstruturaPagamentosAssinatura();

echo "\nSuite de assinaturas — banco de teste: $bancoTeste\n";

// ---------------------------------------------------------------------------
echo "\n=== 1. Vigência do benefício (getAssinaturaCliente) ===\n";
limpar();
salvarAssinaturaCliente('t1', 'pl1', '+30 days', dias(-10), 'ativo');                  // vence em +20
salvarAssinaturaCliente('t2', 'pl1', '+30 days', dias(-40), 'ativo');                  // venceu há 10
salvarAssinaturaCliente('t3', 'pl1', '+30 days', dias(-10), 'cancelamento_agendado');
salvarAssinaturaCliente('t4', 'pl1', '+30 days', dias(-10), 'expirado');
salvarAssinaturaCliente('t5', 'pl1', '+30 days', dias(-30), 'ativo');                  // vence hoje
checar('ativa dentro da validade concede benefício', getAssinaturaCliente('t1') !== null);
checar('vencida NÃO concede benefício', getAssinaturaCliente('t2') === null);
checar('cancelamento agendado mantém benefício até o fim', getAssinaturaCliente('t3') !== null);
checar('status expirado não concede benefício', getAssinaturaCliente('t4') === null);
checar('benefício vale no PRÓPRIO dia do vencimento', getAssinaturaCliente('t5') !== null);

// ---------------------------------------------------------------------------
echo "\n=== 2. Auto-expiração com tolerância (grace de 1 dia) ===\n";
limpar();
salvarAssinaturaCliente('g1', 'pl1', '+30 days', dias(-30), 'ativo');   // fim = hoje
salvarAssinaturaCliente('g2', 'pl1', '+30 days', dias(-31), 'ativo');   // fim = ontem
salvarAssinaturaCliente('g3', 'pl1', '+30 days', dias(-35), 'ativo');   // fim = -5
getAssinaturaClienteQualquerStatus('g1');
getAssinaturaClienteQualquerStatus('g2');
getAssinaturaClienteQualquerStatus('g3');
checar('não expira no dia do vencimento', assinaturaDe('g1')['status'] === 'ativo', 'status=' . assinaturaDe('g1')['status']);
checar('não expira 1 dia depois (tolerância p/ renovação Stripe cair)', assinaturaDe('g2')['status'] === 'ativo', 'status=' . assinaturaDe('g2')['status']);
checar('expira depois da tolerância', assinaturaDe('g3')['status'] === 'expirado', 'status=' . assinaturaDe('g3')['status']);

// ---------------------------------------------------------------------------
echo "\n=== 3. expirarAssinaturasVencidas em lote (cron) ===\n";
limpar();
salvarAssinaturaCliente('b1', 'pl1', '+30 days', dias(-40), 'ativo');
salvarAssinaturaCliente('b2', 'pl1', '+30 days', dias(-40), 'cancelamento_agendado');
salvarAssinaturaCliente('b3', 'pl1', '+30 days', dias(-10), 'ativo');
$expiradas = expirarAssinaturasVencidas(1);
checar('expira as duas vencidas e poupa a vigente',
    $expiradas === 2 && assinaturaDe('b3')['status'] === 'ativo', "expiradas=$expiradas");
checar('é idempotente (rodar de novo não expira nada)', expirarAssinaturasVencidas(1) === 0);

// ---------------------------------------------------------------------------
echo "\n=== 4. Renovação: data-base do novo período ===\n";
limpar();
salvarAssinaturaCliente('r1', 'pl1', '+30 days', dias(-10), 'ativo');  // fim = +20
$fimAntes = assinaturaDe('r1')['data_fim'];
$base = ($fimAntes > date('Y-m-d')) ? $fimAntes : date('Y-m-d');
salvarAssinaturaCliente('r1', 'pl1', '+30 days', $base, 'ativo', ['ultimo_pagamento_id' => 'in_1']);
checar('renovar cedo EMENDA no vencimento (não perde dias pagos)',
    assinaturaDe('r1')['data_fim'] === date('Y-m-d', strtotime($fimAntes . ' +30 days')),
    'fim=' . assinaturaDe('r1')['data_fim']);

limpar();
salvarAssinaturaCliente('r2', 'pl1', '+30 days', dias(-40), 'expirado'); // fim = -10
$fimAntes = assinaturaDe('r2')['data_fim'];
$base = ($fimAntes > date('Y-m-d')) ? $fimAntes : date('Y-m-d');
salvarAssinaturaCliente('r2', 'pl1', '+30 days', $base, 'ativo', ['ultimo_pagamento_id' => 'in_2']);
checar('renovar após vencida conta a partir de hoje (não retroage)',
    assinaturaDe('r2')['data_fim'] === dias(30), 'fim=' . assinaturaDe('r2')['data_fim']);

// ---------------------------------------------------------------------------
echo "\n=== 5. Webhook Stripe: transições de customer.subscription.updated ===\n";
limpar();
salvarAssinaturaCliente('s1', 'pl1', '+30 days', dias(-10), 'ativo', [
    'gateway' => 'stripe', 'gateway_subscription_id' => 'sub_1',
]);
$fimFuturo = strtotime(dias(20));

$r = processarSubscriptionUpdatedStripe(
    ['id' => 'sub_1', 'status' => 'active', 'cancel_at_period_end' => true, 'cancel_at' => $fimFuturo],
    assinaturaDe('s1'));
checar('cancel_at_period_end => cancelamento agendado', $r === 'cancelamento_agendado', "retornou=$r");
checar('  ... e o benefício segue válido', getAssinaturaCliente('s1') !== null);

$r = processarSubscriptionUpdatedStripe(
    ['id' => 'sub_1', 'status' => 'active', 'cancel_at_period_end' => false, 'current_period_end' => $fimFuturo],
    assinaturaDe('s1'));
checar('reativação no portal volta para ativo',
    $r === 'ativo' && assinaturaDe('s1')['cancelamento_em'] === '', "retornou=$r");

$r = processarSubscriptionUpdatedStripe(
    ['id' => 'sub_1', 'status' => 'past_due', 'current_period_end' => $fimFuturo],
    assinaturaDe('s1'));
checar('past_due mantém benefício até o fim do período pago',
    $r === 'ativo' && assinaturaDe('s1')['gateway_status'] === 'past_due', "retornou=$r");

$r = processarSubscriptionUpdatedStripe(
    ['id' => 'sub_1', 'status' => 'canceled', 'current_period_end' => strtotime(dias(-5))],
    assinaturaDe('s1'));
checar('canceled com período já vencido => cancelado', $r === 'cancelado', "retornou=$r");
checar('  ... e o benefício some', getAssinaturaCliente('s1') === null);

// ---------------------------------------------------------------------------
echo "\n=== 6. current_period_end dentro de items (API nova do Stripe) ===\n";
limpar();
salvarAssinaturaCliente('s2', 'pl1', '+30 days', dias(-10), 'ativo', [
    'gateway' => 'stripe', 'gateway_subscription_id' => 'sub_2',
]);
processarSubscriptionUpdatedStripe(
    ['id' => 'sub_2', 'status' => 'active', 'items' => ['data' => [['current_period_end' => strtotime(dias(25))]]]],
    assinaturaDe('s2'));
checar('lê current_period_end de items[].data',
    assinaturaDe('s2')['data_fim'] === dias(25), 'fim=' . assinaturaDe('s2')['data_fim']);

// ---------------------------------------------------------------------------
echo "\n=== 7. Idempotência do registro de pagamento ===\n";
limpar();
registrarPagamentoAssinatura('pi_1', 'p1', 'pl1', 'stripe', 129.90, date('Y-m-d H:i:s'), 'adesao', 'sub_1');
registrarPagamentoAssinatura('pi_1', 'p1', 'pl1', 'stripe', 129.90, date('Y-m-d H:i:s'), 'adesao', 'sub_1');
$linhas = (int)getDB()->query("SELECT COUNT(*) FROM assinatura_pagamentos WHERE id='pi_1'")->fetchColumn();
checar('mesmo id de pagamento não duplica receita', $linhas === 1, "linhas=$linhas");
checar('pagamento com valor zero é rejeitado', registrarPagamentoAssinatura('pi_0', 'p1', 'pl1', 'stripe', 0) === false);
checar('pagamento sem cliente é rejeitado', registrarPagamentoAssinatura('pi_x', '', 'pl1', 'stripe', 50) === false);

// ---------------------------------------------------------------------------
echo "\n=== 8. Reentrega de evento do Stripe (trava de idempotência) ===\n";
limpar();
checar('primeira entrega do evento é aceita',
    reservarEventoWebhook('stripe', 'evt_100', 'checkout.session.completed') === true);
checar('reentrega do MESMO evento é recusada',
    reservarEventoWebhook('stripe', 'evt_100', 'checkout.session.completed') === false);
checar('outro evento continua passando',
    reservarEventoWebhook('stripe', 'evt_101', 'invoice.paid') === true);

limpar();
adesaoComoNoWebhook('c1', 'pl1');                                                                          // adesão
salvarAssinaturaCliente('c1', 'pl1', '+30 days', dias(30), 'ativo', ['ultimo_pagamento_id' => 'in_c1']);   // renovação
$fimAposRenovacao = assinaturaDe('c1')['data_fim'];
adesaoComoNoWebhook('c1', 'pl1');                                                                          // adesão reprocessada
checar('reprocessar a adesão NÃO encurta a assinatura já renovada',
    assinaturaDe('c1')['data_fim'] === $fimAposRenovacao,
    'apos_renovacao=' . $fimAposRenovacao . ' apos_replay=' . assinaturaDe('c1')['data_fim']);

limpar();
adesaoComoNoWebhook('c2', 'pl1');
checar('adesão de cliente novo vale 30 dias', assinaturaDe('c2')['data_fim'] === dias(30));
checar('  ... e começa hoje', assinaturaDe('c2')['data_inicio'] === date('Y-m-d'));

// ---------------------------------------------------------------------------
echo "\n=== 9. Uma assinatura por cliente ===\n";
limpar();
$pdo = getDB();
$pdo->prepare("INSERT INTO clientes_assinaturas (cliente_id,plano_id,data_inicio,data_fim,status) VALUES (?,?,?,?,?)")
    ->execute(['d1', 'pl1', dias(-40), dias(-10), 'expirado']);
$bloqueado = false;
try {
    $pdo->prepare("INSERT INTO clientes_assinaturas (cliente_id,plano_id,data_inicio,data_fim,status) VALUES (?,?,?,?,?)")
        ->execute(['d1', 'pl2', dias(-5), dias(25), 'ativo']);
} catch (PDOException $e) {
    $bloqueado = strpos($e->getMessage(), 'UNIQUE') !== false;
}
checar('banco impede duas assinaturas para o mesmo cliente', $bloqueado,
    'linhas=' . $pdo->query("SELECT COUNT(*) FROM clientes_assinaturas WHERE cliente_id='d1'")->fetchColumn());

salvarAssinaturaCliente('d1', 'pl2', '+30 days', date('Y-m-d'), 'ativo');
checar('salvarAssinaturaCliente ainda atualiza a linha existente',
    (int)$pdo->query("SELECT COUNT(*) FROM clientes_assinaturas WHERE cliente_id='d1'")->fetchColumn() === 1
    && assinaturaDe('d1')['plano_id'] === 'pl2');
salvarAssinaturaCliente('d2', 'pl1', '+30 days', date('Y-m-d'), 'ativo');
checar('  ... e cria a de um cliente novo (UPSERT)', assinaturaDe('d2') !== null);

// ---------------------------------------------------------------------------
echo "\n=== 10. Fusão de duplicatas antigas (migração) ===\n";
limpar();

// Bancos criados pelo install.php declaram cliente_id como PRIMARY KEY, então
// nem dá para inserir a duplicata que esta seção simula — o cenário só existe
// em bancos antigos, montados por garantirEstruturaAssinaturas() sem chave.
$temChavePrimaria = false;
foreach ($pdo->query("PRAGMA table_info(clientes_assinaturas)") as $coluna) {
    if (strcasecmp($coluna['name'], 'cliente_id') === 0 && (int)$coluna['pk'] === 1) {
        $temChavePrimaria = true;
    }
}

if ($temChavePrimaria) {
    checar('banco novo já nasce com cliente_id como PRIMARY KEY (duplicata impossível)', true);
    echo "  (fusão de duplicatas não se aplica a este banco — cenário exclusivo de bases antigas)\n";
} else {
    $pdo->exec("DROP INDEX IF EXISTS idx_uq_assinatura_cliente");
    $ins = $pdo->prepare("INSERT INTO clientes_assinaturas (cliente_id,plano_id,data_inicio,data_fim,status,gateway,gateway_subscription_id) VALUES (?,?,?,?,?,?,?)");
    $ins->execute(['A', 'pl_velho', dias(-70), dias(-40), 'expirado', 'manual', '']);
    $ins->execute(['A', 'pl_novo',  dias(-5),  dias(25), 'ativo',    'stripe', 'sub_A']);
    $ins->execute(['B', 'pl_curto', dias(-5),  dias(10), 'ativo',    'manual', '']);
    $ins->execute(['B', 'pl_longo', dias(-5),  dias(40), 'ativo',    'stripe', 'sub_B']);
    $ins->execute(['C', 'pl_ok',    dias(-5),  dias(20), 'ativo',    'manual', '']);
    migracaoAssinaturaUnicaPorCliente($pdo);
    checar('mantém a assinatura vigente e descarta a expirada', (assinaturaDe('A')['plano_id'] ?? '') === 'pl_novo',
        'ficou=' . (assinaturaDe('A')['plano_id'] ?? '?'));
    checar('entre duas vigentes, mantém a de validade maior', (assinaturaDe('B')['plano_id'] ?? '') === 'pl_longo',
        'ficou=' . (assinaturaDe('B')['plano_id'] ?? '?'));
    checar('não mexe em quem já tinha linha única', (assinaturaDe('C')['plano_id'] ?? '') === 'pl_ok');
    checar('e cria o índice único no fim',
        (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_uq_assinatura_cliente'")->fetchColumn());
}

// ---------------------------------------------------------------------------
echo "\n=== 11. Receita do período (getPagamentosAssinaturaPeriodo) ===\n";
limpar();
registrarPagamentoAssinatura('in_a', 'p1', 'pl1', 'stripe', 100.00, date('Y-m-01') . ' 10:00:00', 'renovacao');
registrarPagamentoAssinatura('in_b', 'p2', 'pl1', 'stripe', 50.00, dias(-200) . ' 10:00:00', 'renovacao');
$soma = array_sum(array_column(getPagamentosAssinaturaPeriodo(date('Y-m-01'), date('Y-m-d'), [], []), 'valor'));
checar('soma só os pagamentos do período', abs($soma - 100.00) < 0.01, "soma=$soma");

// ---------------------------------------------------------------------------
echo "\n=== 12. Comissão do profissional em atendimento de assinatura ===\n";
$agAssin  = ['desconto_aplicado' => 40.0, 'tipo_desconto' => 'assinatura_vip'];
$agNormal = ['desconto_aplicado' => 0,    'tipo_desconto' => ''];
$padrao   = ['comissao' => 50, 'comissao_assinatura_tipo' => 'padrao', 'comissao_assinatura_valor' => 0];

$c = calcularComissaoAtendimento($agAssin, $padrao, 40.0);
checar('assinatura: comissão sobre a TABELA, não sobre o líquido (que é zero)',
    abs($c['comissao_servicos'] - 20.0) < 0.01, 'comissao=' . $c['comissao_servicos']);
$c = calcularComissaoAtendimento($agNormal, $padrao, 40.0);
checar('atendimento normal: comissão sobre o líquido', abs($c['comissao_servicos'] - 20.0) < 0.01);
$c = calcularComissaoAtendimento($agAssin, ['comissao' => 50, 'comissao_assinatura_tipo' => 'fixo', 'comissao_assinatura_valor' => 7.5], 40.0);
checar('regra fixa por atendimento de assinante', abs($c['comissao_servicos'] - 7.5) < 0.01);
$c = calcularComissaoAtendimento($agAssin, ['comissao' => 50, 'comissao_assinatura_tipo' => 'nenhuma', 'comissao_assinatura_valor' => 0], 40.0);
checar('regra "sem comissão" zera de fato', abs($c['comissao_servicos']) < 0.01);
$c = calcularComissaoAtendimento($agAssin, ['comissao' => 50, 'comissao_assinatura_tipo' => 'percentual', 'comissao_assinatura_valor' => 250], 40.0);
checar('percentual absurdo é limitado a 100%', abs($c['comissao_servicos'] - 40.0) < 0.01, 'comissao=' . $c['comissao_servicos']);

// ---------------------------------------------------------------------------
echo "\n=== 13. MRR não conta quem já pediu cancelamento ===\n";
limpar();
salvarAssinaturaCliente('m1', 'plA', '+30 days', dias(-5), 'ativo');
salvarAssinaturaCliente('m2', 'plA', '+30 days', dias(-5), 'cancelamento_agendado');
// Espelha o cálculo de admin_data.php.
$valorPlano = 100.0;
$hoje = date('Y-m-d');
$mrr = 0.0;
$ativos = 0;
foreach (getDB()->query("SELECT * FROM clientes_assinaturas") as $a) {
    $estaAtiva = in_array($a['status'], ['ativo', 'cancelamento_agendado'], true) && $a['data_fim'] >= $hoje;
    if (!$estaAtiva) continue;
    $ativos++;
    if ($a['status'] === 'ativo') { $mrr += $valorPlano; }
}
checar('os dois contam como assinantes ativos (têm benefício)', $ativos === 2, "ativos=$ativos");
checar('mas só um entra no MRR', abs($mrr - 100.0) < 0.01, "mrr=$mrr");

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 62) . "\n";
echo "PASSOU: $ok    FALHOU: " . count($falhas) . "\n";
foreach ($falhas as $f) {
    echo "  X  $f\n";
}
echo str_repeat('=', 62) . "\n";

exit(count($falhas) > 0 ? 1 : 0);
