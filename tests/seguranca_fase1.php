<?php
/**
 * Testes de regressao das 4 vulnerabilidades criticas da auditoria (Fase 0).
 *
 *   S-01  cliente apagava agendamento de outra pessoa (?reagendar_id=)
 *   S-02  conteudo de avaliacao/cliente executava codigo no painel (XSS)
 *   S-03  pagina publica de agendamento expunha clientes, avaliacoes e logins
 *   S-04  reagendamento pelo cliente sem validacao
 *
 * COMO RODAR (a partir da pasta do projeto):
 *
 *     php tests/seguranca_fase1.php
 *
 * O teste NUNCA toca no banco real. Ele copia o codigo para uma pasta
 * temporaria, instala um banco SQLite novo pelo install.php, semeia dados
 * ficticios e sobe o servidor embutido do PHP numa porta livre.
 *
 * A parte de XSS (S-02) roda no navegador (Playwright + Chromium). Se o Node
 * ou o Playwright nao estiverem disponiveis, ela e marcada como PULADA -- e o
 * script sai com codigo 2 para deixar claro que a cobertura foi parcial.
 *
 * Sai com 0 se tudo passar, 1 se algo falhar, 2 se algo foi pulado.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Esta suite roda apenas por linha de comando.\n");
}

$raiz = dirname(__DIR__);
$falhas = 0;
$pulados = 0;

function ok($cond, $descricao) {
    global $falhas;
    echo ($cond ? "  [OK]    " : "  [FALHA] ") . $descricao . "\n";
    if (!$cond) {
        $falhas++;
    }
}

// ---------------------------------------------------------------------------
// 1. Copia do codigo para uma pasta temporaria
// ---------------------------------------------------------------------------
$tmp = sys_get_temp_dir() . '/barbearia_seg_' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$ignorar = ['.git', 'docs', 'novo-sistema', 'node_modules', '_dados', '_logs'];
$it = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
        function ($atual) use ($raiz, $ignorar) {
            $rel = substr($atual->getPathname(), strlen($raiz) + 1);
            return !in_array(explode(DIRECTORY_SEPARATOR, $rel)[0], $ignorar, true);
        }
    ),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $item) {
    $destino = $tmp . '/' . substr($item->getPathname(), strlen($raiz) + 1);
    $item->isDir() ? @mkdir($destino, 0775, true) : copy($item->getPathname(), $destino);
}
foreach (['_dados', '_logs'] as $d) {
    @mkdir("$tmp/$d", 0775, true);
}

// Roteador que imita o .htaccess (URL sem .php).
file_put_contents("$tmp/__router.php", <<<'PHP'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$f = __DIR__ . $p;
if ($p !== '/' && is_file($f)) { return false; }
if (is_file($f . '.php')) {
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $p . '.php';
    require $f . '.php';
    return true;
}
if ($p === '/') { require __DIR__ . '/index.php'; return true; }
http_response_code(404);
return true;
PHP);

// ---------------------------------------------------------------------------
// 2. Servidor embutido
// ---------------------------------------------------------------------------
$sock = stream_socket_server('tcp://127.0.0.1:0');
$porta = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$base = "http://127.0.0.1:$porta";
$servidor = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$porta", "$tmp/__router.php"],
    [0 => ['pipe', 'r'], 1 => ['file', "$tmp/_logs/servidor.log", 'a'], 2 => ['file', "$tmp/_logs/servidor.log", 'a']],
    $pipes,
    $tmp
);
register_shutdown_function(function () use ($servidor, $tmp) {
    if (is_resource($servidor)) {
        proc_terminate($servidor);
        proc_close($servidor);
    }
    // Remove a pasta temporaria.
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($tmp);
});
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $porta)) {
        break;
    }
    usleep(100000);
}

/** Cliente HTTP minimo com cookies proprios (cada "pessoa" tem o seu). */
class Navegador {
    private $jar;
    public $base;
    public function __construct($base) {
        $this->base = $base;
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }
    public function req($metodo, $caminho, array $dados = null) {
        $ch = curl_init($this->base . '/' . ltrim($caminho, '/'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($metodo === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($dados ?? []));
        }
        $corpo = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, (string) $corpo];
    }
    public function csrf($caminho) {
        [, $html] = $this->req('GET', $caminho);
        return preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
    }
    public function arquivoCookies() {
        return $this->jar;
    }
}

// ---------------------------------------------------------------------------
// 3. Instalacao e dados ficticios
// ---------------------------------------------------------------------------
$admin = new Navegador($base);
$admin->req('POST', 'install.php', [
    'action' => 'criar_admin', 'username' => 'admin', 'password' => 'Admin12345',
    'confirm_password' => 'Admin12345', 'nome_barbearia' => 'Barbearia Teste',
]);

$pdo = new PDO("sqlite:$tmp/_dados/database.sqlite");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$payloadXss = '<img src=x onerror="window.__xss=(window.__xss||0)+1">';
$dia = date('Y-m-d', strtotime('+3 days'));
$diaSeguinte = date('Y-m-d', strtotime('+4 days'));

$pdo->exec("INSERT INTO servicos (id, nome, valor, slots, categoria_id, descricao) VALUES ('sv-1', 'Corte', '45', '1', '', '')");
$pdo->prepare("INSERT INTO barbeiros (id, nome, foto, username, password, status, servicos_ids, comissao, comissao_produtos, meta_diaria)
               VALUES ('br-1', 'Carlos', '', 'login_secreto_barbeiro', ?, 'ativo', 'sv-1', '50', '0', '200')")
    ->execute([password_hash('Barbeiro123', PASSWORD_DEFAULT)]);
for ($d = 0; $d <= 6; $d++) {
    $pdo->exec("INSERT INTO horarios_trabalho (barbeiro_id, dia, inicio, fim, ativo) VALUES ('br-1', '$d', '09:00', '18:00', '1')");
}
$insCliente = $pdo->prepare("INSERT INTO clientes (id, nome, email, telefone, password_hash, data_nascimento, foto_perfil, codigo_indicacao, cpf, indicado_por_id, status, confirmation_token)
                             VALUES (?, ?, ?, ?, ?, '', '', ?, '', '', 'ativo', '')");
$insCliente->execute(['CL-AAAAAA', 'Ana Cliente', 'a@teste.local', '11911111111', password_hash('SenhaA123', PASSWORD_DEFAULT), 'CODA01']);
$insCliente->execute(['CL-BBBBBB', 'Bruno Privado Secreto', 'b@teste.local', '11922222222', password_hash('SenhaB123', PASSWORD_DEFAULT), 'CODB01']);
// Cliente cujo nome e um payload (cadastro publico so exige 2+ caracteres).
$insCliente->execute(['CL-XSSXSS', $payloadXss, 'x@teste.local', '11933333333', password_hash('SenhaX123', PASSWORD_DEFAULT), 'CODX01']);

$insAg = $pdo->prepare("INSERT INTO agendamentos (id, nome, email, telefone, barbeiro_id, servicos_ids, data, hora, status, desconto_aplicado, tipo_desconto, observacoes, produtos_vendidos, plano_provisorio, cliente_id, data_criacao)
                        VALUES (?, ?, ?, ?, 'br-1', 'sv-1', ?, ?, ?, '0', '', '', '', '', ?, ?)");
$agora = date('Y-m-d H:i:s');
$insAg->execute(['AG-VITIMA', 'Bruno Privado Secreto', 'b@teste.local', '11922222222', $dia, '11:00', 'aprovado', 'CL-BBBBBB', $agora]);
$insAg->execute(['AG-REAG', 'Bruno Privado Secreto', 'b@teste.local', '11922222222', $dia, '10:00', 'aprovado', 'CL-BBBBBB', $agora]);
$insAg->execute(['AG-OCUPADO', 'Ana Cliente', 'a@teste.local', '11911111111', $dia, '15:00', 'aprovado', 'CL-AAAAAA', $agora]);
$insAg->execute(['AG-CANCEL', 'Bruno Privado Secreto', 'b@teste.local', '11922222222', $diaSeguinte, '10:00', 'cancelado', 'CL-BBBBBB', $agora]);
$insAg->execute(['AG-PASSADO', $payloadXss, 'x@teste.local', '11933333333', date('Y-m-d', strtotime('-5 days')), '10:00', 'concluido', 'CL-XSSXSS', $agora]);
$pdo->prepare("INSERT INTO avaliacoes (id, agendamento_id, cliente_id, barbeiro_id, rating, comment, timestamp) VALUES ('av-1', 'AG-PASSADO', 'CL-XSSXSS', 'br-1', '5', ?, ?)")
    ->execute([$payloadXss, $agora]);

function agendamento(PDO $pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

function loginCliente(Navegador $nav, $email, $senha) {
    $csrf = $nav->csrf('login_cliente');
    [$status] = $nav->req('POST', 'login_cliente', [
        'csrf_token' => $csrf, 'identificador' => $email, 'senha' => $senha, 'redirect' => 'cliente',
    ]);
    return $status === 302;
}

// ---------------------------------------------------------------------------
// S-03 — pagina publica nao pode expor dados internos
// ---------------------------------------------------------------------------
echo "\nS-03  Exposicao publica em /agendamento\n";
$visitante = new Navegador($base);
[$st, $html] = $visitante->req('GET', 'agendamento');
ok($st === 200, 'pagina de agendamento abre para visitante');
ok(strpos($html, 'login_secreto_barbeiro') === false, 'nao expoe o username de login do barbeiro');
ok(strpos($html, 'Bruno Privado Secreto') === false, 'nao expoe nomes de clientes');
ok(strpos($html, 'CL-BBBBBB') === false && strpos($html, 'CL-XSSXSS') === false, 'nao expoe IDs de clientes');
ok(strpos($html, 'AG-PASSADO') === false, 'nao expoe IDs de agendamentos (usados em S-01)');

// ---------------------------------------------------------------------------
// S-01 — cliente nao pode apagar agendamento alheio via ?reagendar_id=
// ---------------------------------------------------------------------------
echo "\nS-01  Exclusao de agendamento alheio\n";
$ana = new Navegador($base);
ok(loginCliente($ana, 'a@teste.local', 'SenhaA123'), 'cliente A faz login');
$ana->req('GET', 'agendamento?reagendar_id=AG-VITIMA');
$csrf = $ana->csrf('agendamento');
$ana->req('POST', 'agendamento', [
    'csrf_token' => $csrf, 'nome' => 'Ana Cliente', 'email' => 'a@teste.local', 'telefone' => '11911111111',
    'barbeiro' => 'br-1', 'servicos' => 'sv-1', 'data' => $diaSeguinte, 'horario' => '14:00',
]);
ok(agendamento($pdo, 'AG-VITIMA') !== false, 'agendamento do cliente B continua existindo');
$novo = $pdo->query("SELECT COUNT(*) FROM agendamentos WHERE cliente_id = 'CL-AAAAAA' AND data = " . $pdo->quote($diaSeguinte) . " AND hora = '14:00'")->fetchColumn();
ok((int) $novo === 1, 'o agendamento legitimo da cliente A continua sendo criado (sem regressao)');

// ---------------------------------------------------------------------------
// S-04 — reagendamento pelo cliente precisa ser validado
// ---------------------------------------------------------------------------
echo "\nS-04  Reagendamento pelo cliente\n";
$bruno = new Navegador($base);
ok(loginCliente($bruno, 'b@teste.local', 'SenhaB123'), 'cliente B faz login');

function reagendar(Navegador $nav, $id, $data, $hora) {
    $csrf = $nav->csrf('cliente');
    [, $corpo] = $nav->req('POST', 'cliente', [
        'csrf_token' => $csrf, 'action' => 'reagendar_agendamento', 'is_ajax' => '1',
        'reagendar_agendamento_id' => $id, 'reagendar_data' => $data, 'reagendar_horario' => $hora,
    ]);
    $json = json_decode($corpo, true);
    return is_array($json) ? $json : ['status' => 'invalido', 'message' => substr($corpo, 0, 200)];
}

$casos = [
    ['horario com conteudo arbitrario', 'AG-REAG', $dia, '<b>x</b>'],
    ['horario fora do expediente (03:00)', 'AG-REAG', $dia, '03:00'],
    ['horario ja ocupado por outro cliente', 'AG-REAG', $dia, '15:00'],
    ['data no passado', 'AG-REAG', '2000-01-01', '10:00'],
    ['data invalida', 'AG-REAG', '2026-02-31', '10:00'],
    ['horario fora da grade de 30 min', 'AG-REAG', $dia, '13:10'],
];
foreach ($casos as [$descricao, $id, $data, $hora]) {
    $r = reagendar($bruno, $id, $data, $hora);
    $ag = agendamento($pdo, 'AG-REAG');
    ok(($r['status'] ?? '') === 'error' && $ag['data'] === $dia && $ag['hora'] === '10:00', "recusa $descricao");
}

$r = reagendar($bruno, 'AG-CANCEL', $diaSeguinte, '12:00');
$ag = agendamento($pdo, 'AG-CANCEL');
ok(($r['status'] ?? '') === 'error' && $ag['status'] === 'cancelado' && $ag['hora'] === '10:00', 'recusa reativar agendamento cancelado');

$r = reagendar($ana, 'AG-REAG', $dia, '13:00');
ok(($r['status'] ?? '') === 'error' && agendamento($pdo, 'AG-REAG')['hora'] === '10:00', 'cliente A nao reagenda agendamento do cliente B (IDOR)');

$r = reagendar($bruno, 'AG-REAG', $dia, '13:00');
$ag = agendamento($pdo, 'AG-REAG');
ok(($r['status'] ?? '') === 'success' && $ag['hora'] === '13:00' && $ag['status'] === 'aprovado', 'aceita reagendamento valido (sem regressao)');

$r = reagendar($bruno, 'AG-REAG', $dia, '13:00');
ok(($r['status'] ?? '') === 'success', 'aceita manter o proprio horario (nao conflita consigo mesmo)');

// ---------------------------------------------------------------------------
// S-02 — XSS no navegador (painel admin + pagina publica)
// ---------------------------------------------------------------------------
echo "\nS-02  Conteudo de avaliacao/cliente no navegador\n";
$csrfAdmin = $admin->csrf('login.php');
[$stLogin] = $admin->req('POST', 'login.php', [
    'csrf_token' => $csrfAdmin, 'login_type' => 'admin', 'username' => 'admin', 'password' => 'Admin12345',
]);
ok($stLogin === 302, 'admin faz login');

$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    echo "  [PULADO] Node.js nao encontrado; o teste de navegador nao rodou.\n";
    $pulados++;
} else {
    $cookieHeader = [];
    foreach (file($admin->arquivoCookies()) as $linha) {
        $partes = explode("\t", trim($linha));
        if (count($partes) === 7) {
            $cookieHeader[] = ['name' => $partes[5], 'value' => $partes[6]];
        }
    }
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/seguranca_xss_navegador.mjs')
        . ' ' . escapeshellarg($base) . ' ' . escapeshellarg(json_encode($cookieHeader)) . ' 2>&1';
    $saida = shell_exec($cmd);
    $res = json_decode(trim((string) $saida), true);
    if (!is_array($res)) {
        echo "  [PULADO] Playwright indisponivel: " . trim(substr((string) $saida, 0, 300)) . "\n";
        $pulados++;
    } else {
        foreach ($res as $item) {
            ok(!empty($item['ok']), $item['descricao'] . (empty($item['ok']) ? ' — ' . ($item['detalhe'] ?? '') : ''));
        }
    }
}

echo "\n" . ($falhas ? "$falhas falha(s)." : "Todos os testes executados passaram.") . ($pulados ? " $pulados bloco(s) pulado(s)." : '') . "\n";
exit($falhas ? 1 : ($pulados ? 2 : 0));
