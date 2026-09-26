<?php
// Cabeçalhos de Segurança Modernos
function requisicaoEhHttps() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}

function configurarCookiesSessaoSegura() {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (requisicaoEhHttps()) {
        ini_set('session.cookie_secure', '1');
    }

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => requisicaoEhHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function iniciarSessaoSegura() {
    configurarCookiesSessaoSegura();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

configurarCookiesSessaoSegura();

header("X-Frame-Options: SAMEORIGIN"); // Previne Clickjacking (reforçado por frame-ancestors no CSP)
header("X-Content-Type-Options: nosniff"); // Previne ataques de sniffing de MIME Type
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload"); // Força o uso do HTTPS

// Content-Security-Policy: limita de onde scripts/estilos/mídia podem ser carregados.
// Observação: o app usa muito <script> e handlers inline (onclick=...), por isso
// 'unsafe-inline' é necessário em script/style até que isso seja refatorado.
// Ainda assim, restringir os hosts + object-src/base-uri/frame-ancestors bloqueia
// injeção de <script src="host-malicioso"> e uma série de vetores de clickjacking.
if (!headers_sent()) {
    $csp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://npmcdn.com https://unpkg.com",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net",
        "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com",
        "img-src 'self' data: blob: https:",
        "media-src 'self' data: https://assets.mixkit.co",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "frame-ancestors 'self'",
        // Permite o mapa incorporado (Google Maps embed) na landing page.
        "frame-src 'self' https://maps.google.com https://www.google.com",
    ]);
    header("Content-Security-Policy: " . $csp);
}

// Exibição de erros: nunca mostrar stack trace/paths em produção (vaza informação).
// Em ambiente local (localhost/127.0.0.1) mantém os erros visíveis para depuração.
if (!function_exists('ambienteEhLocal')) {
    function ambienteEhLocal() {
        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $host = preg_replace('/:\d+$/', '', $host); // remove porta
        $addr = $_SERVER['REMOTE_ADDR'] ?? '';
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || in_array($addr, ['127.0.0.1', '::1'], true);
    }
}
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', ambienteEhLocal() ? '1' : '0');

// functions.php (Arquivo Centralizador - Agora preparado para o ecossistema SQLite)

// --- CONFIGURAÇÕES GLOBAIS ---
date_default_timezone_set('America/Sao_Paulo');

define('DATA_DIR', __DIR__ . '/_dados/');
define('LOG_FILE', __DIR__ . '/_logs/app_log.txt');

// Define a URL base do site dinamicamente
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
define('BASE_URL', $protocol . $host . $uri . '/');


// --- CARREGAMENTO DOS MÓDULOS DE LÓGICA ---
require_once 'lib/utils_functions.php';
require_once 'lib/db_functions.php';
require_once 'lib/config_functions.php';
require_once 'lib/auth_functions.php';
require_once 'lib/email_functions.php';
require_once 'lib/email_layout.php';
require_once 'lib/agendamento_functions.php';
require_once 'lib/marketing_functions.php';
require_once 'lib/notificacao_functions.php';
require_once 'lib/relatorio_functions.php';
require_once 'lib/admin_gestao_functions.php';
require_once 'lib/admin_agenda_functions.php';
require_once 'lib/ia_functions.php';
require_once 'lib/seo_functions.php';

// --- FUSO HORÁRIO CONFIGURÁVEL ---
// Reaplica o fuso salvo nas Configurações (se válido), sobrepondo o padrão acima.
// Protegido para nunca quebrar entrypoints iniciais (ex.: install) sem banco.
try {
    if (function_exists('carregarConfigGeral')) {
        $cfgFuso = carregarConfigGeral();
        $fusoSalvo = trim((string)($cfgFuso['fuso_horario'] ?? ''));
        if ($fusoSalvo !== '' && in_array($fusoSalvo, timezone_identifiers_list(), true)) {
            date_default_timezone_set($fusoSalvo);
        }
    }
} catch (\Throwable $e) {
    // Mantém America/Sao_Paulo em caso de qualquer falha.
}

// --- MEMORIZA A URL PÚBLICA DO SITE ---
// Em toda visita web pelo domínio real, persiste a URL base (config_site). Assim
// o cron/CLI (sem HTTP_HOST) consegue montar links absolutos e a URL da logo nos
// e-mails. Protegido para nunca quebrar entrypoints sem banco.
try {
    if (php_sapi_name() !== 'cli' && function_exists('siteUrl')) { siteUrl(); }
} catch (\Throwable $e) {
    // Ignora: apenas otimização de persistência da URL.
}

function definirCookieSeguro($nome, $valor, $expira, $httpOnly = true) {
    if (PHP_VERSION_ID >= 70300) {
        return setcookie($nome, $valor, [
            'expires' => $expira,
            'path' => '/',
            'secure' => requisicaoEhHttps(),
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ]);
    }

    return setcookie($nome, $valor, $expira, '/', '', requisicaoEhHttps(), $httpOnly);
}

function apagarCookieSeguro($nome) {
    return definirCookieSeguro($nome, '', time() - 3600);
}

function garantirTabelaTokensCliente() {
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS clientes_tokens (
        selector TEXT PRIMARY KEY,
        cliente_id TEXT NOT NULL,
        token_hash TEXT NOT NULL,
        expires_at INTEGER NOT NULL,
        created_at TEXT NOT NULL,
        last_used_at TEXT DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clientes_tokens_cliente ON clientes_tokens (cliente_id)");
}

function criarCookieLembrarCliente($cliente_id, $dias = 30) {
    garantirTabelaTokensCliente();
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expira = time() + (86400 * max(1, (int)$dias));

    $stmt = getDB()->prepare("INSERT INTO clientes_tokens (selector, cliente_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$selector, $cliente_id, hash('sha256', $validator), $expira, date('Y-m-d H:i:s')]);

    definirCookieSeguro('lembrar_cliente', $selector . ':' . $validator, $expira, true);
}

function autenticarClientePorCookieLembrar($cookieValor) {
    if (!is_string($cookieValor) || strpos($cookieValor, ':') === false) {
        apagarCookieSeguro('lembrar_cliente');
        return null;
    }

    [$selector, $validator] = explode(':', $cookieValor, 2);
    if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        apagarCookieSeguro('lembrar_cliente');
        return null;
    }

    garantirTabelaTokensCliente();
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM clientes_tokens WHERE selector = ? LIMIT 1");
    $stmt->execute([$selector]);
    $token = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$token || (int)$token['expires_at'] < time() || !hash_equals($token['token_hash'], hash('sha256', $validator))) {
        $pdo->prepare("DELETE FROM clientes_tokens WHERE selector = ?")->execute([$selector]);
        apagarCookieSeguro('lembrar_cliente');
        return null;
    }

    $stmtCliente = $pdo->prepare("SELECT * FROM clientes WHERE id = ? AND status = 'ativo' LIMIT 1");
    $stmtCliente->execute([$token['cliente_id']]);
    $cliente = $stmtCliente->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        $pdo->prepare("DELETE FROM clientes_tokens WHERE selector = ?")->execute([$selector]);
        apagarCookieSeguro('lembrar_cliente');
        return null;
    }

    $pdo->prepare("UPDATE clientes_tokens SET last_used_at = ? WHERE selector = ?")->execute([date('Y-m-d H:i:s'), $selector]);
    return $cliente;
}

function revogarTokensCliente($cliente_id) {
    garantirTabelaTokensCliente();
    getDB()->prepare("DELETE FROM clientes_tokens WHERE cliente_id = ?")->execute([$cliente_id]);
}

function revogarCookieLembrarClienteAtual() {
    $cookieValor = $_COOKIE['lembrar_cliente'] ?? '';
    if (is_string($cookieValor) && strpos($cookieValor, ':') !== false) {
        [$selector] = explode(':', $cookieValor, 2);
        if (preg_match('/^[a-f0-9]{24}$/', $selector)) {
            garantirTabelaTokensCliente();
            getDB()->prepare("DELETE FROM clientes_tokens WHERE selector = ?")->execute([$selector]);
        }
    }
    apagarCookieSeguro('lembrar_cliente');
}

function senhaAtendePolitica($senha) {
    if (!is_string($senha) || strlen($senha) < 8) {
        return false;
    }
    // Exige ao menos uma letra e um número (protege contra senhas triviais).
    return (bool) preg_match('/\p{L}/u', $senha) && (bool) preg_match('/\d/', $senha);
}

function mensagemPoliticaSenha() {
    return 'A senha deve ter no mínimo 8 caracteres, incluindo pelo menos uma letra e um número.';
}

/* =====================================================================
   PROTEÇÃO CSRF (compartilhada por todas as telas de autenticação)
   ===================================================================== */
function gerarTokenCsrf() {
    if (session_status() === PHP_SESSION_NONE) {
        iniciarSessaoSegura();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validarTokenCsrf($token) {
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function campoCsrf() {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(gerarTokenCsrf(), ENT_QUOTES) . '">';
}

/* =====================================================================
   THROTTLE DE LOGIN PERSISTENTE (por IP + identificador)
   Resiste à limpeza de cookies/sessão do atacante.
   ===================================================================== */
/**
 * Proxies em que se pode confiar para informar o IP real do visitante.
 *
 * VAZIO por padrao, e isso e proposital: confiar em X-Forwarded-For sem saber
 * quem mandou permite que qualquer pessoa forje o proprio IP e escape de todos
 * os limites de tentativa. Enquanto estiver vazio, so o REMOTE_ADDR e usado.
 *
 * Preencha SOMENTE se o site rodar atras de Cloudflare, nginx ou load balancer
 * -- nesse caso o REMOTE_ADDR e sempre o do proxy, todos os visitantes viram um
 * IP so, e um unico usuario esbarrando no limite trancaria o site inteiro.
 *
 * Formato: IPs e/ou faixas CIDR separados por virgula.
 *   define('PROXIES_CONFIAVEIS', '10.0.0.0/8,172.16.0.0/12');
 * Para Cloudflare, use a lista publica em cloudflare.com/ips
 */
if (!defined('PROXIES_CONFIAVEIS')) {
    define('PROXIES_CONFIAVEIS', '');
}

/** Verifica se um IPv4 pertence a uma faixa CIDR (ou e igual a um IP solto). */
function _ipCasaFaixa($ip, $faixa) {
    if (strpos($faixa, '/') === false) {
        return $ip === $faixa;
    }
    list($rede, $bits) = explode('/', $faixa, 2);
    $bits = (int) $bits;
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        || !filter_var($rede, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        || $bits < 0 || $bits > 32) {
        return false;
    }
    $mascara = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
    return (ip2long($ip) & $mascara) === (ip2long($rede) & $mascara);
}

function _ipEhProxyConfiavel($ip, array $confiaveis) {
    foreach ($confiaveis as $faixa) {
        if (_ipCasaFaixa($ip, $faixa)) {
            return true;
        }
    }
    return false;
}

/**
 * IP do visitante, usado como chave dos limites de tentativa.
 *
 * So olha cabecalhos de proxy quando a conexao veio de um proxy declarado em
 * PROXIES_CONFIAVEIS. Fora isso devolve o REMOTE_ADDR, que nao pode ser forjado.
 */
function _ipRequisicao() {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $confiaveis = array_filter(array_map('trim', explode(',', (string) PROXIES_CONFIAVEIS)));
    if (!$confiaveis || !_ipEhProxyConfiavel($remote, $confiaveis)) {
        return $remote;
    }

    // Cloudflare entrega o IP de origem ja resolvido.
    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
        return $cf;
    }

    // X-Forwarded-For: "cliente, proxy1, proxy2". Percorremos da direita para a
    // esquerda e paramos no primeiro que NAO seja proxy nosso -- os valores mais
    // a esquerda podem ter sido forjados pelo proprio cliente.
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        foreach (array_reverse(array_map('trim', explode(',', $xff))) as $candidato) {
            if (!filter_var($candidato, FILTER_VALIDATE_IP)) {
                continue;
            }
            if (_ipEhProxyConfiavel($candidato, $confiaveis)) {
                continue;
            }
            return $candidato;
        }
    }

    return $remote;
}

function garantirTabelaLoginThrottle() {
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_throttle (
        chave TEXT PRIMARY KEY,
        tentativas INTEGER NOT NULL DEFAULT 0,
        bloqueio_ate INTEGER NOT NULL DEFAULT 0,
        atualizado_em INTEGER NOT NULL DEFAULT 0
    )");
}

function _chaveThrottle($identificador) {
    return hash('sha256', strtolower(trim((string)$identificador)) . '|' . _ipRequisicao());
}

/**
 * Chave do contador por IP, sem o identificador.
 *
 * O contador principal e por (identificador + IP), o que nao segura password
 * spraying: quem tenta uma senha comum contra 500 e-mails diferentes nunca
 * passa de 1 falha por chave. Este segundo contador olha so a origem.
 *
 * O limite dele e propositalmente folgado (30 por janela): escritorio, escola e
 * operadora movel com CGNAT compartilham IP, e nao se pode trancar todo mundo
 * por causa de um vizinho distraido.
 */
function _chaveThrottleIp() {
    return hash('sha256', 'ip-global|' . _ipRequisicao());
}

/** Le uma linha do throttle respeitando a janela (fora dela, o contador zera). */
function _lerThrottle(PDO $pdo, $chave, $janelaSegundos) {
    $stmt = $pdo->prepare("SELECT tentativas, bloqueio_ate, atualizado_em FROM login_throttle WHERE chave = ?");
    $stmt->execute([$chave]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$linha) {
        return ['tentativas' => 0, 'bloqueio_ate' => 0];
    }
    // Janela deslizante: se a ultima falha foi ha mais tempo que a janela, o
    // contador recomeca. Sem isto ele so crescia -- depois do primeiro bloqueio
    // o usuario ficava com UMA tentativa a cada janela, indefinidamente, porque
    // tentativas ja estava no maximo e qualquer nova falha rebloqueava na hora.
    $expirou = ((int) $linha['atualizado_em']) < (time() - $janelaSegundos);
    return [
        'tentativas'  => $expirou ? 0 : (int) $linha['tentativas'],
        'bloqueio_ate' => (int) $linha['bloqueio_ate'],
    ];
}

function _gravarThrottle(PDO $pdo, $chave, $tentativas, $bloqueio, $agora) {
    $pdo->prepare("INSERT INTO login_throttle (chave, tentativas, bloqueio_ate, atualizado_em)
        VALUES (?, ?, ?, ?)
        ON CONFLICT(chave) DO UPDATE SET
            tentativas = excluded.tentativas,
            bloqueio_ate = excluded.bloqueio_ate,
            atualizado_em = excluded.atualizado_em")
        ->execute([$chave, $tentativas, $bloqueio, $agora]);
}

/**
 * Retorna o timestamp ate quando esta bloqueado (0 = livre).
 * Considera o bloqueio por (identificador + IP) E o bloqueio so por IP,
 * devolvendo o que terminar mais tarde.
 */
function loginBloqueadoAte($identificador) {
    try {
        garantirTabelaLoginThrottle();
        $pdo = getDB();
        $agora = time();
        $ate = 0;
        foreach ([_chaveThrottle($identificador), _chaveThrottleIp()] as $chave) {
            $stmt = $pdo->prepare("SELECT bloqueio_ate FROM login_throttle WHERE chave = ?");
            $stmt->execute([$chave]);
            $v = (int) ($stmt->fetchColumn() ?: 0);
            if ($v > $agora && $v > $ate) {
                $ate = $v;
            }
        }
        return $ate;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Registra uma falha nos dois contadores.
 * @return int Quantas tentativas restam antes do bloqueio (0 = bloqueado agora).
 */
function registrarFalhaLogin($identificador, $max = 5, $janelaSegundos = 900, $maxIp = 30) {
    try {
        garantirTabelaLoginThrottle();
        $pdo = getDB();
        $agora = time();

        // 1) contador por identificador + IP
        $atual = _lerThrottle($pdo, _chaveThrottle($identificador), $janelaSegundos);
        $tentativas = $atual['tentativas'] + 1;
        $bloqueio = $tentativas >= $max ? $agora + $janelaSegundos : 0;
        _gravarThrottle($pdo, _chaveThrottle($identificador), $tentativas, $bloqueio, $agora);

        // 2) contador so por IP (anti password spraying)
        $atualIp = _lerThrottle($pdo, _chaveThrottleIp(), $janelaSegundos);
        $tentativasIp = $atualIp['tentativas'] + 1;
        $bloqueioIp = $tentativasIp >= $maxIp ? $agora + $janelaSegundos : 0;
        _gravarThrottle($pdo, _chaveThrottleIp(), $tentativasIp, $bloqueioIp, $agora);

        if ($bloqueioIp > 0 && function_exists('log_activity')) {
            log_activity('Bloqueio por IP apos ' . $tentativasIp . ' falhas de login em ' . _ipRequisicao() . '.');
        }

        return max(0, $max - $tentativas);
    } catch (Exception $e) {
        return $max;
    }
}

/**
 * Limpa as falhas do identificador apos um login bem-sucedido.
 * O contador por IP NAO e limpo de proposito: um acerto no meio de uma rajada
 * nao deve zerar o rastro da rajada. Ele expira sozinho com a janela.
 */
function limparFalhasLogin($identificador) {
    try {
        garantirTabelaLoginThrottle();
        getDB()->prepare("DELETE FROM login_throttle WHERE chave = ?")
            ->execute([_chaveThrottle($identificador)]);
    } catch (Exception $e) {
        /* silencioso */
    }
}

/* =====================================================================
   TOKENS DE REDEFINIÇÃO DE SENHA (armazenados como hash)
   ===================================================================== */
function garantirTabelaPasswordResets() {
    getDB()->exec("CREATE TABLE IF NOT EXISTS password_resets (email TEXT, token TEXT, expiry INTEGER)");
}

/** Cria um token, limpa os anteriores do mesmo e-mail e os expirados. Retorna o token BRUTO (vai no link). */
function criarTokenResetSenha($email) {
    garantirTabelaPasswordResets();
    $pdo = getDB();
    $pdo->prepare("DELETE FROM password_resets WHERE email = ? OR expiry < ?")
        ->execute([$email, time() - 3600]);

    $rawToken = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO password_resets (email, token, expiry) VALUES (?, ?, ?)")
        ->execute([$email, hash('sha256', $rawToken), time()]);
    return $rawToken;
}

/** Devolve o e-mail associado a um token válido (< 1h) ou null. */
function emailDoTokenReset($rawToken) {
    if (!is_string($rawToken) || $rawToken === '') {
        return null;
    }
    try {
        garantirTabelaPasswordResets();
        $stmt = getDB()->prepare("SELECT email, expiry FROM password_resets WHERE token = ?");
        $stmt->execute([hash('sha256', $rawToken)]);
        $r = $stmt->fetch();
        if ($r && (time() - (int) $r['expiry']) < 3600) {
            return $r['email'];
        }
    } catch (Exception $e) {
        /* silencioso */
    }
    return null;
}

function apagarTokenReset($rawToken) {
    try {
        garantirTabelaPasswordResets();
        getDB()->prepare("DELETE FROM password_resets WHERE token = ?")
            ->execute([hash('sha256', (string)$rawToken)]);
    } catch (Exception $e) {
        /* silencioso */
    }
}

/** Regera o token de confirmação e reenvia o e-mail de ativação para uma conta inativa. */
/* =====================================================================
   CONFIRMACAO DE CADASTRO: validade do token
   ===================================================================== */

/** Por quantas horas o link de confirmacao de e-mail continua valendo. */
if (!defined('CONFIRMACAO_VALIDADE_HORAS')) {
    define('CONFIRMACAO_VALIDADE_HORAS', 48);
}

/**
 * Gera um token de confirmacao e devolve [token, timestamp de expiracao].
 *
 * O token nao tinha prazo: um link vazado (caixa de e-mail antiga, encaminhado
 * sem querer, backup de mensagens) ativava a conta anos depois. Agora ele morre
 * sozinho e o cliente pede outro pela tela de login.
 */
function gerarTokenConfirmacao() {
    return [
        bin2hex(random_bytes(32)),
        time() + (CONFIRMACAO_VALIDADE_HORAS * 3600),
    ];
}

/* =====================================================================
   LIMITE DE CADASTROS POR IP
   ===================================================================== */

/**
 * Ate quando este IP esta impedido de criar novas contas (0 = livre).
 *
 * O formulario ja tem honeypot e time-trap, que seguram bot ingenuo -- mas um
 * script que respeite os 4 segundos criava contas a vontade. Aqui contamos
 * CADASTROS CONCLUIDOS, nao tentativas: quem erra o formulario varias vezes nao
 * deve ser punido, so quem realmente cria conta atras de conta.
 */
function cadastroBloqueadoAte() {
    try {
        garantirTabelaLoginThrottle();
        $stmt = getDB()->prepare("SELECT bloqueio_ate FROM login_throttle WHERE chave = ?");
        $stmt->execute([_chaveThrottleCadastro()]);
        $ate = (int) ($stmt->fetchColumn() ?: 0);
        return $ate > time() ? $ate : 0;
    } catch (Exception $e) {
        return 0;
    }
}

function _chaveThrottleCadastro() {
    return hash('sha256', 'cadastro-ip|' . _ipRequisicao());
}

/**
 * Registra um cadastro concluido e bloqueia o IP ao atingir o limite.
 *
 * O limite e folgado porque casa, escritorio e operadora movel com CGNAT
 * compartilham IP -- uma familia inteira pode se cadastrar no mesmo dia.
 *
 * @return bool true se este cadastro fez o IP atingir o limite.
 */
function registrarCadastroCriado($max = 5, $janelaSegundos = 3600) {
    try {
        garantirTabelaLoginThrottle();
        $pdo = getDB();
        $agora = time();
        $chave = _chaveThrottleCadastro();

        $atual = _lerThrottle($pdo, $chave, $janelaSegundos);
        $total = $atual['tentativas'] + 1;
        $bloqueio = $total >= $max ? $agora + $janelaSegundos : 0;
        _gravarThrottle($pdo, $chave, $total, $bloqueio, $agora);

        if ($bloqueio > 0 && function_exists('log_activity')) {
            log_activity('Limite de cadastros atingido: ' . $total . ' contas criadas em ' . _ipRequisicao() . '.');
        }
        return $bloqueio > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Ate quando este cliente esta impedido de pedir novo e-mail de confirmacao.
 *
 * A chave e o ID do cliente, nao o IP: o incomodo de um reenvio em rajada cai
 * na caixa de entrada DELE, e quem dispara pode estar em qualquer conexao. Sem
 * isso, quem tivesse o pendente_confirmacao_id na sessao podia usar o SMTP da
 * barbearia para encher o e-mail de outra pessoa.
 */
function reenvioConfirmacaoBloqueadoAte($cliente_id) {
    try {
        garantirTabelaLoginThrottle();
        $stmt = getDB()->prepare("SELECT bloqueio_ate FROM login_throttle WHERE chave = ?");
        $stmt->execute([_chaveThrottleReenvio($cliente_id)]);
        $ate = (int) ($stmt->fetchColumn() ?: 0);
        return $ate > time() ? $ate : 0;
    } catch (Exception $e) {
        return 0;
    }
}

function _chaveThrottleReenvio($cliente_id) {
    return hash('sha256', 'reenvio-confirmacao|' . $cliente_id);
}

/** Contabiliza um reenvio. @return bool true se este atingiu o limite. */
function registrarReenvioConfirmacao($cliente_id, $max = 3, $janelaSegundos = 3600) {
    try {
        garantirTabelaLoginThrottle();
        $pdo = getDB();
        $agora = time();
        $chave = _chaveThrottleReenvio($cliente_id);
        $atual = _lerThrottle($pdo, $chave, $janelaSegundos);
        $total = $atual['tentativas'] + 1;
        $bloqueio = $total >= $max ? $agora + $janelaSegundos : 0;
        _gravarThrottle($pdo, $chave, $total, $bloqueio, $agora);
        return $bloqueio > 0;
    } catch (Exception $e) {
        return false;
    }
}

/* =====================================================================
   LIMITE DE USO DO ASSISTENTE E DO RESET DE SENHA
   ===================================================================== */

/**
 * Ate quando este IP esta impedido de usar o assistente (0 = livre).
 *
 * assistente.php e publico (nao exige login), chama API de IA cobrada por token
 * e, com o agente ligado, cria/cancela/remarca agendamento. Sem limite, um
 * visitante em laco queima a cota de TPM do provedor ou enche a agenda.
 *
 * O teto e folgado de proposito: uma conversa real raramente passa de 20-30
 * mensagens, e escritorio/escola/CGNAT compartilham IP.
 */
function assistenteBloqueadoAte() {
    try {
        garantirTabelaLoginThrottle();
        $stmt = getDB()->prepare("SELECT bloqueio_ate FROM login_throttle WHERE chave = ?");
        $stmt->execute([_chaveThrottleAssistente()]);
        $ate = (int) ($stmt->fetchColumn() ?: 0);
        return $ate > time() ? $ate : 0;
    } catch (Exception $e) {
        return 0;
    }
}

function _chaveThrottleAssistente() {
    return hash('sha256', 'assistente-ip|' . _ipRequisicao());
}

/** Contabiliza uma mensagem ao assistente. @return bool true se atingiu o limite. */
function registrarUsoAssistente($max = 60, $janelaSegundos = 600) {
    try {
        garantirTabelaLoginThrottle();
        $pdo = getDB();
        $agora = time();
        $chave = _chaveThrottleAssistente();

        $atual = _lerThrottle($pdo, $chave, $janelaSegundos);
        $total = $atual['tentativas'] + 1;
        $bloqueio = $total >= $max ? $agora + $janelaSegundos : 0;
        _gravarThrottle($pdo, $chave, $total, $bloqueio, $agora);

        if ($bloqueio > 0 && function_exists('log_activity')) {
            log_activity('Limite do assistente atingido: ' . $total . ' mensagens de ' . _ipRequisicao() . '.');
        }
        return $bloqueio > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Ate quando novos pedidos de reset de senha estao bloqueados (0 = livre).
 *
 * Olha duas chaves: o e-mail alvo (impede encher a caixa de UMA vitima) e o IP
 * (impede varrer varios e-mails). O cooldown que existia antes vivia so na
 * sessao -- quem descartasse o cookie a cada request passava direto.
 *
 * Quem chama deve manter a resposta neutra: bloqueado ou nao, a mensagem
 * exibida e a mesma, senao o limite vira oraculo de "este e-mail existe".
 */
function resetSenhaBloqueadoAte($email) {
    try {
        garantirTabelaLoginThrottle();
        $pdo = getDB();
        $agora = time();
        $ate = 0;
        foreach ([_chaveThrottleReset($email), _chaveThrottleResetIp()] as $chave) {
            $stmt = $pdo->prepare("SELECT bloqueio_ate FROM login_throttle WHERE chave = ?");
            $stmt->execute([$chave]);
            $v = (int) ($stmt->fetchColumn() ?: 0);
            if ($v > $agora && $v > $ate) {
                $ate = $v;
            }
        }
        return $ate;
    } catch (Exception $e) {
        return 0;
    }
}

function _chaveThrottleReset($email) {
    return hash('sha256', 'reset-senha|' . mb_strtolower(trim((string)$email)));
}

function _chaveThrottleResetIp() {
    return hash('sha256', 'reset-senha-ip|' . _ipRequisicao());
}

/**
 * Contabiliza um pedido de reset nos dois contadores (e-mail e IP).
 * Conta o PEDIDO, existindo o e-mail ou nao -- contar so os envios reais
 * deixaria o atacante medir quais e-mails existem pelo proprio bloqueio.
 */
function registrarPedidoResetSenha($email, $maxEmail = 3, $maxIp = 15, $janelaSegundos = 3600) {
    try {
        garantirTabelaLoginThrottle();
        $pdo = getDB();
        $agora = time();

        foreach ([[_chaveThrottleReset($email), $maxEmail], [_chaveThrottleResetIp(), $maxIp]] as $par) {
            list($chave, $max) = $par;
            $atual = _lerThrottle($pdo, $chave, $janelaSegundos);
            $total = $atual['tentativas'] + 1;
            $bloqueio = $total >= $max ? $agora + $janelaSegundos : 0;
            _gravarThrottle($pdo, $chave, $total, $bloqueio, $agora);
        }
    } catch (Exception $e) {
        // Falha de throttle nunca pode derrubar o fluxo de recuperacao.
    }
}

function reenviarConfirmacaoCadastro(array $cliente) {
    if (($cliente['status'] ?? '') === 'ativo' || empty($cliente['email'])) {
        return false;
    }
    // Token novo renova tambem o prazo: quem pede reenvio ganha 48h a contar
    // de agora, nao o que sobrava do link antigo.
    list($token, $expira) = gerarTokenConfirmacao();
    try {
        getDB()->prepare("UPDATE clientes SET confirmation_token = ?, confirmation_expira_em = ? WHERE id = ?")
            ->execute([$token, $expira, $cliente['id']]);
    } catch (Exception $e) {
        // Banco de versao antiga, ainda sem a coluna de prazo.
        getDB()->prepare("UPDATE clientes SET confirmation_token = ? WHERE id = ?")
            ->execute([$token, $cliente['id']]);
    }

    $dados = [
        'nome_cliente'     => $cliente['nome'] ?? 'Cliente',
        'link_confirmacao' => BASE_URL . "confirmar_email?token=$token",
    ];
    return enviarEmail($cliente['email'], 'Confirme seu Cadastro', 'confirmar_cadastro', $dados);
}

/**
 * Resolve o diretório de destino de um upload, opcionalmente dentro de uma
 * subpasta (ex.: "cliente-42"), garantindo que ela exista. A subpasta é
 * higienizada para evitar path traversal.
 *
 * @return array{0:string,1:string} [caminho absoluto, prefixo relativo com barra final]
 */
function resolverPastaUpload($subpasta = '') {
    $dir = __DIR__ . '/uploads';
    $relBase = 'uploads/';

    $subpastaSegura = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$subpasta);
    if ($subpastaSegura !== '') {
        $dir .= '/' . $subpastaSegura;
        $relBase .= $subpastaSegura . '/';
    }

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return [$dir, $relBase];
}

function salvarUploadSeguro($arquivo, $prefixo, $mimesPermitidos, $maxBytes = 5242880, $subpasta = '') {
    if (!isset($arquivo['error']) || $arquivo['error'] !== UPLOAD_ERR_OK) {
        return [false, 'Arquivo nao enviado corretamente.'];
    }
    if (($arquivo['size'] ?? 0) <= 0 || $arquivo['size'] > $maxBytes) {
        return [false, 'Arquivo acima do tamanho permitido.'];
    }
    if (!is_uploaded_file($arquivo['tmp_name'])) {
        return [false, 'Upload invalido.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $arquivo['tmp_name']);
    finfo_close($finfo);

    if (!isset($mimesPermitidos[$mime])) {
        return [false, 'Formato de arquivo nao permitido.'];
    }
    if (strpos($mime, 'image/') === 0 && @getimagesize($arquivo['tmp_name']) === false) {
        return [false, 'Imagem invalida.'];
    }

    [$dir, $relBase] = resolverPastaUpload($subpasta);

    $prefixoSeguro = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefixo) ?: 'arquivo';
    $nomeArquivo = $prefixoSeguro . '-' . bin2hex(random_bytes(12)) . '.' . $mimesPermitidos[$mime];
    $destinoAbs = $dir . '/' . $nomeArquivo;
    $destinoRel = $relBase . $nomeArquivo;

    if (!move_uploaded_file($arquivo['tmp_name'], $destinoAbs)) {
        return [false, 'Nao foi possivel salvar o arquivo.'];
    }

    return [true, $destinoRel];
}

function salvarImagemBase64Segura($base64String, $prefixo, $maxBytes = 3145728, $subpasta = '') {
    if (!is_string($base64String) || !preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $base64String)) {
        return [false, 'Imagem invalida.'];
    }

    [, $dados] = explode(',', $base64String, 2);
    $binario = base64_decode($dados, true);
    if ($binario === false || strlen($binario) === 0 || strlen($binario) > $maxBytes) {
        return [false, 'Imagem acima do tamanho permitido.'];
    }

    $info = @getimagesizefromstring($binario);
    if (!$info || empty($info['mime'])) {
        return [false, 'Imagem invalida.'];
    }

    $extensoes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensoes[$info['mime']])) {
        return [false, 'Formato de imagem nao permitido.'];
    }

    [$dir, $relBase] = resolverPastaUpload($subpasta);

    $prefixoSeguro = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefixo) ?: 'imagem';
    $nomeArquivo = $prefixoSeguro . '-' . bin2hex(random_bytes(12)) . '.' . $extensoes[$info['mime']];
    $destinoAbs = $dir . '/' . $nomeArquivo;
    $destinoRel = $relBase . $nomeArquivo;

    if (file_put_contents($destinoAbs, $binario, LOCK_EX) === false) {
        return [false, 'Nao foi possivel salvar a imagem.'];
    }

    return [true, $destinoRel];
}

// --- PROTEÇÃO CSRF (Cross-Site Request Forgery) ---
/**
 * Gera um token CSRF único por sessão
 */
function generate_csrf_token() {
    iniciarSessaoSegura();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifica se o token recebido é válido
 */
function verify_csrf_token($token) {
    iniciarSessaoSegura();
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    return true;
}
?>
