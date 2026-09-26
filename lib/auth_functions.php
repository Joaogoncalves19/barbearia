<?php
// barbearia/lib/auth_functions.php
// Contém funções de autenticação e gerenciamento de usuários.

/**
 * Busca um barbeiro pelo seu username.
 */
function getBarbeiroByUsername($username) {
    // Definimos as chaves corretas que o sistema usa no banco (password ao invés de password_hash)
    $keys = ['id', 'nome', 'foto', 'username', 'password', 'status', 'servicos_ids', 'comissao', 'comissao_produtos'];
    $barbeirosArr = lerDados('barbeiros', $keys);
    
    foreach ($barbeirosArr as $barbeiro) {
        if (isset($barbeiro['username']) && $barbeiro['username'] === $username) {
            return $barbeiro;
        }
    }
    return null;
}

/**
 * Busca um cliente pelo e-mail, telefone ou CPF.
 *
 * Antes isto lia a tabela inteira e comparava em PHP. Duas consequências:
 *  - varredura completa a cada chamada (registro, esqueci-senha, webhook Stripe);
 *  - a comparação de telefone não tinha guarda de vazio, então buscar por um
 *    e-mail inexistente (limparTelefone('a@b.com') === '') casava com QUALQUER
 *    cliente de telefone vazio e devolvia a pessoa errada. O ramo de CPF já
 *    tinha essa guarda; o de telefone não.
 *
 * A consulta abaixo mantém a mesma precedência do laço original (ordem da
 * tabela, e-mail > telefone > CPF dentro da mesma linha) via ORDER BY rowid.
 * A comparação de telefone é exata porque TODO caminho de escrita do sistema
 * grava o telefone já normalizado por limparTelefone() — e a migration
 * normaliza linhas antigas que porventura tenham máscara.
 */
function getClientePorEmailTelefoneOuCPF($identificador) {
    $keys = [
        'id', 'nome', 'email', 'telefone', 'password_hash', 
        'data_nascimento', 'foto_perfil', 'codigo_indicacao', 'cpf', 
        'indicado_por_id', 'status', 'confirmation_token'
    ];

    $identificador = (string) $identificador;
    $digitos = limparTelefone($identificador);

    if ($identificador === '' && $digitos === '') {
        return null;
    }

    try {
        // E-mail comparado sem diferenciar maiuscula/minuscula: o cadastro grava
        // o que o usuario digitou (so com trim), entao 'Ana@Ex.com' e 'ana@ex.com'
        // precisam ser a mesma pessoa -- tanto para entrar quanto para barrar
        // cadastro duplicado. O indice idx_clientes_email_lower e por expressao,
        // entao LOWER(email) continua indexado.
        $stmt = getDB()->prepare(
            "SELECT " . implode(', ', $keys) . "
               FROM clientes
              WHERE (? <> '' AND LOWER(email) = LOWER(?))
                 OR (? <> '' AND telefone = ?)
                 OR (? <> '' AND cpf = ?)
              ORDER BY rowid
              LIMIT 1"
        );
        $stmt->execute([
            $identificador, $identificador,
            $digitos, $digitos,
            $digitos, $digitos,
        ]);
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        if (function_exists('log_activity')) {
            log_activity('AVISO: falha ao buscar cliente por identificador: ' . $e->getMessage());
        }
        return null;
    }

    if (!$cliente) {
        return null;
    }

    // Mesmo contrato do lerDados(): toda chave presente, default ''.
    foreach ($keys as $k) {
        if (!isset($cliente[$k])) {
            $cliente[$k] = '';
        }
    }

    return $cliente;
}

/**
 * Gera e grava um código de indicação único para o cliente.
 *
 * O UPDATE é atômico e resolve dois problemas de uma vez:
 *  - unicidade: o NOT EXISTS descarta um código já usado por outro cliente
 *    (getClientByReferralCode() busca por esse código; um duplicado creditaria
 *    a indicação à pessoa errada);
 *  - concorrência: a condição "ainda está vazio" faz a segunda requisição
 *    simultânea não sobrescrever o código que a primeira acabou de gravar.
 *
 * @return string O código gravado, ou o que já existir se outra requisição
 *                venceu a corrida. String vazia se não foi possível gerar.
 */
function gerarCodigoIndicacaoUnico(PDO $pdo, $cliente_id) {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    for ($tentativa = 0; $tentativa < 10; $tentativa++) {
        $codigo = strtoupper(substr(str_shuffle($alfabeto), 0, 6));

        try {
            $stmt = $pdo->prepare(
                "UPDATE clientes SET codigo_indicacao = ?
                  WHERE id = ?
                    AND (codigo_indicacao IS NULL OR codigo_indicacao = '')
                    AND NOT EXISTS (SELECT 1 FROM clientes WHERE codigo_indicacao = ?)"
            );
            $stmt->execute([$codigo, $cliente_id, $codigo]);

            if ($stmt->rowCount() > 0) {
                return $codigo;
            }

            // Nenhuma linha afetada: ou o código já existia (colisão, tenta de
            // novo), ou outra requisição gravou primeiro (aí adotamos o dela).
            $jaGravado = $pdo->prepare("SELECT codigo_indicacao FROM clientes WHERE id = ?");
            $jaGravado->execute([$cliente_id]);
            $atual = (string) $jaGravado->fetchColumn();
            if ($atual !== '') {
                return $atual;
            }
        } catch (Exception $e) {
            if (function_exists('log_activity')) {
                log_activity('Falha ao gerar codigo de indicacao para ' . $cliente_id . ': ' . $e->getMessage());
            }
            return '';
        }
    }

    return '';
}

/**
 * Busca um cliente pelo seu ID e gerencia o código de indicação.
 */
function getClientById($cliente_id) {
    $keys_clientes_completo = [
        'id', 'nome', 'email', 'telefone', 'password_hash', 
        'data_nascimento', 'foto_perfil', 'codigo_indicacao', 'cpf', 
        'indicado_por_id', 'status', 'confirmation_token'
    ];

    $pdo = getDB();

    try {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $keys_clientes_completo) . "
               FROM clientes WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$cliente_id]);
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Mesma tolerância do lerDados(): coluna/tabela ausente não derruba a página.
        if (function_exists('log_activity')) {
            log_activity('AVISO: falha ao buscar cliente ' . $cliente_id . ': ' . $e->getMessage());
        }
        return null;
    }

    if (!$cliente) {
        return null;
    }

    // O lerDados() garantia que TODA chave existisse (default ''). Como agora a
    // consulta é direta, replicamos isso para não quebrar quem lê as chaves sem
    // isset() — o contrato de retorno segue idêntico ao de antes.
    foreach ($keys_clientes_completo as $k) {
        if (!isset($cliente[$k])) {
            $cliente[$k] = '';
        }
    }

    if (empty($cliente['codigo_indicacao'])) {
        $cliente['codigo_indicacao'] = gerarCodigoIndicacaoUnico($pdo, $cliente_id);
    }

    if (empty($cliente['status'])) {
        $cliente['status'] = 'ativo';
    }

    return $cliente;
}

/**
 * Exclui permanentemente a conta do cliente (LGPD / direito ao esquecimento).
 *
 * Estratégia: remove todos os dados pessoais do cliente, mas anonimiza os
 * agendamentos (preservando os registros financeiros da barbearia sem vínculo
 * pessoal) e mantém as avaliações (úteis à média dos profissionais) também
 * anonimizadas. Cada operação é isolada em try/catch para que uma tabela
 * ausente nunca impeça a exclusão da conta em si.
 *
 * @return array [bool $ok, string $mensagem]
 */
function excluirContaClientePermanente($cliente_id) {
    $cliente = getClientById($cliente_id);
    if (!$cliente) {
        return [false, 'Conta não encontrada.'];
    }

    $pdo = getDB();
    $email = strtolower(trim($cliente['email'] ?? ''));

    // 1. Anonimiza agendamentos: mantém histórico financeiro, remove dados pessoais.
    try {
        $pdo->prepare("UPDATE agendamentos
            SET nome = 'Conta excluída', telefone = '', email = '', cliente_id = ''
            WHERE cliente_id = ?")->execute([$cliente_id]);
    } catch (Exception $e) { log_activity('Exclusao conta - agendamentos: ' . $e->getMessage()); }

    // 2. Anonimiza avaliações (preserva nota/comentário para a média do barbeiro).
    try {
        $pdo->prepare("UPDATE avaliacoes SET cliente_id = '' WHERE cliente_id = ?")
            ->execute([$cliente_id]);
    } catch (Exception $e) { /* silencioso */ }

    // 3. Remove dados vinculados exclusivamente ao cliente.
    foreach ([
        "DELETE FROM clientes_assinaturas WHERE cliente_id = ?",
        "DELETE FROM fidelidade_historico WHERE cliente_id = ?",
        "DELETE FROM fidelidade WHERE id = ?",
        "DELETE FROM notificacoes WHERE cliente_id = ?",
        "DELETE FROM barbeiros_favoritos WHERE cliente_id = ?",
        "DELETE FROM anotacoes_clientes WHERE id = ?",
        "UPDATE clientes SET indicado_por_id = '' WHERE indicado_por_id = ?",
    ] as $sql) {
        try { $pdo->prepare($sql)->execute([$cliente_id]); }
        catch (Exception $e) { /* tabela pode não existir; ignora */ }
    }

    // 4. Revoga sessões persistentes (cookies "lembrar").
    try { revogarTokensCliente($cliente_id); } catch (Exception $e) {}

    // 5. Opt-out de marketing para o e-mail (não recebe mais nada).
    if ($email !== '' && function_exists('marketingRegistrarOptout')) {
        // Passa o ID junto: o opt-out fica amarrado ao cliente, nao so ao e-mail.
        try { marketingRegistrarOptout($email, $cliente_id); } catch (Exception $e) {}
    }

    // 6. Remove o registro do cliente.
    try {
        $pdo->prepare("DELETE FROM clientes WHERE id = ?")->execute([$cliente_id]);
    } catch (Exception $e) {
        log_activity('Exclusao conta - clientes: ' . $e->getMessage());
        return [false, 'Não foi possível excluir a conta. Tente novamente.'];
    }

    log_activity("Conta de cliente excluída permanentemente: {$cliente_id}");
    return [true, 'Conta excluída permanentemente.'];
}

/**
 * Busca um cliente pelo seu código de indicação.
 * Consulta direta (era varredura da tabela inteira); o índice
 * idx_clientes_codigo_indicacao cobre tanto esta busca quanto a checagem de
 * unicidade feita em gerarCodigoIndicacaoUnico().
 */
function getClientByReferralCode($code) {
    $code = (string) $code;
    if ($code === '') {
        return null;
    }

    try {
        $stmt = getDB()->prepare("SELECT id FROM clientes WHERE codigo_indicacao = ? ORDER BY rowid LIMIT 1");
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
    } catch (Exception $e) {
        if (function_exists('log_activity')) {
            log_activity('AVISO: falha ao buscar cliente por codigo de indicacao: ' . $e->getMessage());
        }
        return null;
    }

    return $id === false ? null : getClientById($id);
}
/**
 * Devolve o cliente logado lendo o BANCO pelo ID da sessao, e realinha as
 * copias que a sessao guarda (nome, e-mail, telefone).
 *
 * A sessao grava esses tres campos no momento do login. Se o admin editar a
 * ficha depois, eles ficam velhos ate o proximo login -- e como algumas partes
 * do sistema comparam por telefone (agendamento feito sem cadastro) ou montam
 * consulta com o e-mail, o dado velho leva a decisao errada em silencio.
 *
 * O ID nao muda, entao ele e a unica coisa da sessao em que se pode confiar:
 * tudo mais e derivado dele a cada requisicao. O resultado fica em cache por
 * requisicao, entao chamar varias vezes custa uma consulta so.
 *
 * @return array|null Ficha atual do cliente, ou null se nao ha login valido.
 */
function clienteDaSessao() {
    // Cache POR ID, nao um static simples: assim login, logout ou troca de
    // conta dentro da mesma requisicao nao devolvem a ficha anterior.
    static $cache = [];

    $id = $_SESSION['cliente_id'] ?? '';
    if (empty($_SESSION['cliente_logado']) || $id === '') {
        return null;
    }

    if (array_key_exists($id, $cache)) {
        return $cache[$id] ?: null;
    }

    $cliente = function_exists('getClientById') ? getClientById($id) : null;
    if (!$cliente) {
        // Conta apagada ou banco indisponivel: nao derruba a pagina, so nao
        // devolve ficha. Quem chama decide o que fazer.
        $cache[$id] = false;
        return null;
    }

    $_SESSION['cliente_nome']     = $cliente['nome'];
    $_SESSION['cliente_email']    = $cliente['email'];
    $_SESSION['cliente_telefone'] = $cliente['telefone'];

    $cache[$id] = $cliente;
    return $cliente;
}

/**
 * Telefone atual do cliente logado, ja normalizado (so digitos).
 * Usar isto em vez de $_SESSION['cliente_telefone'] em qualquer comparacao.
 *
 * @return string Vazio quando nao ha login ou o cadastro nao tem telefone.
 */
function telefoneClienteDaSessao() {
    $c = clienteDaSessao();
    if (!$c) {
        return '';
    }
    return function_exists('limparTelefone') ? limparTelefone($c['telefone']) : (string) $c['telefone'];
}

/**
 * Valida os dados de um cadastro de cliente, um criterio unico para os tres
 * caminhos que criam cliente (formulario publico, painel do admin e chatbot).
 *
 * Antes cada caminho tinha regra propria: o chatbot validava tudo, o formulario
 * publico validava so CPF e senha (o "required" do HTML e do navegador, e um
 * POST direto passa por cima), e o painel do admin nao validava nada. Foi assim
 * que nasceram clientes com nome vazio, e-mail invalido e telefone em branco --
 * e telefone em branco era justamente o que fazia buscas casarem com a pessoa
 * errada.
 *
 * @param array $dados  ['nome','email','telefone','cpf','data_nascimento','senha','senha_confirmacao']
 * @param array $exigir Campos obrigatorios. Os demais so sao validados quando vierem preenchidos.
 * @return array Mensagens de erro; array vazio significa tudo certo.
 */
function validarDadosCadastroCliente(array $dados, array $exigir = ['nome', 'email', 'telefone']) {
    $erros = [];

    $nome      = trim((string) ($dados['nome'] ?? ''));
    $email     = trim((string) ($dados['email'] ?? ''));
    $telefone  = function_exists('limparTelefone') ? limparTelefone($dados['telefone'] ?? '') : (string) ($dados['telefone'] ?? '');
    $cpf       = function_exists('limparTelefone') ? limparTelefone($dados['cpf'] ?? '') : (string) ($dados['cpf'] ?? '');
    $nasc      = trim((string) ($dados['data_nascimento'] ?? ''));
    $senha     = (string) ($dados['senha'] ?? '');
    $senha2    = array_key_exists('senha_confirmacao', $dados) ? (string) $dados['senha_confirmacao'] : null;

    $obrigatorio = function ($campo) use ($exigir) {
        return in_array($campo, $exigir, true);
    };

    // --- nome ---
    if ($nome === '') {
        if ($obrigatorio('nome')) { $erros[] = 'O nome é obrigatório.'; }
    } elseif (mb_strlen($nome) < 2) {
        $erros[] = 'O nome está muito curto.';
    }

    // --- e-mail ---
    if ($email === '') {
        if ($obrigatorio('email')) { $erros[] = 'O e-mail é obrigatório.'; }
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'O e-mail informado não é válido.';
    }

    // --- telefone (so digitos; 10 = fixo com DDD, 11 = celular) ---
    if ($telefone === '') {
        if ($obrigatorio('telefone')) { $erros[] = 'O telefone é obrigatório.'; }
    } elseif (strlen($telefone) < 10 || strlen($telefone) > 11) {
        $erros[] = 'O telefone deve ter DDD + número (10 ou 11 dígitos).';
    }

    // --- CPF ---
    if ($cpf === '') {
        if ($obrigatorio('cpf')) { $erros[] = 'O CPF é obrigatório.'; }
    } elseif (!function_exists('validarCPF') || !validarCPF($cpf)) {
        $erros[] = 'CPF inválido.';
    }

    // --- data de nascimento ---
    if ($nasc === '') {
        if ($obrigatorio('data_nascimento')) { $erros[] = 'A data de nascimento é obrigatória.'; }
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nasc) || strtotime($nasc) === false) {
        $erros[] = 'Data de nascimento inválida.';
    } elseif (strtotime($nasc) > time()) {
        $erros[] = 'A data de nascimento não pode estar no futuro.';
    }

    // --- senha ---
    if ($senha === '') {
        if ($obrigatorio('senha')) { $erros[] = 'A senha é obrigatória.'; }
    } else {
        if ($senha2 !== null && $senha !== $senha2) {
            $erros[] = 'As senhas não coincidem.';
        }
        if (function_exists('senhaAtendePolitica') && !senhaAtendePolitica($senha)) {
            $erros[] = function_exists('mensagemPoliticaSenha') ? mensagemPoliticaSenha() : 'A senha não atende à política de segurança.';
        }
    }

    return $erros;
}

/**
 * Gera um ID de cliente ('CL-' + 6 caracteres) que ainda nao existe no banco.
 *
 * Sao ~1,4 bilhao de combinacoes, entao colisao e rara -- mas antes ela virava
 * uma violacao de chave primaria no INSERT, e o usuario recebia um "erro ao
 * processar seu cadastro" com o cadastro perdido. Aqui a colisao so custa mais
 * uma volta do laco.
 *
 * @return string ID livre; em ultimo caso acrescenta entropia para nao falhar.
 */
function gerarIdClienteUnico(PDO $pdo) {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    for ($tentativa = 0; $tentativa < 10; $tentativa++) {
        $id = 'CL-' . strtoupper(substr(str_shuffle($alfabeto), 0, 6));
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM clientes WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                return $id;
            }
        } catch (Exception $e) {
            // Tabela ainda nao existe (primeiro cadastro): o ID sorteado serve.
            return $id;
        }
    }

    // Dez colisoes seguidas seria absurdo; mesmo assim nao devolvemos algo que
    // possa colidir de novo.
    return 'CL-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
}

?>
