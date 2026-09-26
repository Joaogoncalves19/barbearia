<?php
// imprimir_comprovativo_cliente.php
require_once 'functions.php';
iniciarSessaoSegura();
date_default_timezone_set('America/Sao_Paulo');
require_once 'functions.php';

// Verifica quem está a aceder (Cliente, Barbeiro ou Admin)
$is_cliente = isset($_SESSION['cliente_logado']) && $_SESSION['cliente_logado'];
$is_barbeiro = isset($_SESSION['barbeiro_loggedin']) && $_SESSION['barbeiro_loggedin'];
$is_admin = isset($_SESSION['loggedin']) && $_SESSION['loggedin'];

if (!$is_cliente && !$is_barbeiro && !$is_admin) {
    die('Acesso negado. Por favor, faça login.');
}

$agendamento_id = $_GET['id'] ?? $_GET['ag_id'] ?? 0;

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ?");
$stmt->execute([$agendamento_id]);
$agendamento = $stmt->fetch();

if (!$agendamento) {
    die('Agendamento não encontrado.');
}

// Regras de segurança (Autorização)
$autorizado = false;
if ($is_admin) {
    $autorizado = true;
} elseif ($is_barbeiro) {
    if ($agendamento['barbeiro_id'] == $_SESSION['barbeiro_id']) {
        $autorizado = true;
    }
} elseif ($is_cliente) {
    $cliente_id_sessao = $_SESSION['cliente_id'] ?? '';
    $telefone_sessao = telefoneClienteDaSessao();

    // O ID manda: quando o agendamento tem cliente_id, ele decide sozinho.
    // O telefone so entra para agendamento feito SEM cadastro, e mesmo assim
    // exigindo valor nao vazio dos dois lados.
    //
    // Antes isto era um "||", entao o telefone era testado mesmo com cliente_id
    // presente -- e dois vazios se igualavam, deixando qualquer cliente de
    // telefone vazio imprimir o recibo de outro (o id vem pela URL).
    // Os demais pontos de checagem do sistema (cliente_data, salvar_avaliacao,
    // cliente_actions) ja seguiam este formato; este era a excecao.
    if (!empty($agendamento['cliente_id'])) {
        $autorizado = ($agendamento['cliente_id'] === $cliente_id_sessao);
    } else {
        $telAgendamento = limparTelefone($agendamento['telefone'] ?? '');
        $autorizado = ($telAgendamento !== '' && $telefone_sessao !== '' && $telAgendamento === $telefone_sessao);
    }
}

if (!$autorizado) {
    die('Acesso não autorizado para visualizar este recibo.');
}

$configGeral = carregarConfigGeral();

// ----------------------------------------------------------------------
// LÓGICA DE RECEBIMENTO DO PDF VIA AJAX E DISPARO DE E-MAIL (PHPMailer)
// ----------------------------------------------------------------------
if (isset($_GET['send_pdf']) && $_GET['send_pdf'] == '1' && ($is_admin || $is_barbeiro)) {
    header('Content-Type: application/json');
    
    // Obtém o payload enviado pelo Javascript
    $dados_brutos = file_get_contents('php://input');
    $json = json_decode($dados_brutos, true);
    
    if (!$json) {
        echo json_encode(['success' => false, 'error' => 'Falha na comunicação: JSON inválido.']);
        exit;
    }

    $pdf_base64 = $json['pdf_data'] ?? '';
    $email_cliente = $agendamento['email'] ?? '';

    if (empty($email_cliente)) {
        echo json_encode(['success' => false, 'error' => 'O cliente não possui um e-mail cadastrado.']);
        exit;
    }

    if (empty($pdf_base64)) {
        echo json_encode(['success' => false, 'error' => 'Falha ao gerar o arquivo PDF na tela.']);
        exit;
    }

    // Separação blindada: retira o cabeçalho "data:application/pdf;base64," se existir
    $partes_b64 = explode(',', $pdf_base64);
    $dados_b64 = isset($partes_b64[1]) ? $partes_b64[1] : $partes_b64[0];
    
    // Converte a string base64 num arquivo binário na memória do servidor
    $pdf_decoded = base64_decode($dados_b64);

    if ($pdf_decoded === false || strlen($pdf_decoded) < 100) {
        echo json_encode(['success' => false, 'error' => 'O PDF gerado foi corrompido ou está vazio.']);
        exit;
    }
    
    // Importa as classes do PHPMailer do seu sistema
    require_once __DIR__ . '/PHPMailer/Exception.php';
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/SMTP.php';

    $configEmail = carregarConfigEmail();
    $nome_barbearia = $configGeral['nome_barbearia'] ?? 'Barbearia';

    if (empty($configEmail['username']) || empty($configEmail['password'])) {
        echo json_encode(['success' => false, 'error' => 'E-mail do remetente não configurado no painel Admin.']);
        exit;
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // Configurações SMTP
        $mail->isSMTP();
        $mail->Host       = $configEmail['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $configEmail['username'];
        $mail->Password   = $configEmail['password'];
        $mail->SMTPSecure = $configEmail['smtp_secure'];
        $mail->Port       = $configEmail['port'];
        $mail->CharSet    = 'UTF-8';

        // Remetente e Destinatário
        $mail->setFrom($configEmail['username'], $nome_barbearia);
        $mail->addAddress($email_cliente);
        $mail->addReplyTo($configEmail['username'], $nome_barbearia);

        // Corpo do E-mail
        $mail->isHTML(true);
        $mail->Subject = "Seu Comprovante PDF - " . $nome_barbearia;
        
        $mensagem = "<h2>Olá, " . htmlspecialchars($agendamento['nome']) . "!</h2>";
        $mensagem .= "<p>Obrigado por escolher a <strong>{$nome_barbearia}</strong>.</p>";
        $mensagem .= "<p>Segue em anexo o comprovante do seu serviço em formato PDF para seu controle.</p><br>";
        $mensagem .= "<p>Um abraço e até logo!</p>";
        
        $mail->Body    = $mensagem;
        $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\r\n", "\r\n\r\n"], $mensagem));

        // Anexa o arquivo PDF recém-criado na memória
        // O terceiro parâmetro 'base64' instrui o PHPMailer a preparar o pacote do e-mail corretamente
        $mail->addStringAttachment($pdf_decoded, "Comprovante_Agendamento_{$agendamento['id']}.pdf", 'base64', 'application/pdf');

        // Disparo
        $mail->send();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Erro PHPMailer: ' . $mail->ErrorInfo]);
    }
    exit;
}
// ----------------------------------------------------------------------

// Busca os nomes dos serviços, combos e planos
$servicosArr = lerDados('servicos', ['id', 'nome', 'valor']);
$combosArr = lerDados('combos', ['id', 'nome', 'valor']);
$planosArr = lerDados('planos', ['id', 'nome', 'valor']);
$barbeirosArr = lerDados('barbeiros', ['id', 'nome']);

$servicosLista = [];
$valorBrutoTotal = 0;

$ids = explode(',', $agendamento['servicos_ids']);
foreach ($ids as $id) {
    $id = trim($id);
    if (isset($servicosArr[$id])) {
        $servicosLista[] = ['nome' => $servicosArr[$id]['nome'], 'valor' => (float)$servicosArr[$id]['valor']];
        $valorBrutoTotal += (float)$servicosArr[$id]['valor'];
    } elseif (isset($combosArr[$id])) {
        $servicosLista[] = ['nome' => $combosArr[$id]['nome'] . " (Combo)", 'valor' => (float)$combosArr[$id]['valor']];
        $valorBrutoTotal += (float)$combosArr[$id]['valor'];
    }
}

// Produtos vendidos
$produtosLista = [];
if (!empty($agendamento['produtos_vendidos'])) {
    $prods = json_decode($agendamento['produtos_vendidos'], true);
    if (is_array($prods)) {
        foreach ($prods as $p) {
            $produtosLista[] = ['nome' => $p['nome'], 'valor' => (float)$p['valor']];
            $valorBrutoTotal += (float)$p['valor'];
        }
    }
}

// Lógica de Assinatura (Adesão de Plano neste Agendamento)
$is_adesao = ($agendamento['tipo_desconto'] === 'adesao_plano' && !empty($agendamento['plano_provisorio']));
$plano_nome = '';
$plano_valor = 0;

if ($is_adesao && isset($planosArr[$agendamento['plano_provisorio']])) {
    $plano_nome = $planosArr[$agendamento['plano_provisorio']]['nome'];
    $plano_valor = (float)$planosArr[$agendamento['plano_provisorio']]['valor'];
}

$desconto_db = (float)($agendamento['desconto_aplicado'] ?? 0);

if ($is_adesao) {
    $valorBrutoTotal += $plano_valor;
    $desconto_exibir = $desconto_db; 
    $valorFinal = $valorBrutoTotal - $desconto_exibir;
    $label_desconto = "Desconto (Plano Aplicado)";
} else {
    $valorFinal = $valorBrutoTotal - $desconto_db;
    $desconto_exibir = $desconto_db;
    
    $label_desconto = "Desconto Aplicado";
    if ($agendamento['tipo_desconto'] === 'assinatura_vip' || $agendamento['tipo_desconto'] === 'plano') {
        $label_desconto = "Desconto (Barbearia por assinatura)";
    } elseif ($agendamento['tipo_desconto'] === 'fidelidade') {
        $label_desconto = "Desconto (Fidelidade)";
    } elseif ($agendamento['tipo_desconto'] === 'cupom') {
        $label_desconto = "Desconto (Cupom)";
    } elseif ($agendamento['tipo_desconto'] === 'aniversario') {
        $label_desconto = "Desconto (Aniversário)";
    } elseif ($agendamento['tipo_desconto'] === 'indicacao') {
        $label_desconto = "Desconto (Indicação)";
    }
}

if ($valorFinal < 0) $valorFinal = 0;

// Configuração da Imagem da Logo
$logo_src = '';
if (!empty($configGeral['logo_path']) && file_exists($configGeral['logo_path'])) {
    $logo_src = $configGeral['logo_path'];
} elseif (file_exists('uploads/logo.png')) {
    $logo_src = 'uploads/logo.png';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprovativo - #<?= htmlspecialchars($agendamento['id']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 40px; display: flex; flex-direction: column; align-items: center; }
        .comprovativo-card { background: #fff; width: 100%; max-width: 500px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); padding: 40px; border: 1px solid #e2e8f0; position: relative;}
        .cabecalho { text-align: center; border-bottom: 2px dashed #cbd5e1; padding-bottom: 25px; margin-bottom: 25px; }
        .cabecalho h1 { margin: 0 0 5px; font-size: 1.6rem; font-weight: 800; color: #0f172a; }
        .cabecalho p { margin: 0; color: #64748b; font-size: 0.95rem; }
        .logo-recibo { max-height: 90px; width: auto; margin-bottom: 15px; object-fit: contain; display: inline-block; }
        .dados-cliente { margin-bottom: 25px; font-size: 1rem; line-height: 1.6; }
        .dados-cliente strong { color: #0f172a; }
        .tabela-itens { width: 100%; border-collapse: collapse; margin-bottom: 25px; font-size: 0.95rem; }
        .tabela-itens th, .tabela-itens td { padding: 12px 0; border-bottom: 1px solid #f1f5f9; text-align: left; }
        .tabela-itens th.valor, .tabela-itens td.valor { text-align: right; }
        .totais { font-size: 1rem; display: flex; flex-direction: column; gap: 8px; border-top: 2px solid #0f172a; padding-top: 15px; margin-bottom: 30px; }
        .linha-total { display: flex; justify-content: space-between; }
        .linha-total.desconto { color: #10b981; font-weight: 600;}
        .linha-total.final { font-size: 1.3rem; font-weight: 800; margin-top: 5px; }
        .rodape { text-align: center; color: #64748b; font-size: 0.85rem; padding-top: 20px; border-top: 1px solid #e2e8f0; }
        
        .status-badge { display: inline-block; padding: 5px 12px; border-radius: 8px; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; background: #e2e8f0; margin-top: 15px;}
        .concluido { background: #dcfce7; color: #166534; }
        .pendente, .aprovado { background: #e0f2fe; color: #075985; }
        .aguardando_pagamento { background: #fef3c7; color: #b45309; }
        
        .item-icon { color: #94a3b8; margin-right: 8px; width: 16px; text-align: center;}
        .item-plano { color: #8b5cf6; font-weight: 700; }
        
        /* Botões de Ação na Tela */
        .botoes-acao { display: flex; gap: 10px; margin-top: 20px; }
        .btn-acao { flex: 1; padding: 15px; border-radius: 12px; text-decoration: none; font-weight: 600; text-align: center; cursor: pointer; border: none; font-size: 1rem; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-acao:disabled { opacity: 0.7; cursor: not-allowed; }
        .btn-imprimir { background: #0f172a; color: #fff; }
        .btn-imprimir:hover:not(:disabled) { background: #1e293b; transform: translateY(-2px); }
        .btn-email { background: #8b5cf6; color: #fff; box-shadow: 0 4px 10px rgba(139, 92, 246, 0.3); }
        .btn-email:hover:not(:disabled) { background: #7c3aed; transform: translateY(-2px); box-shadow: 0 6px 15px rgba(139, 92, 246, 0.4); }
        
        @media print {
            body { background: #fff; padding: 0; align-items: flex-start; }
            .comprovativo-card { box-shadow: none; border: none; max-width: 100%; padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="comprovativo-card" id="comprovante-area">
    <div class="cabecalho">
        <?php if($logo_src): ?>
            <img src="<?= htmlspecialchars($logo_src) ?>" alt="Logo Barbearia" class="logo-recibo">
        <?php endif; ?>
        <h1><?= htmlspecialchars($configGeral['nome_barbearia'] ?? 'Barbearia') ?></h1>
        <p>Comprovante de Agendamento</p>
        <div class="status-badge <?= strtolower($agendamento['status']) ?>">
            <?= ucfirst(str_replace('_', ' ', $agendamento['status'])) ?>
        </div>
    </div>
    <div class="dados-cliente">
        <div><strong>Cliente:</strong> <?= htmlspecialchars($agendamento['nome']) ?></div>
        <div><strong>Profissional:</strong> <?= htmlspecialchars($barbeirosArr[$agendamento['barbeiro_id']]['nome'] ?? 'Nossa Equipe') ?></div>
        <div><strong>Data do Serviço:</strong> <?= date('d/m/Y', strtotime($agendamento['data'])) ?> às <?= $agendamento['hora'] ?></div>
        <div><strong>Nº Documento:</strong> #<?= htmlspecialchars($agendamento['id']) ?></div>
    </div>
    
    <table class="tabela-itens">
        <thead>
            <tr>
                <th>Descrição do Item</th>
                <th class="valor">Valor</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($is_adesao): ?>
            <tr>
                <td class="item-plano"><i class="fa fa-crown item-icon" style="color: #8b5cf6;"></i> Assinatura: <?= htmlspecialchars($plano_nome) ?></td>
                <td class="valor">R$ <?= number_format($plano_valor, 2, ',', '.') ?></td>
            </tr>
            <?php endif; ?>
            
            <?php foreach ($servicosLista as $s): ?>
            <tr>
                <td><i class="fa fa-cut item-icon"></i> <?= htmlspecialchars($s['nome']) ?></td>
                <td class="valor">R$ <?= number_format($s['valor'], 2, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
            
            <?php foreach ($produtosLista as $p): ?>
            <tr>
                <td><i class="fa fa-box item-icon"></i> <?= htmlspecialchars($p['nome']) ?> (Produto)</td>
                <td class="valor">R$ <?= number_format($p['valor'], 2, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <?php
        $gorjeta_comprovante = max(0, (float)($agendamento['gorjeta'] ?? 0));
        $forma_pagamento_comprovante = function_exists('rotuloFormaPagamento') ? rotuloFormaPagamento($agendamento['forma_pagamento'] ?? '') : '';
        $total_com_gorjeta = $valorFinal + $gorjeta_comprovante;
    ?>
    <div class="totais">
        <div class="linha-total">
            <span>Subtotal Bruto:</span>
            <span>R$ <?= number_format($valorBrutoTotal, 2, ',', '.') ?></span>
        </div>
        <?php if ($desconto_exibir > 0): ?>
        <div class="linha-total desconto">
            <span><?= $label_desconto ?>:</span>
            <span>- R$ <?= number_format($desconto_exibir, 2, ',', '.') ?></span>
        </div>
        <?php endif; ?>
        <div class="linha-total">
            <span>Total dos Serviços:</span>
            <span>R$ <?= number_format($valorFinal, 2, ',', '.') ?></span>
        </div>
        <?php if ($gorjeta_comprovante > 0): ?>
        <div class="linha-total">
            <span><i class="fa fa-hand-holding-heart" style="opacity:.6;"></i> Gorjeta:</span>
            <span>+ R$ <?= number_format($gorjeta_comprovante, 2, ',', '.') ?></span>
        </div>
        <?php endif; ?>
        <div class="linha-total final">
            <span>Total Final Pago:</span>
            <span>R$ <?= number_format($total_com_gorjeta, 2, ',', '.') ?></span>
        </div>
        <?php if ($forma_pagamento_comprovante !== ''): ?>
        <div class="linha-total" style="border-top:1px dashed #cbd5e1; margin-top:6px; padding-top:8px;">
            <span><i class="fa fa-money-bill-wave" style="opacity:.6;"></i> Forma de pagamento:</span>
            <span><?= htmlspecialchars($forma_pagamento_comprovante) ?></span>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="rodape">
        <p>Agradecemos a sua preferência!</p>
        <p style="margin-top: 5px; font-size: 0.75rem;">Documento gerado em <?= date('d/m/Y H:i') ?></p>
    </div>
    
    <div class="botoes-acao no-print" id="area-botoes">
        <button class="btn-acao btn-imprimir" onclick="window.print()"><i class="fa fa-print"></i> Imprimir</button>
        <?php if ($is_admin || $is_barbeiro): ?>
            <button class="btn-acao btn-email" id="btn-email" onclick="enviarPDFporEmail()"><i class="fa fa-file-pdf"></i> Enviar PDF por E-mail</button>
        <?php endif; ?>
    </div>
</div>

<script>
    function enviarPDFporEmail() {
        const btn = document.getElementById('btn-email');
        const originalText = btn.innerHTML;
        
        // Verifica se o cliente tem e-mail
        const emailCliente = "<?= htmlspecialchars($agendamento['email'] ?? '') ?>";
        if (!emailCliente) {
            alert('Atenção: O cliente não possui um e-mail cadastrado neste agendamento.');
            return;
        }

        // Feedback visual
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Preparando PDF...';
        btn.disabled = true;

        const areaBotoes = document.getElementById('area-botoes');
        
        // Esconde os botões temporariamente para o PDF sair limpo
        if (areaBotoes) areaBotoes.style.display = 'none';

        const elemento = document.getElementById('comprovante-area');

        // Configuração do gerador de PDF
        const opt = {
            margin:       10,
            filename:     'Comprovante_#<?= htmlspecialchars($agendamento['id']) ?>.pdf',
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2, useCORS: true },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };

        // Pega o motor do jsPDF puro para extrair o dado de forma confiável
        html2pdf().set(opt).from(elemento).toPdf().get('pdf').then(async function (pdfObj) {
            
            // Retorna os botões assim que a "foto" da tela for tirada
            if (areaBotoes) areaBotoes.style.display = 'flex';
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Disparando SMTP...';
            
            // Gera a string Base64 certificada pelo jsPDF
            const pdfBase64 = pdfObj.output('datauristring');

            try {
                const response = await fetch('?id=<?= urlencode($agendamento_id) ?>&send_pdf=1', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ pdf_data: pdfBase64 })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ PDF gerado e enviado com sucesso para o e-mail:\n' + emailCliente);
                } else {
                    alert('❌ Erro retornado do SMTP:\n' + result.error);
                }
            } catch (error) {
                console.error(error);
                alert('Erro inesperado de conexão. Tente novamente.');
            }

            // Restaura o botão
            btn.innerHTML = originalText;
            btn.disabled = false;
        });
    }
</script>

</body>
</html>
