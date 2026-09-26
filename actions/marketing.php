<?php
// --- AÇÃO: ENVIAR NEWSLETTER ---
if ($action === 'enviar_newsletter') {
    $destinatarios_tipo = $_POST['destinatarios'] ?? 'ativos';
    $assunto = trim($_POST['assunto']);
    
    // Puxar configurações para a tag {nome_barbearia}
    $configGeral = function_exists('carregarConfigGeral') ? carregarConfigGeral() : [];
    $nome_barbearia = $configGeral['nome_barbearia'] ?? 'Nossa Barbearia';

    $corpo_email_template = trim($_POST['corpo_email']); 
    
    // Inteligência para manter quebras de linha caso o admin escreva o texto manualmente (sem IA)
    if (strpos($corpo_email_template, '<br') === false && strpos($corpo_email_template, '<p>') === false) {
        $corpo_email_template = nl2br($corpo_email_template);
    }

    // CORREÇÃO: Substituir a tag {nome_barbearia} no template
    $corpo_email_template = str_replace('{nome_barbearia}', htmlspecialchars($nome_barbearia), $corpo_email_template);

    if (empty($assunto) || empty($corpo_email_template)) {
        header('Location: admin.php?tab=marketing&error=' . urlencode('Assunto e corpo do e-mail são obrigatórios.'));
        exit;
    }
    
    $pdo = getDB();
    if ($destinatarios_tipo === 'todos') {
        $stmt = $pdo->query("SELECT * FROM clientes");
    } else {
        $stmt = $pdo->query("SELECT * FROM clientes WHERE status = 'ativo' OR status IS NULL OR status = ''");
    }
    $clientes_para_enviar = $stmt->fetchAll();
    
    $total_enviados = 0;
    foreach ($clientes_para_enviar as $cliente) {
        if (!filter_var($cliente['email'], FILTER_VALIDATE_EMAIL)) { continue; }
        
        // Substitui a tag do nome do cliente de forma individual
        $corpo_email_personalizado = str_replace('{nome_cliente}', htmlspecialchars($cliente['nome']), $corpo_email_template);
        
        $dados_email = [
            'nome_cliente' => $cliente['nome'], 
            'assunto_email' => $assunto, 
            'corpo_email' => $corpo_email_personalizado
        ];
        
        if (enviarEmail($cliente['email'], $assunto, 'newsletter_generica', $dados_email)) { 
            $total_enviados++; 
        }
    }
    header('Location: admin.php?tab=marketing&success=' . urlencode("Campanha enviada com sucesso para $total_enviados cliente(s)."));
    exit;
}

// --- AÇÃO: GERAR TEXTO COM IA ---
if ($action === 'gerar_texto_ia') {
    header('Content-Type: application/json');

    $topico = trim($_POST['topico'] ?? '');
    $tom = trim($_POST['tom'] ?? '');

    if (empty($topico)) {
        echo json_encode(['success' => false, 'error' => 'O tópico é obrigatório.']);
        exit;
    }

    if (!function_exists('chamarIAComFallback') || !function_exists('iaTemChaveConfigurada') || !iaTemChaveConfigurada()) {
        echo json_encode(['success' => false, 'error' => 'Nenhuma chave da Groq foi configurada no painel de Inteligência Artificial.']);
        exit;
    }

    $prompt = "Aja como um especialista em marketing. Escreva um e-mail para barbearia sobre: '$topico'. Tom: '$tom'. Use APENAS tags HTML básicas como <br>, <strong>, <p>. NÃO use ```html ou qualquer tipo de markdown, apenas o texto com as tags inseridas.";

    $resultadoIA = chamarIAComFallback($prompt);
    if (empty($resultadoIA['success'])) {
        echo json_encode(['success' => false, 'error' => $resultadoIA['error'] ?? 'A IA (Groq) está indisponível no momento.']);
        exit;
    }

    // Limpeza rigorosa para garantir que não venha nada além de HTML puro
    $texto_gerado = str_ireplace(['```html', '```'], '', $resultadoIA['resposta']);
    $texto_gerado = trim($texto_gerado);

    echo json_encode(['success' => true, 'texto' => $texto_gerado]);
    exit;
}

// --- AÇÃO: REATIVAÇÃO DE CLIENTES ---
if ($action === 'enviar_reativacao') {
    $cupom_id = $_POST['cupom_id'] ?? null;
    $pdo = getDB();
    $stmtCupom = $pdo->prepare("SELECT * FROM cupoes WHERE id = ?");
    $stmtCupom->execute([$cupom_id]);
    $cupom_selecionado = $stmtCupom->fetch();
    
    if (empty($cupom_id) || !$cupom_selecionado) {
        header('Location: admin.php?tab=marketing&error=' . urlencode('Cupom inválido.'));
        exit;
    }

    $dias_inatividade = max(1, (int)($_POST['dias_inatividade'] ?? 90));

    // CORREÇÃO: nomes de tabela corretos (o antigo usava '.txt' e lia tabela inexistente).
    $keys_ag = $keys_agendamentos_completo ?? ['id', 'nome', 'email', 'telefone', 'barbeiro_id', 'servicos_ids', 'data', 'hora', 'status', 'desconto_aplicado', 'tipo_desconto', 'observacoes', 'produtos_vendidos', 'plano_provisorio', 'cliente_id'];
    $agendamentosArr = lerDados('agendamentos', $keys_ag);
    $clientesArr = lerDados('clientes', ['id', 'nome', 'email', 'telefone', 'status']);

    $clientes_em_risco = getClientesEmRisco($agendamentosArr, $clientesArr, $dias_inatividade);

    $total_enviados = 0;
    foreach ($clientes_em_risco as $cliente) {
        if (!filter_var($cliente['email'], FILTER_VALIDATE_EMAIL)) { continue; }
        $dados_email = [
            'nome_cliente' => $cliente['nome'], 
            'cupom_codigo' => $cupom_selecionado['codigo'], 
            'cupom_desconto' => $cupom_selecionado['desconto_percentual']
        ];
        if (enviarEmail($cliente['email'], 'Sentimos sua falta!', 'reativacao_cliente', $dados_email)) { 
            $total_enviados++; 
        }
    }
    header('Location: admin.php?tab=marketing&success=' . urlencode("Enviado para $total_enviados clientes."));
    exit;
}

// --- AÇÃO: VOUCHERS ---
if ($action === 'gerar_voucher') {
    $valor = (float) str_replace(',', '.', $_POST['valor_voucher']);
    $validade = $_POST['data_validade_voucher'] ?? '';
    $comprador = trim($_POST['comprador'] ?? '');
    if ($valor > 0) {
        $id_novo = gerarId('vch-');
        $codigo = strtoupper('PRESENTE-' . substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS vouchers (id TEXT PRIMARY KEY, codigo TEXT, valor TEXT, status TEXT, data_criacao TEXT, agendamento_id_uso TEXT, data_validade TEXT, comprador TEXT DEFAULT '')");
        garantirColunasMarketing();
        $stmt = $pdo->prepare("INSERT INTO vouchers (id, codigo, valor, status, data_criacao, agendamento_id_uso, data_validade, comprador) VALUES (?, ?, ?, 'disponivel', ?, '', ?, ?)");
        $stmt->execute([$id_novo, $codigo, number_format($valor, 2, '.', ''), date('Y-m-d H:i:s'), $validade, $comprador]);
        header('Location: admin.php?tab=marketing&success=' . urlencode('Voucher gerado!'));
    } else {
        header('Location: admin.php?tab=marketing&error=' . urlencode('Informe um valor válido para o voucher.'));
    }
    exit;
}

if ($action === 'excluir_voucher' && !empty($id)) { 
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM vouchers WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: admin.php?tab=marketing&success=' . urlencode('Voucher excluído.')); 
    exit; 
}

if ($action === 'salvar_voucher_editado') { 
    $voucher_id = $_POST['voucher_id']; 
    $data_validade = $_POST['data_validade']; 
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE vouchers SET data_validade = ? WHERE id = ?");
    $stmt->execute([$data_validade, $voucher_id]);
    header('Location: admin.php?tab=marketing&success=' . urlencode('Validade alterada!')); 
    exit; 
}

// --- AÇÕES: FIDELIDADE E CUPONS ---
if ($action === 'salvar_config_fidelidade') {
    $modo  = ($_POST['modo_ganho'] ?? 'visita') === 'valor' ? 'valor' : 'visita';
    $tipo  = in_array($_POST['tipo_recompensa'] ?? 'percentual', ['percentual', 'valor_fixo', 'servico_gratis'], true)
        ? $_POST['tipo_recompensa'] : 'percentual';
    $base  = in_array($_POST['base_desconto'] ?? 'mais_barato', ['mais_barato', 'mais_caro', 'total'], true)
        ? $_POST['base_desconto'] : 'mais_barato';
    $novaConfig = [
        'ativado' => $_POST['fidelidade_ativado'] ?? 0,
        'pontos_necessarios' => max(1, (int) $_POST['pontos_necessarios']),
        'desconto_percentual' => max(0, (int) $_POST['desconto_percentual']),
        'modo_ganho' => $modo,
        'pontos_por_visita' => max(1, (int) ($_POST['pontos_por_visita'] ?? 1)),
        'real_por_ponto' => max(0, (float) str_replace(',', '.', $_POST['real_por_ponto'] ?? 0)),
        'tipo_recompensa' => $tipo,
        'base_desconto' => $base,
        'valor_desconto_fixo' => max(0, (float) str_replace(',', '.', $_POST['valor_desconto_fixo'] ?? 0)),
    ];
    _salvarConfigSQLite('fidelidade_config', $novaConfig);
    header('Location: admin.php?tab=fidelidade&success=' . urlencode('Fidelidade salva!'));
    exit;
}

if ($action === 'ajustar_pontos') {
    $cid = $_POST['cliente_id'] ?? '';
    if ($cid !== '') {
        $atual = getClientFidelityPoints($cid);
        // Suporta ajuste rápido (+/- via 'delta') ou definição do saldo ('novos_pontos').
        if (isset($_POST['delta']) && $_POST['delta'] !== '') {
            $novo = max(0, $atual + (int) $_POST['delta']);
        } else {
            $novo = max(0, (int) $_POST['novos_pontos']);
        }
        $admin = $_SESSION['username'] ?? 'admin';
        // Descrição registra no extrato do cliente (antes o ajuste ficava invisível).
        updateClientFidelityPoints($cid, $novo, "Ajuste manual pelo admin ($admin)");
    }
    header('Location: admin.php?tab=fidelidade&success=' . urlencode('Pontos ajustados!'));
    exit;
}

if ($action === 'exportar_fidelidade') {
    $pdo = getDB();
    $pontos = getAllFidelityPoints();
    $clientes = [];
    foreach ($pdo->query("SELECT id, nome, email, telefone FROM clientes") as $c) { $clientes[$c['id']] = $c; }
    // Ordena por pontos (desc)
    arsort($pontos);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fidelidade_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM p/ acentos no Excel
    fputcsv($out, ['Cliente', 'Email', 'Telefone', 'Pontos']);
    foreach ($pontos as $id => $p) {
        $cli = $clientes[$id] ?? null;
        if (!$cli) { continue; }
        fputcsv($out, [$cli['nome'] ?? '', $cli['email'] ?? '', $cli['telefone'] ?? '', (int) $p]);
    }
    fclose($out);
    exit;
}

if ($action === 'salvar_cupom') {
    $id = $_POST['id'] ?: gerarId('cup-');
    $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
    $tipo = (($_POST['tipo_desconto'] ?? 'percentual') === 'fixo') ? 'fixo' : 'percentual';
    $desc_perc = (int)($_POST['desconto_percentual'] ?? 0);
    $valor_desc = (float) str_replace(',', '.', $_POST['valor_desconto'] ?? '0');
    $ativo = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;

    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS cupoes (id TEXT PRIMARY KEY, codigo TEXT, desconto_percentual INTEGER, usos_maximos INTEGER, data_validade TEXT, usos_atuais INTEGER, tipo_desconto TEXT DEFAULT 'percentual', valor_desconto REAL DEFAULT 0, ativo INTEGER DEFAULT 1)");
    garantirColunasMarketing();

    // Validações
    if ($codigo === '') {
        header('Location: admin.php?tab=marketing&error=' . urlencode('Informe o código do cupom.')); exit;
    }
    $dup = $pdo->prepare("SELECT id FROM cupoes WHERE codigo = ? AND id <> ? LIMIT 1");
    $dup->execute([$codigo, $id]);
    if ($dup->fetch()) {
        header('Location: admin.php?tab=marketing&error=' . urlencode('Já existe um cupom com este código.')); exit;
    }
    if ($tipo === 'fixo' && $valor_desc <= 0) {
        header('Location: admin.php?tab=marketing&error=' . urlencode('Informe o valor (R$) do desconto fixo.')); exit;
    }
    if ($tipo === 'percentual' && ($desc_perc <= 0 || $desc_perc > 100)) {
        header('Location: admin.php?tab=marketing&error=' . urlencode('Percentual de desconto inválido (1 a 100).')); exit;
    }

    $stmt = $pdo->prepare("INSERT OR REPLACE INTO cupoes (id, codigo, desconto_percentual, usos_maximos, data_validade, usos_atuais, tipo_desconto, valor_desconto, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$id, $codigo, $desc_perc, (int)($_POST['usos_maximos'] ?? 0), $_POST['data_validade'] ?? '', (int)($_POST['usos_atuais'] ?? 0), $tipo, $valor_desc, $ativo]);
    header('Location: admin.php?tab=marketing&success=' . urlencode('Cupom salvo!'));
    exit;
}

if ($action === 'toggle_cupom' && !empty($id)) {
    garantirColunasMarketing();
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE cupoes SET ativo = CASE WHEN ativo = 1 THEN 0 ELSE 1 END WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: admin.php?tab=marketing&success=' . urlencode('Status do cupom atualizado.'));
    exit;
}

if ($action === 'excluir_cupom' && !empty($id)) {
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM cupoes WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: admin.php?tab=marketing&success=' . urlencode('Cupom excluído.')); 
    exit; 
}

if ($action === 'salvar_config_aniversario') {
    $novaConfig = [
        'ativado' => $_POST['aniversario_ativado'] ?? 0,
        'desconto_percentual' => (int) $_POST['desconto_percentual']
    ];
    _salvarConfigSQLite('config_aniversario', $novaConfig);
    header('Location: admin.php?tab=marketing&success=' . urlencode('Configuração salva!'));
    exit;
}

/* =====================================================================
   CAMPANHAS — ENVIO EM LOTE (AJAX/JSON)
   ===================================================================== */
$_keys_ag_camp = $keys_agendamentos_completo ?? ['id','nome','email','telefone','barbeiro_id','servicos_ids','data','hora','status','desconto_aplicado','tipo_desconto','observacoes','produtos_vendidos','plano_provisorio','cliente_id'];

function _mkt_carregar_publico($keys_ag) {
    return [
        lerDados('clientes', ['id','nome','email','telefone','status','data_nascimento']),
        lerDados('agendamentos', $keys_ag),
    ];
}

// Contagem de destinatários de um segmento (para o contador ao vivo)
if ($action === 'campanha_contar') {
    header('Content-Type: application/json');
    $segmento = $_POST['segmento'] ?? 'ativos';
    $params = ['dias' => (int)($_POST['dias'] ?? 90), 'barbeiro_id' => $_POST['barbeiro_id'] ?? ''];
    [$clientes, $ags] = _mkt_carregar_publico($_keys_ag_camp);
    echo json_encode(['success' => true, 'total' => marketingContarDestinatarios($segmento, $params, $clientes, $ags)]);
    exit;
}

// Pré-visualização do e-mail
if ($action === 'campanha_preview') {
    header('Content-Type: application/json');
    $configGeral = carregarConfigGeral();
    $corpo = trim($_POST['corpo_email'] ?? '');
    if ($corpo === '') { echo json_encode(['success' => false, 'error' => 'Escreva a mensagem primeiro.']); exit; }
    if (strpos($corpo, '<') === false) { $corpo = nl2br($corpo); }
    $corpo = marketingAplicarTags($corpo, ['nome' => 'Maria Silva'], $configGeral);
    $html = marketingRenderPreview('newsletter_generica', [
        'assunto_email' => $_POST['assunto'] ?? 'Pré-visualização',
        'corpo_email' => $corpo,
        'link_descadastro' => marketingLinkDescadastro('exemplo@email.com'),
    ]);
    echo json_encode(['success' => true, 'html' => $html]);
    exit;
}

// Enviar e-mail de teste
if ($action === 'campanha_teste') {
    header('Content-Type: application/json');
    $configGeral = carregarConfigGeral();
    $email = trim($_POST['email_teste'] ?? '');
    $corpo = trim($_POST['corpo_email'] ?? '');
    $assunto = trim($_POST['assunto'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success' => false, 'error' => 'E-mail de teste inválido.']); exit; }
    if ($assunto === '' || $corpo === '') { echo json_encode(['success' => false, 'error' => 'Preencha assunto e mensagem.']); exit; }
    if (strpos($corpo, '<') === false) { $corpo = nl2br($corpo); }
    $corpo = marketingAplicarTags($corpo, ['nome' => 'Cliente Teste'], $configGeral);
    $ok = enviarEmail($email, '[TESTE] ' . $assunto, 'newsletter_generica', [
        'nome_cliente' => 'Cliente Teste', 'assunto_email' => $assunto, 'corpo_email' => $corpo,
        'link_descadastro' => marketingLinkDescadastro($email),
    ]);
    echo json_encode(['success' => $ok, 'error' => $ok ? '' : 'Falha ao enviar. Verifique a configuração de e-mail (SMTP).']);
    exit;
}

// Iniciar campanha de newsletter (cria a campanha + destinatários)
if ($action === 'campanha_iniciar') {
    header('Content-Type: application/json');
    $assunto = trim($_POST['assunto'] ?? '');
    $corpo = trim($_POST['corpo_email'] ?? '');
    $segmento = $_POST['segmento'] ?? 'ativos';
    $params = ['dias' => (int)($_POST['dias'] ?? 90), 'barbeiro_id' => $_POST['barbeiro_id'] ?? ''];

    if ($assunto === '' || $corpo === '') { echo json_encode(['success' => false, 'error' => 'Assunto e mensagem são obrigatórios.']); exit; }
    if (strpos($corpo, '<') === false) { $corpo = nl2br($corpo); }

    [$clientes, $ags] = _mkt_carregar_publico($_keys_ag_camp);
    $dest = marketingListarDestinatarios($segmento, $params, $clientes, $ags);
    if (empty($dest)) { echo json_encode(['success' => false, 'error' => 'Nenhum destinatário válido neste segmento.']); exit; }

    $campanha_id = marketingCriarCampanha('newsletter', 'newsletter_generica', $assunto, $corpo, $segmento, $dest, [], $_SESSION['username'] ?? '');
    echo json_encode(['success' => true, 'campanha_id' => $campanha_id, 'total' => count($dest)]);
    exit;
}

// Iniciar campanha de reativação (win-back) com cupom + anti-reenvio
if ($action === 'campanha_reativacao_iniciar') {
    header('Content-Type: application/json');
    $dias = max(1, (int)($_POST['dias_inatividade'] ?? 90));
    $cupom_id = $_POST['cupom_id'] ?? '';
    $pdo = getDB();
    $stmtC = $pdo->prepare("SELECT * FROM cupoes WHERE id = ?");
    $stmtC->execute([$cupom_id]);
    $cupom = $stmtC->fetch(PDO::FETCH_ASSOC);
    if (!$cupom) { echo json_encode(['success' => false, 'error' => 'Selecione um cupom válido.']); exit; }

    [$clientes, $ags] = _mkt_carregar_publico($_keys_ag_camp);
    $dest = marketingListarDestinatarios('sem_retorno', ['dias' => $dias], $clientes, $ags);

    // Anti-reenvio: remove quem já recebeu reativação nos últimos 15 dias
    $recentes = marketingClientesContatadosRecentemente('reativacao', 15);
    $dest = array_values(array_filter($dest, fn($d) => !isset($recentes[$d['id']])));
    if (empty($dest)) { echo json_encode(['success' => false, 'error' => 'Nenhum cliente elegível (todos já contatados recentemente).']); exit; }

    $extra = ['cupom_codigo' => $cupom['codigo'], 'cupom_desconto' => $cupom['desconto_percentual']];
    $campanha_id = marketingCriarCampanha('reativacao', 'reativacao_cliente', 'Sentimos sua falta!', '', 'sem_retorno', $dest, $extra, $_SESSION['username'] ?? '');
    echo json_encode(['success' => true, 'campanha_id' => $campanha_id, 'total' => count($dest)]);
    exit;
}

// Processar o próximo lote de qualquer campanha
if ($action === 'campanha_lote') {
    header('Content-Type: application/json');
    $campanha_id = $_POST['campanha_id'] ?? '';
    if ($campanha_id === '') { echo json_encode(['success' => false, 'error' => 'Campanha inválida.']); exit; }
    @set_time_limit(120);
    $res = marketingProcessarLote($campanha_id, 15);
    if (isset($res['erro'])) { echo json_encode(['success' => false, 'error' => $res['erro']]); exit; }
    echo json_encode(array_merge(['success' => true], $res));
    exit;
}
?>