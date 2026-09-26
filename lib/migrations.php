<?php
// lib/migrations.php
// Migrations centralizadas do schema SQLite.
//
// Antes, cada página fazia dezenas de "ALTER TABLE ... ADD COLUMN" dentro de
// try/catch a cada requisição. Aqui elas ficam num único lugar, são aplicadas
// de forma idempotente e — via tabela schema_migracoes — só executam de fato
// uma vez, deixando de rodar em todo page load.

if (!function_exists('migracaoTabelaExiste')) {
    /**
     * Verifica se uma tabela existe no banco.
     */
    function migracaoTabelaExiste(PDO $pdo, $tabela) {
        $tabela = preg_replace('/[^a-zA-Z0-9_]/', '', $tabela);
        try {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
            $stmt->execute([$tabela]);
            return (bool) $stmt->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Verifica se uma coluna já existe numa tabela (via PRAGMA table_info).
     */
    function migracaoColunaExiste(PDO $pdo, $tabela, $coluna) {
        $tabela = preg_replace('/[^a-zA-Z0-9_]/', '', $tabela);
        try {
            foreach ($pdo->query("PRAGMA table_info(" . $tabela . ")") as $col) {
                if (isset($col['name']) && strcasecmp($col['name'], $coluna) === 0) {
                    return true;
                }
            }
        } catch (Exception $e) {
            // tabela pode não existir ainda
        }
        return false;
    }

    /**
     * Adiciona uma coluna apenas se ela ainda não existir.
     */
    function migracaoAdicionarColuna(PDO $pdo, $tabela, $coluna, $definicao) {
        if (migracaoColunaExiste($pdo, $tabela, $coluna)) {
            return;
        }
        $tabelaLimpa  = preg_replace('/[^a-zA-Z0-9_]/', '', $tabela);
        $colunaLimpa  = preg_replace('/[^a-zA-Z0-9_]/', '', $coluna);
        try {
            $pdo->exec("ALTER TABLE {$tabelaLimpa} ADD COLUMN {$colunaLimpa} {$definicao}");
        } catch (Exception $e) {
            // tabela ainda não criada — será tentado numa próxima execução
        }
    }

    /**
     * Lista de colunas que podem faltar em bancos criados por versões antigas.
     * Formato: [tabela, coluna, definição].
     */
    function migracaoColunasPendentes() {
        return [
            // servicos
            ['servicos', 'descricao', "TEXT DEFAULT ''"],

            // agendamentos
            ['agendamentos', 'presenca_confirmada', "TEXT DEFAULT ''"],
            ['agendamentos', 'data_criacao', "TEXT"],
            ['agendamentos', 'payment_gateway', "TEXT DEFAULT ''"],
            ['agendamentos', 'gateway_reference', "TEXT DEFAULT ''"],

            // produtos
            ['produtos', 'estoque_minimo', "INTEGER DEFAULT 5"],
            ['produtos', 'custo', "REAL DEFAULT 0"],

            // barbeiros
            ['barbeiros', 'comissao', "REAL DEFAULT 50"],
            ['barbeiros', 'comissao_produtos', "REAL DEFAULT 0"],
            ['barbeiros', 'meta_diaria', "REAL DEFAULT 200"],
            ['barbeiros', 'comissao_assinatura_tipo', "TEXT DEFAULT 'padrao'"],
            ['barbeiros', 'comissao_assinatura_valor', "REAL DEFAULT 0"],

            // clientes
            ['clientes', 'notas_barbeiro', "TEXT DEFAULT ''"],
            ['clientes', 'confirmation_expira_em', "INTEGER DEFAULT 0"],

            // comissoes_pagas
            ['comissoes_pagas', 'gorjeta', "TEXT DEFAULT '0'"],
            ['comissoes_pagas', 'mes_ano', "TEXT"],
            ['comissoes_pagas', 'valor_total_servicos', "TEXT"],

            // despesas
            ['despesas', 'data_vencimento', "TEXT"],
            ['despesas', 'data_pagamento', "TEXT"],

            // horarios_trabalho
            ['horarios_trabalho', 'ativo', "TEXT"],

            // users
            ['users', 'role', "TEXT DEFAULT 'proprietario'"],
            ['users', 'permissions', "TEXT DEFAULT ''"],
        ];
    }

    /**
     * Cria um índice apenas se ele ainda não existir.
     * A tabela pode não ter sido criada ainda (install em andamento): nesse caso
     * a exceção é engolida e o índice será criado numa próxima execução.
     */
    function migracaoCriarIndice(PDO $pdo, $nome, $tabela, $colunas) {
        $nomeLimpo    = preg_replace('/[^a-zA-Z0-9_]/', '', $nome);
        $tabelaLimpa  = preg_replace('/[^a-zA-Z0-9_]/', '', $tabela);
        $colunasLimpa = implode(', ', array_map(function ($c) {
            return preg_replace('/[^a-zA-Z0-9_]/', '', $c);
        }, $colunas));
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS {$nomeLimpo} ON {$tabelaLimpa} ({$colunasLimpa})");
        } catch (Exception $e) {
            // tabela ainda não criada — será tentado numa próxima execução
        }
    }

    /**
     * Índices que faltavam na tabela `agendamentos` — a mais consultada do
     * sistema e, até aqui, sem nenhum índice além da PK (`id`). Cada consulta
     * abaixo fazia varredura completa da tabela.
     * Formato: [nome, tabela, [colunas]].
     */
    function migracaoIndicesPendentes() {
        return [
            // Disponibilidade de horário (lib/agendamento_functions.php) e checagem
            // de conflito ao reagendar (actions/gestao.php). Caminho mais quente do
            // sistema: roda a cada consulta de horários no fluxo de agendamento.
            ['idx_ag_barbeiro_data', 'agendamentos', ['barbeiro_id', 'data', 'hora']],

            // Agenda do dia (admin_tabs/agendamentos.php) e lembretes por data.
            // Incluir `hora` elimina também o ORDER BY hora.
            ['idx_ag_data_hora', 'agendamentos', ['data', 'hora']],

            // Relatórios e financeiro filtram por status='concluido' antes de
            // recortar o período; a limpeza de antigos filtra status + data.
            ['idx_ag_status_data', 'agendamentos', ['status', 'data']],

            // Histórico do cliente e contagem de atendimentos concluídos.
            ['idx_ag_cliente_status', 'agendamentos', ['cliente_id', 'status']],

            // Guarda de agendamento duplicado: WHERE (cliente_id=? OR email=? OR
            // telefone=?). O SQLite só usa a otimização de OR quando os TRÊS
            // ramos têm índice — por isso email e telefone entram separados.
            ['idx_ag_email', 'agendamentos', ['email']],
            ['idx_ag_telefone', 'agendamentos', ['telefone']],

            // clientes: buscas que antes varriam a tabela inteira.
            // codigo_indicacao serve getClientByReferralCode() e a checagem
            // de unicidade em gerarCodigoIndicacaoUnico(); email/telefone/cpf
            // sao os tres ramos do OR em getClientePorEmailTelefoneOuCPF()
            // (o SQLite so otimiza o OR quando TODOS os ramos tem indice).
            ['idx_clientes_codigo_indicacao', 'clientes', ['codigo_indicacao']],
            ['idx_clientes_email', 'clientes', ['email']],
            ['idx_clientes_telefone', 'clientes', ['telefone']],
            ['idx_clientes_cpf', 'clientes', ['cpf']],
        ];
    }

    /**
     * Colunas que receberam htmlspecialchars() na GRAVACAO ate 2026-08-31.
     * O valor ficava escapado no banco e era escapado de novo na exibicao, entao
     * "Jose D'Avila & Filho" aparecia como "Jose D&amp;#039;Avila &amp;amp; Filho".
     * Formato: [tabela, coluna].
     */
    function migracaoColunasDuplamenteEscapadas() {
        return [
            ['agendamentos', 'nome'], ['agendamentos', 'email'], ['agendamentos', 'observacoes'],
            ['clientes', 'nome'], ['clientes', 'email'],
            ['anotacoes_clientes', 'anotacao'],
            ['barbeiros', 'nome'], ['barbeiros', 'username'],
            ['servicos', 'nome'], ['servicos', 'descricao'],
            ['produtos', 'nome'],
            ['categorias', 'nome'],
            ['combos', 'nome'],
            ['planos', 'nome'],
            ['despesas', 'descricao'],
            ['vales', 'descricao'],
            ['estoque_logs', 'motivo'],
            ['vouchers', 'comprador'],
            ['campanhas', 'assunto'],
            ['respostas_avaliacoes', 'texto_resposta'],
            ['avaliacoes', 'comment'],
        ];
    }

    /**
     * Desfaz UM nivel de escape de htmlspecialchars().
     *
     * Nao usa html_entity_decode() de proposito: ela decodifica centenas de
     * entidades HTML, entao um texto que o cliente digitou como "&copy;" viraria
     * o simbolo. Aqui so revertemos exatamente o que htmlspecialchars() produz.
     *
     * A ORDEM importa: &amp; e trocado por ultimo. Se fosse primeiro, o texto
     * "&amp;lt;" (que representa o literal "&lt;") viraria "&lt;" e depois "<",
     * ou seja, dois niveis de decode em vez de um.
     */
    function migracaoDesescapar($valor) {
        if (!is_string($valor) || strpos($valor, '&') === false) {
            return $valor;
        }
        $valor = str_replace(['&lt;', '&gt;', '&quot;', '&#039;', '&#39;'],
                             ['<',    '>',    '"',      "'",      "'"], $valor);
        return str_replace('&amp;', '&', $valor);
    }

    /**
     * Marca/consulta migrations de DADOS (diferentes das de schema).
     *
     * Tem controle proprio porque a versao de schema so e estampada quando TODAS
     * as tabelas ja existem -- num banco incompleto ela reexecuta a cada
     * requisicao. Para migration de schema isso e inofensivo (idempotente), mas
     * um decode rodando duas vezes CORROMPERIA dados: "A&amp;amp;B" viraria
     * "A&B" em vez de "A&amp;B". Entao o marcador e gravado assim que o decode
     * termina, independente do estado das outras tabelas.
     */
    function migracaoDadosJaAplicada(PDO $pdo, $chave) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migracoes_dados (chave TEXT PRIMARY KEY, aplicada_em TEXT)");
            $stmt = $pdo->prepare("SELECT 1 FROM schema_migracoes_dados WHERE chave = ?");
            $stmt->execute([$chave]);
            return (bool) $stmt->fetchColumn();
        } catch (Exception $e) {
            // Sem conseguir ler o marcador, o seguro e NAO rodar.
            return true;
        }
    }

    function migracaoDadosMarcar(PDO $pdo, $chave) {
        try {
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO schema_migracoes_dados (chave, aplicada_em) VALUES (?, ?)");
            $stmt->execute([$chave, date('Y-m-d H:i:s')]);
        } catch (Exception $e) {
            // ignora
        }
    }

    /**
     * Roda UMA VEZ o decode das colunas duplamente escapadas.
     * @return int Quantidade de valores corrigidos.
     */
    function migracaoCorrigirEscapeDuplo(PDO $pdo) {
        $chave = 'escape_duplo_v1';
        if (migracaoDadosJaAplicada($pdo, $chave)) {
            return 0;
        }

        $corrigidos = 0;
        foreach (migracaoColunasDuplamenteEscapadas() as $alvo) {
            list($tabela, $coluna) = $alvo;
            if (!migracaoTabelaExiste($pdo, $tabela) || !migracaoColunaExiste($pdo, $tabela, $coluna)) {
                continue;
            }
            $t = preg_replace('/[^a-zA-Z0-9_]/', '', $tabela);
            $c = preg_replace('/[^a-zA-Z0-9_]/', '', $coluna);

            try {
                // rowid identifica a linha sem depender de haver PK utilizavel.
                $linhas = $pdo->query("SELECT rowid AS _rid, {$c} AS _v FROM {$t} WHERE {$c} LIKE '%&%'")
                              ->fetchAll(PDO::FETCH_ASSOC);
                if (!$linhas) {
                    continue;
                }
                $upd = $pdo->prepare("UPDATE {$t} SET {$c} = ? WHERE rowid = ?");
                foreach ($linhas as $linha) {
                    $novo = migracaoDesescapar($linha['_v']);
                    if ($novo !== $linha['_v']) {
                        $upd->execute([$novo, $linha['_rid']]);
                        $corrigidos++;
                    }
                }
            } catch (Exception $e) {
                if (function_exists('log_activity')) {
                    log_activity("Migration escape duplo: falha em {$t}.{$c}: " . $e->getMessage());
                }
            }
        }

        migracaoDadosMarcar($pdo, $chave);
        if ($corrigidos > 0 && function_exists('log_activity')) {
            log_activity("Migration escape duplo: {$corrigidos} valor(es) corrigido(s).");
        }
        return $corrigidos;
    }

    /**
     * Vincula opt-outs existentes ao ID do cliente.
     *
     * A tabela email_optout era chaveada so por e-mail. Se a barbearia trocasse o
     * e-mail de um cliente que pediu descadastro, o pedido deixava de valer e ele
     * voltava a receber campanha -- problema de LGPD, nao so de dados. Aqui as
     * linhas antigas recebem o cliente_id correspondente.
     *
     * Linhas sem cliente correspondente ficam com cliente_id vazio de proposito:
     * opt-out tambem vale para quem nunca teve cadastro.
     *
     * @return int Quantidade de vinculos criados.
     */
    function migracaoVincularOptoutAoCliente(PDO $pdo) {
        $chave = 'optout_cliente_id_v1';
        if (migracaoDadosJaAplicada($pdo, $chave)) {
            return 0;
        }
        if (!migracaoTabelaExiste($pdo, 'email_optout') || !migracaoTabelaExiste($pdo, 'clientes')) {
            // Sem as tabelas nao ha o que vincular; nao marca, tenta de novo depois.
            return 0;
        }
        if (!migracaoColunaExiste($pdo, 'email_optout', 'cliente_id')) {
            try {
                $pdo->exec("ALTER TABLE email_optout ADD COLUMN cliente_id TEXT DEFAULT ''");
            } catch (Exception $e) {
                return 0;
            }
        }

        $vinculados = 0;
        try {
            $pdo->exec(
                "UPDATE email_optout
                    SET cliente_id = (
                        SELECT c.id FROM clientes c
                         WHERE lower(trim(c.email)) = email_optout.email
                         LIMIT 1
                    )
                  WHERE (cliente_id IS NULL OR cliente_id = '')
                    AND EXISTS (
                        SELECT 1 FROM clientes c
                         WHERE lower(trim(c.email)) = email_optout.email
                    )"
            );
            $vinculados = (int) $pdo->query(
                "SELECT COUNT(*) FROM email_optout WHERE cliente_id IS NOT NULL AND cliente_id <> ''"
            )->fetchColumn();
        } catch (Exception $e) {
            if (function_exists('log_activity')) {
                log_activity('Migration opt-out: ' . $e->getMessage());
            }
            return 0;
        }

        migracaoDadosMarcar($pdo, $chave);
        return $vinculados;
    }

    /**
     * Cria os indices UNICOS de clientes (e-mail, telefone, CPF).
     *
     * Sao a unica defesa real contra dois cadastros simultaneos com o mesmo
     * e-mail: a checagem em PHP e "consulta e depois insere", e duas requisicoes
     * passam pela consulta antes de qualquer uma inserir.
     *
     * Sao indices PARCIAIS (WHERE campo <> '') porque telefone e CPF podem estar
     * legitimamente em branco em cadastros antigos -- sem o WHERE, o segundo
     * registro em branco seria recusado.
     *
     * Se o banco JA tiver duplicados, o CREATE falharia e derrubaria a migration.
     * Entao cada indice e checado antes: havendo duplicata, ela e registrada no
     * log e o indice fica para uma proxima vez, depois da limpeza manual.
     */
    function migracaoIndicesUnicosClientes(PDO $pdo) {
        if (!migracaoTabelaExiste($pdo, 'clientes')) {
            return;
        }

        $alvos = [
            ['idx_uq_clientes_email',    'LOWER(email)', "email <> '' AND email IS NOT NULL",       'e-mail'],
            ['idx_uq_clientes_telefone', 'telefone',     "telefone <> '' AND telefone IS NOT NULL", 'telefone'],
            ['idx_uq_clientes_cpf',      'cpf',          "cpf <> '' AND cpf IS NOT NULL",           'CPF'],
        ];

        foreach ($alvos as $alvo) {
            list($nome, $expressao, $filtro, $rotulo) = $alvo;

            try {
                // Ja existe? nada a fazer.
                $ja = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name = ?");
                $ja->execute([$nome]);
                if ($ja->fetchColumn()) {
                    continue;
                }

                // Ha duplicados hoje? Se sim, criar o indice falharia.
                $dup = $pdo->query(
                    "SELECT {$expressao} AS v, COUNT(*) AS n
                       FROM clientes
                      WHERE {$filtro}
                      GROUP BY {$expressao}
                     HAVING COUNT(*) > 1
                      LIMIT 5"
                )->fetchAll(PDO::FETCH_ASSOC);

                if ($dup) {
                    if (function_exists('log_activity')) {
                        $amostra = [];
                        foreach ($dup as $d) {
                            $amostra[] = $d['v'] . ' (' . $d['n'] . 'x)';
                        }
                        log_activity(
                            'Indice unico de ' . $rotulo . ' NAO criado: ha valores repetidos em clientes -> '
                            . implode(', ', $amostra) . '. Resolva os duplicados e o indice sera criado sozinho.'
                        );
                    }
                    continue;
                }

                $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS {$nome} ON clientes ({$expressao}) WHERE {$filtro}");
            } catch (Exception $e) {
                if (function_exists('log_activity')) {
                    log_activity('Falha ao criar indice unico de ' . $rotulo . ': ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Da prazo aos tokens de confirmacao que ja estavam pendentes.
     *
     * Ate agora o confirmation_token nao expirava: um link vazado ativava a conta
     * anos depois. A coluna confirmation_expira_em foi criada com default 0, e o
     * confirmar_email.php trata 0 como "sem prazo registrado" (valido) para nao
     * invalidar de uma vez cadastros em andamento.
     *
     * Aqui as linhas pendentes recebem uma janela contada a partir desta
     * atualizacao -- prazo justo para quem se cadastrou ontem, e fim do link
     * eterno para quem se cadastrou ha dois anos.
     *
     * @return int Quantidade de tokens que ganharam prazo.
     */
    function migracaoPrazoTokensConfirmacao(PDO $pdo) {
        $chave = 'prazo_confirmacao_v1';
        if (migracaoDadosJaAplicada($pdo, $chave)) {
            return 0;
        }
        if (!migracaoTabelaExiste($pdo, 'clientes')
            || !migracaoColunaExiste($pdo, 'clientes', 'confirmation_expira_em')) {
            return 0; // sem a coluna nao ha o que preencher; tenta na proxima
        }

        $horas = defined('CONFIRMACAO_VALIDADE_HORAS') ? (int) CONFIRMACAO_VALIDADE_HORAS : 48;
        $limite = time() + ($horas * 3600);

        try {
            $stmt = $pdo->prepare(
                "UPDATE clientes SET confirmation_expira_em = ?
                  WHERE confirmation_token IS NOT NULL
                    AND confirmation_token <> ''
                    AND (confirmation_expira_em IS NULL OR confirmation_expira_em = 0)"
            );
            $stmt->execute([$limite]);
            $n = $stmt->rowCount();
        } catch (Exception $e) {
            if (function_exists('log_activity')) {
                log_activity('Migration prazo de confirmacao: ' . $e->getMessage());
            }
            return 0;
        }

        migracaoDadosMarcar($pdo, $chave);
        if ($n > 0 && function_exists('log_activity')) {
            log_activity("Migration: {$n} token(s) de confirmacao receberam prazo de {$horas}h.");
        }
        return $n;
    }

    /**
     * Indice UNICO que impede dois atendimentos no mesmo profissional/data/hora.
     *
     * A reverificacao dentro da transacao (processar_agendamento.php) fecha a
     * maior parte da corrida, mas so o banco fecha de verdade: entre o SELECT e
     * o INSERT sempre ha um intervalo. Aqui o segundo INSERT simplesmente falha.
     *
     * ALCANCE, para nao passar impressao errada: isto pega mesmo HORARIO DE
     * INICIO. Nao pega SOBREPOSICAO -- um corte de 14:00 que ocupa dois slots
     * nao colide, para o banco, com outro marcado as 14:30. Sobreposicao continua
     * sendo responsabilidade da reverificacao em PHP, que conhece a duracao de
     * cada servico. As duas camadas se complementam.
     *
     * E PARCIAL: cancelado/rejeitado sai do indice, entao o horario volta a ficar
     * livre quando um atendimento e cancelado.
     *
     * Se ja houver duplicatas (a corrida pode ter acontecido antes desta
     * correcao), o indice nao e criado -- elas vao para o log para resolucao
     * manual, porque escolher qual atendimento apagar nao e decisao de migration.
     */
    /**
     * Garante UMA assinatura por cliente em clientes_assinaturas.
     *
     * Todo o sistema (salvarAssinaturaCliente, getAssinaturaClienteQualquerStatus,
     * o webhook do Stripe) trata a tabela como "uma linha por cliente" — lê com
     * LIMIT 1 e grava com UPDATE ... WHERE cliente_id = ?. Só que não havia
     * NENHUMA restrição no banco garantindo isso. Com duas linhas, o LIMIT 1
     * escolhe uma qualquer: um assinante em dia pode cair na linha expirada e
     * perder o benefício que pagou, e o UPDATE do webhook pode acertar a linha
     * errada.
     *
     * Aqui as duplicatas existentes são fundidas (fica a linha que vale mais
     * para o cliente) e um índice único passa a impedir novas.
     */
    function migracaoAssinaturaUnicaPorCliente(PDO $pdo) {
        if (!migracaoTabelaExiste($pdo, 'clientes_assinaturas')) {
            return;
        }

        $nome = 'idx_uq_assinatura_cliente';
        try {
            $ja = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='index' AND name = ?");
            $ja->execute([$nome]);
            if ($ja->fetchColumn()) {
                return; // já aplicada
            }

            $hoje = date('Y-m-d');
            $linhas = $pdo->query("SELECT rowid AS _rid, cliente_id, plano_id, data_fim, status,
                                          COALESCE(gateway_subscription_id, '') AS gsid
                                     FROM clientes_assinaturas")->fetchAll(PDO::FETCH_ASSOC);

            $porCliente = [];
            foreach ($linhas as $linha) {
                $porCliente[(string)($linha['cliente_id'] ?? '')][] = $linha;
            }

            // Ordena cada grupo pelo que mais interessa ao cliente: primeiro a
            // assinatura que ainda dá benefício, depois a de validade mais
            // longa, e por fim a que está ligada ao Stripe (quem cobra).
            $remover = [];
            $fundidos = [];
            foreach ($porCliente as $clienteId => $grupo) {
                if (count($grupo) < 2) {
                    continue;
                }
                usort($grupo, function ($a, $b) use ($hoje) {
                    $vale = function ($l) use ($hoje) {
                        return in_array((string)($l['status'] ?? ''), ['ativo', 'cancelamento_agendado'], true)
                            && (string)($l['data_fim'] ?? '') >= $hoje ? 0 : 1;
                    };
                    if ($vale($a) !== $vale($b)) return $vale($a) <=> $vale($b);
                    if (($a['data_fim'] ?? '') !== ($b['data_fim'] ?? '')) return strcmp((string)$b['data_fim'], (string)$a['data_fim']);
                    $temGw = function ($l) { return ((string)($l['gsid'] ?? '')) !== '' ? 0 : 1; };
                    if ($temGw($a) !== $temGw($b)) return $temGw($a) <=> $temGw($b);
                    return (int)$b['_rid'] <=> (int)$a['_rid'];
                });
                $mantida = array_shift($grupo);
                foreach ($grupo as $descartada) {
                    $remover[] = (int)$descartada['_rid'];
                }
                $fundidos[] = $clienteId . ' (ficou plano ' . ($mantida['plano_id'] ?? '?')
                    . ' ate ' . ($mantida['data_fim'] ?? '?') . ', descartadas ' . count($grupo) . ')';
            }

            if ($remover) {
                $pdo->exec("DELETE FROM clientes_assinaturas WHERE rowid IN (" . implode(',', $remover) . ")");
                if (function_exists('log_activity')) {
                    log_activity('Assinaturas duplicadas fundidas antes do indice unico: ' . implode('; ', $fundidos));
                }
            }

            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS {$nome} ON clientes_assinaturas (cliente_id)");
        } catch (Exception $e) {
            if (function_exists('log_activity')) {
                log_activity('Falha ao criar indice unico de assinatura por cliente: ' . $e->getMessage());
            }
        }
    }

    function migracaoIndiceUnicoSlot(PDO $pdo) {
        if (!migracaoTabelaExiste($pdo, 'agendamentos')) {
            return;
        }

        $nome = 'idx_uq_ag_slot';
        // aguardando_pagamento ENTRA no indice: o horario fica reservado enquanto
        // o cliente esta no checkout, igual ao que o getHorariosOcupados ja fazia.
        // Quando a janela vence, liberarAgendamentosPagamentoExpirado() muda o
        // status para cancelado e a linha sai do indice sozinha.
        $filtro = "barbeiro_id <> '' AND data <> '' AND hora <> ''"
                . " AND status NOT IN ('cancelado', 'cancelado_pelo_cliente', 'rejeitado')";

        try {
            // Se ja existe MAS com definicao antiga (sem aguardando_pagamento),
            // derruba para recriar. Indice e dado derivado: recriar nao perde nada.
            $ja = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='index' AND name = ?");
            $ja->execute([$nome]);
            $sqlAtual = (string) $ja->fetchColumn();
            if ($sqlAtual !== '') {
                if (strpos($sqlAtual, 'aguardando_pagamento') === false) {
                    return; // ja esta na forma nova
                }
                $pdo->exec("DROP INDEX IF EXISTS {$nome}");
            }

            $dup = $pdo->query(
                "SELECT barbeiro_id, data, hora, COUNT(*) AS n
                   FROM agendamentos
                  WHERE {$filtro}
                  GROUP BY barbeiro_id, data, hora
                 HAVING COUNT(*) > 1
                  LIMIT 10"
            )->fetchAll(PDO::FETCH_ASSOC);

            if ($dup) {
                if (function_exists('log_activity')) {
                    $amostra = [];
                    foreach ($dup as $d) {
                        $amostra[] = $d['data'] . ' ' . $d['hora'] . ' (prof. ' . $d['barbeiro_id'] . ', ' . $d['n'] . 'x)';
                    }
                    log_activity(
                        'Indice unico de horario NAO criado: ja existem atendimentos duplicados -> '
                        . implode('; ', $amostra)
                        . '. Cancele ou remarque os repetidos e o indice sera criado sozinho.'
                    );
                }
                return;
            }

            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS {$nome} ON agendamentos (barbeiro_id, data, hora) WHERE {$filtro}");
        } catch (Exception $e) {
            if (function_exists('log_activity')) {
                log_activity('Falha ao criar indice unico de horario: ' . $e->getMessage());
            }
        }
    }

    /**
     * Versão-alvo do schema. Incremente ao adicionar novas colunas em
     * migracaoColunasPendentes() para forçar a reaplicação em bancos existentes.
     */
    if (!defined('SCHEMA_VERSAO_ALVO')) {
        define('SCHEMA_VERSAO_ALVO', 4);
    }

    /**
     * Aplica todas as migrations pendentes uma única vez.
     * É idempotente e barata: se o banco já está na versão-alvo, retorna de imediato.
     *
     * @param PDO|null $pdo Conexão a usar (útil no install.php, onde getDB() falha por lock).
     */
    function aplicarMigracoes($pdo = null) {
        static $jaRodou = false;
        if ($jaRodou) {
            return;
        }
        $jaRodou = true;

        if ($pdo === null) {
            if (!function_exists('getDB')) {
                return;
            }
            $pdo = getDB();
        }

        // Controle de versão: evita reprocessar em toda requisição.
        $versaoAtual = 0;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migracoes (versao INTEGER PRIMARY KEY, aplicada_em TEXT)");
            $versaoAtual = (int) $pdo->query("SELECT MAX(versao) FROM schema_migracoes")->fetchColumn();
        } catch (Exception $e) {
            $versaoAtual = 0;
        }

        // Correcao de DADOS antes do portao de versao, de proposito: ela tem
        // marcador proprio (schema_migracoes_dados) e precisa alcancar bancos
        // que ja estao na versao-alvo de schema -- se ficasse depois do return
        // abaixo, nunca rodaria numa instalacao ja atualizada.
        migracaoCorrigirEscapeDuplo($pdo);
        migracaoVincularOptoutAoCliente($pdo);
        migracaoIndicesUnicosClientes($pdo);
        migracaoPrazoTokensConfirmacao($pdo);
        migracaoIndiceUnicoSlot($pdo);
        migracaoAssinaturaUnicaPorCliente($pdo);

        if ($versaoAtual >= SCHEMA_VERSAO_ALVO) {
            return;
        }

        $todasTabelasExistem = true;
        foreach (migracaoColunasPendentes() as $c) {
            if (!migracaoTabelaExiste($pdo, $c[0])) {
                $todasTabelasExistem = false;
            }
            migracaoAdicionarColuna($pdo, $c[0], $c[1], $c[2]);
        }

        // Índices depois das colunas: um índice pode referenciar coluna
        // recém-adicionada pelo laço acima.
        foreach (migracaoIndicesPendentes() as $i) {
            if (!migracaoTabelaExiste($pdo, $i[1])) {
                $todasTabelasExistem = false;
                continue;
            }
            migracaoCriarIndice($pdo, $i[0], $i[1], $i[2]);
        }

        // Indice por EXPRESSAO para LOWER(email): a busca de cliente compara o
        // e-mail sem diferenciar maiuscula/minuscula, e um indice na coluna crua
        // nao serviria. Fica fora de migracaoIndicesPendentes() porque o helper
        // sanitiza nomes de coluna e removeria o LOWER().
        if (migracaoTabelaExiste($pdo, 'clientes')) {
            try {
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clientes_email_lower ON clientes (LOWER(email))");
            } catch (Exception $e) {
                // banco antigo sem suporte a indice por expressao: segue sem ele
            }
        }

        // getClientePorEmailTelefoneOuCPF() compara telefone de forma exata
        // (para poder usar indice). Todo caminho de escrita do sistema ja
        // grava normalizado por limparTelefone(), mas linhas antigas ou
        // importadas podem ter mascara — aqui elas sao alinhadas uma vez.
        if (migracaoTabelaExiste($pdo, 'clientes')) {
            try {
                $semMascara = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefone,'(',''),')',''),' ',''),'-',''),'+',''),'.','')";
                $pdo->exec("UPDATE clientes SET telefone = {$semMascara} WHERE telefone IS NOT NULL AND telefone <> {$semMascara}");
            } catch (Exception $e) {
                // sem a coluna/tabela nao ha o que normalizar
            }
        }

        // Só marca a versão como aplicada quando todas as tabelas já existiam.
        // Num install ainda em andamento (tabelas sendo criadas), deixamos para
        // reaplicar na próxima chamada, evitando estampar o schema cedo demais.
        if ($todasTabelasExistem) {
            try {
                $stmt = $pdo->prepare("INSERT OR IGNORE INTO schema_migracoes (versao, aplicada_em) VALUES (?, ?)");
                $stmt->execute([SCHEMA_VERSAO_ALVO, date('Y-m-d H:i:s')]);
            } catch (Exception $e) {
                // sem controle de versão o pior caso é reexecutar (idempotente)
            }
        }
    }
}
