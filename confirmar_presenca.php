<?php
// confirmar_presenca.php — link público (um clique, sem login) para o cliente
// confirmar que vai comparecer ao agendamento. Autenticado por token por-agendamento.
require_once 'functions.php';
date_default_timezone_set('America/Sao_Paulo');

$agId = trim((string)($_GET['ag'] ?? ''));
$token = trim((string)($_GET['t'] ?? ''));

$estado = 'erro';   // erro | ok | ja | cancelado
$detalhe = ['data' => '', 'hora' => '', 'barbeiro' => '', 'servicos' => ''];

if ($agId !== '' && $token !== '' && function_exists('tokenPresenca') && hash_equals(tokenPresenca($agId), $token)) {
    try {
        $pdo = getDB();
        if (function_exists('_garantirColunaLembrete')) _garantirColunaLembrete($pdo);
        $stmt = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ? LIMIT 1");
        $stmt->execute([$agId]);
        $ag = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($ag) {
            $barbeiros = function_exists('lerDados') ? lerDados('barbeiros', ['id', 'nome']) : [];
            $servicos  = function_exists('lerDados') ? lerDados('servicos', ['id', 'nome']) : [];
            $combos    = function_exists('lerDados') ? lerDados('combos', ['id', 'nome']) : [];
            $nomesServ = [];
            foreach (explode(',', (string)($ag['servicos_ids'] ?? '')) as $sid) {
                $sid = trim($sid);
                if (isset($servicos[$sid])) $nomesServ[] = $servicos[$sid]['nome'];
                elseif (isset($combos[$sid])) $nomesServ[] = $combos[$sid]['nome'];
            }
            $detalhe = [
                'data' => !empty($ag['data']) ? date('d/m/Y', strtotime($ag['data'])) : '',
                'hora' => substr((string)($ag['hora'] ?? ''), 0, 5),
                'barbeiro' => $barbeiros[$ag['barbeiro_id']]['nome'] ?? '',
                'servicos' => $nomesServ ? implode(', ', $nomesServ) : 'Serviço',
            ];

            if (in_array($ag['status'] ?? '', ['cancelado', 'cancelado_pelo_cliente', 'rejeitado'], true)) {
                $estado = 'cancelado';
            } elseif (!empty($ag['presenca_confirmada'])) {
                $estado = 'ja';
            } else {
                $agora = date('Y-m-d H:i:s');
                $pdo->prepare("UPDATE agendamentos SET presenca_confirmada = ? WHERE id = ?")->execute([$agora, $agId]);
                if (function_exists('registrarHistoricoAgenda')) {
                    registrarHistoricoAgenda($agId, 'Presença confirmada', 'Cliente confirmou presença pelo link do lembrete', ($ag['nome'] ?? 'Cliente') . ' (cliente)');
                }
                if (!empty($ag['cliente_id']) && function_exists('criarNotificacao')) {
                    criarNotificacao($ag['cliente_id'], "✅ Presença confirmada para " . $detalhe['data'] . " às " . $detalhe['hora'] . ". Até logo!");
                }
                $estado = 'ok';
            }
        }
    } catch (Exception $e) {
        $estado = 'erro';
    }
}

// ---- Página (autocontida, no tema do site) ----
$configGeral = function_exists('carregarConfigGeral') ? carregarConfigGeral() : [];
$nomeBarbearia = htmlspecialchars($configGeral['nome_barbearia'] ?? 'Barbearia');
$theme = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('theme_config', []) : [];
$accent = $theme['secondary_color'] ?? ($theme['primary_color'] ?? '#6366f1');
if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string)$accent)) $accent = '#6366f1';

$mapa = [
    'ok'        => ['icone' => '✅', 'cor' => '#10b981', 'titulo' => 'Presença confirmada!', 'texto' => 'Obrigado por avisar. Já deixamos tudo pronto para o seu atendimento. Até logo! 😊'],
    'ja'        => ['icone' => '👍', 'cor' => '#10b981', 'titulo' => 'Você já havia confirmado', 'texto' => 'Sua presença já estava confirmada. Nos vemos em breve!'],
    'cancelado' => ['icone' => '⚠️', 'cor' => '#f59e0b', 'titulo' => 'Este agendamento não está ativo', 'texto' => 'Ele foi cancelado. Se quiser, faça um novo agendamento pelo nosso site.'],
    'erro'      => ['icone' => '😕', 'cor' => '#ef4444', 'titulo' => 'Link inválido', 'texto' => 'Não conseguimos validar este link. Ele pode ter expirado. Entre em contato conosco se precisar.'],
];
$v = $mapa[$estado];
$temDetalhe = in_array($estado, ['ok', 'ja'], true) && $detalhe['data'] !== '';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmação de Presença — <?= $nomeBarbearia ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ac: <?= htmlspecialchars($accent) ?>; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;
            font-family: 'Inter', system-ui, -apple-system, Arial, sans-serif;
            background: linear-gradient(135deg, color-mix(in srgb, var(--ac) 14%, #fff), #eef2f7); color: #1e293b; }
        .card { background: #fff; max-width: 440px; width: 100%; border-radius: 20px; padding: 40px 32px; text-align: center;
            box-shadow: 0 24px 60px -20px rgba(15,23,42,.35); border-top: 6px solid <?= htmlspecialchars($v['cor']) ?>; }
        .ico { font-size: 3.4rem; line-height: 1; margin-bottom: 14px; }
        h1 { font-size: 1.4rem; margin: 0 0 10px; color: #0f172a; }
        p { color: #475569; line-height: 1.6; margin: 0 0 8px; }
        .box { text-align: left; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 18px; margin: 22px 0; }
        .box div { margin: 6px 0; color: #334155; font-size: .95rem; }
        .box i { color: var(--ac); width: 20px; }
        .btn { display: inline-block; margin-top: 14px; background: var(--ac); color: #fff; text-decoration: none;
            padding: 12px 24px; border-radius: 12px; font-weight: 700; }
        .brand { margin-top: 22px; color: #94a3b8; font-size: .82rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="ico"><?= $v['icone'] ?></div>
        <h1><?= htmlspecialchars($v['titulo']) ?></h1>
        <p><?= htmlspecialchars($v['texto']) ?></p>

        <?php if ($temDetalhe): ?>
        <div class="box">
            <div><i class="fas fa-calendar-day"></i> <strong><?= htmlspecialchars($detalhe['data']) ?></strong> às <strong><?= htmlspecialchars($detalhe['hora']) ?></strong></div>
            <div><i class="fas fa-scissors"></i> <?= htmlspecialchars($detalhe['servicos']) ?></div>
            <?php if ($detalhe['barbeiro'] !== ''): ?><div><i class="fas fa-user"></i> <?= htmlspecialchars($detalhe['barbeiro']) ?></div><?php endif; ?>
        </div>
        <?php endif; ?>

        <a class="btn" href="<?= htmlspecialchars(function_exists('siteUrl') ? rtrim(siteUrl(), '/') . '/cliente' : 'cliente') ?>">Ver minha conta</a>
        <div class="brand"><?= $nomeBarbearia ?></div>
    </div>
</body>
</html>
