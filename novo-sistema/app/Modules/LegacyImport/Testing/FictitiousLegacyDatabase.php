<?php

namespace App\Modules\LegacyImport\Testing;

use Carbon\CarbonImmutable;
use PDO;

/**
 * Gera um banco SQLite NO FORMATO DO SISTEMA ANTIGO com dados 100%
 * FICTICIOS, para validar o importador. Nunca representa pessoas reais:
 * nomes de listas inventadas, e-mails @exemplo.test (dominio reservado),
 * CPFs gerados, credenciais obviamente falsas.
 *
 * Contem dados normais (volume configuravel) e um conjunto fixo de casos
 * problematicos (duplicidades, orfaos, formatos invalidos, status
 * desconhecidos...) com ids conhecidos, usados pelos testes.
 *
 * $minimalSchema=true simula uma instalacao antiga, sem as colunas que o
 * sistema antigo foi acrescentando com ALTER TABLE.
 */
final class FictitiousLegacyDatabase
{
    private const NOMES = ['Ana', 'Bruno', 'Carla', 'Diego', 'Elisa', 'Fabio', 'Gabriela', 'Heitor', 'Iara', 'Joaquim', 'Lara', 'Marcos', 'Nina', 'Otavio', 'Paula', 'Quirino', 'Renata', 'Sergio', 'Tereza', 'Ulisses', 'Vera', 'Wagner', 'Yara', 'Zeca'];

    private const SOBRENOMES = ['Exemplo', 'Ficticio', 'Teste', 'Modelo', 'Amostra', 'Simulado', 'Demonstracao', 'Prototipo'];

    /** Frase de acesso de TODAS as contas ficticias (nunca uma credencial real). */
    public const LOGIN_PHRASE = 'frase-ficticia-123';

    /** Valor obviamente falso usado em TODOS os campos de credencial do banco ficticio. */
    public const FAKE_CREDENTIAL = 'valor-ficticio-de-teste';

    private PDO $pdo;

    private string $hash;

    public function __construct(
        private readonly string $path,
        private readonly int $customers = 60,
        private readonly int $appointments = 300,
        private readonly bool $minimalSchema = false,
        private readonly ?CarbonImmutable $now = null,
    ) {}

    public function build(): string
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        mt_srand(20260926);
        $this->pdo = new PDO('sqlite:'.$this->path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA journal_mode = DELETE');
        // bcrypt custo 10, como o password_hash(PASSWORD_DEFAULT) do sistema antigo.
        $this->hash = password_hash(self::LOGIN_PHRASE, PASSWORD_BCRYPT, ['cost' => 10]);

        $this->schema();
        $this->pdo->beginTransaction();
        $this->settingsAndStaff();
        $this->catalog();
        $this->team();
        $this->customersData();
        $this->subscriptions();
        $this->promotions();
        $this->appointmentsData();
        $this->reviews();
        $this->loyalty();
        $this->stock();
        $this->finance();
        $this->misc();
        $this->pdo->commit();

        return $this->path;
    }

    private function now(): CarbonImmutable
    {
        return ($this->now ?? CarbonImmutable::now('UTC'))->setTimezone('America/Sao_Paulo');
    }

    private function schema(): void
    {
        $m = $this->minimalSchema;
        $sql = [
            'users (username TEXT PRIMARY KEY, password_hash TEXT NOT NULL'.($m ? '' : ", role TEXT DEFAULT 'proprietario', permissions TEXT DEFAULT ''").')',
            'barbeiros (id TEXT PRIMARY KEY, nome TEXT, foto TEXT, username TEXT, password TEXT, status TEXT, servicos_ids TEXT, comissao TEXT, comissao_produtos TEXT'.($m ? '' : ", meta_diaria TEXT, comissao_assinatura_tipo TEXT DEFAULT 'padrao', comissao_assinatura_valor REAL DEFAULT 0").')',
            'clientes (id TEXT PRIMARY KEY, nome TEXT, email TEXT, telefone TEXT, password_hash TEXT, data_nascimento TEXT, foto_perfil TEXT, codigo_indicacao TEXT, cpf TEXT, indicado_por_id TEXT, status TEXT, confirmation_token TEXT'.($m ? '' : ', notas_barbeiro TEXT, confirmation_expira_em TEXT').')',
            'clientes_tokens (selector TEXT PRIMARY KEY, cliente_id TEXT NOT NULL, token_hash TEXT NOT NULL, expires_at INTEGER NOT NULL, created_at TEXT NOT NULL, last_used_at TEXT DEFAULT \'\')',
            'password_resets (email TEXT, token TEXT, expiry INTEGER)',
            'login_throttle (chave TEXT PRIMARY KEY, tentativas INTEGER, bloqueio_ate INTEGER, atualizado_em INTEGER)',
            'sys_login_attempts (ip_address TEXT PRIMARY KEY, attempts INTEGER, last_attempt INTEGER)',
            "admin_atividade (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario TEXT DEFAULT '', acao TEXT NOT NULL, detalhes TEXT DEFAULT '', created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
            'categorias (id TEXT PRIMARY KEY, nome TEXT, ordem TEXT)',
            'servicos (id TEXT PRIMARY KEY, nome TEXT, valor TEXT, slots TEXT, categoria_id TEXT'.($m ? '' : ', descricao TEXT').')',
            'combos (id TEXT PRIMARY KEY, nome TEXT, servicos_ids TEXT, valor TEXT, categoria_id TEXT)',
            'planos (id TEXT PRIMARY KEY, nome TEXT, valor TEXT, servicos_ids TEXT)',
            'produtos (id TEXT PRIMARY KEY, nome TEXT, valor TEXT, quantidade INTEGER, categoria_id TEXT'.($m ? '' : ', estoque_minimo INTEGER, custo TEXT').')',
            'estoque_logs (id TEXT PRIMARY KEY, produto_id TEXT, tipo TEXT, quantidade INTEGER, motivo TEXT, data_hora TEXT, usuario TEXT)',
            'horarios_trabalho (barbeiro_id TEXT, dia TEXT, inicio TEXT, fim TEXT'.($m ? '' : ', ativo TEXT').')',
            'horarios_bloqueados (barbeiro_id TEXT, data TEXT, hora TEXT)',
            'config_almoco_barbeiro (barbeiro_id TEXT PRIMARY KEY, horario TEXT, status TEXT)',
            "barbeiro_ausencias (id TEXT PRIMARY KEY, barbeiro_id TEXT NOT NULL, data_inicio TEXT NOT NULL, data_fim TEXT NOT NULL, tipo TEXT DEFAULT 'folga', motivo TEXT DEFAULT '', criado_em TEXT DEFAULT '')",
            'barbeiros_favoritos (cliente_id TEXT NOT NULL, barbeiro_id TEXT NOT NULL, created_at TEXT, PRIMARY KEY (cliente_id, barbeiro_id))',
            'agendamentos (id TEXT PRIMARY KEY, nome TEXT, email TEXT, telefone TEXT, barbeiro_id TEXT, servicos_ids TEXT, data TEXT, hora TEXT, status TEXT, desconto_aplicado TEXT, tipo_desconto TEXT, observacoes TEXT, produtos_vendidos TEXT, plano_provisorio TEXT, cliente_id TEXT, data_criacao TEXT'
                .($m ? '' : ", presenca_confirmada TEXT DEFAULT '', payment_gateway TEXT DEFAULT '', gateway_reference TEXT DEFAULT '', gorjeta TEXT DEFAULT '0', forma_pagamento TEXT DEFAULT '', comanda_fechada_em TEXT DEFAULT '', lembrete_data TEXT DEFAULT '', lembrete_hora_em TEXT DEFAULT ''").')',
            "agenda_historico (id INTEGER PRIMARY KEY AUTOINCREMENT, agendamento_id TEXT NOT NULL, acao TEXT NOT NULL, detalhes TEXT DEFAULT '', usuario TEXT DEFAULT '', created_at TEXT NOT NULL)",
            "agenda_operacao (agendamento_id TEXT PRIMARY KEY, confirmacao_status TEXT DEFAULT 'pendente', updated_at TEXT, updated_by TEXT)",
            "admin_lista_espera (id INTEGER PRIMARY KEY AUTOINCREMENT, cliente_id TEXT DEFAULT '', nome TEXT NOT NULL, telefone TEXT DEFAULT '', barbeiro_id TEXT DEFAULT '', data_preferida TEXT DEFAULT '', periodo TEXT DEFAULT 'qualquer', observacoes TEXT DEFAULT '', status TEXT DEFAULT 'aguardando', created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
            'despesas (id TEXT PRIMARY KEY, descricao TEXT, valor TEXT, data_despesa TEXT, data_vencimento TEXT, data_pagamento TEXT, categoria TEXT, status TEXT'.($m ? '' : ', recorrente TEXT, recorrencia_origem TEXT').')',
            'comissoes_pagas (id TEXT PRIMARY KEY, barbeiro_id TEXT, valor TEXT, data_pagamento TEXT, periodo_inicio TEXT, periodo_fim TEXT, mes_ano TEXT, valor_total_servicos TEXT'.($m ? '' : ', gorjeta TEXT').')',
            'vales (id TEXT PRIMARY KEY, barbeiro_id TEXT, valor TEXT, data_vale TEXT, mes_referencia TEXT, descricao TEXT)',
            'meta_financeira (id INTEGER PRIMARY KEY, valor TEXT)',
            "admin_conciliacao (referencia TEXT PRIMARY KEY, gateway TEXT DEFAULT '', status TEXT DEFAULT 'revisado', observacao TEXT DEFAULT '', updated_at TEXT DEFAULT CURRENT_TIMESTAMP)",
            'admin_metas_equipe (barbeiro_id TEXT NOT NULL, mes_ano TEXT NOT NULL, meta_atendimentos INTEGER DEFAULT 0, meta_faturamento REAL DEFAULT 0, meta_avaliacao REAL DEFAULT 0, PRIMARY KEY (barbeiro_id, mes_ano))',
            "clientes_assinaturas (cliente_id TEXT PRIMARY KEY, plano_id TEXT, data_inicio TEXT, data_fim TEXT, status TEXT, gateway TEXT DEFAULT 'manual', gateway_subscription_id TEXT DEFAULT '', gateway_status TEXT DEFAULT '', ultimo_pagamento_id TEXT DEFAULT '', cancelamento_em TEXT DEFAULT '', gateway_customer_id TEXT DEFAULT '')",
            "assinatura_pagamentos (id TEXT PRIMARY KEY, cliente_id TEXT NOT NULL, plano_id TEXT NOT NULL, gateway TEXT DEFAULT 'manual', gateway_subscription_id TEXT DEFAULT '', valor REAL DEFAULT 0, moeda TEXT DEFAULT 'BRL', status TEXT DEFAULT 'confirmado', data_pagamento TEXT NOT NULL, tipo TEXT DEFAULT 'mensalidade', referencia TEXT DEFAULT '', created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
            "webhook_eventos_processados (gateway TEXT NOT NULL, evento_id TEXT NOT NULL, tipo TEXT DEFAULT '', processado_em TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (gateway, evento_id))",
            'fidelidade (id TEXT PRIMARY KEY, pontos INTEGER)',
            'fidelidade_historico (cliente_id TEXT, pontos INTEGER, descricao TEXT, timestamp TEXT)',
            'cupoes (id TEXT PRIMARY KEY, codigo TEXT, desconto_percentual TEXT, usos_maximos TEXT, data_validade TEXT, usos_atuais TEXT'.($m ? '' : ", tipo_desconto TEXT DEFAULT 'percentual', valor_desconto REAL DEFAULT 0, ativo INTEGER DEFAULT 1").')',
            'cupom_usos (cupom_id TEXT, cliente_id TEXT)',
            'vouchers (id TEXT PRIMARY KEY, codigo TEXT, valor TEXT, status TEXT, data_criacao TEXT, agendamento_id_uso TEXT, data_validade TEXT'.($m ? '' : ", comprador TEXT DEFAULT ''").')',
            "campanhas (id TEXT PRIMARY KEY, tipo TEXT, template TEXT, assunto TEXT, corpo TEXT, segmento TEXT, extra_json TEXT DEFAULT '', total INTEGER DEFAULT 0, enviados INTEGER DEFAULT 0, falhas INTEGER DEFAULT 0, status TEXT DEFAULT 'preparando', criada_em TEXT, concluida_em TEXT DEFAULT '', criada_por TEXT DEFAULT '')",
            "campanha_destinatarios (id INTEGER PRIMARY KEY AUTOINCREMENT, campanha_id TEXT, cliente_id TEXT, nome TEXT, email TEXT, ref TEXT DEFAULT '', status TEXT DEFAULT 'pendente', enviado_em TEXT DEFAULT '')",
            'email_optout (email TEXT PRIMARY KEY, criado_em TEXT'.($m ? '' : ", cliente_id TEXT DEFAULT ''").')',
            "notificacoes (id TEXT PRIMARY KEY, cliente_id TEXT, mensagem TEXT, lida INTEGER, data_criacao TEXT, status TEXT DEFAULT 'nao_lida', timestamp TEXT)",
            'avaliacoes (id TEXT PRIMARY KEY, agendamento_id TEXT, cliente_id TEXT, barbeiro_id TEXT, rating TEXT, comment TEXT, timestamp TEXT)',
            'avaliacoes_destacadas (id_avaliacao TEXT PRIMARY KEY)',
            'respostas_avaliacoes (id_resposta TEXT PRIMARY KEY, id_avaliacao TEXT, texto_resposta TEXT, timestamp TEXT)',
            'anotacoes_clientes (id TEXT PRIMARY KEY, anotacao TEXT)',
            "admin_crm_clientes (cliente_id TEXT PRIMARY KEY, tags TEXT DEFAULT '', observacoes TEXT DEFAULT '', status_relacionamento TEXT DEFAULT 'ativo', updated_at TEXT DEFAULT CURRENT_TIMESTAMP)",
            "admin_retencao (cliente_id TEXT PRIMARY KEY, motivo TEXT DEFAULT '', oferta TEXT DEFAULT '', status TEXT DEFAULT 'pendente', updated_at TEXT DEFAULT CURRENT_TIMESTAMP)",
            'configuracoes (secao TEXT PRIMARY KEY, dados_json TEXT)',
            'config (chave TEXT PRIMARY KEY, valor TEXT)',
            'schema_migracoes (versao INTEGER PRIMARY KEY, aplicada_em TEXT)',
            'schema_migracoes_dados (chave TEXT PRIMARY KEY, aplicada_em TEXT)',
            'tabela_misteriosa (id INTEGER PRIMARY KEY, dado TEXT)',
        ];
        foreach ($sql as $t) {
            $this->pdo->exec('CREATE TABLE '.$t);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function ins(string $table, array $row): void
    {
        $cols = array_keys($row);
        if ($this->minimalSchema) {
            $existentes = array_column($this->pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
            $cols = array_values(array_intersect($cols, $existentes));
            $row = array_intersect_key($row, array_flip($cols));
        }
        $st = $this->pdo->prepare("INSERT INTO {$table} (".implode(',', $cols).') VALUES ('.implode(',', array_fill(0, count($cols), '?')).')');
        $st->execute(array_values($row));
    }

    private function name(): string
    {
        return self::NOMES[mt_rand(0, count(self::NOMES) - 1)].' '.self::SOBRENOMES[mt_rand(0, count(self::SOBRENOMES) - 1)];
    }

    public static function cpf(int $n): string
    {
        $base = str_pad((string) (100000000 + $n * 7), 9, '0', STR_PAD_LEFT);
        for ($t = 9; $t < 11; $t++) {
            $s = 0;
            for ($i = 0; $i < $t; $i++) {
                $s += (int) $base[$i] * (($t + 1) - $i);
            }
            $base .= (string) (((10 * $s) % 11) % 10);
        }

        return substr($base, 0, 3).'.'.substr($base, 3, 3).'.'.substr($base, 6, 3).'-'.substr($base, 9, 2);
    }

    private function settingsAndStaff(): void
    {
        $cfg = [
            'config_geral' => ['nome_barbearia' => 'Barbearia Exemplo', 'telefone_contato' => '(11) 3000-0000', 'endereco' => 'Rua Ficticia, 100'],
            'config_agendamento' => ['antecedencia_minima_minutos' => 60, 'antecedencia_maxima' => 30],
            'landing_page' => ['titulo' => 'Barbearia Exemplo', 'integracao' => ['api_key' => self::FAKE_CREDENTIAL, 'cor' => '#000']],
            'fidelidade_config' => ['pontos_por_visita' => 10, 'modo_ganho' => 'visita'],
            'config_email' => ['host' => 'smtp.exemplo.test', 'username' => 'ficticio', 'password' => self::FAKE_CREDENTIAL],
            'config_stripe' => ['secret_key' => self::FAKE_CREDENTIAL, 'webhook_secret' => self::FAKE_CREDENTIAL],
            'config_cron' => ['token' => self::FAKE_CREDENTIAL],
        ];
        foreach ($cfg as $secao => $dados) {
            $this->ins('configuracoes', ['secao' => $secao, 'dados_json' => json_encode($dados)]);
        }
        $this->ins('configuracoes', ['secao' => 'theme_config', 'dados_json' => '{isto nao e json']);

        foreach ([['dono', 'proprietario'], ['gerente1', 'gerente'], ['recep', 'recepcao'], ['fin', 'financeiro'], ['estranho', 'supervisor'], ['Dono', 'gerente']] as [$u, $r]) {
            $this->ins('users', ['username' => $u, 'password_hash' => $this->hash, 'role' => $r, 'permissions' => '']);
        }
        $this->ins('users', ['username' => 'antigo', 'password_hash' => md5('x'), 'role' => 'recepcao', 'permissions' => '']);
    }

    private function catalog(): void
    {
        $this->ins('categorias', ['id' => 'cat-1', 'nome' => 'Cabelo', 'ordem' => '1']);
        $this->ins('categorias', ['id' => 'cat-2', 'nome' => 'Barba &amp; Bigode', 'ordem' => 'x']);
        $servicos = [
            ['sv-1', 'Corte', '45.00', '1', 'cat-1'],
            ['sv-2', 'Barba', '30,00', '1', 'cat-2'],   // formato BR: o antigo lia como 30 (float) -> divergente
            ['sv-3', 'Sobrancelha', '15.5', '', 'cat-1'],
            ['sv-4', 'Servico sem preco', 'gratis', '1', 'cat-1'],
            ['sv-5', 'Pigmentacao', '33.335', '2', 'cat-1'],
        ];
        foreach ($servicos as [$id, $nome, $valor, $slots, $cat]) {
            $this->ins('servicos', ['id' => $id, 'nome' => $nome, 'valor' => $valor, 'slots' => $slots, 'categoria_id' => $cat, 'descricao' => '']);
        }
        $this->ins('combos', ['id' => 'cb-1', 'nome' => 'Corte + Barba', 'servicos_ids' => 'sv-1,sv-2', 'valor' => '70', 'categoria_id' => 'cat-1']);
        $this->ins('combos', ['id' => 'cb-2', 'nome' => 'Combo antigo', 'servicos_ids' => 'sv-1,sv-apagado', 'valor' => '55.90', 'categoria_id' => 'cat-9']);
        $this->ins('produtos', ['id' => 'prod-1', 'nome' => 'Pomada', 'valor' => '35', 'quantidade' => 10, 'categoria_id' => '', 'estoque_minimo' => 2, 'custo' => '15']);
        $this->ins('produtos', ['id' => 'prod-2', 'nome' => 'Shampoo', 'valor' => '28.90', 'quantidade' => 0, 'categoria_id' => '', 'estoque_minimo' => 1, 'custo' => '']);
        $this->ins('produtos', ['id' => 'prod-3', 'nome' => 'Oleo', 'valor' => '40', 'quantidade' => -2, 'categoria_id' => '', 'estoque_minimo' => 0, 'custo' => '20']);
    }

    private function team(): void
    {
        $bar = [
            ['br-1', 'Carlos Exemplo', 'carlos', $this->hash, 'ativo', 'sv-1,sv-2,sv-3,cb-1', '40', '1', '200', 'padrao', 0],
            ['br-2', 'Rafael Teste', 'rafael', $this->hash, '', 'sv-1,sv-5,sv-antigo', '37.5', '0', '150', 'percentual', 20],
            ['br-3', 'Bento Inativo', '', '', 'inativo', 'sv-1', '30', '', '', 'fixo', 12.5],
            ['br-4', 'Dono Barbeiro', 'dono', $this->hash, 'ativo', 'sv-1', 'abc', '', '', 'desconhecido', 0],
            ['br-5', 'Senha Antiga', 'senhaantiga', 'segredo-em-texto', 'ativo', '', '50', '', '', 'nenhuma', 0],
        ];
        foreach ($bar as [$id, $nome, $user, $senha, $st, $sv, $com, $prod, $meta, $modo, $val]) {
            $this->ins('barbeiros', ['id' => $id, 'nome' => $nome, 'foto' => '', 'username' => $user, 'password' => $senha, 'status' => $st, 'servicos_ids' => $sv,
                'comissao' => $com, 'comissao_produtos' => $prod, 'meta_diaria' => $meta, 'comissao_assinatura_tipo' => $modo, 'comissao_assinatura_valor' => $val]);
        }
        foreach ([1, 2, 3, 4, 5] as $d) {
            $this->ins('horarios_trabalho', ['barbeiro_id' => 'br-1', 'dia' => (string) $d, 'inicio' => '09:00', 'fim' => '18:00', 'ativo' => '1']);
            $this->ins('horarios_trabalho', ['barbeiro_id' => 'br-2', 'dia' => (string) $d, 'inicio' => '10:00', 'fim' => '19:00', 'ativo' => '1']);
        }
        $this->ins('horarios_trabalho', ['barbeiro_id' => 'br-1', 'dia' => '1', 'inicio' => '13:00', 'fim' => '20:00', 'ativo' => '1']); // duplicado
        $this->ins('horarios_trabalho', ['barbeiro_id' => 'br-2', 'dia' => '6', 'inicio' => '09:00', 'fim' => '13:00', 'ativo' => '0']);
        $this->ins('horarios_trabalho', ['barbeiro_id' => 'br-99', 'dia' => '1', 'inicio' => '09:00', 'fim' => '18:00', 'ativo' => '1']);
        $this->ins('horarios_trabalho', ['barbeiro_id' => 'br-4', 'dia' => '2', 'inicio' => '18:00', 'fim' => '09:00', 'ativo' => '1']);
        $this->ins('config_almoco_barbeiro', ['barbeiro_id' => 'br-1', 'horario' => '12:00', 'status' => 'ativo']);
        $this->ins('config_almoco_barbeiro', ['barbeiro_id' => 'br-2', 'horario' => '13:00', 'status' => 'inativo']);
        $this->ins('config_almoco_barbeiro', ['barbeiro_id' => 'br-99', 'horario' => '12:00', 'status' => 'ativo']);
        $hoje = $this->now();
        $this->ins('barbeiro_ausencias', ['id' => 'aus-1', 'barbeiro_id' => 'br-1', 'data_inicio' => $hoje->addDays(40)->toDateString(), 'data_fim' => $hoje->addDays(50)->toDateString(), 'tipo' => 'ferias', 'motivo' => 'Ferias', 'criado_em' => $hoje->format('Y-m-d H:i:s')]);
        $this->ins('barbeiro_ausencias', ['id' => 'aus-2', 'barbeiro_id' => 'br-2', 'data_inicio' => '2026-05-10', 'data_fim' => '2026-05-01', 'tipo' => 'folga', 'motivo' => '', 'criado_em' => '']);
        $this->ins('barbeiro_ausencias', ['id' => 'aus-3', 'barbeiro_id' => 'br-2', 'data_inicio' => '2026-03-02', 'data_fim' => '2026-03-02', 'tipo' => 'licenca', 'motivo' => 'Curso', 'criado_em' => '']);
        $this->ins('horarios_bloqueados', ['barbeiro_id' => 'br-1', 'data' => $hoje->addDays(3)->toDateString(), 'hora' => '15:00']);
        $this->ins('horarios_bloqueados', ['barbeiro_id' => 'br-1', 'data' => $hoje->addDays(3)->toDateString(), 'hora' => '15:00']);
        $this->ins('horarios_bloqueados', ['barbeiro_id' => 'br-1', 'data' => '2026-02-30', 'hora' => '15:00']);
    }

    private function customersData(): void
    {
        for ($i = 1; $i <= $this->customers; $i++) {
            $this->ins('clientes', [
                'id' => sprintf('CL-N%05d', $i), 'nome' => $this->name(), 'email' => "cliente{$i}@exemplo.test",
                'telefone' => sprintf('(%02d) 9%04d-%04d', [11, 21, 31][$i % 3], 1000 + intdiv($i, 10000), $i % 10000),
                'password_hash' => $this->hash, 'data_nascimento' => sprintf('19%02d-%02d-%02d', 60 + $i % 40, 1 + $i % 12, 1 + $i % 28),
                'foto_perfil' => '', 'codigo_indicacao' => sprintf('IND%05d', $i), 'cpf' => $i % 4 === 0 ? self::cpf($i) : '',
                'indicado_por_id' => $i > 5 && $i % 7 === 0 ? sprintf('CL-N%05d', $i - 5) : '', 'status' => $i % 25 === 0 ? 'inativo' : 'ativo',
                'confirmation_token' => '', 'notas_barbeiro' => $i % 10 === 0 ? 'Prefere maquina 2 nas laterais' : '',
            ]);
        }
        $probl = [
            ['CL-DUPMAIL', 'Duplicado Email', 'CLIENTE1@Exemplo.test ', '(41) 98888-0001', self::cpf(900001)],
            ['CL-DUPTEL', 'Duplicado Telefone', 'outro.tel@exemplo.test', '+55 21 91000-0001', ''],
            ['CL-DUPCPF', 'Duplicado Cpf', 'outro.cpf@exemplo.test', '', self::cpf(4)],
            ['CL-EMAILRUIM', 'Email Invalido', 'joao@', '(11) 97777-0002', ''],
            ['CL-TELRUIM', 'Telefone Invalido', 'tel.ruim@exemplo.test', '123', '111.111.111-11'],
            ['CL-SEMNOME', '', 'sem.nome@exemplo.test', '', ''],
            ['CL-ESCAPE', 'Joana D&amp;#039;Avila', 'joana@exemplo.test', '', ''],
            ['CL-OPTOUT', 'Opt Out Por Id', 'optout.id@exemplo.test', '', ''],
            ['CL-OPTMAIL', 'Opt Out Por Email', 'optout.email@exemplo.test', '', ''],
        ];
        foreach ($probl as [$id, $nome, $email, $tel, $cpf]) {
            $this->ins('clientes', ['id' => $id, 'nome' => $nome, 'email' => $email, 'telefone' => $tel, 'password_hash' => $this->hash,
                'data_nascimento' => '', 'foto_perfil' => '', 'codigo_indicacao' => '', 'cpf' => $cpf, 'indicado_por_id' => '', 'status' => 'ativo', 'confirmation_token' => '', 'notas_barbeiro' => '']);
        }
        $extra = [
            ['CL-DATARUIM', ['data_nascimento' => '31/02/1990']],
            ['CL-SENHAMD5', ['password_hash' => md5('senha')]],
            ['CL-SEMSENHA', ['password_hash' => '']],
            ['CL-STATUSX', ['status' => 'bloqueado']],
            ['CL-INDORFAO', ['indicado_por_id' => 'CL-NAOEXISTE']],
            ['CL-INDSI', ['indicado_por_id' => 'CL-INDSI']],
            ['CL-DUPCOD', ['codigo_indicacao' => 'IND00001']],
        ];
        foreach ($extra as $k => [$id, $campos]) {
            $this->ins('clientes', [...['id' => $id, 'nome' => 'Caso '.$id, 'email' => strtolower($id).'@exemplo.test', 'telefone' => '', 'password_hash' => $this->hash,
                'data_nascimento' => '', 'foto_perfil' => '', 'codigo_indicacao' => '', 'cpf' => '', 'indicado_por_id' => '', 'status' => 'ativo', 'confirmation_token' => '', 'notas_barbeiro' => ''], ...$campos]);
        }
        $this->ins('anotacoes_clientes', ['id' => 'CL-N00001', 'anotacao' => 'Cliente desde a inauguracao']);
        $this->ins('anotacoes_clientes', ['id' => 'CL-FANTASMA', 'anotacao' => 'Orfa']);
        $this->ins('barbeiros_favoritos', ['cliente_id' => 'CL-N00001', 'barbeiro_id' => 'br-1', 'created_at' => '2026-01-10 10:00:00']);
        $this->ins('barbeiros_favoritos', ['cliente_id' => 'CL-N00002', 'barbeiro_id' => 'br-99', 'created_at' => '']);
        $this->ins('notificacoes', ['id' => 'notif-1', 'cliente_id' => 'CL-N00001', 'mensagem' => 'Seu horario foi confirmado', 'lida' => 1, 'data_criacao' => '2026-08-01 10:00:00', 'status' => 'lida', 'timestamp' => '']);
        $this->ins('notificacoes', ['id' => 'notif-2', 'cliente_id' => 'CL-N00001', 'mensagem' => 'Voce ganhou pontos', 'lida' => 0, 'data_criacao' => '', 'status' => 'lida', 'timestamp' => '2026-08-02 11:00:00']);
        $this->ins('notificacoes', ['id' => 'notif-3', 'cliente_id' => 'CL-N00002', 'mensagem' => 'Nao lida', 'lida' => 0, 'data_criacao' => '2026-08-03 12:00:00', 'status' => 'nao_lida', 'timestamp' => '']);
        $this->ins('notificacoes', ['id' => 'notif-4', 'cliente_id' => 'CL-FANTASMA', 'mensagem' => 'Orfa', 'lida' => 0, 'data_criacao' => '', 'status' => '', 'timestamp' => '']);
        $this->ins('email_optout', ['email' => 'qualquer@exemplo.test', 'criado_em' => '2026-04-01 09:00:00', 'cliente_id' => 'CL-OPTOUT']);
        $this->ins('email_optout', ['email' => 'OPTOUT.EMAIL@exemplo.test', 'criado_em' => '2026-04-02 09:00:00', 'cliente_id' => '']);
        $this->ins('email_optout', ['email' => 'sem.cadastro@exemplo.test', 'criado_em' => '2026-04-03 09:00:00', 'cliente_id' => '']);
        $this->ins('email_optout', ['email' => 'invalido@@exemplo', 'criado_em' => '', 'cliente_id' => '']);
    }

    private function subscriptions(): void
    {
        $this->ins('planos', ['id' => 'plano-1', 'nome' => 'Clube do Corte', 'valor' => '99.90', 'servicos_ids' => 'sv-1,sv-2']);
        $this->ins('planos', ['id' => 'plano-2', 'nome' => 'Plano antigo', 'valor' => '79', 'servicos_ids' => 'sv-apagado']);
        $hoje = $this->now();
        $subs = [
            ['CL-N00001', 'plano-1', 'ativo', 'stripe', 'sub_FICTICIO_0001', 'cus_FICTICIO_0001', $hoje->addDays(20)->toDateString()],
            ['CL-N00002', 'plano-1', 'cancelamento_agendado', 'stripe', 'sub_FICTICIO_0002', 'cus_FICTICIO_0002', $hoje->addDays(5)->toDateString()],
            ['CL-N00003', 'plano-2', 'expirado', 'manual', '', '', '2026-01-31'],
            ['CL-FANTASMA', 'plano-1', 'ativo', 'stripe', 'sub_FICTICIO_0099', 'cus_FICTICIO_0099', $hoje->addDays(10)->toDateString()],
            ['CL-N00004', 'plano-1', 'suspenso', 'stripe', 'sub_FICTICIO_0004', 'cus_FICTICIO_0004', $hoje->addDays(10)->toDateString()],
            ['CL-N00005', 'plano-x', 'ativo', 'manual', '', '', $hoje->subDays(10)->toDateString()],
        ];
        foreach ($subs as [$c, $p, $st, $gw, $sub, $cus, $fim]) {
            $this->ins('clientes_assinaturas', ['cliente_id' => $c, 'plano_id' => $p, 'data_inicio' => '2026-01-01', 'data_fim' => $fim, 'status' => $st, 'gateway' => $gw,
                'gateway_subscription_id' => $sub, 'gateway_status' => $st === 'ativo' ? 'active' : '', 'ultimo_pagamento_id' => '', 'cancelamento_em' => $st === 'cancelamento_agendado' ? '2026-08-20 10:00:00' : '', 'gateway_customer_id' => $cus]);
        }
        $this->ins('assinatura_pagamentos', ['id' => 'pag-1', 'cliente_id' => 'CL-N00001', 'plano_id' => 'plano-1', 'gateway' => 'stripe', 'gateway_subscription_id' => 'sub_FICTICIO_0001', 'valor' => 99.9, 'moeda' => 'brl', 'status' => 'confirmado', 'data_pagamento' => '2026-08-01 08:00:00', 'tipo' => 'mensalidade', 'referencia' => 'in_FICTICIO_0001', 'created_at' => '2026-08-01 08:00:01']);
        $this->ins('assinatura_pagamentos', ['id' => 'pag-2', 'cliente_id' => 'CL-N00001', 'plano_id' => 'plano-1', 'gateway' => 'stripe', 'gateway_subscription_id' => 'sub_FICTICIO_0001', 'valor' => 99.9, 'moeda' => 'BRL', 'status' => 'confirmado', 'data_pagamento' => '2026-09-01 08:00:00', 'tipo' => 'mensalidade', 'referencia' => 'in_FICTICIO_0001', 'created_at' => '2026-09-01 08:00:01']);
        $this->ins('assinatura_pagamentos', ['id' => 'pag-3', 'cliente_id' => 'CL-FANTASMA', 'plano_id' => 'plano-1', 'gateway' => 'stripe', 'gateway_subscription_id' => 'sub_FICTICIO_0099', 'valor' => 99.9, 'moeda' => 'BRL', 'status' => 'confirmado', 'data_pagamento' => '2026-09-01 08:00:00', 'tipo' => 'mensalidade', 'referencia' => 'in_FICTICIO_0099', 'created_at' => '']);
        $this->ins('webhook_eventos_processados', ['gateway' => 'stripe', 'evento_id' => 'evt_FICTICIO_0001', 'tipo' => 'invoice.paid', 'processado_em' => '2026-08-01 08:00:02']);
        $this->ins('webhook_eventos_processados', ['gateway' => 'stripe', 'evento_id' => 'evt_FICTICIO_0002', 'tipo' => 'customer.subscription.updated', 'processado_em' => '2026-08-20 10:00:00']);
    }

    private function promotions(): void
    {
        $this->ins('cupoes', ['id' => 'cup-1', 'codigo' => 'BEMVINDO', 'desconto_percentual' => '10', 'usos_maximos' => '100', 'data_validade' => '2027-12-31', 'usos_atuais' => '3', 'tipo_desconto' => 'percentual', 'valor_desconto' => 0, 'ativo' => 1]);
        $this->ins('cupoes', ['id' => 'cup-2', 'codigo' => 'bemvindo', 'desconto_percentual' => '15', 'usos_maximos' => '10', 'data_validade' => '', 'usos_atuais' => '0', 'tipo_desconto' => 'percentual', 'valor_desconto' => 0, 'ativo' => 1]);
        $this->ins('cupoes', ['id' => 'cup-3', 'codigo' => 'MENOS15', 'desconto_percentual' => '0', 'usos_maximos' => '0', 'data_validade' => '', 'usos_atuais' => '7', 'tipo_desconto' => 'fixo', 'valor_desconto' => 15, 'ativo' => 1]);
        $this->ins('cupoes', ['id' => 'cup-4', 'codigo' => 'ZERO', 'desconto_percentual' => '0', 'usos_maximos' => '5', 'data_validade' => '', 'usos_atuais' => '0', 'tipo_desconto' => 'percentual', 'valor_desconto' => 0, 'ativo' => 1]);
        $this->ins('cupoes', ['id' => 'cup-5', 'codigo' => 'DESLIGADO', 'desconto_percentual' => '20', 'usos_maximos' => '5', 'data_validade' => '2026-01-01', 'usos_atuais' => '5', 'tipo_desconto' => 'percentual', 'valor_desconto' => 0, 'ativo' => 0]);
        $this->ins('vouchers', ['id' => 'vch-1', 'codigo' => 'PRESENTE-001', 'valor' => '50', 'status' => 'disponivel', 'data_criacao' => '2026-06-01 10:00:00', 'agendamento_id_uso' => '', 'data_validade' => '2026-12-31', 'comprador' => 'Comprador Ficticio']);
        $this->ins('vouchers', ['id' => 'vch-2', 'codigo' => 'PRESENTE-002', 'valor' => '30', 'status' => 'utilizado', 'data_criacao' => '2026-06-01 10:00:00', 'agendamento_id_uso' => 'AG-VOUCHER', 'data_validade' => '', 'comprador' => '']);
        $this->ins('vouchers', ['id' => 'vch-3', 'codigo' => 'PRESENTE-001', 'valor' => '50', 'status' => 'disponivel', 'data_criacao' => '', 'agendamento_id_uso' => '', 'data_validade' => '', 'comprador' => '']);
        $this->ins('vouchers', ['id' => 'vch-4', 'codigo' => 'PRESENTE-004', 'valor' => '20', 'status' => 'bloqueado', 'data_criacao' => '', 'agendamento_id_uso' => '', 'data_validade' => '', 'comprador' => '']);
    }

    private function appointmentsData(): void
    {
        $agora = $this->now();
        $barbeiros = ['br-1', 'br-2'];
        $itens = ['sv-1', 'sv-1', 'sv-2', 'sv-3', 'cb-1', 'sv-1,sv-3', 'sv-5'];
        $metodos = ['dinheiro', 'pix', 'debito', 'credito', 'pix', ''];
        for ($i = 1; $i <= $this->appointments; $i++) {
            $futuro = $i % 10 === 0;
            $dia = $futuro ? $agora->addDays(1 + $i % 29) : $agora->subDays(1 + $i % 540);
            $hora = sprintf('%02d:%02d', 9 + intdiv($i % 18, 2), ($i % 2) * 30);
            // Pares em horarios distintos por barbeiro: sem sobreposicao nos dados normais.
            $bid = $barbeiros[$i % 2];
            $status = $futuro ? 'aprovado' : ['concluido', 'concluido', 'concluido', 'cancelado', 'cancelado_pelo_cliente', 'concluido', 'rejeitado', 'concluido'][$i % 8];
            $cliente = sprintf('CL-N%05d', 1 + $i % max(1, $this->customers));
            $concluido = $status === 'concluido';
            $this->ins('agendamentos', [
                'id' => sprintf('AG-N%06d', $i), 'nome' => 'Cliente '.$i, 'email' => "cliente{$i}@exemplo.test", 'telefone' => '(11) 90000-0000',
                'barbeiro_id' => $bid, 'servicos_ids' => $itens[$i % count($itens)], 'data' => $dia->toDateString(), 'hora' => $hora, 'status' => $status,
                'desconto_aplicado' => $i % 9 === 0 ? '5' : '0', 'tipo_desconto' => $i % 9 === 0 ? 'cupom' : '', 'observacoes' => '',
                'produtos_vendidos' => $concluido && $i % 6 === 0 ? json_encode([['nome' => 'Pomada (1x)', 'valor' => 35, 'custo' => 15, 'produto_id' => 'prod-1']]) : '',
                'plano_provisorio' => '', 'cliente_id' => $i % 5 === 0 ? '' : $cliente, 'data_criacao' => $dia->subDays(2)->format('Y-m-d H:i:s'),
                'presenca_confirmada' => '', 'payment_gateway' => '', 'gateway_reference' => '', 'gorjeta' => $concluido && $i % 4 === 0 ? '10' : '0',
                'forma_pagamento' => $concluido ? $metodos[$i % count($metodos)] : '', 'comanda_fechada_em' => $concluido ? $dia->format('Y-m-d').' '.$hora.':00' : '',
                'lembrete_data' => $i % 3 === 0 ? $dia->toDateString() : '', 'lembrete_hora_em' => $i % 6 === 0 ? $dia->format('Y-m-d').' 08:00:00' : '',
            ]);
        }

        $base = ['nome' => 'Caso Especial', 'email' => '', 'telefone' => '', 'barbeiro_id' => 'br-1', 'servicos_ids' => 'sv-1', 'status' => 'concluido',
            'desconto_aplicado' => '0', 'tipo_desconto' => '', 'observacoes' => '', 'produtos_vendidos' => '', 'plano_provisorio' => '', 'cliente_id' => 'CL-N00001',
            'data_criacao' => '', 'presenca_confirmada' => '', 'payment_gateway' => '', 'gateway_reference' => '', 'gorjeta' => '0', 'forma_pagamento' => 'pix',
            'comanda_fechada_em' => '', 'lembrete_data' => '', 'lembrete_hora_em' => ''];
        $passado = $agora->subDays(20)->toDateString();
        $futuro = $agora->addDays(7)->toDateString();
        $casos = [
            'AG-ITEMSUMIU' => ['servicos_ids' => 'sv-1,sv-apagado', 'data' => $passado, 'hora' => '08:00'],
            'AG-DATARUIM' => ['data' => '2026-13-45', 'hora' => '10:00'],
            'AG-STATUSX' => ['status' => 'em_andamento', 'data' => $passado, 'hora' => '08:30'],
            'AG-BARBORFAO' => ['barbeiro_id' => 'br-77', 'data' => $passado, 'hora' => '09:00'],
            'AG-CLIORFAO' => ['cliente_id' => 'CL-FANTASMA', 'nome' => 'Visitante', 'data' => $passado, 'hora' => '07:00'],
            'AG-PAGVELHO' => ['status' => 'aguardando_pagamento', 'data' => $futuro, 'hora' => '08:00', 'data_criacao' => $agora->subHours(2)->format('Y-m-d H:i:s'), 'payment_gateway' => 'stripe', 'gateway_reference' => 'cs_FICTICIO_0001'],
            'AG-PAGNOVO' => ['status' => 'aguardando_pagamento', 'data' => $futuro, 'hora' => '08:30', 'data_criacao' => $agora->subMinutes(5)->format('Y-m-d H:i:s'), 'payment_gateway' => 'stripe', 'gateway_reference' => 'cs_FICTICIO_0002'],
            'AG-PASSADOAPROV' => ['status' => 'aprovado', 'data' => $passado, 'hora' => '19:00'],
            'AG-CONFLITO1' => ['status' => 'aprovado', 'data' => $futuro, 'hora' => '20:00', 'barbeiro_id' => 'br-2'],
            'AG-CONFLITO2' => ['status' => 'pendente', 'data' => $futuro, 'hora' => '20:00', 'barbeiro_id' => 'br-2', 'servicos_ids' => 'cb-1'],
            'AG-JSONRUIM' => ['produtos_vendidos' => '{nao e json', 'data' => $passado, 'hora' => '20:00'],
            'AG-DESCONTOALTO' => ['desconto_aplicado' => '100', 'tipo_desconto' => 'fidelidade', 'gorjeta' => '0', 'data' => $passado, 'hora' => '20:30', 'produtos_vendidos' => json_encode([['nome' => 'Shampoo (1x)', 'valor' => 28.9, 'custo' => 0, 'produto_id' => 'prod-2']])],
            'AG-DESCONTOBR' => ['desconto_aplicado' => '4,50', 'tipo_desconto' => 'aniversario', 'data' => $passado, 'hora' => '21:00'],
            'AG-CHEQUE' => ['forma_pagamento' => 'cheque', 'gorjeta' => '2.5', 'data' => $passado, 'hora' => '21:30'],
            'AG-VOUCHER' => ['desconto_aplicado' => '30', 'tipo_desconto' => 'voucher', 'data' => $passado, 'hora' => '06:30'],
            'AG-SEMSERV' => ['servicos_ids' => '', 'status' => 'cancelado', 'data' => $passado, 'hora' => '06:00'],
            'AG-LEMBRETE' => ['status' => 'aprovado', 'data' => $futuro, 'hora' => '06:00', 'lembrete_data' => $futuro, 'lembrete_hora_em' => $agora->format('Y-m-d H:i:s'), 'presenca_confirmada' => $agora->format('Y-m-d H:i:s'), 'plano_provisorio' => 'plano-1'],
            'AG-SEMNOME' => ['nome' => '', 'cliente_id' => '', 'data' => $passado, 'hora' => '05:30'],
            'AG-SERVSEMPRECO' => ['servicos_ids' => 'sv-4', 'data' => $passado, 'hora' => '05:00'],
        ];
        foreach ($casos as $id => $campos) {
            $this->ins('agendamentos', ['id' => $id, ...$base, ...$campos]);
        }

        $this->ins('agenda_historico', ['agendamento_id' => 'AG-N000001', 'acao' => 'criado', 'detalhes' => 'Agendamento criado pelo site', 'usuario' => 'cliente', 'created_at' => '2026-01-01 10:00:00']);
        $this->ins('agenda_historico', ['agendamento_id' => 'AG-N000001', 'acao' => 'concluido', 'detalhes' => 'Comanda fechada', 'usuario' => 'recep', 'created_at' => '2026-01-03 11:00:00']);
        $this->ins('agenda_historico', ['agendamento_id' => 'AG-APAGADO', 'acao' => 'cancelado', 'detalhes' => '', 'usuario' => 'dono', 'created_at' => '2026-01-05 11:00:00']);
        $this->ins('agenda_operacao', ['agendamento_id' => 'AG-LEMBRETE', 'confirmacao_status' => 'enviado', 'updated_at' => $agora->format('Y-m-d H:i:s'), 'updated_by' => 'recep']);
    }

    private function reviews(): void
    {
        $rev = [
            ['av-1', 'AG-N000001', 'CL-N00002', 'br-2', '5', 'Otimo atendimento'],
            ['av-2', 'AG-N000002', 'CL-N00003', 'br-1', 'cinco', 'Nota em texto'],
            ['av-3', 'AG-N000003', 'CL-N00004', 'br-2', '7', 'Fora da escala'],
            ['av-4', 'AG-N000001', 'CL-N00002', 'br-2', '4', 'Segunda avaliacao do mesmo'],
            ['av-5', 'AG-APAGADO', 'CL-N00005', 'br-1', '3', 'Agendamento apagado'],
            ['av-6', 'AG-N000005', '', 'br-77', '4', 'Barbeiro removido'],
        ];
        foreach ($rev as [$id, $ag, $cl, $br, $nota, $txt]) {
            $this->ins('avaliacoes', ['id' => $id, 'agendamento_id' => $ag, 'cliente_id' => $cl, 'barbeiro_id' => $br, 'rating' => $nota, 'comment' => $txt, 'timestamp' => '2026-07-01 10:00:00']);
        }
        $this->ins('avaliacoes_destacadas', ['id_avaliacao' => 'av-1']);
        $this->ins('respostas_avaliacoes', ['id_resposta' => 'resp-1', 'id_avaliacao' => 'av-1', 'texto_resposta' => 'Obrigado!', 'timestamp' => '2026-07-02 10:00:00']);
        $this->ins('respostas_avaliacoes', ['id_resposta' => 'resp-2', 'id_avaliacao' => 'av-99', 'texto_resposta' => 'Orfa', 'timestamp' => '']);
    }

    private function loyalty(): void
    {
        // CL-N00001: coerente (saldo = soma). CL-N00002: saldo diverge. CL-N00003: historico sem saldo. CL-N00004: saldo negativo.
        foreach ([['CL-N00001', 10], ['CL-N00001', 10], ['CL-N00001', -15], ['CL-N00002', 10], ['CL-N00003', 20], ['CL-FANTASMA', 5], ['CL-N00001', 0]] as $k => [$c, $p]) {
            $this->ins('fidelidade_historico', ['cliente_id' => $c, 'pontos' => $p, 'descricao' => $p >= 0 ? 'Pontos por visita' : 'Resgate', 'timestamp' => sprintf('2026-06-%02d 10:00:00', $k + 1)]);
        }
        foreach ([['CL-N00001', 5], ['CL-N00002', 40], ['CL-N00004', -3], ['CL-FANTASMA', 5]] as [$c, $p]) {
            $this->ins('fidelidade', ['id' => $c, 'pontos' => $p]);
        }
        $this->ins('cupom_usos', ['cupom_id' => 'cup-1', 'cliente_id' => 'CL-N00001']);
        $this->ins('cupom_usos', ['cupom_id' => 'cup-1', 'cliente_id' => 'CL-N00001']);
        $this->ins('cupom_usos', ['cupom_id' => 'cup-1', 'cliente_id' => '']);
        $this->ins('cupom_usos', ['cupom_id' => 'cup-2', 'cliente_id' => 'CL-N00002']);
        $this->ins('cupom_usos', ['cupom_id' => 'cup-3', 'cliente_id' => 'CL-N00003']);
    }

    private function stock(): void
    {
        $this->ins('estoque_logs', ['id' => 'log-1', 'produto_id' => 'prod-1', 'tipo' => 'entrada', 'quantidade' => 20, 'motivo' => 'Compra inicial', 'data_hora' => '2026-01-01 09:00:00', 'usuario' => 'dono']);
        $this->ins('estoque_logs', ['id' => 'log-2', 'produto_id' => 'prod-1', 'tipo' => 'saida', 'quantidade' => 1, 'motivo' => 'Venda no Agendamento AG-N000006', 'data_hora' => '2026-01-02 09:00:00', 'usuario' => 'recep']);
        $this->ins('estoque_logs', ['id' => 'log-3', 'produto_id' => 'prod-x', 'tipo' => 'entrada', 'quantidade' => 5, 'motivo' => '', 'data_hora' => '', 'usuario' => '']);
        $this->ins('estoque_logs', ['id' => 'log-4', 'produto_id' => 'prod-2', 'tipo' => 'misterioso', 'quantidade' => 5, 'motivo' => '', 'data_hora' => '', 'usuario' => '']);
    }

    private function finance(): void
    {
        $this->ins('despesas', ['id' => 'desp-1', 'descricao' => 'Aluguel', 'valor' => '2500.00', 'data_despesa' => '', 'data_vencimento' => '2026-08-05', 'data_pagamento' => '2026-08-05', 'categoria' => 'Fixas', 'status' => 'pago', 'recorrente' => '1', 'recorrencia_origem' => '']);
        $this->ins('despesas', ['id' => 'desp-2', 'descricao' => 'Aluguel', 'valor' => '2500.00', 'data_despesa' => '', 'data_vencimento' => '2026-09-05', 'data_pagamento' => '', 'categoria' => 'Fixas', 'status' => 'pendente', 'recorrente' => '1', 'recorrencia_origem' => 'desp-1']);
        $this->ins('despesas', ['id' => 'desp-3', 'descricao' => 'Luz', 'valor' => '310,75', 'data_despesa' => '2026-08-10', 'data_vencimento' => '', 'data_pagamento' => '', 'categoria' => 'Contas', 'status' => 'pendente', 'recorrente' => '', 'recorrencia_origem' => '']);
        $this->ins('despesas', ['id' => 'desp-4', 'descricao' => 'Status estranho', 'valor' => '10', 'data_despesa' => '', 'data_vencimento' => '', 'data_pagamento' => '', 'categoria' => '', 'status' => 'parcial', 'recorrente' => '', 'recorrencia_origem' => '']);
        $this->ins('despesas', ['id' => 'desp-5', 'descricao' => 'Sem valor', 'valor' => 'dez reais', 'data_despesa' => '', 'data_vencimento' => '', 'data_pagamento' => '', 'categoria' => '', 'status' => 'pago', 'recorrente' => '', 'recorrencia_origem' => '']);
        $this->ins('comissoes_pagas', ['id' => 'com-1', 'barbeiro_id' => 'br-1', 'valor' => '1840.50', 'data_pagamento' => '2026-08-31', 'periodo_inicio' => '2026-08-01', 'periodo_fim' => '2026-08-31', 'mes_ano' => '2026-08', 'valor_total_servicos' => '4601.25', 'gorjeta' => '120']);
        $this->ins('comissoes_pagas', ['id' => 'com-2', 'barbeiro_id' => 'br-77', 'valor' => '300', 'data_pagamento' => '2025-12-31', 'periodo_inicio' => '', 'periodo_fim' => '', 'mes_ano' => '2025-12', 'valor_total_servicos' => '', 'gorjeta' => '']);
        $this->ins('vales', ['id' => 'vale-1', 'barbeiro_id' => 'br-2', 'valor' => '200', 'data_vale' => '2026-08-15', 'mes_referencia' => '2026-08', 'descricao' => 'Adiantamento']);
        $this->ins('vales', ['id' => 'vale-2', 'barbeiro_id' => 'br-88', 'valor' => '50', 'data_vale' => '2026-02-15', 'mes_referencia' => 'fev', 'descricao' => '']);
        $this->ins('meta_financeira', ['id' => 1, 'valor' => '15000']);
    }

    private function misc(): void
    {
        $this->ins('campanhas', ['id' => 'camp-1', 'tipo' => 'email', 'template' => 'promo', 'assunto' => 'Promocao de setembro', 'corpo' => '<p>Texto</p>', 'segmento' => 'todos', 'extra_json' => '', 'total' => 3, 'enviados' => 2, 'falhas' => 1, 'status' => 'concluida', 'criada_em' => '2026-09-01 10:00:00', 'concluida_em' => '2026-09-01 10:05:00', 'criada_por' => 'dono']);
        foreach (['cliente1@exemplo.test', 'cliente2@exemplo.test', 'cliente3@exemplo.test'] as $k => $e) {
            $this->ins('campanha_destinatarios', ['campanha_id' => 'camp-1', 'cliente_id' => sprintf('CL-N%05d', $k + 1), 'nome' => 'Cliente', 'email' => $e, 'ref' => '', 'status' => $k < 2 ? 'enviado' : 'falha', 'enviado_em' => '2026-09-01 10:01:00']);
        }
        $this->ins('admin_atividade', ['usuario' => 'dono', 'acao' => 'Login', 'detalhes' => 'Painel', 'created_at' => '2026-09-01 08:00:00']);
        $this->ins('admin_atividade', ['usuario' => 'recep', 'acao' => 'Agendamento concluido', 'detalhes' => 'AG-N000001', 'created_at' => '2026-09-01 09:00:00']);
        $this->ins('config', ['chave' => 'aprovar', 'valor' => 'auto']);
        $this->ins('admin_crm_clientes', ['cliente_id' => 'CL-N00001', 'tags' => 'vip', 'observacoes' => 'Gosta de cafe', 'status_relacionamento' => 'ativo', 'updated_at' => '2026-05-01 10:00:00']);
        $this->ins('admin_lista_espera', ['cliente_id' => 'CL-N00002', 'nome' => 'Espera Ficticia', 'telefone' => '', 'barbeiro_id' => 'br-1', 'data_preferida' => '2026-10-01', 'periodo' => 'manha', 'observacoes' => '', 'status' => 'aguardando', 'created_at' => '2026-09-20 10:00:00']);
        $this->ins('admin_metas_equipe', ['barbeiro_id' => 'br-1', 'mes_ano' => '2026-09', 'meta_atendimentos' => 120, 'meta_faturamento' => 6000, 'meta_avaliacao' => 4.8]);
        $this->ins('admin_retencao', ['cliente_id' => 'CL-N00003', 'motivo' => 'Sumiu', 'oferta' => '10%', 'status' => 'pendente', 'updated_at' => '2026-05-01 10:00:00']);
        $this->ins('admin_conciliacao', ['referencia' => 'in_FICTICIO_0001', 'gateway' => 'stripe', 'status' => 'revisado', 'observacao' => '', 'updated_at' => '2026-08-02 10:00:00']);
        $this->ins('clientes_tokens', ['selector' => 'sel-ficticio', 'cliente_id' => 'CL-N00001', 'token_hash' => hash('sha256', 'token-ficticio'), 'expires_at' => 1893456000, 'created_at' => '2026-09-01 10:00:00', 'last_used_at' => '']);
        $this->ins('password_resets', ['email' => 'cliente1@exemplo.test', 'token' => hash('sha256', 'reset-ficticio'), 'expiry' => 1893456000]);
        $this->ins('login_throttle', ['chave' => 'login:ficticio', 'tentativas' => 2, 'bloqueio_ate' => 0, 'atualizado_em' => 1790000000]);
        $this->ins('sys_login_attempts', ['ip_address' => '192.0.2.10', 'attempts' => 1, 'last_attempt' => 1790000000]);
        $this->ins('schema_migracoes', ['versao' => 4, 'aplicada_em' => '2026-08-31 10:00:00']);
        $this->ins('tabela_misteriosa', ['id' => 1, 'dado' => 'ninguem sabe']);
    }
}
