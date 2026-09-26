<?php
// cliente_data.php
$barbeirosArr = lerDados('barbeiros', ['id', 'nome', 'foto']);
$servicosArr = lerDados('servicos', ['id', 'nome', 'valor']);
$combosArr = lerDados('combos', ['id', 'nome', 'servicos_ids', 'valor']);
$planosArr = lerDados('planos', ['id', 'nome', 'valor', 'servicos_ids']);
$agendamentosArr = lerDados('agendamentos', $keys_agendamentos);
$avaliacoesArr = lerDados('avaliacoes', ['id', 'agendamento_id', 'cliente_id', 'barbeiro_id', 'rating', 'comment', 'timestamp']);
$respostasAvaliacoesRaw = lerDados('respostas_avaliacoes', ['id_resposta', 'id_avaliacao', 'texto_resposta', 'timestamp']);

$clienteAtual = getClientById($cliente_id);

$respostasAvaliacoesArr = [];
foreach ($respostasAvaliacoesRaw as $resposta) {
    if (isset($resposta['id_avaliacao'])) $respostasAvaliacoesArr[$resposta['id_avaliacao']] = $resposta;
}

$notificacoes = lerNotificacoesCliente($cliente_id);
$notificacoes_nao_lidas = count(array_filter($notificacoes, function($n){ return $n['status'] === 'nao_lida'; }));

$agendamentos_avaliados_ids = [];
$minhas_avaliacoes = [];
foreach ($avaliacoesArr as $av) {
    if ($av['cliente_id'] === $cliente_id) {
        $agendamentos_avaliados_ids[] = $av['agendamento_id'];
        $minhas_avaliacoes[] = $av;
    }
}

$config_fidelidade = getFidelityConfig();
$config_aniversario = getAniversarioConfig();
$config_indicacao = getIndicacaoConfig();

$pontos_fidelidade = getClientFidelityPoints($cliente_id);
$pontos_necessarios = $config_fidelidade['pontos_necessarios'] ?? 10;
$historico_pontos = getFidelityHistory($cliente_id);

$assinaturaAtiva = getAssinaturaCliente($cliente_id);

$agendamentosAtivos = [];
$agendamentosHistorico = [];
$hojeTimestamp = time();
$totalVisitas = 0;
$gastoTotal = 0;
$economiaTotal = 0;

foreach ($agendamentosArr as $ag) {
    $pertence_ao_cliente = false;
    if (!empty($ag['cliente_id'])) {
        $pertence_ao_cliente = ($ag['cliente_id'] === $cliente_id);
    } else {
        // Agendamento sem cadastro: compara telefone, mas o da sessao vem do
        // banco (pelo ID) e os dois lados precisam ser nao vazios.
        $telSessao = telefoneClienteDaSessao();
        $telAg = limparTelefone($ag['telefone'] ?? '');
        $pertence_ao_cliente = ($telAg !== '' && $telSessao !== '' && $telAg === $telSessao);
    }
    
    if ($pertence_ao_cliente && $ag['status'] !== 'aguardando_pagamento') {
        $dataHoraAg = strtotime($ag['data'] . ' ' . $ag['hora']);
        
        if (($ag['status'] === 'aprovado' || $ag['status'] === 'pendente') && $dataHoraAg >= $hojeTimestamp) {
            $agendamentosAtivos[] = $ag;
        } else {
            $agendamentosHistorico[] = $ag;
        }
        
        if ($ag['status'] === 'concluido') {
            $totalVisitas++;
            
            $valorBruto = 0;
            $sids = explode(',', $ag['servicos_ids']);
            foreach($sids as $sid) {
                $sid = trim($sid);
                if(isset($servicosArr[$sid])) {
                    $valorBruto += (float)$servicosArr[$sid]['valor'];
                } elseif(isset($combosArr[$sid])) {
                    $valorBruto += (float)$combosArr[$sid]['valor'];
                }
            }
            
            // Adiciona o valor do plano ao total investido se for uma adesão
            if ($ag['tipo_desconto'] === 'adesao_plano' && !empty($ag['plano_provisorio'])) {
                if (isset($planosArr[$ag['plano_provisorio']])) {
                    $valorBruto += (float)$planosArr[$ag['plano_provisorio']]['valor'];
                }
            }
            
            // Os produtos vendidos não são mais somados no $valorBruto que vai pro Gasto Total
            
            $desconto = (float)($ag['desconto_aplicado'] ?? 0);
            
            $gastoParcial = ($valorBruto - $desconto);
            if ($gastoParcial < 0) $gastoParcial = 0; // Previne que o gasto fique negativo
            
            $gastoTotal += $gastoParcial;
            
            if ($desconto > 0) {
                $economiaTotal += $desconto;
            }
        }
    }
}

usort($agendamentosAtivos, function($a, $b) { return strtotime($a['data'].' '.$a['hora']) - strtotime($b['data'].' '.$b['hora']); });
usort($agendamentosHistorico, function($a, $b) { return strtotime($b['data'].' '.$b['hora']) - strtotime($a['data'].' '.$a['hora']); });

$proximoAgendamento = !empty($agendamentosAtivos) ? $agendamentosAtivos[0] : null;
$proximoAgendamentoDetalhes = ['barbeiro_foto' => 'uploads/default-profile.jpg', 'servicos_nomes' => [], 'barbeiro_media' => 'N/A'];

if ($proximoAgendamento) {
    $bid = $proximoAgendamento['barbeiro_id'];
    
    if (isset($barbeirosArr[$bid]) && !empty(trim($barbeirosArr[$bid]['foto'] ?? '')) && file_exists(trim($barbeirosArr[$bid]['foto']))) {
        $proximoAgendamentoDetalhes['barbeiro_foto'] = trim($barbeirosArr[$bid]['foto']);
    }
    
    foreach(explode(',', $proximoAgendamento['servicos_ids']) as $sid) {
       $sid = trim($sid);
       if(isset($servicosArr[$sid])) $proximoAgendamentoDetalhes['servicos_nomes'][] = $servicosArr[$sid]['nome'];
       elseif(isset($combosArr[$sid])) $proximoAgendamentoDetalhes['servicos_nomes'][] = $combosArr[$sid]['nome']." (Combo)";
    }
    
    $avsBarbeiro = array_filter($avaliacoesArr, function($av) use ($bid) { return $av['barbeiro_id'] === $bid; });
    if (count($avsBarbeiro) > 0) {
       $proximoAgendamentoDetalhes['barbeiro_media'] = round(array_sum(array_column($avsBarbeiro, 'rating')) / count($avsBarbeiro), 1);
    }
}

$e_aniversariante = false;
if (($config_aniversario['ativado'] ?? 0) && !empty($clienteAtual['data_nascimento'])) {
    if (date('m') == date('m', strtotime($clienteAtual['data_nascimento']))) {
        $e_aniversariante = true;
    }
}

$foto_perfil = (!empty(trim($clienteAtual['foto_perfil'] ?? '')) && file_exists(trim($clienteAtual['foto_perfil']))) ? trim($clienteAtual['foto_perfil']) : 'uploads/default-profile.jpg';
?>