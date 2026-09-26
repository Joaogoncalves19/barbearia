<?php
// admin_data.php
// Prepara os dados para exibição no painel administrativo (Dashboard e Relatórios)

$pdo = getDB();

// --- DEFINIÇÃO DE PERÍODO (DASHBOARD/RELATÓRIOS) ---
$data_hoje = date('Y-m-d');
[$data_inicio_filtro, $data_fim_filtro] = normalizarPeriodoRelatorio(
    $_GET['data_inicio'] ?? date('Y-m-01'),
    $_GET['data_fim'] ?? date('Y-m-t')
);

// Filtra os agendamentos para o período (USADO APENAS NO DASHBOARD E RELATÓRIOS)
$agendamentosFiltrados = array_filter($agendamentosArr, function($ag) use ($data_inicio_filtro, $data_fim_filtro) {
    if (!isset($ag['data'])) {
        return false;
    }
    return $ag['data'] >= $data_inicio_filtro && $ag['data'] <= $data_fim_filtro;
});
$agendamentosConcluidos = array_filter($agendamentosFiltrados, function($a) {
    return isset($a['status']) && $a['status'] === 'concluido';
});

// CARREGAMENTO DE DADOS DE PLANOS E ASSINATURAS VIA SQLITE NATIVO
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS planos (id TEXT PRIMARY KEY, nome TEXT, valor TEXT, servicos_ids TEXT)");
    garantirEstruturaAssinaturas();
    // Rede de segurança: acerta o status de assinaturas vencidas antes de exibir,
    // mesmo que algum webhook do Stripe tenha se perdido.
    if (function_exists('expirarAssinaturasVencidas')) {
        expirarAssinaturasVencidas();
    }

    $planosArr = lerDados('planos', ['id', 'nome', 'valor', 'servicos_ids']);
    $assinaturasClientesArr = lerDados('clientes_assinaturas', [
        'cliente_id', 'plano_id', 'data_inicio', 'data_fim', 'status', 'gateway',
        'gateway_subscription_id', 'gateway_status', 'ultimo_pagamento_id', 'cancelamento_em'
    ]);
} catch (PDOException $e) {
    $planosArr = [];
    $assinaturasClientesArr = [];
}

// CARREGAMENTO DE PRODUTOS (ESTOQUE)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS produtos (id TEXT PRIMARY KEY, nome TEXT, valor TEXT, quantidade INTEGER, categoria_id TEXT)");
    // Colunas estoque_minimo/custo garantidas por lib/migrations.php.
    $produtosArr = lerDados('produtos', ['id', 'nome', 'valor', 'quantidade', 'categoria_id', 'estoque_minimo', 'custo']);
} catch (PDOException $e) {
    $produtosArr = [];
}

// --- CÁLCULOS DE ASSINATURAS (NOVO SISTEMA) ---
$totalAssinantesAtivos = 0;
$mrrAtual = 0; // Monthly Recurring Revenue
$planosPopulares = []; 
$assinaturasVencendo = []; 
$novasAssinaturasPeriodo = 0; 

foreach($assinaturasClientesArr as $assinatura) {
    $estaAtiva = in_array($assinatura['status'] ?? '', ['ativo', 'cancelamento_agendado'], true)
        && ($assinatura['data_fim'] ?? '') >= $data_hoje;
    
    // MRR é receita que VAI se repetir. Uma assinatura com cancelamento
    // agendado ainda dá benefício (por isso conta como assinante ativo), mas
    // não será cobrada de novo — somá-la ao MRR inflava a projeção de receita
    // recorrente justamente com quem está saindo.
    $vaiRenovar = ($assinatura['status'] ?? '') === 'ativo';

    if ($estaAtiva) {
        $totalAssinantesAtivos++;
        if (isset($planosArr[$assinatura['plano_id']])) {
            $valorPlano = (float) $planosArr[$assinatura['plano_id']]['valor'];
            if ($vaiRenovar) {
                $mrrAtual += $valorPlano;
            }
            $nomePlano = $planosArr[$assinatura['plano_id']]['nome'];
            if (!isset($planosPopulares[$nomePlano])) {
                $planosPopulares[$nomePlano] = 0;
            }
            $planosPopulares[$nomePlano]++;
        }
        
        $dataVencimento = $assinatura['data_fim'];
        $dataLimiteAlerta = date('Y-m-d', strtotime('+15 days'));
        
        if ($dataVencimento <= $dataLimiteAlerta && $dataVencimento >= $data_hoje) {
            $tempAssinatura = $assinatura; 
            $tempAssinatura['nome_cliente'] = $clientesArr[$assinatura['cliente_id']]['nome'] ?? 'Cliente Removido';
            $tempAssinatura['nome_plano'] = $planosArr[$assinatura['plano_id']]['nome'] ?? 'Plano Removido';
            $assinaturasVencendo[] = $tempAssinatura;
        }
    }

}
arsort($planosPopulares); 

// --- CÁLCULOS FINANCEIROS GERAIS ---
$resumoFinanceiroRelatorio = calcularResumoFinanceiroRelatorio(
    $agendamentosConcluidos,
    $servicosArr,
    $combosArr,
    $assinaturasClientesArr,
    $planosArr,
    $data_inicio_filtro,
    $data_fim_filtro
);
$receitaTotal = $resumoFinanceiroRelatorio['receita_total'];
$receitaServicos = $resumoFinanceiroRelatorio['receita_servicos'];
$receitaProdutos = $resumoFinanceiroRelatorio['receita_produtos'];
$totalProdutos = $receitaProdutos;
$receitaPlanos = $resumoFinanceiroRelatorio['receita_planos'];
$totalDescontos = $resumoFinanceiroRelatorio['descontos'];
$economiaVIP = $resumoFinanceiroRelatorio['economia_assinatura'];
$totalDescontosMkt = max(0, $totalDescontos - $economiaVIP);
$pagamentosAssinaturaPeriodo = $resumoFinanceiroRelatorio['pagamentos_assinatura'];
$pagamentosAssinaturaEstimados = $resumoFinanceiroRelatorio['pagamentos_estimados'];
$novasAssinaturasPeriodo = count(array_filter($pagamentosAssinaturaPeriodo, function ($pagamento) {
    return ($pagamento['tipo'] ?? '') === 'adesao';
}));
$receitaDiaria = []; 

foreach ($agendamentosConcluidos as $ag) {
    $valoresAgendamento = calcularValoresAgendamentoRelatorio($ag, $servicosArr, $combosArr);

    // Para o gráfico de receita diária
    $data_ag = $ag['data'];
    if (!isset($receitaDiaria[$data_ag])) {
        $receitaDiaria[$data_ag] = 0;
    }
    $receitaDiaria[$data_ag] += $valoresAgendamento['total'];
}
foreach ($pagamentosAssinaturaPeriodo as $pagamentoAssinatura) {
    $dataPagamento = substr((string)($pagamentoAssinatura['data_pagamento'] ?? ''), 0, 10);
    if ($dataPagamento === '') {
        continue;
    }
    if (!isset($receitaDiaria[$dataPagamento])) {
        $receitaDiaria[$dataPagamento] = 0;
    }
    $receitaDiaria[$dataPagamento] += (float)($pagamentoAssinatura['valor'] ?? 0);
}
ksort($receitaDiaria);

$vendasProdutos = getVendasProdutosNoPeriodo($agendamentosConcluidos);
$analiseDescontos = getAnaliseDescontos($agendamentosConcluidos);

// --- CENTRAL DE RELATÓRIOS: motores compartilhados (tela, impressão e CSV) ---
// Despesas do período + quebra por categoria.
$despesasRelatorio = calcularDespesasPeriodoRelatorio($despesasArr, $data_inicio_filtro, $data_fim_filtro);
$totalDespesasPeriodo = $despesasRelatorio['total'];
$despesasPorCategoria = calcularDespesasPorCategoria($despesasRelatorio['itens']);

// Desempenho e comissões da equipe no período.
$equipeRelatorio = calcularEquipeRelatorio(
    $agendamentosConcluidos, $barbeirosArr, $servicosArr, $combosArr,
    $data_inicio_filtro, $data_fim_filtro
);
$totalComissoesPeriodo = $equipeRelatorio['total_comissoes'];
$dados_equipe_relatorio = $equipeRelatorio['profissionais'];

// Caixa por forma de pagamento, gorjetas, CMV e lucratividade por produto.
$relCaixa = calcularCaixaGorjetasCmv($data_inicio_filtro, $data_fim_filtro, $servicosArr, $combosArr);
$relGorjetas = $relCaixa['gorjetas'];
$relCmvProdutos = $relCaixa['cmv'];
$relCaixaPorForma = $relCaixa['caixa_por_forma'];
$relTotalCaixa = $relCaixa['total_caixa'];
$lucratividadeProdutos = $relCaixa['produtos'];

// Resultado líquido do período (receita - comissões - despesas - CMV).
$totalCustosPeriodo = $totalComissoesPeriodo + $totalDespesasPeriodo + $relCmvProdutos;
$lucroLiquidoPeriodo = $receitaTotal - $totalCustosPeriodo;
$margemLucroPeriodo = $receitaTotal > 0 ? round(($lucroLiquidoPeriodo / $receitaTotal) * 100, 1) : 0;

// Comparativo com o período imediatamente anterior (mesma duração).
[$data_inicio_anterior, $data_fim_anterior] = calcularPeriodoAnteriorRelatorio($data_inicio_filtro, $data_fim_filtro);
$agsAnteriorConcluidos = array_filter($agendamentosArr, function ($ag) use ($data_inicio_anterior, $data_fim_anterior) {
    return isset($ag['data'], $ag['status']) && $ag['status'] === 'concluido'
        && $ag['data'] >= $data_inicio_anterior && $ag['data'] <= $data_fim_anterior;
});
$resumoAnterior = calcularResumoFinanceiroRelatorio(
    $agsAnteriorConcluidos, $servicosArr, $combosArr,
    $assinaturasClientesArr, $planosArr, $data_inicio_anterior, $data_fim_anterior
);
$despesasAnterior = calcularDespesasPeriodoRelatorio($despesasArr, $data_inicio_anterior, $data_fim_anterior);
$equipeAnterior = calcularEquipeRelatorio(
    $agsAnteriorConcluidos, $barbeirosArr, $servicosArr, $combosArr,
    $data_inicio_anterior, $data_fim_anterior
);
$caixaAnterior = calcularCaixaGorjetasCmv($data_inicio_anterior, $data_fim_anterior, $servicosArr, $combosArr);
$lucroAnterior = $resumoAnterior['receita_total'] - $equipeAnterior['total_comissoes'] - $despesasAnterior['total'] - $caixaAnterior['cmv'];
$comparativoPeriodo = [
    'anterior_inicio'   => $data_inicio_anterior,
    'anterior_fim'      => $data_fim_anterior,
    'receita'           => ['atual' => $receitaTotal, 'anterior' => $resumoAnterior['receita_total'], 'var' => calcularVariacaoPercentual($receitaTotal, $resumoAnterior['receita_total'])],
    'lucro'             => ['atual' => $lucroLiquidoPeriodo, 'anterior' => $lucroAnterior, 'var' => calcularVariacaoPercentual($lucroLiquidoPeriodo, $lucroAnterior)],
    'atendimentos'      => ['atual' => count($agendamentosConcluidos), 'anterior' => count($agsAnteriorConcluidos), 'var' => calcularVariacaoPercentual(count($agendamentosConcluidos), count($agsAnteriorConcluidos))],
    'ticket'            => ['atual' => $resumoFinanceiroRelatorio['ticket_medio'], 'anterior' => $resumoAnterior['ticket_medio'], 'var' => calcularVariacaoPercentual($resumoFinanceiroRelatorio['ticket_medio'], $resumoAnterior['ticket_medio'])],
];

// --- CÁLCULOS OPERACIONAIS ---
$contagemStatus = getContagemStatusAgendamentos($agendamentosFiltrados);
$ticketMedio = $resumoFinanceiroRelatorio['ticket_medio'];

$horariosTrabalhoRaw = [];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS horarios_trabalho (barbeiro_id TEXT, dia TEXT, inicio TEXT, fim TEXT)");
    $stmtWork = $pdo->query("SELECT barbeiro_id, dia, inicio, fim FROM horarios_trabalho");
    if ($stmtWork) {
        $horariosTrabalhoRaw = $stmtWork->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { }

$taxasOcupacao = [];
foreach ($barbeirosArr as $id => $barbeiro) {
    $taxa = getTaxaOcupacaoBarbeiro($id, $data_inicio_filtro, $data_fim_filtro, $agendamentosFiltrados, $horariosTrabalhoRaw, $servicosArr, $combosArr); 
    $taxasOcupacao[$barbeiro['nome']] = $taxa;
}
$taxaOcupacaoMedia = !empty($taxasOcupacao) ? round(array_sum($taxasOcupacao) / count($taxasOcupacao), 1) : 0;

$melhorBarbeiro = getMelhorBarbeiro($agendamentosConcluidos, $barbeirosArr, $servicosArr, $combosArr); 
$rankingServicosIds = getRankingServicos($agendamentosConcluidos, $servicosArr, $combosArr); 
$servicosMaisCombinados = getServicosMaisCombinados($agendamentosConcluidos, $servicosArr); 

$agendamentosDiaSemana = array_fill_keys(['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab'], 0);
foreach ($agendamentosFiltrados as $ag) {
    $dia_semana_num = date('w', strtotime($ag['data']));
    $dia_semana_label = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab'][$dia_semana_num];
    if (isset($agendamentosDiaSemana[$dia_semana_label])) {
        $agendamentosDiaSemana[$dia_semana_label]++;
    }
}

$horariosPico = getHorariosDePico($agendamentosFiltrados); 

// --- CÁLCULOS DE CRM (AGORA CORRIGIDO E PRECISO) ---
// Passa os agendamentos do periodo E do banco todo para a funcao comparar e saber se o cliente já veio antes.
$dadosNovosVsRecorrentes = getNovosVsRecorrentes($agendamentosFiltrados, $agendamentosArr); 

// Substitui a variável falha do "data_cadastro" pelo calculo novo e preciso
$statsClientes = ['novos_no_periodo' => $dadosNovosVsRecorrentes['novos']];

$frequenciaMedia = getFrequenciaMediaVisitas($agendamentosFiltrados); 
$topClientes = getTopClientesPorGasto($agendamentosConcluidos, $servicosArr, $combosArr); 
$clientesEmRisco = getClientesEmRisco($agendamentosArr, $clientesArr, 90); 
$aniversariantesDoMes = getAniversariantesDoMes($clientesArr); 

// --- CÁLCULOS DE AVALIAÇÕES ---
$avaliacoesFiltradas = array_filter($avaliacoesArr, function($av) use ($data_inicio_filtro, $data_fim_filtro) {
    if (!isset($av['timestamp'])) {
        return false;
    }
    $data_av = date('Y-m-d', strtotime($av['timestamp']));
    return $data_av >= $data_inicio_filtro && $data_av <= $data_fim_filtro;
});
$totalAvaliacoes = count($avaliacoesFiltradas);
$mediaAvaliacoes = $totalAvaliacoes > 0 ? round(array_sum(array_column($avaliacoesFiltradas, 'rating')) / $totalAvaliacoes, 1) : 'N/A';

$agendamentos_avaliados_ids = array_column($avaliacoesArr, 'agendamento_id');
$agendamentosPendentesDeAvaliacao = array_filter($agendamentosArr, function($ag) use ($agendamentos_avaliados_ids) {
    return isset($ag['status']) && $ag['status'] === 'concluido' && !in_array($ag['id'], $agendamentos_avaliados_ids);
});

$media_geral_avaliacoes = count($avaliacoesArr) > 0 ? round(array_sum(array_column($avaliacoesArr, 'rating')) / count($avaliacoesArr), 1) : 0;
$distribuicao_notas = ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0];
$sentimentoGeral = ['positivo_count' => 0, 'neutro_count' => 0, 'negativo_count' => 0];
$media_por_barbeiro = [];
foreach($avaliacoesArr as $av) {
    $nota = (int)($av['rating'] ?? 0);
    if ($nota >= 1 && $nota <= 5) {
        $distribuicao_notas[(string)$nota]++;
    }
    if ($nota >= 4) {
        $sentimentoGeral['positivo_count']++;
    } elseif ($nota == 3) {
        $sentimentoGeral['neutro_count']++;
    } else {
        $sentimentoGeral['negativo_count']++;
    }
}
foreach($barbeirosArr as $b_id => $b) {
    $avaliacoes_do_barbeiro = array_filter($avaliacoesArr, function($av) use ($b_id) {
        return $av['barbeiro_id'] === $b_id;
    });
    $media = count($avaliacoes_do_barbeiro) > 0 ? round(array_sum(array_column($avaliacoes_do_barbeiro, 'rating')) / count($avaliacoes_do_barbeiro), 1) : 0;
    if (!empty($b['nome'])) { 
        $media_por_barbeiro[$b['nome']] = $media;
    }
}

$landingConfig = carregarLandingPageConfig(); 

$agendamentosPendentes = array_filter($agendamentosArr, function($a) {
    return isset($a['status']) && $a['status'] === 'pendente';
});

// PREPARAÇÃO DE ESTATÍSTICAS DOS CLIENTES E BARBEIROS
$estatisticas_clientes = [];
$pontosFidelidade = getAllFidelityPoints(); 
foreach ($clientesArr as $id => $cliente) {
    $agendamentos_cliente = array_filter($agendamentosArr, function($ag) use ($cliente) {
        $ag_cliente_id = $ag['cliente_id'] ?? '';
        if (!empty($ag_cliente_id)) {
            return $ag_cliente_id === $cliente['id'];
        }
        return ($ag['email'] === $cliente['email'] || limparTelefone($ag['telefone']) === limparTelefone($cliente['telefone']));
    });
    
    $agendamentos_concluidos_cliente = array_filter($agendamentos_cliente, function($ag) {
        return $ag['status'] === 'concluido';
    });
    
    $gasto_total = 0;
    $ultima_visita = 'N/A';
    $ultima_visita_data = '';
    if (!empty($agendamentos_concluidos_cliente)) {
        uasort($agendamentos_concluidos_cliente, function($a, $b) {
            return strtotime($b['data']) - strtotime($a['data']);
        });
        $ultima_visita_data = reset($agendamentos_concluidos_cliente)['data'];
        $ultima_visita = date('d/m/Y', strtotime($ultima_visita_data));
        
        foreach ($agendamentos_concluidos_cliente as $ag) {
             $sids = isset($ag['servicos_ids']) ? explode(',', $ag['servicos_ids']) : []; 
             $total_servicos_ag = 0; 
             foreach($sids as $sid) {
                $sid_limpo = trim($sid);
                if(isset($servicosArr[$sid_limpo])) {
                    $total_servicos_ag += (float)$servicosArr[$sid_limpo]['valor'];
                } elseif (isset($combosArr[$sid_limpo])) {
                    $total_servicos_ag += (float)$combosArr[$sid_limpo]['valor'];
                }
             }
             $produtos_vendidos = !empty($ag['produtos_vendidos']) ? json_decode($ag['produtos_vendidos'], true) : [];
             if (is_array($produtos_vendidos)) {
                foreach($produtos_vendidos as $produto) {
                    $total_servicos_ag += (float)($produto['valor'] ?? 0);
                }
             }
             $gasto_total += $total_servicos_ag - (float)($ag['desconto_aplicado'] ?? 0);
        }
    }
    
    $estatisticas_clientes[$id] = [
        'gasto_total' => $gasto_total,
        'total_agendamentos' => count($agendamentos_concluidos_cliente),
        'pontos_fidelidade' => $pontosFidelidade[$id] ?? 0,
        'ultima_visita' => $ultima_visita,
        'ultima_visita_data' => $ultima_visita_data
    ];
}

$estatisticas_barbeiros = [];
foreach ($barbeirosArr as $id => $barbeiro) {
    $agendamentos_barbeiro = array_filter($agendamentosArr, function($ag) use ($id) {
        return $ag['barbeiro_id'] === $id;
    });
    $agendamentos_concluidos_barbeiro = array_filter($agendamentos_barbeiro, function($ag) {
        return $ag['status'] === 'concluido';
    });
    $avaliacoes_barbeiro = array_filter($avaliacoesArr, function($av) use ($id) {
        return $av['barbeiro_id'] === $id;
    });
    
    $receita = 0;
    foreach ($agendamentos_concluidos_barbeiro as $ag) {
         $sids = isset($ag['servicos_ids']) ? explode(',', $ag['servicos_ids']) : []; 
         $total_servicos_ag = 0; 
         foreach($sids as $sid) {
            $sid_limpo = trim($sid);
            if(isset($servicosArr[$sid_limpo])) {
                $total_servicos_ag += (float)$servicosArr[$sid_limpo]['valor'];
            } elseif (isset($combosArr[$sid_limpo])) {
                $total_servicos_ag += (float)$combosArr[$sid_limpo]['valor'];
            }
         }
         $produtos_vendidos = !empty($ag['produtos_vendidos']) ? json_decode($ag['produtos_vendidos'], true) : [];
         if (is_array($produtos_vendidos)) {
            foreach($produtos_vendidos as $produto) {
                $total_servicos_ag += (float)($produto['valor'] ?? 0);
            }
         }
         $receita += $total_servicos_ag - (float)($ag['desconto_aplicado'] ?? 0);
    }
    
    $media = count($avaliacoes_barbeiro) > 0 ? round(array_sum(array_column($avaliacoes_barbeiro, 'rating')) / count($avaliacoes_barbeiro), 1) : 0;
    
    $estatisticas_barbeiros[$id] = [
        'concluidos' => count($agendamentos_concluidos_barbeiro),
        'receita' => $receita,
        'media_avaliacoes' => $media
    ];
}

// ==========================================================================
// LÓGICA DE PAGINAÇÃO 
// ==========================================================================
$busca_cliente = trim($_GET['busca_cliente'] ?? '');
$filtro_status_cliente = $_GET['filtro_status_cliente'] ?? '';
$filtro_assinatura_cliente = $_GET['filtro_assinatura_cliente'] ?? '';
$ordenar_cliente = $_GET['ordenar_cliente'] ?? 'nome';

if (!in_array($filtro_status_cliente, ['', 'ativo', 'inativo'], true)) {
    $filtro_status_cliente = '';
}
if (!in_array($filtro_assinatura_cliente, ['', 'assinante', 'sem_assinatura', 'cancelamento'], true)) {
    $filtro_assinatura_cliente = '';
}
if (!in_array($ordenar_cliente, ['nome', 'gasto', 'visitas', 'pontos', 'recente'], true)) {
    $ordenar_cliente = 'nome';
}

$clientes_resumo = [
    'total' => count($clientesArr),
    'ativos' => 0,
    'assinantes' => 0,
    'sem_retorno' => 0
];
foreach ($clientesArr as $clienteResumoId => $clienteResumo) {
    $statusResumo = $clienteResumo['status'] ?? 'ativo';
    if ($statusResumo !== 'inativo') {
        $clientes_resumo['ativos']++;
    }

    $assinaturaResumo = $assinaturasClientesArr[$clienteResumoId] ?? null;
    $assinaturaValida = $assinaturaResumo
        && in_array($assinaturaResumo['status'] ?? '', ['ativo', 'cancelamento_agendado'], true)
        && ($assinaturaResumo['data_fim'] ?? '') >= date('Y-m-d');
    if ($assinaturaValida) {
        $clientes_resumo['assinantes']++;
    }

    $ultimaVisitaResumo = $estatisticas_clientes[$clienteResumoId]['ultima_visita_data'] ?? '';
    if ($ultimaVisitaResumo !== '' && strtotime($ultimaVisitaResumo) <= strtotime('-90 days')) {
        $clientes_resumo['sem_retorno']++;
    }
}

$clientesFiltrados = $clientesArr;
if (!empty($busca_cliente)) {
    $term = strtolower($busca_cliente);
    $termPhone = limparTelefone($busca_cliente); 
    $clientesFiltrados = array_filter($clientesArr, function($c) use ($term, $termPhone) {
        $nome = strtolower($c['nome'] ?? '');
        $email = strtolower($c['email'] ?? '');
        $id = strtolower($c['id'] ?? '');
        $phoneMatch = false;
        if (!empty($termPhone)) {
             $phone = limparTelefone($c['telefone'] ?? '');
             if (!empty($phone)) $phoneMatch = (strpos($phone, $termPhone) !== false);
        }
        return strpos($nome, $term) !== false || strpos($email, $term) !== false || strpos($id, $term) !== false || $phoneMatch;
    });
}

if ($filtro_status_cliente !== '') {
    $clientesFiltrados = array_filter($clientesFiltrados, function ($cliente) use ($filtro_status_cliente) {
        $status = $cliente['status'] ?? 'ativo';
        if ($status === '') {
            $status = 'ativo';
        }
        return $status === $filtro_status_cliente;
    });
}

if ($filtro_assinatura_cliente !== '') {
    $clientesFiltrados = array_filter($clientesFiltrados, function ($cliente) use ($filtro_assinatura_cliente, $assinaturasClientesArr) {
        $assinatura = $assinaturasClientesArr[$cliente['id']] ?? null;
        $valida = $assinatura
            && in_array($assinatura['status'] ?? '', ['ativo', 'cancelamento_agendado'], true)
            && ($assinatura['data_fim'] ?? '') >= date('Y-m-d');

        if ($filtro_assinatura_cliente === 'assinante') {
            return $valida;
        }
        if ($filtro_assinatura_cliente === 'cancelamento') {
            return $valida && ($assinatura['status'] ?? '') === 'cancelamento_agendado';
        }
        return !$valida;
    });
}

uasort($clientesFiltrados, function ($a, $b) use ($ordenar_cliente, $estatisticas_clientes) {
    $statsA = $estatisticas_clientes[$a['id']] ?? [];
    $statsB = $estatisticas_clientes[$b['id']] ?? [];

    if ($ordenar_cliente === 'gasto') {
        return ($statsB['gasto_total'] ?? 0) <=> ($statsA['gasto_total'] ?? 0);
    }
    if ($ordenar_cliente === 'visitas') {
        return ($statsB['total_agendamentos'] ?? 0) <=> ($statsA['total_agendamentos'] ?? 0);
    }
    if ($ordenar_cliente === 'pontos') {
        return ($statsB['pontos_fidelidade'] ?? 0) <=> ($statsA['pontos_fidelidade'] ?? 0);
    }
    if ($ordenar_cliente === 'recente') {
        return strcmp($statsB['ultima_visita_data'] ?? '', $statsA['ultima_visita_data'] ?? '');
    }
    return strnatcasecmp($a['nome'] ?? '', $b['nome'] ?? '');
});
$clientes_itemsPerPage = 10;
$clientes_totalItems = count($clientesFiltrados);
$clientes_totalPages = $clientes_totalItems > 0 ? ceil($clientes_totalItems / $clientes_itemsPerPage) : 1;
$clientes_currentPage = max(1, min((int)($_GET['cli_page'] ?? 1), $clientes_totalPages));
$clientes_offset = ($clientes_currentPage - 1) * $clientes_itemsPerPage;
$clientesPaginados = array_slice($clientesFiltrados, $clientes_offset, $clientes_itemsPerPage, true);

$busca_barbeiro = trim($_GET['busca_barbeiro'] ?? '');
$filtro_status_barbeiro = $_GET['filtro_status_barbeiro'] ?? '';
if (!in_array($filtro_status_barbeiro, ['', 'ativo', 'inativo'], true)) {
    $filtro_status_barbeiro = '';
}

$barbeirosPaginados = $barbeirosArr;

if ($busca_barbeiro !== '') {
    $barbeirosPaginados = array_filter($barbeirosPaginados, function ($b) use ($busca_barbeiro) {
        $alvo = ($b['nome'] ?? '') . ' ' . ($b['username'] ?? '');
        return mb_stripos($alvo, $busca_barbeiro) !== false;
    });
}
if ($filtro_status_barbeiro !== '') {
    $barbeirosPaginados = array_filter($barbeirosPaginados, function ($b) use ($filtro_status_barbeiro) {
        $status = ($b['status'] ?? 'ativo') === 'inativo' ? 'inativo' : 'ativo';
        return $status === $filtro_status_barbeiro;
    });
}

uasort($barbeirosPaginados, fn($a, $b) => strnatcasecmp($a['nome'], $b['nome']));
$barbeiros_itemsPerPage = 10;
$barbeiros_totalItems = count($barbeirosPaginados);
$barbeiros_totalPages = $barbeiros_totalItems > 0 ? ceil($barbeiros_totalItems / $barbeiros_itemsPerPage) : 1;
$barbeiros_currentPage = max(1, min((int)($_GET['bar_page'] ?? 1), $barbeiros_totalPages));
$barbeiros_offset = ($barbeiros_currentPage - 1) * $barbeiros_itemsPerPage;
$barbeirosPaginados = array_slice($barbeirosPaginados, $barbeiros_offset, $barbeiros_itemsPerPage, true);

$busca_fidelidade = trim($_GET['busca_fidelidade'] ?? '');
$fidelidade_apenas_aptos = (($_GET['fid_aptos'] ?? '') === '1');
$fidelidade_meta = (int)(getFidelityConfig()['pontos_necessarios'] ?? 10);
$clientesFidelidadeFiltrados = $clientesArr;
if (!empty($busca_fidelidade)) {
    $termFid = strtolower($busca_fidelidade);
    $clientesFidelidadeFiltrados = array_filter($clientesFidelidadeFiltrados, function($c) use ($termFid) {
        return strpos(strtolower($c['nome']), $termFid) !== false;
    });
}
if ($fidelidade_apenas_aptos) {
    $clientesFidelidadeFiltrados = array_filter($clientesFidelidadeFiltrados, function($c) use ($pontosFidelidade, $fidelidade_meta) {
        return (int)($pontosFidelidade[$c['id']] ?? 0) >= $fidelidade_meta;
    });
}
uasort($clientesFidelidadeFiltrados, function($a, $b) use ($pontosFidelidade) {
    $ptsA = $pontosFidelidade[$a['id']] ?? 0;
    $ptsB = $pontosFidelidade[$b['id']] ?? 0;
    return $ptsB <=> $ptsA; 
});
$fidelidade_itemsPerPage = 15;
$fidelidade_totalItems = count($clientesFidelidadeFiltrados);
$fidelidade_totalPages = $fidelidade_totalItems > 0 ? ceil($fidelidade_totalItems / $fidelidade_itemsPerPage) : 1;
$fidelidade_currentPage = max(1, min((int)($_GET['fid_page'] ?? 1), $fidelidade_totalPages));
$fidelidadePaginados = array_slice($clientesFidelidadeFiltrados, ($fidelidade_currentPage - 1) * $fidelidade_itemsPerPage, $fidelidade_itemsPerPage, true);
$clientesFidelidadePaginados = $fidelidadePaginados;

$filtro_barbeiro_av = $_GET['filtro_barbeiro_av'] ?? '';
$filtro_nota_av = $_GET['filtro_nota_av'] ?? '';
$filtro_extra_av = $_GET['filtro_extra_av'] ?? '';
$filtro_data_ini_av = $_GET['filtro_data_ini_av'] ?? '';
$filtro_data_fim_av = $_GET['filtro_data_fim_av'] ?? '';
$busca_av = trim($_GET['busca_av'] ?? '');
$respAvParaFiltro = $respostasAvaliacoesArr ?? [];

$avaliacoesFiltradasLista = $avaliacoesArr;
if (!empty($filtro_barbeiro_av)) {
    $avaliacoesFiltradasLista = array_filter($avaliacoesFiltradasLista, fn($av) => $av['barbeiro_id'] === $filtro_barbeiro_av);
}
if (!empty($filtro_nota_av)) {
    $avaliacoesFiltradasLista = array_filter($avaliacoesFiltradasLista, fn($av) => (int)$av['rating'] == (int)$filtro_nota_av);
}
if ($filtro_data_ini_av !== '') {
    $avaliacoesFiltradasLista = array_filter($avaliacoesFiltradasLista, fn($av) => substr($av['timestamp'], 0, 10) >= $filtro_data_ini_av);
}
if ($filtro_data_fim_av !== '') {
    $avaliacoesFiltradasLista = array_filter($avaliacoesFiltradasLista, fn($av) => substr($av['timestamp'], 0, 10) <= $filtro_data_fim_av);
}
if ($filtro_extra_av === 'com_comentario') {
    $avaliacoesFiltradasLista = array_filter($avaliacoesFiltradasLista, fn($av) => trim($av['comment'] ?? '') !== '');
} elseif ($filtro_extra_av === 'sem_resposta') {
    $avaliacoesFiltradasLista = array_filter($avaliacoesFiltradasLista, fn($av) => empty($respAvParaFiltro[$av['id']]));
} elseif ($filtro_extra_av === 'negativas') {
    $avaliacoesFiltradasLista = array_filter($avaliacoesFiltradasLista, fn($av) => (int)$av['rating'] <= 2);
}
if ($busca_av !== '') {
    $buscaLower = mb_strtolower($busca_av);
    $avaliacoesFiltradasLista = array_filter($avaliacoesFiltradasLista, function ($av) use ($buscaLower, $clientesArr) {
        $comentario = mb_strtolower($av['comment'] ?? '');
        $nomeCli = mb_strtolower($clientesArr[$av['cliente_id']]['nome'] ?? '');
        return strpos($comentario, $buscaLower) !== false || strpos($nomeCli, $buscaLower) !== false;
    });
}
uasort($avaliacoesFiltradasLista, fn($a, $b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));
$avaliacoes_itemsPerPage = 10;
$avaliacoes_totalItems = count($avaliacoesFiltradasLista);
$avaliacoes_totalPages = $avaliacoes_totalItems > 0 ? ceil($avaliacoes_totalItems / $avaliacoes_itemsPerPage) : 1;
$avaliacoes_currentPage = max(1, min((int)($_GET['av_page'] ?? 1), $avaliacoes_totalPages));
$avaliacoes_offset = ($avaliacoes_currentPage - 1) * $avaliacoes_itemsPerPage;
$avaliacoesPaginados = array_slice($avaliacoesFiltradasLista, $avaliacoes_offset, $avaliacoes_itemsPerPage, true);

$agendamentosListagem = $agendamentosArr;
$view = $_GET['view'] ?? 'today';
$filtro_data = $_GET['filtro_data'] ?? '';
$filtro_horario = $_GET['filtro_horario'] ?? ''; 
$filtro_barbeiro = $_GET['filtro_barbeiro'] ?? '';
$filtro_status = $_GET['filtro_status'] ?? '';
$hoje = date('Y-m-d');

if (!empty($filtro_data)) {
    $agendamentosListagem = array_filter($agendamentosListagem, fn($ag) => $ag['data'] === $filtro_data);
} else {
    if ($view === 'today') {
        $agendamentosListagem = array_filter($agendamentosListagem, fn($ag) => $ag['data'] === $hoje);
    } elseif ($view === 'future') {
        $agendamentosListagem = array_filter($agendamentosListagem, fn($ag) => $ag['data'] > $hoje);
    }
}
if (!empty($filtro_barbeiro)) {
    $agendamentosListagem = array_filter($agendamentosListagem, fn($ag) => $ag['barbeiro_id'] === $filtro_barbeiro);
}
if (!empty($filtro_status)) {
    $agendamentosListagem = array_filter($agendamentosListagem, fn($ag) => $ag['status'] === $filtro_status);
}
if (!empty($filtro_horario)) {
    $agendamentosListagem = array_filter($agendamentosListagem, fn($ag) => substr($ag['hora'] ?? '', 0, 5) === substr($filtro_horario, 0, 5));
}

uasort($agendamentosListagem, fn($a, $b) => strtotime($a['data'] . ' ' . $a['hora']) - strtotime($b['data'] . ' ' . $b['hora']));
$itemsPerPage = 10;
$totalItems = count($agendamentosListagem);
$totalPages = $totalItems > 0 ? ceil($totalItems / $itemsPerPage) : 1;
$currentPage = max(1, min((int)($_GET['page'] ?? 1), $totalPages));
$offset = ($currentPage - 1) * $itemsPerPage;
$agendamentosPaginados = array_slice($agendamentosListagem, $offset, $itemsPerPage, true);
$agendamentosAgrupadosPorBarbeiro = [];
foreach ($agendamentosListagem as $ag) {
    $agendamentosAgrupadosPorBarbeiro[$ag['barbeiro_id']][] = $ag;
}

// --- DADOS PARA O JAVASCRIPT ---
$adminJSData = []; 
$adminJSData['dashboardChartData'] = [
    'novosRecorrentes' => [
        'labels' => ['Novos Clientes', 'Recorrentes'],
        'data' => [$dadosNovosVsRecorrentes['novos'], $dadosNovosVsRecorrentes['recorrentes']]
    ],
    // Receita por dia no dashboard. Reaproveita a mesma serie de Relatorios,
    // mas com os rotulos ja em dd/mm -- o eixo com datas ISO ficava ilegivel
    // assim que o periodo passava de uma semana.
    'receitaDiaria' => [
        'labels' => array_map(function ($dia) { return date('d/m', strtotime($dia)); }, array_keys($receitaDiaria)),
        'data'   => array_map(function ($valor) { return round($valor, 2); }, array_values($receitaDiaria)),
    ],
];
$labelsDescontos = array_column($analiseDescontos, 'label');
$valoresDescontos = array_column($analiseDescontos, 'total');
$adminJSData['relatoriosChartData'] = [
    'receitaDiaria' => ['labels' => array_keys($receitaDiaria), 'data' => array_values($receitaDiaria)],
    'fontesReceita' => [
        'labels' => ['Serviços Avulsos', 'Assinaturas/Planos', 'Produtos'],
        'data' => [$receitaServicos, $receitaPlanos, $receitaProdutos]
    ],
    'popularidadePlanos' => [
        'labels' => array_keys($planosPopulares),
        'data' => array_values($planosPopulares)
    ],
    'analiseDescontos' => ['labels' => $labelsDescontos, 'data' => $valoresDescontos],
    'ocupacaoBarbeiro' => ['labels' => array_keys($taxasOcupacao), 'data' => array_values($taxasOcupacao)],
    'agendamentosDiaSemana' => ['labels' => array_keys($agendamentosDiaSemana), 'data' => array_values($agendamentosDiaSemana)],
    'novosClientes' => ['data' => [$dadosNovosVsRecorrentes['recorrentes'], $dadosNovosVsRecorrentes['novos']]],
    'horariosPico' => ['labels' => array_keys($horariosPico), 'data' => array_values($horariosPico)],
    'despesasCategoria' => [
        'labels' => array_keys($despesasPorCategoria),
        'data' => array_map(fn($c) => round($c['total'], 2), array_values($despesasPorCategoria))
    ],
    'formasPagamento' => (function () use ($relCaixaPorForma) {
        $rotulos = ['dinheiro' => 'Dinheiro', 'pix' => 'PIX', 'debito' => 'Débito', 'credito' => 'Crédito', 'outro' => 'Outro', 'nao_informado' => 'Não informado'];
        $labels = []; $data = [];
        foreach ($rotulos as $chave => $rotulo) {
            if (($relCaixaPorForma[$chave] ?? 0) > 0) { $labels[] = $rotulo; $data[] = round($relCaixaPorForma[$chave], 2); }
        }
        return ['labels' => $labels, 'data' => $data];
    })(),
    'comparativo' => [
        'labels' => ['Receita', 'Lucro líquido'],
        'anterior' => [round($comparativoPeriodo['receita']['anterior'], 2), round($comparativoPeriodo['lucro']['anterior'], 2)],
        'atual' => [round($comparativoPeriodo['receita']['atual'], 2), round($comparativoPeriodo['lucro']['atual'], 2)]
    ]
];
$adminJSData['avaliacoesChartData'] = [
    'distribuicaoNotas' => ['data' => array_values(array_reverse($distribuicao_notas, true))],
    'mediaPorBarbeiro' => ['labels' => array_keys($media_por_barbeiro), 'data' => array_values($media_por_barbeiro)],
    'sentimentoGeral' => ['data' => array_values($sentimentoGeral)]
];

$clientesJS = array_map(function($cliente) {
    unset($cliente['password_hash'], $cliente['confirmation_token']);
    return $cliente;
}, array_values($clientesArr));
$barbeirosJS = array_map(function($barbeiro) {
    unset($barbeiro['password']);
    return $barbeiro;
}, $barbeirosArr);

$adminJSData['clientesData'] = $clientesJS;
$adminJSData['agendamentosData'] = array_values($agendamentosArr);
$adminJSData['anotacoesClientesData'] = $anotacoesClientes;
$adminJSData['barbeirosData'] = $barbeirosJS;
$combosJS = []; foreach($combosArr as $combo) { $combosJS[$combo['id']] = $combo; }
$adminJSData['combosData'] = $combosJS;
$adminJSData['categoriasData'] = $categoriasArr;
$adminJSData['horariosTrabalhoData'] = $horariosTrabalhoRaw;
$adminJSData['avaliacoesData'] = array_values($avaliacoesArr);
$adminJSData['clientesInfoData'] = array_column($clientesArr, 'nome', 'id');
$adminJSData['respostasAvaliacoesData'] = $respostasAvaliacoesArr;
$adminJSData['servicosData'] = $servicosArr;
$adminJSData['planosData'] = $planosArr;
$adminJSData['assinaturasData'] = $assinaturasClientesArr;

$adminJSData['produtosData'] = $produtosArr;
?>
