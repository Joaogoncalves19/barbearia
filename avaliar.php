<?php
// avaliar.php — avaliação direta por link com token (sem necessidade de login)
require_once 'functions.php';
iniciarSessaoSegura();
$configGeral = carregarConfigGeral();

$ag_id = trim($_GET['a'] ?? $_POST['a'] ?? '');
$token = trim($_GET['t'] ?? $_POST['t'] ?? '');
$valido = $ag_id !== '' && $token !== '' && hash_equals(avaliacaoToken($ag_id), $token);

$erro = '';
$sucesso = false;
$jaAvaliado = false;
$agendamento = null;

if ($valido) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ?");
        $stmt->execute([$ag_id]);
        $agendamento = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$agendamento || ($agendamento['status'] ?? '') !== 'concluido') {
            $valido = false;
        } else {
            $chk = $pdo->prepare("SELECT 1 FROM avaliacoes WHERE agendamento_id = ? LIMIT 1");
            $chk->execute([$ag_id]);
            $jaAvaliado = (bool) $chk->fetchColumn();
        }
    } catch (Exception $e) { $valido = false; }
}

if ($valido && !$jaAvaliado && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = (int)($_POST['rating'] ?? 0);
    $comment = str_replace(["\r", "\n"], ' ', trim($_POST['comment'] ?? ''));
    if ($rating < 1 || $rating > 5) {
        $erro = 'Escolha uma nota de 1 a 5 estrelas.';
    } else {
        try {
            $pdo = getDB();
            $pdo->exec("CREATE TABLE IF NOT EXISTS avaliacoes (id TEXT PRIMARY KEY, agendamento_id TEXT, cliente_id TEXT, barbeiro_id TEXT, rating TEXT, comment TEXT, timestamp TEXT)");
            // Revalida duplicidade dentro da transação lógica
            $chk = $pdo->prepare("SELECT 1 FROM avaliacoes WHERE agendamento_id = ? LIMIT 1");
            $chk->execute([$ag_id]);
            if ($chk->fetchColumn()) {
                $jaAvaliado = true;
            } else {
                $stmt = $pdo->prepare("INSERT INTO avaliacoes (id, agendamento_id, cliente_id, barbeiro_id, rating, comment, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([gerarId('av-'), $ag_id, $agendamento['cliente_id'] ?? '', $agendamento['barbeiro_id'] ?? '', $rating, $comment, date('Y-m-d H:i:s')]);
                $sucesso = true;
            }
        } catch (PDOException $e) {
            $erro = 'Não foi possível registrar sua avaliação. Tente novamente.';
            if (function_exists('log_activity')) { log_activity('Erro avaliar.php: ' . $e->getMessage()); }
        }
    }
}

$accent = '#eab308';
$tc = _lerConfigSQLite('theme_config', []);
$accent = $tc['secondary_color'] ?? $accent;
$primeiroNome = $agendamento ? trim(explode(' ', trim($agendamento['nome'] ?? 'Cliente'))[0]) : 'Cliente';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/pwa_head.php'; ?>
    <title>Avaliar atendimento - <?= htmlspecialchars($configGeral['nome_barbearia'] ?? 'Barbearia') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --a: <?= htmlspecialchars($accent) ?>; --bg:#eef2f8; --card:#fff; --ink:#0f172a; --muted:#64748b; --bd:#e2e8f0; }
        @media (prefers-color-scheme: dark){ :root{ --bg:#0b1220; --card:#131b2c; --ink:#f1f5f9; --muted:#94a3b8; --bd:#263247; } }
        *{box-sizing:border-box;} body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--ink);}
        .card{width:100%;max-width:480px;background:var(--card);border:1px solid var(--bd);border-radius:18px;padding:38px 32px;text-align:center;box-shadow:0 24px 60px -30px rgba(15,23,42,.45);}
        .ring{width:82px;height:82px;margin:0 auto 20px;display:flex;align-items:center;justify-content:center;border-radius:50%;font-size:2.2rem;}
        .ring.q{color:var(--a);background:color-mix(in srgb,var(--a) 14%,transparent);border:2px solid color-mix(in srgb,var(--a) 32%,transparent);}
        .ring.ok{color:#15803d;background:#f0fdf4;border:2px solid #bbf7d0;} .ring.err{color:#b91c1c;background:#fef2f2;border:2px solid #fecaca;}
        h1{font-size:1.4rem;margin:0 0 10px;font-weight:800;} p{color:var(--muted);font-size:1rem;line-height:1.55;margin:0 0 22px;}
        .stars{display:flex;justify-content:center;gap:8px;margin:8px 0 22px;direction:rtl;}
        .stars input{display:none;}
        .stars label{font-size:2.4rem;color:#cbd5e1;cursor:pointer;transition:color .15s;}
        .stars label:hover,.stars label:hover ~ label,.stars input:checked ~ label{color:var(--a);}
        textarea{width:100%;padding:13px 15px;border:1.5px solid var(--bd);border-radius:11px;background:transparent;color:var(--ink);font-family:inherit;font-size:.95rem;resize:vertical;min-height:90px;}
        textarea:focus{outline:none;border-color:var(--a);box-shadow:0 0 0 4px color-mix(in srgb,var(--a) 20%,transparent);}
        .btn{width:100%;margin-top:16px;padding:14px;border:none;border-radius:11px;cursor:pointer;font-family:inherit;font-size:1.02rem;font-weight:700;color:#fff;background:var(--a);}
        .btn:hover{filter:brightness(1.05);}
        .err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:11px 14px;border-radius:11px;font-size:.9rem;margin-bottom:16px;}
        .home{display:inline-block;margin-top:18px;color:var(--muted);text-decoration:none;font-weight:600;font-size:.9rem;} .home:hover{color:var(--a);}
    </style>
</head>
<body>
    <div class="card">
    <?php if (!$valido): ?>
        <div class="ring err"><i class="fa fa-link-slash"></i></div>
        <h1>Link inválido</h1>
        <p>Este link de avaliação é inválido, expirou ou o atendimento não está disponível para avaliação.</p>
        <a href="index" class="home">Ir para o início</a>
    <?php elseif ($sucesso): ?>
        <div class="ring ok"><i class="fa fa-circle-check"></i></div>
        <h1>Obrigado! 🙌</h1>
        <p>Sua avaliação foi registrada com sucesso. Seu feedback nos ajuda muito!</p>
        <a href="index" class="home">Ir para o início</a>
    <?php elseif ($jaAvaliado): ?>
        <div class="ring q"><i class="fa fa-star"></i></div>
        <h1>Você já avaliou</h1>
        <p>Este atendimento já recebeu sua avaliação. Obrigado!</p>
        <a href="index" class="home">Ir para o início</a>
    <?php else: ?>
        <div class="ring q"><i class="fa fa-star"></i></div>
        <h1>Olá, <?= htmlspecialchars($primeiroNome) ?>!</h1>
        <p>Como foi o seu atendimento? Toque nas estrelas e, se quiser, deixe um comentário.</p>
        <?php if ($erro): ?><div class="err"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="a" value="<?= htmlspecialchars($ag_id) ?>">
            <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
            <?php $rPre = (int)($_GET['r'] ?? 0); ?>
            <div class="stars">
                <?php for ($s = 5; $s >= 1; $s--): ?>
                    <input type="radio" name="rating" id="s<?= $s ?>" value="<?= $s ?>" <?= $rPre === $s ? 'checked' : '' ?>><label for="s<?= $s ?>">★</label>
                <?php endfor; ?>
            </div>
            <textarea name="comment" placeholder="Conte como foi sua experiência (opcional)..."></textarea>
            <button type="submit" class="btn"><i class="fa fa-paper-plane"></i> Enviar avaliação</button>
        </form>
    <?php endif; ?>
    </div>
<?php include 'chatbot_widget.php'; ?>
</body>
</html>
