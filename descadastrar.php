<?php
// Página pública de descadastro (opt-out / LGPD)
require_once 'functions.php';

$configGeral = carregarConfigGeral();
$email = strtolower(trim($_GET['e'] ?? ''));
$token = $_GET['t'] ?? '';
$valido = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)
    && hash_equals(marketingTokenDescadastro($email), $token);

$feito = false;
if ($valido && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirmar'] ?? '') === '1') {
    marketingRegistrarOptout($email);
    $feito = true;
}
$jaEstava = $valido && marketingEstaOptout($email);

$accent = '#0ea5e9';
$tc = _lerConfigSQLite('theme_config', []);
$accent = $tc['secondary_color'] ?? $accent;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancelar inscrição - <?= htmlspecialchars($configGeral['nome_barbearia'] ?? 'Barbearia') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --a: <?= htmlspecialchars($accent) ?>; --bg:#eef2f8; --card:#fff; --ink:#0f172a; --muted:#64748b; --bd:#e2e8f0; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0b1220; --card:#131b2c; --ink:#f1f5f9; --muted:#94a3b8; --bd:#263247; } }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px;
               font-family:'Inter',system-ui,sans-serif; background:var(--bg); color:var(--ink); }
        .card { width:100%; max-width:440px; background:var(--card); border:1px solid var(--bd); border-radius:18px;
                padding:40px 34px; text-align:center; box-shadow:0 24px 60px -30px rgba(15,23,42,.45); }
        .ring { width:84px; height:84px; margin:0 auto 22px; display:flex; align-items:center; justify-content:center;
                border-radius:50%; font-size:2.3rem; }
        .ring.ok { color:#15803d; background:#f0fdf4; border:2px solid #bbf7d0; }
        .ring.q  { color:var(--a); background:color-mix(in srgb, var(--a) 12%, transparent); border:2px solid color-mix(in srgb, var(--a) 30%, transparent); }
        .ring.err{ color:#b91c1c; background:#fef2f2; border:2px solid #fecaca; }
        h1 { font-size:1.4rem; margin:0 0 10px; font-weight:800; }
        p { color:var(--muted); font-size:1rem; line-height:1.55; margin:0 0 22px; }
        .email { color:var(--ink); font-weight:700; word-break:break-all; }
        .btn { width:100%; padding:14px; border:none; border-radius:11px; cursor:pointer; font-family:inherit;
               font-size:1rem; font-weight:700; color:#fff; background:#dc2626; }
        .btn:hover { filter:brightness(1.05); }
        .home { display:inline-block; margin-top:18px; color:var(--muted); text-decoration:none; font-weight:600; font-size:.9rem; }
        .home:hover { color:var(--a); }
    </style>
</head>
<body>
    <div class="card">
    <?php if (!$valido): ?>
        <div class="ring err"><i class="fa fa-link-slash"></i></div>
        <h1>Link inválido</h1>
        <p>Este link de cancelamento é inválido ou está incompleto.</p>
        <a href="index" class="home">Ir para o início</a>
    <?php elseif ($feito || $jaEstava): ?>
        <div class="ring ok"><i class="fa fa-circle-check"></i></div>
        <h1>Inscrição cancelada</h1>
        <p>O e-mail <span class="email"><?= htmlspecialchars($email) ?></span> não receberá mais nossos e-mails de novidades e promoções.</p>
        <a href="index" class="home">Ir para o início</a>
    <?php else: ?>
        <div class="ring q"><i class="fa fa-envelope-circle-check"></i></div>
        <h1>Cancelar inscrição?</h1>
        <p>Confirme para deixar de receber e-mails de marketing em <span class="email"><?= htmlspecialchars($email) ?></span>.</p>
        <form method="POST">
            <input type="hidden" name="confirmar" value="1">
            <button type="submit" class="btn"><i class="fa fa-ban"></i> Confirmar cancelamento</button>
        </form>
        <a href="index" class="home">Manter minha inscrição</a>
    <?php endif; ?>
    </div>
<?php include 'chatbot_widget.php'; ?>
</body>
</html>
