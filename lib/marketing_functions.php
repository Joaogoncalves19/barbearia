<?php
// lib/marketing_functions.php
// Contém funções relacionadas a Fidelidade, Cupons, Vouchers, Indicações e PLANOS DE ASSINATURA (Atualizado para SQLite NATIVO).

// ==========================================================================
// FUNÇÕES DE FIDELIDADE
// ==========================================================================

function getAllFidelityPoints() {
    // Atualizado: Removido .txt e alterado 'cliente_id' para 'id' para coincidir com o banco
    $dados = lerDados('fidelidade', ['id', 'pontos']);
    $pointsData = [];
    
    if (!empty($dados)) {
        foreach ($dados as $id => $item) {
            if (isset($item['pontos'])) {
                $pointsData[$id] = (int)$item['pontos'];
            }
        }
    }
    return $pointsData;
}

function getClientFidelityPoints($cliente_id) {
    $allPoints = getAllFidelityPoints();
    return $allPoints[$cliente_id] ?? 0;
}

function updateClientFidelityPoints($cliente_id, $points, $descricao = '') {
    $pdo = getDB();
    $points = (int) $points;

    // Lê só o saldo deste cliente (antes carregava a tabela inteira).
    try {
        $stmt = $pdo->prepare("SELECT pontos FROM fidelidade WHERE id = ?");
        $stmt->execute([$cliente_id]);
        $linha = $stmt->fetchColumn();
        $pontos_anteriores = ($linha === false) ? 0 : (int) $linha;
    } catch (Exception $e) {
        $pontos_anteriores = 0;
    }

    // UPSERT de uma linha (antes: DELETE da tabela inteira + reinserção de
    // todos os clientes, a cada atendimento concluído — o que também fazia
    // duas conclusões simultâneas perderem uma das atualizações).
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO fidelidade (id, pontos) VALUES (?, ?)
             ON CONFLICT(id) DO UPDATE SET pontos = excluded.pontos"
        );
        $stmt->execute([$cliente_id, $points]);
    } catch (Exception $e) {
        if (function_exists('log_activity')) {
            log_activity('FALHA ao gravar pontos de fidelidade de ' . $cliente_id . ': ' . $e->getMessage());
        }
        return;
    }

    // Registra no histórico se houve mudança e tem descrição
    if ($descricao && ($points != $pontos_anteriores)) {
        addFidelityHistory($cliente_id, $points - $pontos_anteriores, $descricao);
    }
}

function addFidelityHistory($cliente_id, $pontos, $descricao) {
    $pdo = getDB();
    $timestamp = date('Y-m-d H:i:s');
    
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fidelidade_historico (
            cliente_id TEXT,
            pontos INTEGER,
            descricao TEXT,
            timestamp TEXT
        )");
        
        $stmt = $pdo->prepare("INSERT INTO fidelidade_historico (cliente_id, pontos, descricao, timestamp) VALUES (?, ?, ?, ?)");
        $stmt->execute([$cliente_id, $pontos, $descricao, $timestamp]);
    } catch (PDOException $e) {
        if (function_exists('log_activity')) {
            log_activity("FALHA: Erro ao adicionar histórico de fidelidade para $cliente_id. Erro: " . $e->getMessage());
        }
    }
}

function getFidelityHistory($cliente_id) {
    $pdo = getDB();
    $history = [];
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM fidelidade_historico WHERE cliente_id = ? ORDER BY timestamp DESC, rowid DESC");
        $stmt->execute([$cliente_id]);
        
        while ($row = $stmt->fetch()) {
            $history[] = [
                'cliente_id' => $row['cliente_id'],
                'pontos' => (int)$row['pontos'],
                'descricao' => $row['descricao'],
                'timestamp' => $row['timestamp']
            ];
        }
    } catch (PDOException $e) {
        // Tabela pode não existir ainda
    }

    return $history;
}

/**
 * Devolve a config de fidelidade já normalizada (tipos corretos e valores
 * válidos), com retrocompatibilidade: uma barbearia que só tinha os 3 campos
 * antigos continua funcionando exatamente como antes.
 */
function getFidelidadeRegras() {
    $c = getFidelityConfig();
    $modo = ($c['modo_ganho'] ?? 'visita') === 'valor' ? 'valor' : 'visita';
    $tipoIn = $c['tipo_recompensa'] ?? 'percentual';
    $tipo = in_array($tipoIn, ['percentual', 'valor_fixo', 'servico_gratis'], true) ? $tipoIn : 'percentual';
    $baseIn = $c['base_desconto'] ?? 'mais_barato';
    $base = in_array($baseIn, ['mais_barato', 'mais_caro', 'total'], true) ? $baseIn : 'mais_barato';
    return [
        'ativado'             => (int)($c['ativado'] ?? 0),
        'pontos_necessarios'  => max(1, (int)($c['pontos_necessarios'] ?? 10)),
        'desconto_percentual' => max(0, (float)($c['desconto_percentual'] ?? 50)),
        'modo_ganho'          => $modo,
        'pontos_por_visita'   => max(1, (int)($c['pontos_por_visita'] ?? 1)),
        'real_por_ponto'      => max(0, (float)($c['real_por_ponto'] ?? 0)),
        'tipo_recompensa'     => $tipo,
        'base_desconto'       => $base,
        'valor_desconto_fixo' => max(0, (float)($c['valor_desconto_fixo'] ?? 0)),
    ];
}

/**
 * Quantos pontos um atendimento concede, conforme as regras.
 * No modo 'valor', usa o valor efetivamente pago (serviços + produtos − desconto).
 */
function fidelidadePontosGanhos($valor_pago = 0) {
    $r = getFidelidadeRegras();
    if ($r['modo_ganho'] === 'valor' && $r['real_por_ponto'] > 0) {
        return (int) floor(max(0, (float)$valor_pago) / $r['real_por_ponto']);
    }
    return $r['pontos_por_visita'];
}

/**
 * Calcula os pontos de um atendimento a partir da linha do agendamento,
 * resolvendo o valor pago quando o modo de ganho é por valor gasto.
 */
function fidelidadePontosPorAtendimento($pdo, $agendamento) {
    $r = getFidelidadeRegras();
    if ($r['modo_ganho'] !== 'valor' || $r['real_por_ponto'] <= 0) {
        return $r['pontos_por_visita'];
    }
    $valor = 0;
    if (function_exists('calcularValoresAgendamentoRelatorio')) {
        try {
            $servicos = [];
            foreach ($pdo->query("SELECT id, valor, slots FROM servicos") as $s) { $servicos[$s['id']] = $s; }
            $combos = [];
            foreach ($pdo->query("SELECT id, valor, servicos_ids FROM combos") as $co) { $combos[$co['id']] = $co; }
            $vals = calcularValoresAgendamentoRelatorio($agendamento, $servicos, $combos);
            $valor = $vals['total'] ?? 0;
        } catch (Exception $e) { $valor = 0; }
    }
    return fidelidadePontosGanhos($valor);
}

/**
 * Valor (R$) do desconto de fidelidade num resgate.
 * $valores_servicos = lista de valores dos serviços individuais do atendimento.
 * $valor_total      = valor total dos serviços (incluindo combos).
 */
function fidelidadeValorDesconto($valores_servicos, $valor_total) {
    $r = getFidelidadeRegras();
    $valores = array_values(array_filter(array_map('floatval', (array)$valores_servicos), fn($v) => $v > 0));
    $valor_total = max(0, (float)$valor_total);

    if ($r['tipo_recompensa'] === 'valor_fixo') {
        return min($valor_total, $r['valor_desconto_fixo']);
    }
    if ($r['tipo_recompensa'] === 'servico_gratis') {
        // Serviço mais caro do atendimento sai de graça.
        return !empty($valores) ? max($valores) : 0.0;
    }
    // percentual: escolhe a base sobre a qual o % incide
    if ($r['base_desconto'] === 'total') {
        $base = $valor_total;
    } elseif ($r['base_desconto'] === 'mais_caro') {
        $base = !empty($valores) ? max($valores) : 0.0;
    } else { // mais_barato (padrão histórico)
        $base = !empty($valores) ? min($valores) : 0.0;
    }
    return $base * ($r['desconto_percentual'] / 100);
}

// ==========================================================================
// FUNÇÕES DE CUPONS
// ==========================================================================

// Colunas completas dos cupons/vouchers (usadas em TODA leitura/gravação para
// que salvarDados não apague os campos novos ao reinserir a tabela).
function cupomKeys() {
    return ['id', 'codigo', 'desconto_percentual', 'usos_maximos', 'data_validade', 'usos_atuais', 'tipo_desconto', 'valor_desconto', 'ativo'];
}
function voucherKeys() {
    return ['id', 'codigo', 'valor', 'status', 'data_criacao', 'agendamento_id_uso', 'data_validade', 'comprador'];
}

/** Garante colunas novas em cupoes/vouchers (idempotente). */
function garantirColunasMarketing() {
    static $feito = false;
    if ($feito) { return; }
    $feito = true;
    try {
        $pdo = getDB();
        foreach ([
            "ALTER TABLE cupoes ADD COLUMN tipo_desconto TEXT DEFAULT 'percentual'",
            "ALTER TABLE cupoes ADD COLUMN valor_desconto REAL DEFAULT 0",
            "ALTER TABLE cupoes ADD COLUMN ativo INTEGER DEFAULT 1",
            "ALTER TABLE vouchers ADD COLUMN comprador TEXT DEFAULT ''",
        ] as $sql) {
            try { $pdo->exec($sql); } catch (Exception $e) { /* coluna/tabela já existe */ }
        }
    } catch (Exception $e) { /* silencioso */ }
}

function getCouponByCode($codigo) {
    garantirColunasMarketing();
    $keys = cupomKeys();
    $cupoesArr = lerDados('cupoes', $keys);
    $hoje = date('Y-m-d');

    if (!empty($cupoesArr)) {
        foreach ($cupoesArr as $id => $cupom) {
            if (isset($cupom['codigo']) && $cupom['codigo'] === $codigo) {

                $validade = $cupom['data_validade'] ?? '';
                $usos_atuais = (int)($cupom['usos_atuais'] ?? 0);
                $usos_max = (int)($cupom['usos_maximos'] ?? 0);
                $ativo = ($cupom['ativo'] ?? 1);
                $tipo = ($cupom['tipo_desconto'] ?? 'percentual') ?: 'percentual';

                if ($ativo !== '' && (int)$ativo === 0) {
                    return ['error' => 'Cupom desativado.'];
                } elseif (!empty($validade) && $hoje > $validade) {
                    return ['error' => 'Cupom expirado.'];
                } elseif ($usos_atuais >= $usos_max && $usos_max > 0) {
                    return ['error' => 'Limite de usos atingido.'];
                } else {
                    return [
                        'id' => $id,
                        'codigo' => $cupom['codigo'],
                        'desconto_percentual' => $cupom['desconto_percentual'],
                        'tipo_desconto' => $tipo,
                        'valor_desconto' => (float)($cupom['valor_desconto'] ?? 0),
                        'usos_maximos' => $usos_max,
                        'data_validade' => $validade,
                        'usos_atuais' => $usos_atuais
                    ];
                }
            }
        }
    }
    return null;
}

function incrementarUsoCupom($cupom_id) {
    garantirColunasMarketing();

    // Incremento no próprio banco. Além de não reescrever a tabela inteira,
    // isto é atômico: antes, dois resgates simultâneos liam o mesmo valor e
    // gravavam o mesmo +1, perdendo uma das contagens de uso.
    try {
        $stmt = getDB()->prepare(
            "UPDATE cupoes SET usos_atuais = CAST(COALESCE(usos_atuais, 0) AS INTEGER) + 1 WHERE id = ?"
        );
        $stmt->execute([$cupom_id]);
    } catch (Exception $e) {
        if (function_exists('log_activity')) {
            log_activity('FALHA ao incrementar uso do cupom ' . $cupom_id . ': ' . $e->getMessage());
        }
    }
}

function checkIfUserUsedCoupon($cupom_id, $cliente_id) {
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM cupom_usos WHERE cupom_id = ? AND cliente_id = ? LIMIT 1");
        $stmt->execute([$cupom_id, $cliente_id]);
        return $stmt->fetch() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

function logCouponUsage($cupom_id, $cliente_id) {
    $pdo = getDB();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cupom_usos (cupom_id TEXT, cliente_id TEXT)");
        $stmt = $pdo->prepare("INSERT INTO cupom_usos (cupom_id, cliente_id) VALUES (?, ?)");
        $stmt->execute([$cupom_id, $cliente_id]);
    } catch (PDOException $e) {
        if (function_exists('log_activity')) {
            log_activity("FALHA: Erro ao registrar uso do cupom $cupom_id para $cliente_id.");
        }
    }
}

// ==========================================================================
// FUNÇÕES DE VOUCHERS
// ==========================================================================

function getVoucherByCode($codigo) {
    garantirColunasMarketing();
    $keys = voucherKeys();
    $vouchersArr = lerDados('vouchers', $keys);

    if (!empty($vouchersArr)) {
        foreach ($vouchersArr as $id => $voucher) {
            if (isset($voucher['codigo']) && $voucher['codigo'] === $codigo) {
                
                $status = $voucher['status'] ?? '';
                $validade = $voucher['data_validade'] ?? '';
                
                if ($status !== 'disponivel') {
                    return ['error' => 'Voucher inválido ou já utilizado.'];
                } elseif (!empty($validade) && date('Y-m-d') > $validade) {
                    return ['error' => 'Voucher expirado.'];
                } else {
                    return [
                        'id' => $id,
                        'codigo' => $voucher['codigo'],
                        'valor' => (float)($voucher['valor'] ?? 0),
                        'status' => $status,
                        'data_validade' => $validade
                    ];
                }
            }
        }
    }
    return null;
}

function marcarVoucherComoUtilizado($voucher_id, $agendamento_id) {
    garantirColunasMarketing();

    // Resgate ATÔMICO: a condição status = 'disponivel' é exatamente a que
    // getVoucherByCode() usa para autorizar, reavaliada aqui no momento da
    // gravação. Sem ela, dois agendamentos simultâneos com o mesmo código
    // validavam ambos como disponível e ambos ganhavam o desconto — o
    // voucher era gasto duas vezes.
    //
    // @return bool true se ESTA chamada resgatou o voucher; false se ele já
    //              estava usado, não existe, ou houve erro. O chamador deve
    //              conferir antes de conceder o desconto.
    try {
        $stmt = getDB()->prepare(
            "UPDATE vouchers SET status = 'utilizado', agendamento_id_uso = ?
              WHERE id = ? AND status = 'disponivel'"
        );
        $stmt->execute([$agendamento_id, $voucher_id]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        if (function_exists('log_activity')) {
            log_activity('FALHA ao marcar voucher ' . $voucher_id . ' como utilizado: ' . $e->getMessage());
        }
        return false;
    }
}

// ==========================================================================
// FUNÇÕES DE ASSINATURA (BARBEARIA POR ASSINATURA)
// ==========================================================================

function getPlanos() {
    return lerDados('planos', ['id', 'nome', 'valor', 'servicos_ids']);
}

function garantirEstruturaAssinaturas() {
    static $estruturaPreparada = false;
    if ($estruturaPreparada) {
        return;
    }

    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS clientes_assinaturas (
            cliente_id TEXT,
            plano_id TEXT,
            data_inicio TEXT,
            data_fim TEXT,
            status TEXT,
            gateway TEXT DEFAULT 'manual',
            gateway_subscription_id TEXT DEFAULT '',
            gateway_status TEXT DEFAULT '',
            ultimo_pagamento_id TEXT DEFAULT '',
            cancelamento_em TEXT DEFAULT '',
            gateway_customer_id TEXT DEFAULT ''
        )");
        foreach ([
            "ALTER TABLE clientes_assinaturas ADD COLUMN gateway TEXT DEFAULT 'manual'",
            "ALTER TABLE clientes_assinaturas ADD COLUMN gateway_subscription_id TEXT DEFAULT ''",
            "ALTER TABLE clientes_assinaturas ADD COLUMN gateway_status TEXT DEFAULT ''",
            "ALTER TABLE clientes_assinaturas ADD COLUMN ultimo_pagamento_id TEXT DEFAULT ''",
            "ALTER TABLE clientes_assinaturas ADD COLUMN cancelamento_em TEXT DEFAULT ''",
            "ALTER TABLE clientes_assinaturas ADD COLUMN gateway_customer_id TEXT DEFAULT ''"
        ] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Exception $e) {
            }
        }
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_assinaturas_gateway_id ON clientes_assinaturas (gateway, gateway_subscription_id)");
        // Uma assinatura por cliente: todo o código lê com LIMIT 1 e grava com
        // WHERE cliente_id = ?. Em banco recém-criado o índice nasce aqui; em
        // banco já existente quem funde as duplicatas antes é
        // migracaoAssinaturaUnicaPorCliente() (lib/migrations.php).
        try {
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_uq_assinatura_cliente ON clientes_assinaturas (cliente_id)");
        } catch (Exception $e) {
            // Já há duplicatas: a migration cuida da fusão na próxima passagem.
        }
        $estruturaPreparada = true;
    } catch (Exception $e) {
        if (function_exists('log_activity')) {
            log_activity('Erro ao preparar estrutura de assinaturas: ' . $e->getMessage());
        }
    }
}

function garantirEstruturaPagamentosAssinatura() {
    static $estruturaPreparada = false;
    if ($estruturaPreparada) {
        return;
    }

    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS assinatura_pagamentos (
        id TEXT PRIMARY KEY,
        cliente_id TEXT NOT NULL,
        plano_id TEXT NOT NULL,
        gateway TEXT DEFAULT 'manual',
        gateway_subscription_id TEXT DEFAULT '',
        valor REAL DEFAULT 0,
        moeda TEXT DEFAULT 'BRL',
        status TEXT DEFAULT 'confirmado',
        data_pagamento TEXT NOT NULL,
        tipo TEXT DEFAULT 'mensalidade',
        referencia TEXT DEFAULT '',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_assinatura_pagamentos_data ON assinatura_pagamentos (data_pagamento)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_assinatura_pagamentos_cliente ON assinatura_pagamentos (cliente_id)");
    $estruturaPreparada = true;
}

/**
 * Reserva um evento de webhook para processamento — a trava de idempotência
 * dos gateways de pagamento.
 *
 * O Stripe reentrega eventos (retentativa, "Resend" no painel, entrega
 * duplicada). Sem esta trava, reprocessar um checkout.session.completed
 * reescrevia data_inicio/data_fim para "hoje + 30 dias" e ENCURTAVA a
 * assinatura de quem já tinha renovado — o cliente perdia dias que pagou.
 *
 * @return bool true na primeira vez (siga com o processamento);
 *              false se o evento já foi processado (ignore).
 */
function reservarEventoWebhook($gateway, $eventoId, $tipo = '') {
    $eventoId = trim((string)$eventoId);
    if ($eventoId === '') {
        return true; // sem id não há como deduplicar; processa e registra no log
    }

    $pdo = getDB();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_eventos_processados (
            gateway TEXT NOT NULL,
            evento_id TEXT NOT NULL,
            tipo TEXT DEFAULT '',
            processado_em TEXT DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (gateway, evento_id)
        )");
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO webhook_eventos_processados
            (gateway, evento_id, tipo, processado_em) VALUES (?, ?, ?, ?)");
        $stmt->execute([(string)$gateway, $eventoId, (string)$tipo, date('Y-m-d H:i:s')]);
        $novo = $stmt->rowCount() > 0;

        // Poda ocasional: o histórico só serve para deduplicar entregas
        // recentes; o Stripe não reentrega eventos de meses atrás.
        if ($novo && random_int(1, 50) === 1) {
            $pdo->prepare("DELETE FROM webhook_eventos_processados WHERE processado_em < ?")
                ->execute([date('Y-m-d H:i:s', strtotime('-90 days'))]);
        }
        return $novo;
    } catch (Exception $e) {
        if (function_exists('log_activity')) {
            log_activity('Falha na trava de idempotencia do webhook: ' . $e->getMessage());
        }
        return true; // na dúvida processa: perder um pagamento é pior
    }
}

function registrarPagamentoAssinatura(
    $id,
    $clienteId,
    $planoId,
    $gateway,
    $valor,
    $dataPagamento = null,
    $tipo = 'mensalidade',
    $gatewaySubscriptionId = '',
    $referencia = ''
) {
    $id = trim((string)$id);
    $clienteId = trim((string)$clienteId);
    $planoId = trim((string)$planoId);
    $valor = max(0, (float)$valor);
    if ($id === '' || $clienteId === '' || $planoId === '' || $valor <= 0) {
        return false;
    }

    garantirEstruturaPagamentosAssinatura();
    $dataPagamento = $dataPagamento ?: date('Y-m-d H:i:s');
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO assinatura_pagamentos (
        id, cliente_id, plano_id, gateway, gateway_subscription_id, valor,
        moeda, status, data_pagamento, tipo, referencia
    ) VALUES (?, ?, ?, ?, ?, ?, 'BRL', 'confirmado', ?, ?, ?)");
    $stmt->execute([
        $id,
        $clienteId,
        $planoId,
        trim((string)$gateway) ?: 'manual',
        trim((string)$gatewaySubscriptionId),
        $valor,
        $dataPagamento,
        trim((string)$tipo) ?: 'mensalidade',
        trim((string)$referencia)
    ]);
    return true;
}

function getPagamentosAssinaturaPeriodo($dataInicio, $dataFim, $assinaturas = [], $planos = []) {
    garantirEstruturaPagamentosAssinatura();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM assinatura_pagamentos
        WHERE substr(data_pagamento, 1, 10) BETWEEN ? AND ?
          AND status = 'confirmado'
        ORDER BY data_pagamento ASC");
    $stmt->execute([$dataInicio, $dataFim]);
    $pagamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $assinaturasComPagamento = [];
    foreach ($pagamentos as &$pagamento) {
        $pagamento['valor'] = (float)($pagamento['valor'] ?? 0);
        $pagamento['estimado'] = false;
        $chave = ($pagamento['cliente_id'] ?? '') . '|' . ($pagamento['plano_id'] ?? '');
        $assinaturasComPagamento[$chave] = true;
    }
    unset($pagamento);

    // Bases antigas guardavam somente o ultimo pagamento. Quando ele pertence ao
    // periodo, o valor atual do plano e exibido como estimativa identificada.
    foreach ($assinaturas as $assinatura) {
        $status = (string)($assinatura['status'] ?? '');
        $dataPagamento = substr((string)($assinatura['data_inicio'] ?? ''), 0, 10);
        $planoId = (string)($assinatura['plano_id'] ?? '');
        $clienteId = (string)($assinatura['cliente_id'] ?? '');
        $chave = $clienteId . '|' . $planoId;
        if (!in_array($status, ['ativo', 'cancelamento_agendado'], true)
            || $dataPagamento < $dataInicio
            || $dataPagamento > $dataFim
            || isset($assinaturasComPagamento[$chave])
            || !isset($planos[$planoId])) {
            continue;
        }

        $valor = max(0, (float)($planos[$planoId]['valor'] ?? 0));
        if ($valor <= 0) {
            continue;
        }
        $pagamentos[] = [
            'id' => 'estimado-' . $clienteId . '-' . $dataPagamento,
            'cliente_id' => $clienteId,
            'plano_id' => $planoId,
            'gateway' => $assinatura['gateway'] ?? 'manual',
            'gateway_subscription_id' => $assinatura['gateway_subscription_id'] ?? '',
            'valor' => $valor,
            'status' => 'confirmado',
            'data_pagamento' => $dataPagamento,
            'tipo' => 'mensalidade',
            'referencia' => $assinatura['ultimo_pagamento_id'] ?? '',
            'estimado' => true
        ];
    }

    usort($pagamentos, fn($a, $b) => strcmp($a['data_pagamento'] ?? '', $b['data_pagamento'] ?? ''));
    return $pagamentos;
}

/**
 * Auto-expira UM registro de assinatura, se o período já terminou (com
 * tolerância para a renovação chegar). Roda no caminho de leitura, então a
 * assinatura vencida vira 'expirado' já na PRÓXIMA interação do próprio cliente
 * (conta/agendamento) — sem depender de cron nem de abrir o painel. Silencioso:
 * a notificação de expiração fica no fluxo em lote (cron/admin).
 */
function _autoExpirarAssinaturaSePreciso($assinatura, $graceDays = 1) {
    if (!$assinatura) { return $assinatura; }
    $status = (string)($assinatura['status'] ?? '');
    if (!in_array($status, ['ativo', 'cancelamento_agendado'], true)) { return $assinatura; }
    $dataFim = (string)($assinatura['data_fim'] ?? '');
    if ($dataFim === '') { return $assinatura; }

    $limite = date('Y-m-d', strtotime('-' . max(0, (int)$graceDays) . ' days'));
    if ($dataFim >= $limite) { return $assinatura; }

    $pdo = getDB();
    $upd = $pdo->prepare("UPDATE clientes_assinaturas
        SET status = 'expirado', gateway_status = 'expired'
        WHERE cliente_id = ? AND status IN ('ativo', 'cancelamento_agendado') AND data_fim < ?");
    $upd->execute([$assinatura['cliente_id'], $limite]);
    $assinatura['status'] = 'expirado';
    $assinatura['gateway_status'] = 'expired';
    return $assinatura;
}

function getAssinaturaClienteQualquerStatus($cliente_id) {
    garantirEstruturaAssinaturas();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM clientes_assinaturas WHERE cliente_id = ? LIMIT 1");
    $stmt->execute([$cliente_id]);
    $assinatura = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return _autoExpirarAssinaturaSePreciso($assinatura);
}

function getAssinaturaPorGateway($gateway, $gateway_subscription_id) {
    garantirEstruturaAssinaturas();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM clientes_assinaturas WHERE gateway = ? AND gateway_subscription_id = ? LIMIT 1");
    $stmt->execute([$gateway, $gateway_subscription_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getAssinaturaCliente($cliente_id) {
    $assinatura = getAssinaturaClienteQualquerStatus($cliente_id);
    if ($assinatura) {
        $statusComBeneficio = in_array($assinatura['status'] ?? '', ['ativo', 'cancelamento_agendado'], true);
        if ($statusComBeneficio && ($assinatura['data_fim'] ?? '') >= date('Y-m-d')) {
            return $assinatura;
        }
    }
    return null;
}

function salvarAssinaturaCliente($cliente_id, $plano_id, $duracao_str = '+1 month', $data_inicio_base = null, $status = 'ativo', $metadados = []) {
    garantirEstruturaAssinaturas();
    $data_inicio = $data_inicio_base ? $data_inicio_base : date('Y-m-d');
    $data_fim = date('Y-m-d', strtotime($data_inicio . ' ' . $duracao_str));
    $existente = getAssinaturaClienteQualquerStatus($cliente_id) ?: [];

    $gateway = array_key_exists('gateway', $metadados) ? $metadados['gateway'] : ($existente['gateway'] ?? 'manual');
    $gatewaySubscriptionId = array_key_exists('gateway_subscription_id', $metadados)
        ? $metadados['gateway_subscription_id']
        : ($existente['gateway_subscription_id'] ?? '');
    $gatewayStatus = array_key_exists('gateway_status', $metadados)
        ? $metadados['gateway_status']
        : ($existente['gateway_status'] ?? '');
    $ultimoPagamentoId = array_key_exists('ultimo_pagamento_id', $metadados)
        ? $metadados['ultimo_pagamento_id']
        : ($existente['ultimo_pagamento_id'] ?? '');
    $cancelamentoEm = array_key_exists('cancelamento_em', $metadados)
        ? $metadados['cancelamento_em']
        : ($existente['cancelamento_em'] ?? '');
    $gatewayCustomerId = array_key_exists('gateway_customer_id', $metadados)
        ? $metadados['gateway_customer_id']
        : ($existente['gateway_customer_id'] ?? '');

    $pdo = getDB();
    if ($existente) {
        $stmt = $pdo->prepare("UPDATE clientes_assinaturas SET
            plano_id = ?, data_inicio = ?, data_fim = ?, status = ?, gateway = ?,
            gateway_subscription_id = ?, gateway_status = ?, ultimo_pagamento_id = ?,
            cancelamento_em = ?, gateway_customer_id = ?
            WHERE cliente_id = ?");
        $stmt->execute([
            $plano_id, $data_inicio, $data_fim, $status, $gateway,
            $gatewaySubscriptionId, $gatewayStatus, $ultimoPagamentoId,
            $cancelamentoEm, $gatewayCustomerId, $cliente_id
        ]);
    } else {
        // UPSERT em vez de INSERT puro: o webhook do Stripe e a navegação do
        // cliente podem chegar juntos e os dois verem "não existe". Com o
        // índice único, o segundo INSERT falharia; aqui ele vira atualização.
        $stmt = $pdo->prepare("INSERT INTO clientes_assinaturas (
            cliente_id, plano_id, data_inicio, data_fim, status, gateway,
            gateway_subscription_id, gateway_status, ultimo_pagamento_id, cancelamento_em,
            gateway_customer_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(cliente_id) DO UPDATE SET
            plano_id = excluded.plano_id,
            data_inicio = excluded.data_inicio,
            data_fim = excluded.data_fim,
            status = excluded.status,
            gateway = excluded.gateway,
            gateway_subscription_id = excluded.gateway_subscription_id,
            gateway_status = excluded.gateway_status,
            ultimo_pagamento_id = excluded.ultimo_pagamento_id,
            cancelamento_em = excluded.cancelamento_em,
            gateway_customer_id = excluded.gateway_customer_id");
        $stmt->execute([
            $cliente_id, $plano_id, $data_inicio, $data_fim, $status, $gateway,
            $gatewaySubscriptionId, $gatewayStatus, $ultimoPagamentoId, $cancelamentoEm,
            $gatewayCustomerId
        ]);
    }
}

function getAssinaturaPorCustomerStripe($gateway_customer_id) {
    garantirEstruturaAssinaturas();
    if ((string)$gateway_customer_id === '') { return null; }
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM clientes_assinaturas WHERE gateway_customer_id = ? LIMIT 1");
    $stmt->execute([$gateway_customer_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Fim do período vigente de forma resiliente: o campo current_period_end migrou
 * do topo da subscription para os itens em versões recentes da API do Stripe;
 * cancel_at é o mais confiável quando há cancelamento agendado.
 */
function _fimPeriodoStripe(array $subscription, $fallback = null) {
    foreach ([
        $subscription['cancel_at'] ?? null,
        $subscription['current_period_end'] ?? null,
        $subscription['items']['data'][0]['current_period_end'] ?? null,
    ] as $ts) {
        if (!empty($ts)) { return date('Y-m-d', (int)$ts); }
    }
    return $fallback !== null ? $fallback : date('Y-m-d');
}

/**
 * Processa um evento customer.subscription.updated do Stripe sobre a assinatura
 * local já resolvida. Centraliza a decisão (encerrada / cancelamento agendado /
 * ativa-reativada), grava no banco e notifica. Retorna o novo status local.
 * Extraída do webhook para ser testável de forma determinística.
 *
 * Regras:
 *  - status Stripe canceled/unpaid/incomplete_expired => encerrada (mantém
 *    benefício até o fim se a data ainda é futura).
 *  - cancel_at_period_end=true OU cancel_at no futuro => cancelamento agendado.
 *  - caso contrário => ativa (cobre a REATIVAÇÃO: cliente desfez o cancelamento
 *    no portal, o Stripe zera cancel_at e o status volta a 'ativo').
 */
function processarSubscriptionUpdatedStripe(array $subscription, array $assinaturaLocal) {
    $clienteId        = $assinaturaLocal['cliente_id'];
    $statusLocalAtual = (string)($assinaturaLocal['status'] ?? '');
    $statusStripe     = (string)($subscription['status'] ?? '');
    $cancelAtTs       = (int)($subscription['cancel_at'] ?? 0);
    $cancelamentoAgendado = !empty($subscription['cancel_at_period_end']) || $cancelAtTs > time();
    $fimPeriodo       = _fimPeriodoStripe($subscription, $assinaturaLocal['data_fim'] ?? date('Y-m-d'));
    $agora            = date('Y-m-d H:i:s');
    $hoje             = date('Y-m-d');

    $pdo = getDB();

    if (in_array($statusStripe, ['canceled', 'unpaid', 'incomplete_expired'], true)) {
        $novoStatus = $fimPeriodo >= $hoje ? 'cancelamento_agendado' : 'cancelado';
        $pdo->prepare("UPDATE clientes_assinaturas SET
            data_fim = ?, status = ?, gateway_status = ?, cancelamento_em = ?
            WHERE cliente_id = ?")
            ->execute([$fimPeriodo, $novoStatus, $statusStripe, $agora, $clienteId]);
        if ($statusLocalAtual !== $novoStatus && function_exists('criarNotificacao')) {
            criarNotificacao($clienteId, 'Sua assinatura foi encerrada. Não haverá novas cobranças.');
        }
        $resultado = $novoStatus;
    } elseif ($cancelamentoAgendado) {
        $pdo->prepare("UPDATE clientes_assinaturas SET
            data_fim = ?, status = 'cancelamento_agendado', gateway_status = 'canceled', cancelamento_em = ?
            WHERE cliente_id = ?")
            ->execute([$fimPeriodo, $agora, $clienteId]);
        if ($statusLocalAtual !== 'cancelamento_agendado' && function_exists('criarNotificacao')) {
            criarNotificacao($clienteId, 'Cancelamento agendado. Não haverá novas cobranças e seus benefícios seguem ativos até ' . date('d/m/Y', strtotime($fimPeriodo)) . '.');
        }
        $resultado = 'cancelamento_agendado';
    } else {
        $pdo->prepare("UPDATE clientes_assinaturas SET
            data_fim = ?, status = 'ativo', gateway_status = ?, cancelamento_em = ''
            WHERE cliente_id = ?")
            ->execute([$fimPeriodo, ($statusStripe !== '' ? $statusStripe : 'active'), $clienteId]);
        if ($statusLocalAtual === 'cancelamento_agendado' && function_exists('criarNotificacao')) {
            criarNotificacao($clienteId, 'Sua assinatura foi reativada! As cobranças e os benefícios continuam normalmente.');
        }
        $resultado = 'ativo';
    }

    if (function_exists('log_activity')) {
        log_activity('Stripe: subscription ' . (string)($subscription['id'] ?? '') . " processada -> $resultado (fim $fimPeriodo).");
    }
    return $resultado;
}

/**
 * Rede de segurança: expira assinaturas cujo período já terminou e que não
 * foram renovadas. Os benefícios já são bloqueados por data em
 * getAssinaturaCliente(); esta função apenas acerta o STATUS no banco para
 * 'expirado', garantindo consistência mesmo se um webhook do Stripe se perder
 * (renovação que falhou, cartão recusado, evento não entregue, etc.).
 *
 * Usa uma tolerância ($graceDays) para não expirar no exato momento da virada
 * do período, dando tempo do invoice.paid (renovação) chegar. É idempotente:
 * uma vez expirada, a assinatura não é mais selecionada.
 */
function expirarAssinaturasVencidas($graceDays = 1) {
    garantirEstruturaAssinaturas();
    $pdo = getDB();
    $limite = date('Y-m-d', strtotime('-' . max(0, (int)$graceDays) . ' days'));

    $sel = $pdo->prepare("SELECT cliente_id FROM clientes_assinaturas
        WHERE status IN ('ativo', 'cancelamento_agendado') AND data_fim < ?");
    $sel->execute([$limite]);
    $vencidas = $sel->fetchAll(PDO::FETCH_COLUMN);
    if (!$vencidas) { return 0; }

    $upd = $pdo->prepare("UPDATE clientes_assinaturas
        SET status = 'expirado', gateway_status = 'expired'
        WHERE cliente_id = ? AND status IN ('ativo', 'cancelamento_agendado') AND data_fim < ?");
    foreach ($vencidas as $clienteId) {
        $upd->execute([$clienteId, $limite]);
        if (function_exists('criarNotificacao')) {
            criarNotificacao($clienteId, 'Sua assinatura expirou. Os benefícios foram encerrados — renove quando quiser para voltar a aproveitar.');
        }
    }
    if (function_exists('log_activity')) {
        log_activity('Assinaturas expiradas automaticamente: ' . count($vencidas) . " (data_fim < $limite).");
    }
    return count($vencidas);
}

function atualizarStatusAssinaturaGateway($cliente_id, $status, $gateway_status, $cancelamento_em = '') {
    garantirEstruturaAssinaturas();
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE clientes_assinaturas
        SET status = ?, gateway_status = ?, cancelamento_em = ?
        WHERE cliente_id = ?");
    $stmt->execute([$status, $gateway_status, $cancelamento_em, $cliente_id]);
}

// ==========================================================================
// FUNÇÕES DE INDICAÇÃO
// ==========================================================================

function processarRecompensaIndicacao($indicador_id, $nome_novo_cliente) {
    $configIndicacao = getIndicacaoConfig();
    
    if (!($configIndicacao['ativado'] ?? 0)) {
        return;
    }
    
    $pontos_indicacao = (int)($configIndicacao['pontos_indicacao'] ?? 1);
    
    if ($pontos_indicacao > 0) {
        $pontos_atuais = getClientFidelityPoints($indicador_id);
        updateClientFidelityPoints($indicador_id, $pontos_atuais + $pontos_indicacao, "Amigo indicado visitou: $nome_novo_cliente");
        
        if (function_exists('criarNotificacao')) {
            criarNotificacao($indicador_id, "Parabéns! Seu amigo $nome_novo_cliente completou a primeira visita. Você ganhou $pontos_indicacao ponto(s) de fidelidade!");
        }
    }
}

// ==========================================================================
// MOTOR DE CAMPANHAS / SEGMENTAÇÃO / OPT-OUT (LGPD)
// ==========================================================================

if (!defined('MKT_OPTOUT_SECRET')) {
    // Troque por uma frase secreta própria (protege os links de descadastro).
    define('MKT_OPTOUT_SECRET', 'mkt-optout::mude-esta-frase::7Kd2Pq');
}

/** Cria as tabelas de campanhas e opt-out (idempotente). */
function marketingGarantirTabelas() {
    static $feito = false;
    if ($feito) { return; }
    $feito = true;
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS campanhas (
        id TEXT PRIMARY KEY,
        tipo TEXT, template TEXT, assunto TEXT, corpo TEXT, segmento TEXT,
        extra_json TEXT DEFAULT '', total INTEGER DEFAULT 0, enviados INTEGER DEFAULT 0,
        falhas INTEGER DEFAULT 0, status TEXT DEFAULT 'preparando',
        criada_em TEXT, concluida_em TEXT DEFAULT '', criada_por TEXT DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS campanha_destinatarios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campanha_id TEXT, cliente_id TEXT, nome TEXT, email TEXT, ref TEXT DEFAULT '',
        status TEXT DEFAULT 'pendente', enviado_em TEXT DEFAULT ''
    )");
    try { $pdo->exec("ALTER TABLE campanha_destinatarios ADD COLUMN ref TEXT DEFAULT ''"); } catch (Exception $e) {}
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_camp_dest ON campanha_destinatarios (campanha_id, status)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_optout (email TEXT PRIMARY KEY, criado_em TEXT)");
    // cliente_id torna o opt-out imune a troca de e-mail: o vinculo passa a
    // ser o ID imutavel do cliente. O e-mail continua sendo chave porque o
    // opt-out tambem vale para quem nao tem conta (link enviado na campanha).
    try { $pdo->exec("ALTER TABLE email_optout ADD COLUMN cliente_id TEXT DEFAULT ''"); } catch (Exception $e) {}
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_optout_cliente ON email_optout (cliente_id)");
}

/* ---------- Link direto de avaliação (por token, sem login) ---------- */
if (!defined('AVALIACAO_SECRET')) {
    define('AVALIACAO_SECRET', 'aval::mude-esta-frase-secreta::v3Rp8Zk');
}
function avaliacaoToken($agendamento_id) {
    return substr(hash_hmac('sha256', (string)$agendamento_id, AVALIACAO_SECRET), 0, 20);
}
function avaliacaoLink($agendamento_id) {
    return BASE_URL . 'avaliar.php?a=' . urlencode($agendamento_id) . '&t=' . avaliacaoToken($agendamento_id);
}

/* ---------- Opt-out (descadastro) ---------- */
function marketingTokenDescadastro($email) {
    return substr(hash_hmac('sha256', strtolower(trim($email)), MKT_OPTOUT_SECRET), 0, 20);
}
function marketingLinkDescadastro($email) {
    $e = strtolower(trim($email));
    return BASE_URL . 'descadastrar.php?e=' . urlencode($e) . '&t=' . marketingTokenDescadastro($e);
}
/**
 * Verifica opt-out por ID do cliente OU por e-mail.
 *
 * O ID vem primeiro porque e imutavel: se o cliente pediu descadastro e
 * depois trocou de e-mail, o pedido continua valendo. O e-mail permanece
 * como chave para quem nao tem cadastro.
 */
function marketingEstaOptout($email, $cliente_id = '') {
    marketingGarantirTabelas();
    $email = strtolower(trim((string) $email));
    $cliente_id = trim((string) $cliente_id);
    try {
        $stmt = getDB()->prepare(
            "SELECT 1 FROM email_optout
              WHERE (:email <> '' AND email = :email)
                 OR (:cid <> '' AND cliente_id = :cid)
              LIMIT 1"
        );
        $stmt->execute([':email' => $email, ':cid' => $cliente_id]);
        return (bool) $stmt->fetchColumn();
    } catch (Exception $e) {
        // Na duvida NAO envia: opt-out e obrigacao legal (LGPD).
        return true;
    }
}
/**
 * Registra o opt-out gravando e-mail E ID do cliente.
 *
 * Quando o ID nao e informado, tenta resolve-lo pelo e-mail, para que o
 * descadastro feito pelo link da campanha tambem fique amarrado ao cadastro.
 */
function marketingRegistrarOptout($email, $cliente_id = '') {
    marketingGarantirTabelas();
    $email = strtolower(trim((string) $email));
    $cliente_id = trim((string) $cliente_id);

    if ($cliente_id === '' && $email !== '' && function_exists('getClientePorEmailTelefoneOuCPF')) {
        try {
            $c = getClientePorEmailTelefoneOuCPF($email);
            if ($c && !empty($c['id'])) { $cliente_id = $c['id']; }
        } catch (Exception $e) { /* segue so com o e-mail */ }
    }

    try {
        getDB()->prepare("INSERT OR IGNORE INTO email_optout (email, criado_em, cliente_id) VALUES (?, ?, ?)")
            ->execute([$email, date('Y-m-d H:i:s'), $cliente_id]);
        // Linha antiga (gravada antes da coluna existir) recebe o vinculo agora.
        if ($cliente_id !== '') {
            getDB()->prepare("UPDATE email_optout SET cliente_id = ? WHERE email = ? AND (cliente_id IS NULL OR cliente_id = '')")
                ->execute([$cliente_id, $email]);
        }
    } catch (Exception $e) {
        if (function_exists('log_activity')) {
            log_activity('FALHA ao registrar opt-out de ' . $email . ': ' . $e->getMessage());
        }
    }
}

/* ---------- Tags de personalização ---------- */
function marketingAplicarTags($texto, array $cliente, array $configGeral) {
    $nome = $cliente['nome'] ?? 'Cliente';
    $primeiro = trim(explode(' ', trim($nome))[0]);
    $mapa = [
        '{nome_cliente}'   => htmlspecialchars($nome),
        '{primeiro_nome}'  => htmlspecialchars($primeiro !== '' ? $primeiro : $nome),
        '{nome_barbearia}' => htmlspecialchars($configGeral['nome_barbearia'] ?? 'Barbearia'),
        '{link_agendamento}' => BASE_URL . 'agendamento',
    ];
    return strtr($texto, $mapa);
}

/* ---------- Segmentação ---------- */
/**
 * Lista destinatários de um segmento.
 * @return array lista de ['id','nome','email','telefone']
 */
function marketingListarDestinatarios($segmento, array $params, array $clientes, array $agendamentos) {
    $resultado = [];
    $mesAtual = date('m');

    // Índice de agendamentos por cliente (por email/telefone)
    foreach ($clientes as $id => $c) {
        $email = trim($c['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { continue; }
        if (marketingEstaOptout($email, $id)) { continue; }

        $status = $c['status'] ?? '';
        $incluir = false;

        switch ($segmento) {
            case 'todos':
                $incluir = true;
                break;
            case 'ativos':
                $incluir = ($status === 'ativo' || $status === '' || $status === null);
                break;
            case 'aniversariantes':
                $nasc = $c['data_nascimento'] ?? '';
                $incluir = (strlen($nasc) >= 7 && substr($nasc, 5, 2) === $mesAtual);
                break;
            case 'assinantes':
                $incluir = function_exists('getAssinaturaCliente') && (getAssinaturaCliente($id) !== null);
                break;
            case 'novos':
                // Nunca fez agendamento
                $temAg = false;
                foreach ($agendamentos as $ag) {
                    if (($ag['email'] ?? '') === $email || (isset($ag['telefone'], $c['telefone']) && limparTelefone($ag['telefone']) === limparTelefone($c['telefone']))) { $temAg = true; break; }
                }
                $incluir = !$temAg;
                break;
            case 'sem_retorno':
                // tratado abaixo em bloco separado (usa getClientesEmRisco)
                break;
            case 'barbeiro':
                $bid = $params['barbeiro_id'] ?? '';
                foreach ($agendamentos as $ag) {
                    $mesmoCliente = (($ag['email'] ?? '') === $email) || (isset($ag['telefone'], $c['telefone']) && limparTelefone($ag['telefone']) === limparTelefone($c['telefone']));
                    if ($mesmoCliente && ($ag['barbeiro_id'] ?? '') === $bid) { $incluir = true; break; }
                }
                break;
        }

        if ($incluir) {
            $resultado[$id] = ['id' => $id, 'nome' => $c['nome'] ?? 'Cliente', 'email' => $email, 'telefone' => $c['telefone'] ?? ''];
        }
    }

    // Segmento "sem retorno" reutiliza a lógica de win-back
    if ($segmento === 'sem_retorno') {
        $dias = max(1, (int)($params['dias'] ?? 90));
        $emRisco = function_exists('getClientesEmRisco') ? getClientesEmRisco($agendamentos, $clientes, $dias) : [];
        foreach ($emRisco as $id => $c) {
            $email = trim($c['email'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || marketingEstaOptout($email, $id)) { continue; }
            $resultado[$id] = ['id' => $id, 'nome' => $c['nome'] ?? 'Cliente', 'email' => $email, 'telefone' => $c['telefone'] ?? ''];
        }
    }

    return array_values($resultado);
}

function marketingContarDestinatarios($segmento, array $params, array $clientes, array $agendamentos) {
    return count(marketingListarDestinatarios($segmento, $params, $clientes, $agendamentos));
}

/** Clientes que já receberam campanha do tipo informado nos últimos N dias (anti-reenvio). */
function marketingClientesContatadosRecentemente($tipo, $dias) {
    marketingGarantirTabelas();
    $limite = date('Y-m-d H:i:s', time() - ($dias * 86400));
    $stmt = getDB()->prepare("SELECT DISTINCT d.cliente_id
        FROM campanha_destinatarios d
        JOIN campanhas c ON c.id = d.campanha_id
        WHERE c.tipo = ? AND d.status = 'enviado' AND d.enviado_em >= ?");
    $stmt->execute([$tipo, $limite]);
    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) { $ids[$cid] = true; }
    return $ids;
}

/* ---------- Criação e processamento de campanhas ---------- */
function marketingCriarCampanha($tipo, $template, $assunto, $corpo, $segmento, array $destinatarios, array $extra = [], $criadaPor = '') {
    marketingGarantirTabelas();
    $pdo = getDB();
    $id = 'CMP-' . strtoupper(bin2hex(random_bytes(4)));
    $pdo->prepare("INSERT INTO campanhas (id, tipo, template, assunto, corpo, segmento, extra_json, total, enviados, falhas, status, criada_em, criada_por)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 'enviando', ?, ?)")
        ->execute([$id, $tipo, $template, $assunto, $corpo, $segmento, json_encode($extra, JSON_UNESCAPED_UNICODE), count($destinatarios), date('Y-m-d H:i:s'), $criadaPor]);

    $stmt = $pdo->prepare("INSERT INTO campanha_destinatarios (campanha_id, cliente_id, nome, email, ref) VALUES (?, ?, ?, ?, ?)");
    foreach ($destinatarios as $d) {
        $stmt->execute([$id, $d['id'] ?? '', $d['nome'] ?? '', $d['email'] ?? '', $d['ref'] ?? '']);
    }
    return $id;
}

/**
 * Envia o próximo lote de uma campanha.
 * @return array ['enviados','falhas','total','restantes','done']
 */
function marketingProcessarLote($campanha_id, $limite = 15) {
    marketingGarantirTabelas();
    $pdo = getDB();
    $cmp = $pdo->prepare("SELECT * FROM campanhas WHERE id = ?");
    $cmp->execute([$campanha_id]);
    $campanha = $cmp->fetch(PDO::FETCH_ASSOC);
    if (!$campanha) { return ['erro' => 'Campanha não encontrada']; }

    $configGeral = carregarConfigGeral();
    $extra = json_decode($campanha['extra_json'] ?: '[]', true) ?: [];
    $template = $campanha['template'] ?: 'newsletter_generica';

    $stmt = $pdo->prepare("SELECT * FROM campanha_destinatarios WHERE campanha_id = ? AND status = 'pendente' ORDER BY id ASC LIMIT ?");
    $stmt->bindValue(1, $campanha_id);
    $stmt->bindValue(2, (int)$limite, PDO::PARAM_INT);
    $stmt->execute();
    $lote = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $up = $pdo->prepare("UPDATE campanha_destinatarios SET status = ?, enviado_em = ? WHERE id = ?");

    foreach ($lote as $dest) {
        $cliente = ['nome' => $dest['nome'], 'email' => $dest['email']];
        $link_descadastro = marketingLinkDescadastro($dest['email']);

        if ($template === 'reativacao_cliente') {
            $dados = [
                'nome_cliente'   => $dest['nome'],
                'cupom_codigo'   => $extra['cupom_codigo'] ?? '',
                'cupom_desconto' => $extra['cupom_desconto'] ?? '',
                'link_descadastro' => $link_descadastro,
            ];
            $assunto = $campanha['assunto'] ?: 'Sentimos sua falta!';
        } elseif ($template === 'lembrete_avaliacao') {
            $ag_id = $dest['ref'] ?? '';
            $ag = null; $barbeiroNome = 'nosso time';
            try {
                $stmtAg = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ?");
                $stmtAg->execute([$ag_id]);
                $ag = $stmtAg->fetch(PDO::FETCH_ASSOC);
                if ($ag) {
                    $stmtB = $pdo->prepare("SELECT nome FROM barbeiros WHERE id = ?");
                    $stmtB->execute([$ag['barbeiro_id'] ?? '']);
                    $barbeiroNome = $stmtB->fetchColumn() ?: 'nosso time';
                }
            } catch (Exception $e) {}
            $dados = [
                'nome_cliente'    => $dest['nome'],
                'data_agendamento'=> $ag ? date('d/m/Y', strtotime($ag['data'])) : '',
                'barbeiro'        => $barbeiroNome,
                'link_avaliacao'  => $ag_id ? avaliacaoLink($ag_id) : (BASE_URL . 'cliente.php'),
            ];
            $assunto = $campanha['assunto'] ?: 'Como foi sua experiência? Deixe sua avaliação!';
        } else {
            $corpoFinal = marketingAplicarTags($campanha['corpo'] ?? '', $cliente, $configGeral);
            $dados = [
                'nome_cliente'  => $dest['nome'],
                'assunto_email' => $campanha['assunto'],
                'corpo_email'   => $corpoFinal,
                'link_descadastro' => $link_descadastro,
            ];
            $assunto = $campanha['assunto'];
        }

        $ok = enviarEmail($dest['email'], $assunto, $template, $dados);
        $up->execute([$ok ? 'enviado' : 'falha', date('Y-m-d H:i:s'), $dest['id']]);
    }

    // Recalcula contadores
    $tot = $pdo->prepare("SELECT
            SUM(status='enviado') AS enviados,
            SUM(status='falha') AS falhas,
            SUM(status='pendente') AS pendentes
        FROM campanha_destinatarios WHERE campanha_id = ?");
    $tot->execute([$campanha_id]);
    $c = $tot->fetch(PDO::FETCH_ASSOC);
    $enviados = (int)$c['enviados']; $falhas = (int)$c['falhas']; $pendentes = (int)$c['pendentes'];
    $done = $pendentes === 0;

    $pdo->prepare("UPDATE campanhas SET enviados = ?, falhas = ?, status = ?, concluida_em = ? WHERE id = ?")
        ->execute([$enviados, $falhas, $done ? 'concluida' : 'enviando', $done ? date('Y-m-d H:i:s') : '', $campanha_id]);

    return ['enviados' => $enviados, 'falhas' => $falhas, 'total' => (int)$campanha['total'], 'restantes' => $pendentes, 'done' => $done];
}

/** Renderiza um template de e-mail para PRÉ-VISUALIZAÇÃO (sem enviar). */
function marketingRenderPreview($template, array $dados) {
    $configGeral = carregarConfigGeral();
    $logo_url = '';
    ob_start();
    include __DIR__ . '/../email_templates/' . preg_replace('/[^a-z0-9_]/i', '', $template) . '.php';
    return ob_get_clean();
}

/** Lista as campanhas mais recentes. */
function marketingCampanhasRecentes($limite = 8) {
    marketingGarantirTabelas();
    $stmt = getDB()->prepare("SELECT * FROM campanhas ORDER BY criada_em DESC LIMIT ?");
    $stmt->bindValue(1, (int)$limite, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
