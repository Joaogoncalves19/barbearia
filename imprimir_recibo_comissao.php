<?php
require_once 'functions.php';
iniciarSessaoSegura();

// Proteção de rota
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

require_once 'functions.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    die("ID do registro não fornecido.");
}

// Carrega a tabela de comissões para encontrar o registro
// Coluna gorjeta garantida por lib/migrations.php (via getDB()).
$comissoesPagasArr = lerDados('comissoes_pagas', ['id', 'barbeiro_id', 'mes_ano', 'valor_total_servicos', 'valor', 'gorjeta', 'data_pagamento']);
$registro = null;

// Busca o registro pelo ID
if (isset($comissoesPagasArr[$id])) {
    $registro = $comissoesPagasArr[$id];
} else {
    // Fallback de segurança
    foreach ($comissoesPagasArr as $c) {
        if (isset($c['id']) && $c['id'] === $id) {
            $registro = $c;
            break;
        }
    }
}

if (!$registro) {
    die("Erro: Registro de pagamento não encontrado no sistema.");
}

// Carrega APENAS os dados que existem na tabela de barbeiros (evita erro de coluna inexistente no SQLite)
$barbeirosArr = lerDados('barbeiros', ['id', 'nome']);
$barbeiro = ['nome' => 'Profissional Desconhecido'];

// Verifica se o ID do barbeiro existe no array retornado pelo banco
if (isset($barbeirosArr[$registro['barbeiro_id']])) {
    $barbeiro = $barbeirosArr[$registro['barbeiro_id']];
}

// Dados do negócio (Nome da Barbearia)
$configGeral = carregarConfigGeral();

// O 'valor' pago já inclui a gorjeta repassada (a folha soma a gorjeta ao líquido).
// Aqui apenas separamos as partes para o holerite, usando o valor gravado no pagamento.
$total_pago = (float)$registro['valor'];
$total_gorjetas_recibo = max(0, (float)($registro['gorjeta'] ?? 0));
$comissao_repasse = max(0, $total_pago - $total_gorjetas_recibo);
$total_recebido_mes = $total_pago;
$valor_declarado = $total_pago;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo de Pagamento - <?= htmlspecialchars($barbeiro['nome']) ?></title>
    <style>
        body { 
            font-family: 'Courier New', Courier, monospace; 
            background: #f4f7fa; 
            margin: 0; 
            padding: 40px 20px; 
            color: #000; 
        }
        .recibo-container { 
            max-width: 600px; 
            margin: 0 auto; 
            background: #fff; 
            border: 2px dashed #334155; 
            padding: 35px 40px; 
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
        }
        .text-center { text-align: center; }
        .linha { border-bottom: 2px dashed #cbd5e1; margin: 20px 0; }
        
        .info-row { 
            display: flex; 
            justify-content: space-between; 
            margin-bottom: 8px; 
            font-size: 1rem;
        }
        .info-row.destaque {
            font-size: 1.2rem;
            font-weight: bold;
            margin-top: 15px;
            padding: 10px 0;
            border-top: 2px dashed #cbd5e1;
            border-bottom: 2px dashed #cbd5e1;
            background: #f8fafc;
        }
        
        .titulo { font-size: 1.5rem; font-weight: bold; margin-bottom: 5px; text-transform: uppercase;}
        
        .assinatura-box { margin-top: 60px; display: flex; justify-content: space-between; gap: 20px; }
        .assinatura { text-align: center; flex: 1; }
        .assinatura-linha { border-top: 1px solid #000; padding-top: 8px; font-weight: bold; }
        
        .no-print { text-align: center; margin-bottom: 20px; }
        .btn-imprimir { background: #10b981; color: white; border: none; padding: 12px 25px; border-radius: 8px; font-size: 1.1rem; cursor: pointer; font-weight: bold; }
        
        @media print {
            body { background: #fff; padding: 0; }
            .recibo-container { border: 1px solid #000; box-shadow: none; padding: 20px; }
            .no-print { display: none; }
        }
    </style>
</head>
<body onload="window.print()">

    <div class="no-print">
        <button class="btn-imprimir" onclick="window.print()">🖨️ Imprimir Recibo</button>
    </div>

    <div class="recibo-container">
        <div class="text-center">
            <div class="titulo"><?= htmlspecialchars($configGeral['nome_barbearia']) ?></div>
            <div style="font-size: 1.1rem; margin-top: 5px;">Recibo de Repasse de Comissões (Holerite)</div>
        </div>
        
        <div class="linha"></div>
        
        <div class="info-row">
            <span><strong>Nº Recibo:</strong> <?= htmlspecialchars($registro['id']) ?></span>
            <span><strong>Data:</strong> <?= date('d/m/Y H:i', strtotime($registro['data_pagamento'])) ?></span>
        </div>
        <div class="info-row">
            <span><strong>Profissional:</strong> <?= htmlspecialchars($barbeiro['nome']) ?></span>
        </div>
        <div class="info-row">
            <span><strong>Mês de Referência:</strong> <?= htmlspecialchars(substr($registro['mes_ano'], 5, 2)) ?>/<?= htmlspecialchars(substr($registro['mes_ano'], 0, 4)) ?></span>
        </div>
        
        <div class="linha"></div>
        
        <div class="info-row">
            <span>(+) Base de Cálculo da Comissão:</span>
            <span>R$ <?= number_format((float)$registro['valor_total_servicos'], 2, ',', '.') ?></span>
        </div>
        
        <?php if ($total_gorjetas_recibo > 0): ?>
            <div class="info-row">
                <span>(=) Comissão líquida (repasse):</span>
                <span>R$ <?= number_format($comissao_repasse, 2, ',', '.') ?></span>
            </div>
            <div class="info-row">
                <span>(+) Gorjetas do período (100% do profissional):</span>
                <span>R$ <?= number_format($total_gorjetas_recibo, 2, ',', '.') ?></span>
            </div>
            <div class="info-row destaque">
                <span>(=) TOTAL RECEBIDO NO MÊS:</span>
                <span>R$ <?= number_format($total_recebido_mes, 2, ',', '.') ?></span>
            </div>
        <?php else: ?>
            <div class="info-row destaque">
                <span>(=) VALOR LÍQUIDO PAGO:</span>
                <span>R$ <?= number_format($total_pago, 2, ',', '.') ?></span>
            </div>
        <?php endif; ?>

        <p style="text-align: justify; font-size: 0.95rem; margin-top: 25px; line-height: 1.6;">
            Recebi da empresa <strong><?= htmlspecialchars($configGeral['nome_barbearia']) ?></strong> a importância líquida supra de <strong>R$ <?= number_format($valor_declarado, 2, ',', '.') ?></strong> referente ao repasse de comissões por serviços prestados (já abatidos eventuais vales/adiantamentos)<?= $total_gorjetas_recibo > 0 ? ', acrescido das gorjetas recebidas no período,' : '' ?> no período de <?= htmlspecialchars(substr($registro['mes_ano'], 5, 2)) ?>/<?= htmlspecialchars(substr($registro['mes_ano'], 0, 4)) ?>.
        </p>
        
        <div class="assinatura-box">
            <div class="assinatura">
                <div class="assinatura-linha">
                    <?= htmlspecialchars($configGeral['nome_barbearia']) ?><br>
                    <span style="font-size: 0.8rem; font-weight: normal;">Responsável Pagador</span>
                </div>
            </div>
            <div class="assinatura">
                <div class="assinatura-linha">
                    <?= htmlspecialchars($barbeiro['nome']) ?><br>
                    <span style="font-size: 0.8rem; font-weight: normal;">Profissional Recebedor</span>
                </div>
            </div>
        </div>
        
        <div class="text-center" style="margin-top: 40px; font-size: 0.8rem; color: #64748b;">
            Comprovante gerado digitalmente pelo sistema.
        </div>
    </div>

</body>
</html>
