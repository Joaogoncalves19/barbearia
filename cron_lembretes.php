<?php
// cron_lembretes.php — envia os lembretes de agendamento (véspera e "horas antes").
// Agende para rodar A CADA 15–30 MIN para o lembrete de "horas antes" funcionar
// bem; a véspera sai 1x por dia, a partir da hora configurada no painel.
//   • Linha de comando (Agendador do Windows / cron do Linux):
//       php cron_lembretes.php            (ambos, respeitando a config)
//   • URL (cron de hospedagem, ex.: cPanel), protegido por token:
//       https://SEU-SITE/cron_lembretes.php?token=SEU_TOKEN
// Parâmetros opcionais:
//   tipo = ambos (padrão) | dia | hora
//   data = AAAA-MM-DD  (força a data da véspera; só com tipo=dia)
// O token aparece no painel: Admin → Agendamentos (caixa "Lembretes automáticos").

require_once __DIR__ . '/functions.php';
date_default_timezone_set('America/Sao_Paulo');

$viaCli = (php_sapi_name() === 'cli');

// Autenticação: CLI é confiável; via web exige o token.
if (!$viaCli) {
    header('Content-Type: application/json; charset=utf-8');
    $tokenEsperado = function_exists('getCronToken') ? getCronToken() : '';
    $tokenRecebido = (string)($_GET['token'] ?? '');
    if ($tokenEsperado === '' || !hash_equals($tokenEsperado, $tokenRecebido)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token inválido ou ausente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// tipo: aceita via ?tipo= (web) ou 1º argumento (CLI). Padrão 'ambos'.
$tipo = strtolower((string)($_GET['tipo'] ?? ($viaCli && isset($argv[1]) ? $argv[1] : 'ambos')));
if (!in_array($tipo, ['ambos', 'dia', 'hora'], true)) $tipo = 'ambos';

$data = null;
if (!empty($_GET['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['data'])) {
    $data = $_GET['data'];
}

$origem = $viaCli ? 'cron-cli' : 'cron-web';

// Rede de segurança das assinaturas: expira as que venceram sem renovar
// (roda em qualquer chamada do cron; é idempotente e barato).
$assinaturasExpiradas = function_exists('expirarAssinaturasVencidas')
    ? expirarAssinaturasVencidas()
    : 0;

if ($tipo === 'dia') {
    $res = ['tipo' => 'dia', 'dia' => enviarLembretesAgendamentos($data, $origem)];
} elseif ($tipo === 'hora') {
    $res = ['tipo' => 'hora', 'hora' => enviarLembretesHorasAntes(null, $origem)];
} else {
    $res = array_merge(['tipo' => 'ambos'], enviarLembretesAutomaticos($origem));
}

echo json_encode(array_merge(['ok' => true, 'assinaturas_expiradas' => $assinaturasExpiradas], $res), JSON_UNESCAPED_UNICODE) . "\n";
