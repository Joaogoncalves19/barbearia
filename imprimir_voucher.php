<?php
if (!isset($_GET['id'])) {
    die('Voucher não especificado.');
}

require_once 'functions.php';
$configGeral = carregarConfigGeral(); // Carrega as configurações gerais

$voucher_id = $_GET['id'];

// --- ATUALIZADO PARA SQLITE NATIVO ---
try {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE id = ?");
    $stmt->execute([$voucher_id]);
    $voucher = $stmt->fetch();
} catch (PDOException $e) {
    $voucher = null;
}
// --- FIM DA ATUALIZAÇÃO ---

if (!$voucher) {
    die('Voucher não encontrado.');
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Voucher de Presente - <?= htmlspecialchars($configGeral['nome_barbearia']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-page: #f8fafc;
            --card-bg-start: #0f172a; /* Slate 900 */
            --card-bg-end: #1e293b;   /* Slate 800 */
            --accent-gold: #f59e0b;   /* Amber 500 */
            --text-light: #f8fafc;
            --text-muted: #94a3b8;
            --border-color: #334155;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            display: flex; 
            flex-direction: column;
            justify-content: center; 
            align-items: center; 
            min-height: 100vh; 
            margin: 0; 
            background-color: var(--bg-page); 
        }

        /* Container dos Botões */
        .actions-wrapper {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }

        .btn-primary { background: #3b82f6; color: white; }
        .btn-primary:hover { background: #2563eb; transform: translateY(-2px); }
        
        .btn-secondary { background: #e2e8f0; color: #475569; }
        .btn-secondary:hover { background: #cbd5e1; transform: translateY(-2px); }

        /* Embalagem com linha pontilhada (para sugerir recorte) */
        .voucher-print-area {
            padding: 20px;
            border: 2px dashed #cbd5e1;
            border-radius: 24px;
            background: #ffffff;
        }

        /* Cartão do Voucher (Estilo Premium) */
        .voucher-card { 
            width: 600px; 
            height: 320px; 
            background: linear-gradient(135deg, var(--card-bg-start) 0%, var(--card-bg-end) 100%);
            border-radius: 16px; 
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1); 
            display: flex; 
            flex-direction: column; 
            justify-content: space-between; 
            padding: 40px; 
            box-sizing: border-box; 
            color: var(--text-light); 
            position: relative; 
            overflow: hidden; 
            border: 1px solid var(--border-color);
        }

        /* Efeito de brilho/círculo no fundo do cartão */
        .voucher-card::before { 
            content: ''; 
            position: absolute; 
            top: -100px; 
            right: -50px; 
            width: 300px; 
            height: 300px; 
            background: radial-gradient(circle, rgba(245, 158, 11, 0.15) 0%, rgba(0,0,0,0) 70%); 
            border-radius: 50%; 
            pointer-events: none;
        }

        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 15px;
            z-index: 1;
        }

        .header .logo { 
            font-weight: 800; 
            font-size: 1.1rem; 
            letter-spacing: 1px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .header .logo i { color: var(--accent-gold); }

        .header h1 { 
            margin: 0; 
            font-size: 1.5rem; 
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--accent-gold);
        }

        .main-content { 
            text-align: center; 
            z-index: 1;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .main-content .code-label { 
            font-size: 0.85rem; 
            letter-spacing: 1.5px; 
            text-transform: uppercase; 
            color: var(--text-muted); 
            font-weight: 600;
            margin-bottom: 12px;
        }

        .main-content .code { 
            font-size: 2.2rem; 
            font-weight: 900; 
            background: rgba(0, 0, 0, 0.4); 
            padding: 15px 35px; 
            border-radius: 12px; 
            display: inline-block; 
            letter-spacing: 4px; 
            border: 1px dashed rgba(245, 158, 11, 0.4); 
            color: var(--accent-gold);
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.2);
        }

        .footer { 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-end; 
            z-index: 1;
        }

        .footer .value-label { 
            font-size: 0.85rem; 
            color: var(--text-muted); 
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
            display: block;
            margin-bottom: 4px;
        }

        .footer .value { 
            font-size: 2.8rem; 
            font-weight: 900; 
            line-height: 1; 
            color: #ffffff;
        }

        .footer .validity { 
            text-align: right; 
        }
        
        .footer .validity-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 4px;
            display: block;
        }
        
        .footer .validity-date {
            font-size: 1.1rem;
            font-weight: 700;
            color: #e2e8f0;
        }

        /* Configurações rigorosas para impressão manter o fundo escuro */
        @media print { 
            body { background-color: #fff; padding: 0; justify-content: flex-start; margin-top: 2cm; } 
            .actions-wrapper { display: none; } 
            .voucher-print-area { border: none; padding: 0; }
            .voucher-card { 
                box-shadow: none; 
                margin: 0 auto; 
                -webkit-print-color-adjust: exact !important; 
                print-color-adjust: exact !important;
                border: 1px solid #000;
            } 
        }
    </style>
</head>
<body onload="window.print()">
    
    <div class="actions-wrapper no-print">
        <button onclick="window.print()" class="btn btn-primary"><i class="fa fa-print"></i> Imprimir</button>
        <button onclick="window.close()" class="btn btn-secondary"><i class="fa fa-times"></i> Fechar Aba</button>
    </div>

    <div class="voucher-print-area">
        <div class="voucher-card">
            <div class="header">
                <span class="logo"><i class="fa fa-cut"></i> <?= htmlspecialchars($configGeral['nome_barbearia']) ?></span>
                <h1>Vale-Presente</h1>
            </div>
            
            <div class="main-content">
                <div class="code-label"><?= htmlspecialchars($configGeral['voucher_instrucao'] ?? 'Use esse voucher no agendamento do site') ?></div>
                <div class="code"><?= htmlspecialchars($voucher['codigo']) ?></div>
            </div>
            
            <div class="footer">
                <div class="value-container">
                    <span class="value-label">Valor do Presente</span>
                    <div class="value">R$ <?= htmlspecialchars(number_format((float)$voucher['valor'], 2, ',', '.')) ?></div>
                </div>
                <div class="validity">
                    <?php if (!empty($voucher['data_validade'])): ?>
                        <span class="validity-label">Válido até</span>
                        <div class="validity-date"><?= htmlspecialchars(date('d/m/Y', strtotime($voucher['data_validade']))) ?></div>
                    <?php else: ?>
                        <span class="validity-label">Validade</span>
                        <div class="validity-date">Sem Data Limite</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</body>
</html>