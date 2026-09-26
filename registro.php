<?php
require_once 'functions.php';
iniciarSessaoSegura();

$configGeral = carregarConfigGeral();
$configIndicacao = getIndicacaoConfig();
$csrf = gerarTokenCsrf();

$mensagem = '';
$sucesso = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Guarda os valores BRUTOS (escapados apenas na saída, evita double-encoding no banco)
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefone = limparTelefone($_POST['telefone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $data_nascimento = $_POST['data_nascimento'] ?? '';
    $cpf = limparTelefone($_POST['cpf'] ?? '');
    $codigo_indicacao_usado = trim(strtoupper($_POST['codigo_indicacao'] ?? ''));

    // Sistema anti-bot nativo
    $honeypot = $_POST['website_url'] ?? '';
    $tempo_inicio = $_SESSION['form_start_time'] ?? 0;
    $tempo_preenchimento = time() - $tempo_inicio;

    if (!validarTokenCsrf($_POST['csrf_token'] ?? '')) {
        $mensagem = "Sessão inválida ou expirada. Recarregue a página e tente novamente.";
    } elseif (!empty($honeypot)) {
        $mensagem = "Comportamento automatizado suspeito detectado (Cod: 101).";
    } elseif ($tempo_preenchimento < 4 && $tempo_inicio > 0) {
        $mensagem = "Preenchimento muito rápido. Por favor, tente novamente devagar.";
    } elseif (($bloqueioCadastro = cadastroBloqueadoAte()) > 0) {
        // Honeypot e time-trap seguram bot ingenuo; isto segura script paciente.
        // Conta cadastros CONCLUIDOS por IP, entao errar o formulario nao pune.
        $minutos = max(1, ceil(($bloqueioCadastro - time()) / 60));
        $mensagem = "Muitos cadastros a partir desta conexão. Tente novamente em {$minutos} minuto(s).";
    } elseif ($errosCadastro = validarDadosCadastroCliente([
        'nome'              => $nome,
        'email'             => $email,
        'telefone'          => $telefone,
        'cpf'               => $cpf,
        'data_nascimento'   => $data_nascimento,
        'senha'             => $password,
        'senha_confirmacao' => $confirm_password,
    ], ['nome', 'email', 'telefone', 'cpf', 'data_nascimento', 'senha'])) {
        // O "required" do formulário é só do navegador; um POST direto passava
        // por cima e criava cliente com nome vazio, e-mail inválido e telefone
        // em branco. A validação abaixo é a mesma dos outros caminhos de cadastro.
        $mensagem = implode(' ', $errosCadastro);
    } elseif (getClientePorEmailTelefoneOuCPF($email)) {
        $mensagem = "Este e-mail já está cadastrado.";
    } elseif (getClientePorEmailTelefoneOuCPF($telefone)) {
        $mensagem = "Este telefone já está cadastrado.";
    } elseif (getClientePorEmailTelefoneOuCPF($cpf)) {
        $mensagem = "Este CPF já está cadastrado.";
    } else {
        $cliente_que_indicou = null;
        if (($configIndicacao['ativado'] ?? 0) && !empty($codigo_indicacao_usado)) {
            $cliente_que_indicou = getClientByReferralCode($codigo_indicacao_usado);
            if (!$cliente_que_indicou) {
                $mensagem = "Código de indicação inválido.";
            }
        }

        if (empty($mensagem)) {
            $pdo = getDB();
            $id = gerarIdClienteUnico($pdo); // confere colisao antes de inserir
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            // Codigo de indicacao entra vazio e e gravado logo apos o INSERT por
            // gerarCodigoIndicacaoUnico(), que garante que ninguem mais tem o mesmo.
            $meu_codigo_indicacao = '';
            $foto_perfil = 'uploads/default-profile.jpg';
            $status = 'inativo';
            list($confirmation_token, $confirmation_expira_em) = gerarTokenConfirmacao();
            $id_do_indicador = $cliente_que_indicou ? $cliente_que_indicou['id'] : '';

            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (id TEXT PRIMARY KEY, nome TEXT, email TEXT, telefone TEXT, password_hash TEXT, data_nascimento TEXT, foto_perfil TEXT, codigo_indicacao TEXT, cpf TEXT, indicado_por_id TEXT, status TEXT, confirmation_token TEXT)");

                $stmtIns = $pdo->prepare("INSERT INTO clientes (id, nome, email, telefone, password_hash, data_nascimento, foto_perfil, codigo_indicacao, cpf, indicado_por_id, status, confirmation_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtIns->execute([$id, $nome, $email, $telefone, $password_hash, $data_nascimento, $foto_perfil, $meu_codigo_indicacao, $cpf, $id_do_indicador, $status, $confirmation_token]);

                // Codigo unico gravado apos o INSERT: a funcao recusa um codigo que
                // ja exista, porque getClientByReferralCode() busca por ele e um
                // duplicado creditaria a indicacao a pessoa errada.
                gerarCodigoIndicacaoUnico($pdo, $id);

                // Prazo do token gravado a parte: num banco recem-criado a coluna
                // pode ainda nao existir (a migration roda antes do CREATE TABLE
                // deste arquivo). Sem a coluna, o cadastro segue -- so fica sem prazo.
                try {
                    $pdo->prepare("UPDATE clientes SET confirmation_expira_em = ? WHERE id = ?")
                        ->execute([$confirmation_expira_em, $id]);
                } catch (Exception $e) { /* banco antigo, sem a coluna */ }

                // So conta para o limite por IP quando a conta foi mesmo criada.
                registrarCadastroCriado();

                $dados_email = [
                    'nome_cliente' => $nome,
                    'link_confirmacao' => BASE_URL . "confirmar_email?token=$confirmation_token"
                ];
                enviarEmail($email, 'Confirme seu Cadastro', 'confirmar_cadastro', $dados_email);

                $sucesso = true;
                $mensagem = "Cadastro realizado com sucesso! Um e-mail de confirmação foi enviado para {$email}. Verifique sua caixa de entrada (e spam).";
            } catch (PDOException $e) {
                // Com os indices unicos, dois cadastros simultaneos com o mesmo
                // e-mail/telefone/CPF fazem o segundo estourar aqui -- e a corrida
                // que a checagem em PHP nao consegue fechar sozinha. Melhor dizer o
                // que houve do que um erro generico.
                if (stripos($e->getMessage(), 'UNIQUE') !== false) {
                    $mensagem = "Estes dados já foram cadastrados. Se a conta é sua, use 'Entrar' ou recupere a senha.";
                } else {
                    $mensagem = "Ocorreu um erro ao processar seu cadastro. Tente novamente mais tarde.";
                }
                if (function_exists('log_activity')) { log_activity("Erro no registro SQLite: " . $e->getMessage()); }
            }
        }
    }
}

// Inicia o cronômetro do Time-Trap
$_SESSION['form_start_time'] = time();
$indicacaoAtiva = (bool)($configIndicacao['ativado'] ?? 0);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/pwa_head.php'; ?>
    <title>Cadastro - <?= htmlspecialchars($configGeral['nome_barbearia']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= assetUrl('css/style.css') ?>">
    <link rel="stylesheet" href="css/custom_theme.css.php">
    <link rel="stylesheet" href="<?= assetUrl('css/auth.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-body">

<?php $pageTitle = 'Cadastro'; require_once 'header_app.php'; ?>

<div class="auth-shell">
    <aside class="auth-aside">
        <div class="auth-aside-content">
            <div class="auth-badge"><i class="fa fa-star"></i></div>
            <h1>Faça parte do clube!</h1>
            <p class="auth-aside-lead">Crie sua conta e desbloqueie uma experiência de agendamento completa.</p>
            <ul class="auth-features">
                <li><i class="fa fa-bolt"></i> Agendamento rápido e sem filas</li>
                <li><i class="fa fa-gift"></i> Programa de fidelidade e indicações</li>
                <li><i class="fa fa-bell"></i> Lembretes automáticos dos horários</li>
            </ul>
        </div>
    </aside>

    <main class="auth-main is-wide">
        <div class="auth-card is-wide">
            <div class="auth-head">
                <h2>Criar Nova Conta</h2>
                <p>Preencha os dados abaixo para se cadastrar.</p>
            </div>

            <?php if ($mensagem): ?>
                <div class="auth-alert <?= $sucesso ? 'is-success' : 'is-error' ?>" role="alert">
                    <i class="fa <?= $sucesso ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
                    <span><?= htmlspecialchars($mensagem) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($sucesso): ?>
                <a href="login_cliente" class="auth-btn" style="text-decoration:none;">
                    <i class="fa fa-right-to-bracket"></i> Ir para o Login
                </a>
            <?php else: ?>
            <form method="POST" data-spinner>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                <div class="auth-hp" aria-hidden="true">
                    <label for="website_url">Não preencha este campo</label>
                    <input type="text" name="website_url" id="website_url" tabindex="-1" autocomplete="off">
                </div>

                <div class="auth-grid">
                    <div class="auth-field full">
                        <label class="auth-label" for="nome">Nome e Sobrenome</label>
                        <div class="auth-input-wrap">
                            <input class="auth-input" type="text" name="nome" id="nome" required
                                   autocomplete="name" placeholder="Como devemos te chamar?"
                                   value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>">
                            <i class="fa fa-user auth-ficon"></i>
                        </div>
                    </div>

                    <div class="auth-field full">
                        <label class="auth-label" for="email">E-mail</label>
                        <div class="auth-input-wrap">
                            <input class="auth-input" type="email" name="email" id="email" required
                                   autocomplete="email" placeholder="seu-email@dominio.com"
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            <i class="fa fa-envelope auth-ficon"></i>
                        </div>
                    </div>

                    <div class="auth-field">
                        <label class="auth-label" for="cpf">CPF</label>
                        <div class="auth-input-wrap">
                            <input class="auth-input" type="text" name="cpf" id="cpf" required
                                   inputmode="numeric" data-mask="cpf" placeholder="000.000.000-00"
                                   value="<?= htmlspecialchars($_POST['cpf'] ?? '') ?>">
                            <i class="fa fa-id-card auth-ficon"></i>
                        </div>
                    </div>

                    <div class="auth-field">
                        <label class="auth-label" for="telefone">WhatsApp / Celular</label>
                        <div class="auth-input-wrap">
                            <input class="auth-input" type="tel" name="telefone" id="telefone" required
                                   autocomplete="tel" inputmode="numeric" data-mask="telefone" placeholder="(99) 99999-9999"
                                   value="<?= htmlspecialchars($_POST['telefone'] ?? '') ?>">
                            <i class="fa fa-phone auth-ficon"></i>
                        </div>
                    </div>

                    <div class="auth-field <?= $indicacaoAtiva ? '' : 'full' ?>">
                        <label class="auth-label" for="data_nascimento">Data de Nascimento</label>
                        <div class="auth-input-wrap">
                            <input class="auth-input" type="date" name="data_nascimento" id="data_nascimento" required
                                   value="<?= htmlspecialchars($_POST['data_nascimento'] ?? '') ?>">
                            <i class="fa fa-calendar-days auth-ficon"></i>
                        </div>
                    </div>

                    <?php if ($indicacaoAtiva): ?>
                    <div class="auth-field">
                        <label class="auth-label" for="codigo_indicacao">Código Convite (Opcional)</label>
                        <div class="auth-input-wrap">
                            <input class="auth-input" type="text" name="codigo_indicacao" id="codigo_indicacao"
                                   placeholder="Ex: A1B2C3" style="text-transform:uppercase;"
                                   value="<?= htmlspecialchars($_POST['codigo_indicacao'] ?? $_GET['ref'] ?? '') ?>">
                            <i class="fa fa-gift auth-ficon"></i>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="auth-field">
                        <label class="auth-label" for="password">Senha</label>
                        <div class="auth-input-wrap">
                            <input class="auth-input has-toggle" type="password" name="password" id="password"
                                   minlength="8" required autocomplete="new-password" placeholder="Crie uma senha">
                            <i class="fa fa-lock auth-ficon"></i>
                            <button type="button" class="auth-toggle" data-toggle-password="password" aria-label="Mostrar senha">
                                <i class="fa fa-eye"></i>
                            </button>
                        </div>
                        <div class="auth-strength" data-strength="password" data-score="0">
                            <div class="auth-strength-bars"><span></span><span></span><span></span><span></span></div>
                            <div class="auth-strength-label">Força: <b>—</b></div>
                        </div>
                    </div>

                    <div class="auth-field">
                        <label class="auth-label" for="confirm_password">Confirmar Senha</label>
                        <div class="auth-input-wrap">
                            <input class="auth-input has-toggle" type="password" name="confirm_password" id="confirm_password"
                                   required autocomplete="new-password" placeholder="Repita a senha"
                                   data-match="password" data-match-hint="matchHint">
                            <i class="fa fa-circle-check auth-ficon"></i>
                            <button type="button" class="auth-toggle" data-toggle-password="confirm_password" aria-label="Mostrar senha">
                                <i class="fa fa-eye"></i>
                            </button>
                        </div>
                        <div class="auth-hint" id="matchHint"></div>
                    </div>
                </div>

                <button type="submit" class="auth-btn" style="margin-top:22px;" data-loading-text="Cadastrando...">
                    <i class="fa fa-user-plus"></i> Finalizar Cadastro
                </button>
            </form>
            <?php endif; ?>

            <div class="auth-foot">
                Já tem uma conta? <a href="login_cliente" class="auth-link">Faça login aqui</a>
            </div>
        </div>
    </main>
</div>

<script src="<?= assetUrl('js/auth.js') ?>"></script>
<?php include 'chatbot_widget.php'; ?>
</body>
</html>
