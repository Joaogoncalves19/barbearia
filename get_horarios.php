<?php
header('Content-Type: application/json');
require_once 'functions.php';

// Garante que o fuso horário esteja correto para todas as operações de data/hora no script
date_default_timezone_set('America/Sao_Paulo');

// ====================================================================
// ROTINA DE LIMPEZA AUTOMÁTICA (Libera horários abandonados no Stripe)
// ====================================================================
try {
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS agendamentos (id TEXT PRIMARY KEY, nome TEXT, email TEXT, telefone TEXT, barbeiro_id TEXT, servicos_ids TEXT, data TEXT, hora TEXT, status TEXT, desconto_aplicado TEXT, tipo_desconto TEXT, observacoes TEXT, produtos_vendidos TEXT, plano_provisorio TEXT, cliente_id TEXT)");
} catch(Exception $e) {}

// Coluna data_criacao garantida por lib/migrations.php (via getDB()).

// Liberação de horários com pagamento vencido: centralizada em
// lib/agendamento_functions.php e chamada também de dentro do
// getHorariosOcupados(), para a lista e a gravação nunca discordarem.
if (function_exists('liberarAgendamentosPagamentoExpirado')) {
    liberarAgendamentosPagamentoExpirado();
}
// ====================================================================

$configAgendamento = carregarConfigAgendamento();

// HELPER PARA INDEXAR ARRAYS PELO ID (Corrige o bug da lista vazia)
function indexarPorId($array_dados) {
    $resultado = [];
    if (is_array($array_dados)) {
        foreach ($array_dados as $item) {
            if (isset($item['id'])) {
                $resultado[$item['id']] = $item;
            }
        }
    }
    return $resultado;
}

// 1. Carrega todos os barbeiros e serviços com as chaves corretas
$todosBarbeirosArr = indexarPorId(lerDados('barbeiros', ['id', 'nome', 'foto', 'username', 'status', 'servicos_ids']));
$servicosArr = indexarPorId(lerDados('servicos', ['id', 'nome', 'valor', 'slots', 'categoria_id']));
$combosArr = indexarPorId(lerDados('combos', ['id', 'nome', 'servicos_ids', 'valor', 'categoria_id']));

// 2. Cria um array filtrado APENAS com barbeiros "ativos"
$barbeirosAtivosArr = array_filter($todosBarbeirosArr, function($barbeiro) {
    $status = $barbeiro['status'] ?? 'ativo';
    if(empty($status)) $status = 'ativo';
    return $status === 'ativo';
});

// 3. Pega os ITENS (serviços E combos) que o cliente selecionou
$itens_selecionados_ids = isset($_GET['itens_selecionados']) ? array_filter(explode(',', $_GET['itens_selecionados'])) : [];

// 4. CALCULA O TOTAL DE SLOTS (DURAÇÃO) NO BACKEND
$totalSlots = 0;
$servicosJaProcessados = []; 

foreach ($itens_selecionados_ids as $itemId) {
    if (strpos($itemId, 'cb-') === 0 && isset($combosArr[$itemId])) {
        $servicosDoCombo = explode(',', $combosArr[$itemId]['servicos_ids']);
        foreach ($servicosDoCombo as $sid) {
            $sid = trim($sid);
            if (!in_array($sid, $servicosJaProcessados) && isset($servicosArr[$sid])) {
                $slots = (int)($servicosArr[$sid]['slots'] ?? 1);
                $totalSlots += ($slots > 0) ? $slots : 1;
                $servicosJaProcessados[] = $sid;
            }
        }
    }
}

foreach ($itens_selecionados_ids as $itemId) {
    if (strpos($itemId, 'sv-') === 0 && isset($servicosArr[$itemId])) {
        if (!in_array($itemId, $servicosJaProcessados)) {
            $slots = (int)($servicosArr[$itemId]['slots'] ?? 1);
            $totalSlots += ($slots > 0) ? $slots : 1;
            $servicosJaProcessados[] = $itemId;
        }
    }
}

$numServicosParaCalculo = ($totalSlots > 0) ? $totalSlots : 1;

$barbeiro_id = $_GET['barbeiro_id'] ?? null;
$data = $_GET['data'] ?? null;
$mode = $_GET['mode'] ?? 'horarios'; 
$admin_mode = isset($_GET['admin_mode']) && $_GET['admin_mode'] == '1';
$modosRestritos = ['bloqueados', 'ocupados', 'todos_slots', 'admin_manual_completo'];
if ($admin_mode || in_array($mode, $modosRestritos, true)) {
    iniciarSessaoSegura();
    if (empty($_SESSION['loggedin']) && empty($_SESSION['barbeiro_loggedin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso nao autorizado.']);
        exit;
    }

    if (!empty($_SESSION['barbeiro_loggedin']) && empty($_SESSION['loggedin']) && $barbeiro_id && $barbeiro_id !== ($_SESSION['barbeiro_id'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso nao autorizado para este profissional.']);
        exit;
    }
}

if ($mode === 'bloqueados') {
    if (!$barbeiro_id || !$data) { echo json_encode([]); exit; }
    $horariosBloqueados = getHorariosBloqueados($barbeiro_id, $data);
    echo json_encode($horariosBloqueados);
    exit;
}

if ($mode === 'ocupados') {
    if (!$barbeiro_id || !$data) { echo json_encode([]); exit; }
    echo json_encode(getHorariosOcupados($barbeiro_id, $data));
    exit;
}

if ($mode === 'todos_slots') {
    if (!$barbeiro_id || !$data) { echo json_encode([]); exit; }
    $diaSemana = date('w', strtotime($data));
    $horariosDeTrabalho = getHorarioDeTrabalho($barbeiro_id, $diaSemana, $data);
    
    if (!$horariosDeTrabalho) { echo json_encode([]); exit; }
    
    $horariosPossiveis = [];
    $start = strtotime($horariosDeTrabalho['inicio']);
    $end = strtotime($horariosDeTrabalho['fim']);
    while ($start < $end) {
        $horariosPossiveis[] = date('H:i', $start);
        $start = strtotime('+30 minutes', $start);
    }
    echo json_encode($horariosPossiveis);
    exit;
}

// === NOVO MODO: AGENDAMENTO MANUAL COMPLETO (AVISA SE ESTÁ OCUPADO) ===
if ($mode === 'admin_manual_completo') {
    if (!$barbeiro_id || !$data) { echo json_encode([]); exit; }
    
    $diaSemana = date('w', strtotime($data));
    $horariosDeTrabalho = getHorarioDeTrabalho($barbeiro_id, $diaSemana, $data);
    
    if (!$horariosDeTrabalho) { echo json_encode([]); exit; }
    
    $horariosOcupados = getHorariosOcupados($barbeiro_id, $data);
    
    $resultado = [];
    $start = strtotime($horariosDeTrabalho['inicio']);
    $end = strtotime($horariosDeTrabalho['fim']);
    
    $duracao_servico_segundos = $numServicosParaCalculo * 30 * 60;
    
    while ($start < $end) {
        $h = date('H:i', $start);
        
        $is_ocupado = false;
        // Verifica se qualquer um dos slots exigidos pela duração está ocupado
        for ($i = 0; $i < $numServicosParaCalculo; $i++) {
            $slot_check = date('H:i', $start + ($i * 30 * 60));
            if (in_array($slot_check, $horariosOcupados)) {
                $is_ocupado = true;
                break;
            }
        }
        
        // Verifica se ultrapassa o horário de expediente
        if (($start + $duracao_servico_segundos) > $end) {
            $is_ocupado = true;
        }
        
        $resultado[] = [
            'horario' => $h,
            'ocupado' => $is_ocupado
        ];
        
        $start = strtotime('+30 minutes', $start);
    }
    
    echo json_encode($resultado);
    exit;
}

function barbeiroPodeRealizarItens($barbeiro, $itens_selecionados_ids) {
    if (empty($itens_selecionados_ids)) { return true; }
    
    $especialidades_barbeiro = isset($barbeiro['servicos_ids']) ? array_filter(explode(',', $barbeiro['servicos_ids'])) : [];
    if (empty($especialidades_barbeiro)) { return false; }
    
    foreach ($itens_selecionados_ids as $item_id) {
        if (!in_array($item_id, $especialidades_barbeiro)) { return false; }
    }
    
    return true;
}

if ($mode === 'dias_disponiveis') {
    $mes = $_GET['mes'] ?? date('m');
    $ano = $_GET['ano'] ?? date('Y');
    
    $numServicos = $numServicosParaCalculo;
    
    $diasDisponiveis = [];
    $diasNoMes = cal_days_in_month(CAL_GREGORIAN, $mes, $ano);
    
    for ($dia = 1; $dia <= $diasNoMes; $dia++) {
        $dataCompleta = sprintf('%s-%s-%s', $ano, str_pad($mes, 2, '0', STR_PAD_LEFT), str_pad($dia, 2, '0', STR_PAD_LEFT));
        
        if (!$admin_mode && strtotime($dataCompleta) < strtotime(date('Y-m-d'))) continue;
        
        $barbeirosParaVerificar = ($barbeiro_id === 'qualquer' || empty($barbeiro_id)) ? $barbeirosAtivosArr : [$barbeirosAtivosArr[$barbeiro_id] ?? null];
        
        foreach ($barbeirosParaVerificar as $barbeiro) {
            if (!$barbeiro) continue;
            if (!barbeiroPodeRealizarItens($barbeiro, $itens_selecionados_ids)) { continue; }
            
            $horariosDoDia = getHorariosDisponiveisParaDia($barbeiro['id'], $dataCompleta, $configAgendamento, $numServicos, $servicosArr, $admin_mode);
            if (!empty($horariosDoDia)) {
                $diasDisponiveis[] = (int)$dia;
                break; 
            }
        }
    }
    
    echo json_encode(['dias' => array_values(array_unique($diasDisponiveis))]);
    exit;
}

if (!$barbeiro_id || !$data) {
    echo json_encode(['error' => 'Dados de barbeiro ou data não fornecidos.']);
    exit;
}

$numServicos = $numServicosParaCalculo;

if ($barbeiro_id === 'qualquer') {
    $horariosConsolidados = [];
    foreach ($barbeirosAtivosArr as $barbeiro) {
        if (!barbeiroPodeRealizarItens($barbeiro, $itens_selecionados_ids)) { continue; }
        
        $horariosDoBarbeiro = getHorariosDisponiveisParaDia($barbeiro['id'], $data, $configAgendamento, $numServicos, $servicosArr, $admin_mode);
        foreach ($horariosDoBarbeiro as $horario) {
            $horariosConsolidados[] = [
                'horario' => $horario,
                'barbeiro_id' => $barbeiro['id'],
                'barbeiro_nome' => $barbeiro['nome']
            ];
        }
    }
    
    usort($horariosConsolidados, function($a, $b) {
        return strcmp($a['horario'], $b['horario']);
    });
    
    echo json_encode($horariosConsolidados);
} else {
    if (!isset($barbeirosAtivosArr[$barbeiro_id])) {
        echo json_encode(['error' => 'Este barbeiro não está disponível.']);
        exit;
    }
    
    $barbeiro_selecionado = $barbeirosAtivosArr[$barbeiro_id];
    if (!barbeiroPodeRealizarItens($barbeiro_selecionado, $itens_selecionados_ids)) {
        echo json_encode(['error' => 'Este barbeiro não realiza todos os itens selecionados.']);
        exit;
    }
    
    $horariosDisponiveis = getHorariosDisponiveisParaDia($barbeiro_id, $data, $configAgendamento, $numServicos, $servicosArr, $admin_mode);
    echo json_encode($horariosDisponiveis);
}

function getHorariosDisponiveisParaDia($barbeiro_id, $data, $config, $numServicos, $servicosArr, $admin_mode = false) {
    $diaSemana = date('w', strtotime($data));
    $horariosDeTrabalho = getHorarioDeTrabalho($barbeiro_id, $diaSemana, $data);
    
    if (!$horariosDeTrabalho) return [];
    
    $horariosOcupados = getHorariosOcupados($barbeiro_id, $data);
    
    $horariosPossiveis = [];
    $start = strtotime($horariosDeTrabalho['inicio']);
    $end = strtotime($horariosDeTrabalho['fim']);
    while ($start < $end) {
        $horariosPossiveis[] = date('H:i', $start);
        $start = strtotime('+30 minutes', $start);
    }
    
    $horariosLivres = array_diff($horariosPossiveis, $horariosOcupados);
    
    $horariosAgendaveis = [];
    $duracao_servico_segundos = $numServicos * 30 * 60;
    
    foreach ($horariosLivres as $horarioInicio) {
        $timestampInicio = strtotime($data . ' ' . $horarioInicio);
        if (($timestampInicio + $duracao_servico_segundos) > strtotime($data . ' ' . $horariosDeTrabalho['fim'])) {
            continue; 
        }
        
        $temSlotCompleto = true;
        for ($i = 1; $i < $numServicos; $i++) {
            $proximoSlot = date('H:i', $timestampInicio + ($i * 30 * 60));
            if (!in_array($proximoSlot, $horariosLivres)) {
                $temSlotCompleto = false;
                break;
            }
        }
        
        if ($temSlotCompleto) {
            $horariosAgendaveis[] = $horarioInicio;
        }
    }
    
    if (empty($horariosAgendaveis)) { return []; }
    
    if ($data == date('Y-m-d') && !$admin_mode) {
        $antecedenciaMinima = (int) ($config['antecedencia_minima_minutos'] ?? 0);
        $horarioMinimoPermitido = date('H:i', strtotime("+$antecedenciaMinima minutes"));
        
        $horariosFinais = array_filter($horariosAgendaveis, function($horario) use ($horarioMinimoPermitido) {
            return $horario >= $horarioMinimoPermitido;
        });
        return array_values($horariosFinais);
    }
    
    return array_values($horariosAgendaveis);
}
?>
