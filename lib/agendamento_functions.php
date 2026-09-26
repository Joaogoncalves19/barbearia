<?php
// lib/agendamento_functions.php
// Contém a lógica de agendamento, verificação de horários e disponibilidade (Otimizado para SQLite).

/**
 * ATUALIZADO (SQLite Nativo): Retorna um array com todos os slots de 30 minutos já ocupados.
 * Depende de: getHorariosBloqueados(), getHorariosAlmocoBloqueados()
 *
 * @param string $barbeiro_id O ID do barbeiro.
 * @param string $data A data no formato 'Y-m-d'.
 * @return array Um array de horários 'H:i' ocupados.
 */
/** Por quantos minutos um agendamento segura o horario aguardando pagamento. */
if (!defined('PAGAMENTO_PENDENTE_MINUTOS')) {
    define('PAGAMENTO_PENDENTE_MINUTOS', 15);
}

/**
 * Libera horarios presos em 'aguardando_pagamento' alem do prazo.
 *
 * O horario FICA reservado enquanto o cliente vai ao checkout -- senao duas
 * pessoas chegariam a pagar pelo mesmo slot. Passado o prazo sem confirmacao,
 * o agendamento e cancelado e o horario volta a ficar livre.
 *
 * Isto vivia duplicado em get_horarios.php e email_templates/get_horarios.php,
 * e o processar_agendamento.php nao chamava nenhum dos dois. Resultado: a lista
 * de horarios limpava e mostrava o slot livre, mas a gravacao (que le direto o
 * getHorariosOcupados) ainda o via ocupado -- o cliente clicava num horario
 * livre e recebia "ja esta ocupado". Agora mora aqui e e chamada de dentro do
 * proprio getHorariosOcupados(), entao todos os caminhos enxergam o mesmo.
 *
 * Roda no maximo uma vez por requisicao.
 *
 * @return int Quantidade de horarios liberados.
 */
function liberarAgendamentosPagamentoExpirado() {
    static $jaRodou = false;
    if ($jaRodou) {
        return 0;
    }
    $jaRodou = true;

    try {
        $pdo = getDB();
        $limite = date('Y-m-d H:i:s', strtotime('-' . PAGAMENTO_PENDENTE_MINUTOS . ' minutes'));

        // Confere antes de escrever: na maioria das requisicoes nao ha nada a
        // liberar, e este e um caminho de LEITURA.
        $ha = $pdo->prepare(
            "SELECT 1 FROM agendamentos
              WHERE status = 'aguardando_pagamento'
                AND data_criacao IS NOT NULL AND data_criacao <> '' AND data_criacao < ?
              LIMIT 1"
        );
        $ha->execute([$limite]);
        if (!$ha->fetchColumn()) {
            return 0;
        }

        $stmt = $pdo->prepare(
            "UPDATE agendamentos
                SET status = 'cancelado',
                    observacoes = 'Cancelado: pagamento não confirmado em " . PAGAMENTO_PENDENTE_MINUTOS . " minutos.'
              WHERE status = 'aguardando_pagamento'
                AND data_criacao IS NOT NULL AND data_criacao <> '' AND data_criacao < ?"
        );
        $stmt->execute([$limite]);
        $n = $stmt->rowCount();

        if ($n > 0 && function_exists('log_activity')) {
            log_activity("Agendamentos liberados por pagamento nao confirmado: {$n}.");
        }
        return $n;
    } catch (Exception $e) {
        // Tabela/coluna ausente ou banco ocupado: nao derruba a pagina.
        return 0;
    }
}

function getHorariosOcupados($barbeiro_id, $data) {
    // Libera horarios cuja janela de pagamento venceu, para que TODOS os
    // caminhos (lista de horarios, gravacao, painel) vejam o mesmo estado.
    liberarAgendamentosPagamentoExpirado();

    $horariosOcupados = [];
    $pdo = getDB();

    // Carrega os serviços para saber a duração (slots) de cada um (Removemos o .txt)
    $servicosArr = lerDados('servicos', ['id', 'nome', 'valor', 'slots']);
    $combosArr = lerDados('combos', ['id', 'servicos_ids']);

    try {
        // Busca apenas os agendamentos que importam: daquela data, daquele barbeiro e que não estejam cancelados
        $stmt = $pdo->prepare("SELECT hora, servicos_ids FROM agendamentos WHERE data = ? AND barbeiro_id = ? AND status NOT IN ('cancelado', 'cancelado_pelo_cliente')");
        $stmt->execute([$data, $barbeiro_id]);
        
        while ($row = $stmt->fetch()) {
            $hora = $row['hora'] ?? '';
            $servicos_ids = $row['servicos_ids'] ?? '';

            // Calcula o total de slots REAIS baseado nos serviços do agendamento
            $totalSlots = 0;
            $ids = explode(',', $servicos_ids);
            foreach ($ids as $sid) {
                $sid_limpo = trim($sid);
                if (isset($servicosArr[$sid_limpo]) && !empty($servicosArr[$sid_limpo]['slots'])) {
                    $totalSlots += (int)$servicosArr[$sid_limpo]['slots'];
                } elseif (isset($combosArr[$sid_limpo])) {
                    foreach (array_filter(array_map('trim', explode(',', (string)$combosArr[$sid_limpo]['servicos_ids']))) as $servicoComboId) {
                        $totalSlots += max(1, (int)($servicosArr[$servicoComboId]['slots'] ?? 1));
                    }
                } else {
                    $totalSlots += 1; 
                }
            }

            // Adiciona os slots ocupados com base na duração total (totalSlots)
            if (!empty($hora)) {
                $timeParts = explode(':', $hora);
                if (count($timeParts) == 2) {
                    $startMinutes = (intval($timeParts[0]) * 60) + intval($timeParts[1]);
                    
                    for ($i = 0; $i < $totalSlots; $i++) {
                        $minutes = $startMinutes + ($i * 30);
                        $formatted = sprintf('%02d:%02d', floor($minutes / 60), $minutes % 60);
                        $horariosOcupados[] = $formatted;
                    }
                }
            }
        }
    } catch (PDOException $e) {
        // Tabela pode não existir ainda
        if (function_exists('log_activity')) {
            log_activity("Aviso em getHorariosOcupados: " . $e->getMessage());
        }
    }

    // Adiciona bloqueios manuais e horários de almoço à lista de ocupados
    $horariosOcupados = array_merge($horariosOcupados, getHorariosBloqueados($barbeiro_id, $data)); 
    $horariosOcupados = array_merge($horariosOcupados, getHorariosAlmocoBloqueados($barbeiro_id)); 
    
    return array_values(array_unique($horariosOcupados));
}

/**
 * ATUALIZADO (SQLite): Retorna um array de horários bloqueados manualmente.
 *
 * @param string $barbeiro_id O ID do barbeiro.
 * @param string $data A data no formato 'Y-m-d'.
 * @return array Um array de horários 'H:i' bloqueados.
 */
function getHorariosBloqueados($barbeiro_id, $data) {
    $horariosBloqueados = [];
    $pdo = getDB();
    
    try {
        $stmt = $pdo->prepare("SELECT hora FROM horarios_bloqueados WHERE barbeiro_id = ? AND data = ?");
        $stmt->execute([$barbeiro_id, $data]);
        
        while ($row = $stmt->fetch()) {
            if (!empty($row['hora'])) {
                $horariosBloqueados[] = $row['hora'];
            }
        }
    } catch (PDOException $e) {
        // Tabela pode não existir ainda, ignora
    }
    
    return array_values(array_unique($horariosBloqueados));
}

/**
 * ATUALIZADO (SQLite): Retorna os slots (1 hora = 2 slots) bloqueados para o almoço.
 *
 * @param string $barbeiro_id O ID do barbeiro.
 * @return array Um array de 2 horários 'H:i' bloqueados para almoço.
 */
function getHorariosAlmocoBloqueados($barbeiro_id) {
    $horariosAlmoco = [];
    $pdo = getDB();

    try {
        $stmt = $pdo->prepare("SELECT horario FROM config_almoco_barbeiro WHERE barbeiro_id = ? AND status = 'ativo'");
        $stmt->execute([$barbeiro_id]);
        
        if ($row = $stmt->fetch()) {
            if (!empty($row['horario'])) {
                $horario_inicio = $row['horario'];
                $horariosAlmoco[] = $horario_inicio;
                $horariosAlmoco[] = date('H:i', strtotime($horario_inicio . ' +30 minutes'));
            }
        }
    } catch (PDOException $e) {
        // Tabela pode não existir ainda, ignora
    }
    
    return $horariosAlmoco;
}

/**
 * ATUALIZADO (SQLite): Retorna o horário de início e fim de trabalho.
 *
 * @param string $barbeiroId O ID do barbeiro.
 * @param int $diaSemana O dia da semana (0 = Domingo, 1 = Segunda, ...).
 * @return array|null ['inicio' => 'H:i', 'fim' => 'H:i'] ou null se não houver.
 */
function getHorarioDeTrabalho($barbeiroId, $diaSemana, $data = null) {
    $pdo = getDB();

    // Se o profissional estiver de folga/férias na data, não há expediente —
    // isso bloqueia automaticamente a agenda em todos os fluxos de reserva.
    if ($data !== null && barbeiroEstaAusente($barbeiroId, $data)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT inicio, fim FROM horarios_trabalho WHERE barbeiro_id = ? AND dia = ?");
        $stmt->execute([$barbeiroId, $diaSemana]);

        if ($row = $stmt->fetch()) {
            return ['inicio' => $row['inicio'], 'fim' => $row['fim']];
        }
    } catch (PDOException $e) {
         // Tabela pode não existir ainda, ignora
    }

    return null;
}

/**
 * Garante a existência da tabela de ausências (folgas / férias / afastamentos).
 */
function garantirTabelaAusencias() {
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS barbeiro_ausencias (
        id TEXT PRIMARY KEY,
        barbeiro_id TEXT NOT NULL,
        data_inicio TEXT NOT NULL,
        data_fim TEXT NOT NULL,
        tipo TEXT DEFAULT 'folga',
        motivo TEXT DEFAULT '',
        criado_em TEXT DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ausencias_barbeiro ON barbeiro_ausencias (barbeiro_id)");
}

/**
 * Verifica se o barbeiro está ausente (folga/férias) em determinada data.
 *
 * @return array|false Registro da ausência ou false.
 */
function barbeiroEstaAusente($barbeiro_id, $data) {
    if (empty($barbeiro_id) || empty($data)) return false;
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("SELECT * FROM barbeiro_ausencias WHERE barbeiro_id = ? AND data_inicio <= ? AND data_fim >= ? LIMIT 1");
        $stmt->execute([$barbeiro_id, $data, $data]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: false;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Lista as ausências de um barbeiro (por padrão, apenas as que ainda não terminaram).
 */
function getAusenciasBarbeiro($barbeiro_id, $apenasVigentes = true) {
    $pdo = getDB();
    try {
        if ($apenasVigentes) {
            $stmt = $pdo->prepare("SELECT * FROM barbeiro_ausencias WHERE barbeiro_id = ? AND data_fim >= ? ORDER BY data_inicio ASC");
            $stmt->execute([$barbeiro_id, date('Y-m-d')]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM barbeiro_ausencias WHERE barbeiro_id = ? ORDER BY data_inicio DESC");
            $stmt->execute([$barbeiro_id]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Rótulo amigável para o tipo de ausência.
 */
function rotuloTipoAusencia($tipo) {
    $mapa = ['folga' => 'Folga', 'ferias' => 'Férias', 'atestado' => 'Atestado/Afastamento', 'outro' => 'Indisponível'];
    return $mapa[$tipo] ?? 'Indisponível';
}

/**
 * Aplica os efeitos colaterais da conclusão de um atendimento
 * (e-mail de avaliação, ponto de fidelidade e recompensa de indicação).
 * Reutilizada tanto pela conclusão rápida quanto pelo fechamento de comanda.
 */
function aplicarEfeitosConclusaoAtendimento($pdo, $agendamento_atual, $cliente = null) {
    // E-mail de pedido de avaliação (idêntico ao enviado pela aba de Avaliações)
    if (!empty($agendamento_atual['email']) && filter_var($agendamento_atual['email'], FILTER_VALIDATE_EMAIL)) {
        $ag_id = $agendamento_atual['id'] ?? '';
        $barbeiroNome = 'nosso time';
        try {
            $stmtB = $pdo->prepare("SELECT nome FROM barbeiros WHERE id = ?");
            $stmtB->execute([$agendamento_atual['barbeiro_id'] ?? '']);
            $barbeiroNome = $stmtB->fetchColumn() ?: 'nosso time';
        } catch (Exception $e) {}
        enviarEmail($agendamento_atual['email'], 'Como foi sua experiência? Deixe sua avaliação!', 'lembrete_avaliacao', [
            'nome_cliente'     => $agendamento_atual['nome'] ?? '',
            'data_agendamento' => date('d/m/Y', strtotime($agendamento_atual['data'] ?? 'now')),
            'barbeiro'         => $barbeiroNome,
            'link_avaliacao'   => (function_exists('avaliacaoLink') && $ag_id) ? avaliacaoLink($ag_id) : (defined('BASE_URL') ? BASE_URL . 'cliente.php' : 'cliente.php'),
        ]);
    }

    if (!$cliente) return;

    // Ponto de fidelidade (exceto assinantes ativos)
    $configFidelidade = getFidelityConfig();
    if (($configFidelidade['ativado'] ?? 0)) {
        // getAssinaturaCliente() só retorna quando há benefício vigente (status
        // ativo OU cancelamento_agendado E dentro da validade). Assinante com
        // benefício ativo não acumula fidelidade — evita benefício em dobro.
        $assinatura = getAssinaturaCliente($cliente['id']);
        if (!$assinatura) {
            $ganho = fidelidadePontosPorAtendimento($pdo, $agendamento_atual);
            if ($ganho > 0) {
                updateClientFidelityPoints($cliente['id'], getClientFidelityPoints($cliente['id']) + $ganho, "Agendamento concluído");
            }
        }
    }

    // Recompensa de indicação (na primeira conclusão do indicado)
    if (!empty($cliente['indicado_por_id'])) {
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM agendamentos WHERE cliente_id = ? AND status = 'concluido'");
        $stmtCount->execute([$cliente['id']]);
        if ($stmtCount->fetchColumn() == 1) {
            processarRecompensaIndicacao($cliente['indicado_por_id'], $cliente['nome']);
        }
    }
}

/**
 * Calcula o desconto de assinatura para um conjunto de serviços/combos de um
 * cliente. Zera (do ponto de vista de cobrança) os serviços cobertos pelo plano
 * ativo — inclusive quando o cliente assinou depois de agendar ou quando o
 * barbeiro adiciona um serviço extra coberto direto na comanda.
 *
 * Espelha a "lógica infalível" usada no agendamento online (processar_agendamento.php)
 * e no admin (actions/agendamentos.php), centralizando-a num único ponto.
 *
 * @param string $cliente_id       ID do cliente (vazio => sem assinatura).
 * @param string $servicos_ids_csv IDs de serviços/combos separados por vírgula.
 * @return array{desconto: float, tipo: string, plano_nome: string, cobertos: array, valor_tabela: float, a_pagar: float}
 */
function calcularDescontoAssinaturaCliente($cliente_id, $servicos_ids_csv) {
    $resultado = [
        'desconto'     => 0.0,
        'tipo'         => '',
        'plano_nome'   => '',
        'cobertos'     => [],
        'valor_tabela' => 0.0,
        'a_pagar'      => 0.0,
    ];
    if (empty($cliente_id) || !function_exists('getAssinaturaCliente')) {
        return $resultado;
    }

    $assinatura = getAssinaturaCliente($cliente_id);
    if (!$assinatura) {
        return $resultado;
    }

    $planosArr = lerDados('planos', ['id', 'nome', 'valor', 'servicos_ids']) ?: [];
    $plano = $planosArr[$assinatura['plano_id']] ?? null;
    if (!$plano) {
        return $resultado;
    }

    $servicosArr = lerDados('servicos', ['id', 'nome', 'valor']) ?: [];
    $combosArr   = lerDados('combos', ['id', 'nome', 'valor', 'servicos_ids']) ?: [];
    $cobertosPlano = array_filter(array_map('trim', explode(',', (string)($plano['servicos_ids'] ?? ''))));

    $calc = descontoAssinaturaSobreItens($servicos_ids_csv, $cobertosPlano, $servicosArr, $combosArr);
    $resultado['valor_tabela'] = $calc['valor_tabela'];
    $resultado['a_pagar']      = $calc['a_pagar'];
    $resultado['cobertos']     = $calc['cobertos'];
    $resultado['plano_nome']   = $plano['nome'] ?? '';
    if ($calc['desconto'] > 0) {
        $resultado['desconto'] = $calc['desconto'];
        $resultado['tipo']     = 'assinatura_vip';
    }
    return $resultado;
}

/**
 * Núcleo puro do cálculo do benefício de assinatura sobre um conjunto de itens,
 * usando arrays já carregados em memória (sem tocar no banco). Reutilizado pelas
 * listagens do painel do barbeiro para não multiplicar consultas por linha.
 *
 * @param string $servicos_ids_csv        IDs de serviços/combos separados por vírgula.
 * @param array  $servicosInclusosNoPlano IDs de serviços cobertos pelo plano.
 * @param array  $servicosArr             Mapa id => ['valor', ...] de serviços.
 * @param array  $combosArr               Mapa id => ['valor', 'servicos_ids', ...] de combos.
 * @return array{desconto: float, cobertos: array, valor_tabela: float, a_pagar: float}
 */
function descontoAssinaturaSobreItens($servicos_ids_csv, array $servicosInclusosNoPlano, array $servicosArr, array $combosArr) {
    $valorTabela = 0.0;
    $aPagar      = 0.0;
    $cobertos    = [];
    foreach (array_filter(array_map('trim', explode(',', (string)$servicos_ids_csv))) as $item) {
        if (isset($combosArr[$item])) {
            $valorCombo = (float)$combosArr[$item]['valor'];
            $valorTabela += $valorCombo;
            $custoNaoCoberto = 0.0;
            $algumCoberto = false;
            foreach (array_filter(array_map('trim', explode(',', (string)($combosArr[$item]['servicos_ids'] ?? '')))) as $sc) {
                if (isset($servicosArr[$sc])) {
                    if (in_array($sc, $servicosInclusosNoPlano, true)) { $algumCoberto = true; }
                    else { $custoNaoCoberto += (float)$servicosArr[$sc]['valor']; }
                }
            }
            if ($algumCoberto) {
                $aPagar += min($valorCombo, $custoNaoCoberto);
                $cobertos[] = $item;
            } else {
                $aPagar += $valorCombo;
            }
        } elseif (isset($servicosArr[$item])) {
            $valorTabela += (float)$servicosArr[$item]['valor'];
            if (in_array($item, $servicosInclusosNoPlano, true)) {
                $cobertos[] = $item;
            } else {
                $aPagar += (float)$servicosArr[$item]['valor'];
            }
        }
    }
    return [
        'desconto'     => max(0.0, $valorTabela - $aPagar),
        'cobertos'     => $cobertos,
        'valor_tabela' => $valorTabela,
        'a_pagar'      => $aPagar,
    ];
}

/**
 * Garante as colunas usadas pela comanda digital na tabela de agendamentos.
 */
function garantirColunasComanda() {
    $pdo = getDB();
    try { $pdo->exec("ALTER TABLE agendamentos ADD COLUMN gorjeta REAL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE agendamentos ADD COLUMN forma_pagamento TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE agendamentos ADD COLUMN comanda_fechada_em TEXT DEFAULT ''"); } catch (Exception $e) {}
}

/**
 * Rótulo amigável para a forma de pagamento.
 */
function rotuloFormaPagamento($forma) {
    $mapa = [
        'dinheiro' => 'Dinheiro',
        'pix' => 'PIX',
        'debito' => 'Cartão de débito',
        'credito' => 'Cartão de crédito',
        'outro' => 'Outro',
    ];
    return $mapa[$forma] ?? '';
}

/**
 * ATUALIZADO (SQLite Nativo): Adiciona um produto vendido a um agendamento existente.
 *
 * @param string $agendamento_id O ID do agendamento.
 * @param string $nome_produto O nome do produto.
 * @param float $valor_produto O valor do produto.
 * @return bool True se foi salvo com sucesso.
 */
function adicionarProdutoAoAgendamento($agendamento_id, $nome_produto, $valor_produto) {
    $pdo = getDB();
    
    try {
        // Busca os produtos atuais daquele agendamento
        $stmt = $pdo->prepare("SELECT produtos_vendidos FROM agendamentos WHERE id = ?");
        $stmt->execute([$agendamento_id]);
        $row = $stmt->fetch();
        
        if ($row) {
            $produtos = !empty($row['produtos_vendidos']) ? json_decode($row['produtos_vendidos'], true) : [];
            if (!is_array($produtos)) {
                $produtos = [];
            }
            
            // Adiciona o novo produto
            $produtos[] = ['nome' => $nome_produto, 'valor' => $valor_produto];
            $produtos_json = json_encode($produtos);
            
            // Atualiza diretamente na tabela
            $stmtUp = $pdo->prepare("UPDATE agendamentos SET produtos_vendidos = ? WHERE id = ?");
            $stmtUp->execute([$produtos_json, $agendamento_id]);
            
            return true;
        }
    } catch (PDOException $e) {
        if (function_exists('log_activity')) {
            log_activity("Erro ao adicionar produto no agendamento no SQLite: " . $e->getMessage());
        }
    }
    
    return false;
}

/**
 * ATUALIZADO (SQLite Nativo): Verifica se é o primeiro agendamento de um cliente.
 * * @param string $cliente_id O ID do cliente (ex: 'CL-XXXXXX')
 * @return bool True se for o primeiro agendamento, False caso contrário.
 */
function isPrimeiroAgendamento($cliente_id) {
    $cliente = getClientById($cliente_id); 
    if (!$cliente) return false;

    $pdo = getDB();
    
    try {
        $telefone_limpo = limparTelefone($cliente['telefone'] ?? '');
        $email = $cliente['email'] ?? '';
        
        // Conta quantos agendamentos válidos existem para esse cliente
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM agendamentos WHERE (cliente_id = ? OR email = ? OR telefone = ?) AND status NOT IN ('cancelado', 'cancelado_pelo_cliente', 'reprovado', 'nao_compareceu')");
        $stmt->execute([$cliente_id, $email, $telefone_limpo]);
        $quantidade_agendamentos = $stmt->fetchColumn();
        
        // Se a contagem for zero, é o primeiro agendamento
        return ($quantidade_agendamentos == 0);
        
    } catch (PDOException $e) {
        // Se a tabela não existir ou der erro, assume que é o primeiro
        return true;
    }
}

/**
 * Garante as colunas de controle de lembrete em 'agendamentos':
 *  - 'lembrete_data'     : DATA para a qual a VÉSPERA já foi enviada (idempotência
 *                          por data; remarcar para outro dia volta a ser elegível).
 *  - 'lembrete_hora_em'  : timestamp em que o lembrete de "horas antes" foi enviado
 *                          (vazio = ainda não enviado).
 */
function _garantirColunaLembrete($pdo) {
    try {
        $existentes = [];
        foreach ($pdo->query("PRAGMA table_info(agendamentos)") as $col) {
            $existentes[($col['name'] ?? '')] = true;
        }
        if (empty($existentes['lembrete_data']))       $pdo->exec("ALTER TABLE agendamentos ADD COLUMN lembrete_data TEXT DEFAULT ''");
        if (empty($existentes['lembrete_hora_em']))    $pdo->exec("ALTER TABLE agendamentos ADD COLUMN lembrete_hora_em TEXT DEFAULT ''");
        if (empty($existentes['presenca_confirmada'])) $pdo->exec("ALTER TABLE agendamentos ADD COLUMN presenca_confirmada TEXT DEFAULT ''");
    } catch (Exception $e) {
        if (function_exists('log_activity')) log_activity('Falha ao garantir colunas de lembrete: ' . $e->getMessage());
    }
}

/** Token anti-adulteração do link público de confirmação de presença. */
function tokenPresenca($agId) {
    $segredo = function_exists('getCronToken') ? getCronToken() : 'barbearia';
    return substr(hash_hmac('sha256', 'presenca:' . $agId, $segredo), 0, 24);
}

/** URL pública (um clique, sem login) para o cliente confirmar presença. */
function linkConfirmarPresenca($agId) {
    $base = function_exists('siteUrl') ? siteUrl() : (defined('BASE_URL') ? BASE_URL : '');
    return rtrim($base, '/') . '/confirmar_presenca.php?ag=' . urlencode($agId) . '&t=' . tokenPresenca($agId);
}

/**
 * Envia um lembrete pelos canais disponíveis (e-mail + notificação no app) para
 * um agendamento. Reaproveitado pela véspera e pelo "horas antes".
 * @return array ['email'=>bool, 'app'=>bool, 'sem_contato'=>bool, 'falha_email'=>bool]
 */
function _lembreteEnviarCanais($ag, $servicosArr, $combosArr, $barbeirosArr, $assunto, $notifPrefixo, $extraDados = []) {
    $servicosNomes = array_map(function ($sid) use ($servicosArr, $combosArr) {
        $sid = trim($sid);
        if (isset($servicosArr[$sid])) return $servicosArr[$sid]['nome'];
        if (isset($combosArr[$sid])) return $combosArr[$sid]['nome'] . ' (Combo)';
        return 'Item (removido)';
    }, explode(',', (string)($ag['servicos_ids'] ?? '')));

    $barbeiroNome = $barbeirosArr[$ag['barbeiro_id']]['nome'] ?? 'Não especificado';
    $horaFmt = substr((string)($ag['hora'] ?? ''), 0, 5);
    $emailValido = !empty($ag['email']) && filter_var($ag['email'], FILTER_VALIDATE_EMAIL);
    $temConta = !empty($ag['cliente_id']);
    $out = ['email' => false, 'app' => false, 'sem_contato' => false, 'falha_email' => false];

    if ($emailValido) {
        $dados_email = array_merge([
            'nome_cliente'     => $ag['nome'],
            'data_agendamento' => date('d/m/Y', strtotime($ag['data'])),
            'hora_agendamento' => $ag['hora'],
            'servicos'         => $servicosNomes,
            'barbeiro'         => $barbeiroNome,
            'tipo_desconto'    => $ag['tipo_desconto'] ?? '',
        ], $extraDados);
        if (function_exists('enviarEmail') && enviarEmail($ag['email'], $assunto, 'lembrete', $dados_email)) {
            $out['email'] = true;
        } else {
            $out['falha_email'] = true;
        }
    }
    if ($temConta && function_exists('criarNotificacao')) {
        $primeiro = explode(' ', trim((string)$ag['nome']))[0];
        $servTxt = implode(', ', array_slice($servicosNomes, 0, 3));
        criarNotificacao($ag['cliente_id'], "$notifPrefixo — {$servTxt} com {$barbeiroNome}. Até logo, {$primeiro}!");
        $out['app'] = true;
    }
    if (!$emailValido && !$temConta) $out['sem_contato'] = true;
    return $out;
}

/**
 * Envia os lembretes dos agendamentos de um dia (padrão: amanhã) por e-mail e,
 * quando o cliente tem conta, também por notificação no app. É idempotente:
 * cada agendamento só é lembrado uma vez por data (coluna lembrete_data).
 *
 * @param string|null $data      Data alvo 'Y-m-d' (null = amanhã).
 * @param string      $origem    'manual' | 'cron' (só para o log).
 * @return array  ['data','total','email','app','ja_lembrados','sem_contato','falhas']
 */
function enviarLembretesAgendamentos($data = null, $origem = 'manual') {
    $alvo = $data ?: date('Y-m-d', strtotime('+1 day'));
    $pdo = getDB();
    _garantirColunaLembrete($pdo);

    $res = ['data' => $alvo, 'total' => 0, 'email' => 0, 'app' => 0, 'ja_lembrados' => 0, 'sem_contato' => 0, 'falhas' => 0];

    // Só agendamentos confirmados e ainda não lembrados PARA ESSA data.
    $stmt = $pdo->prepare("SELECT * FROM agendamentos
        WHERE data = ? AND status = 'aprovado'
          AND (lembrete_data IS NULL OR lembrete_data = '' OR lembrete_data <> ?)");
    $stmt->execute([$alvo, $alvo]);
    $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $res['total'] = count($lista);
    if (empty($lista)) return $res;

    $barbeirosArr = function_exists('lerDados') ? lerDados('barbeiros', ['id', 'nome']) : [];
    $servicosArr  = function_exists('lerDados') ? lerDados('servicos', ['id', 'nome']) : [];
    $combosArr    = function_exists('lerDados') ? lerDados('combos', ['id', 'nome']) : [];
    $marcar = $pdo->prepare("UPDATE agendamentos SET lembrete_data = ? WHERE id = ?");

    foreach ($lista as $ag) {
        $horaFmt = substr((string)($ag['hora'] ?? ''), 0, 5);
        $extra = ['tipo_lembrete' => 'vespera', 'quando_txt' => 'amanhã'];
        if (empty($ag['presenca_confirmada'])) $extra['link_confirmar'] = linkConfirmarPresenca($ag['id']);
        $c = _lembreteEnviarCanais($ag, $servicosArr, $combosArr, $barbeirosArr,
            'Lembrete do seu Agendamento', "⏰ Lembrete: você tem horário amanhã ({$horaFmt})", $extra);
        if ($c['email']) $res['email']++;
        if ($c['app']) $res['app']++;
        if ($c['falha_email']) $res['falhas']++;
        if ($c['sem_contato']) { $res['sem_contato']++; }
        if ($c['email'] || $c['app']) $marcar->execute([$alvo, $ag['id']]);
    }

    if (function_exists('log_activity')) {
        log_activity("Lembretes véspera ($origem) para $alvo: {$res['email']} e-mail, {$res['app']} app, {$res['sem_contato']} sem contato, {$res['falhas']} falhas.");
    }
    return $res;
}

/**
 * Envia o lembrete de "algumas horas antes" para agendamentos que começam dentro
 * da janela [agora, agora + $horas]. Idempotente por 'lembrete_hora_em'.
 *
 * @param int|null $horas  Horas de antecedência (null = usa a config).
 * @return array ['janela_horas','total','email','app','sem_contato','falhas']
 */
function enviarLembretesHorasAntes($horas = null, $origem = 'cron') {
    $cfg = function_exists('carregarConfigLembretes') ? carregarConfigLembretes() : ['horas_antes' => 2];
    $horas = $horas !== null ? (int)$horas : (int)($cfg['horas_antes'] ?? 2);
    $horas = max(1, min(24, $horas));

    $pdo = getDB();
    _garantirColunaLembrete($pdo);
    $res = ['janela_horas' => $horas, 'total' => 0, 'email' => 0, 'app' => 0, 'sem_contato' => 0, 'falhas' => 0];

    $agora = time();
    $limite = $agora + $horas * 3600;
    $hoje = date('Y-m-d');
    $amanha = date('Y-m-d', strtotime('+1 day'));

    // Candidatos: confirmados, hoje ou amanhã (a janela pode cruzar a meia-noite),
    // ainda não lembrados por hora. O filtro fino de janela é feito em PHP.
    $stmt = $pdo->prepare("SELECT * FROM agendamentos
        WHERE status = 'aprovado' AND (data = ? OR data = ?)
          AND (lembrete_hora_em IS NULL OR lembrete_hora_em = '')");
    $stmt->execute([$hoje, $amanha]);
    $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($lista)) return $res;

    $barbeirosArr = function_exists('lerDados') ? lerDados('barbeiros', ['id', 'nome']) : [];
    $servicosArr  = function_exists('lerDados') ? lerDados('servicos', ['id', 'nome']) : [];
    $combosArr    = function_exists('lerDados') ? lerDados('combos', ['id', 'nome']) : [];
    $marcar = $pdo->prepare("UPDATE agendamentos SET lembrete_hora_em = ? WHERE id = ?");
    $agoraStr = date('Y-m-d H:i:s');

    foreach ($lista as $ag) {
        $ts = strtotime(($ag['data'] ?? '') . ' ' . ($ag['hora'] ?? ''));
        if ($ts === false) continue;
        if ($ts < $agora || $ts > $limite) continue; // fora da janela (passado ou distante)
        $res['total']++;

        $horaFmt = substr((string)($ag['hora'] ?? ''), 0, 5);
        $faltamMin = max(0, (int) round(($ts - $agora) / 60));
        $quando = $faltamMin >= 90 ? ('em ~' . round($faltamMin / 60) . 'h') : ($faltamMin <= 5 ? 'já já' : "em ~{$faltamMin} min");
        $extra = ['tipo_lembrete' => 'hora', 'quando_txt' => 'hoje', 'falta_txt' => $quando];
        if (empty($ag['presenca_confirmada'])) $extra['link_confirmar'] = linkConfirmarPresenca($ag['id']);
        $c = _lembreteEnviarCanais($ag, $servicosArr, $combosArr, $barbeirosArr,
            'Seu horário é logo mais!', "⏰ Seu horário é hoje às {$horaFmt} ({$quando})", $extra);
        if ($c['email']) $res['email']++;
        if ($c['app']) $res['app']++;
        if ($c['falha_email']) $res['falhas']++;
        if ($c['sem_contato']) $res['sem_contato']++;
        if ($c['email'] || $c['app']) $marcar->execute([$agoraStr, $ag['id']]);
    }

    if (function_exists('log_activity')) {
        log_activity("Lembretes {$horas}h antes ($origem): {$res['email']} e-mail, {$res['app']} app, {$res['sem_contato']} sem contato, {$res['falhas']} falhas.");
    }
    return $res;
}

/**
 * Orquestrador chamado pelo cron automático: dispara os lembretes ativos na
 * config. A véspera só sai a partir da hora configurada (evita mandar de
 * madrugada quando o cron roda a cada poucos minutos).
 * @return array ['hora'=>?stats, 'dia'=>?stats]
 */
function enviarLembretesAutomaticos($origem = 'cron') {
    $cfg = function_exists('carregarConfigLembretes') ? carregarConfigLembretes()
        : ['dia_antes_ativo' => 1, 'dia_hora_envio' => 9, 'hora_antes_ativo' => 1, 'horas_antes' => 2];
    $out = ['hora' => null, 'dia' => null];

    if (!empty($cfg['hora_antes_ativo'])) {
        $out['hora'] = enviarLembretesHorasAntes((int)$cfg['horas_antes'], $origem);
    }
    if (!empty($cfg['dia_antes_ativo']) && (int)date('G') >= (int)$cfg['dia_hora_envio']) {
        $out['dia'] = enviarLembretesAgendamentos(null, $origem);
    }
    return $out;
}
?>
