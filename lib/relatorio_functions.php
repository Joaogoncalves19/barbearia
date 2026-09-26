<?php
// lib/relatorio_functions.php
// Contém todas as funções de cálculo para o Dashboard e a aba de Relatórios.

function normalizarPeriodoRelatorio($dataInicio, $dataFim) {
    $inicio = DateTime::createFromFormat('Y-m-d', (string)$dataInicio);
    $fim = DateTime::createFromFormat('Y-m-d', (string)$dataFim);
    if (!$inicio || $inicio->format('Y-m-d') !== $dataInicio) {
        $inicio = new DateTime('first day of this month');
    }
    if (!$fim || $fim->format('Y-m-d') !== $dataFim) {
        $fim = new DateTime('last day of this month');
    }
    if ($inicio > $fim) {
        [$inicio, $fim] = [$fim, $inicio];
    }
    return [$inicio->format('Y-m-d'), $fim->format('Y-m-d')];
}

function calcularValoresAgendamentoRelatorio($agendamento, $servicos, $combos = []) {
    $valorServicosBruto = 0;
    $duracaoMinutos = 0;
    $itens = [];
    $ids = array_filter(array_map('trim', explode(',', (string)($agendamento['servicos_ids'] ?? ''))));

    foreach ($ids as $id) {
        if (isset($servicos[$id])) {
            $item = $servicos[$id];
            $valorServicosBruto += (float)($item['valor'] ?? 0);
            $duracaoMinutos += max(1, (int)($item['slots'] ?? 1)) * 30;
            $itens[] = ['id' => $id, 'nome' => $item['nome'] ?? 'Serviço', 'tipo' => 'servico'];
            continue;
        }
        if (isset($combos[$id])) {
            $combo = $combos[$id];
            $valorServicosBruto += (float)($combo['valor'] ?? 0);
            $slotsCombo = 0;
            foreach (array_filter(array_map('trim', explode(',', (string)($combo['servicos_ids'] ?? '')))) as $servicoId) {
                $slotsCombo += max(1, (int)($servicos[$servicoId]['slots'] ?? 1));
            }
            $duracaoMinutos += max(1, $slotsCombo) * 30;
            $itens[] = ['id' => $id, 'nome' => $combo['nome'] ?? 'Combo', 'tipo' => 'combo'];
        }
    }

    $valorProdutos = 0;
    $produtos = json_decode((string)($agendamento['produtos_vendidos'] ?? ''), true);
    if (is_array($produtos)) {
        foreach ($produtos as $produto) {
            $valorProdutos += max(0, (float)($produto['valor'] ?? 0));
        }
    }

    $desconto = max(0, (float)($agendamento['desconto_aplicado'] ?? 0));
    $valorServicosLiquido = max(0, $valorServicosBruto - $desconto);
    return [
        'servicos_bruto' => $valorServicosBruto,
        'servicos_liquido' => $valorServicosLiquido,
        'produtos' => $valorProdutos,
        'desconto' => min($desconto, $valorServicosBruto + $valorProdutos),
        'total' => max(0, $valorServicosBruto + $valorProdutos - $desconto),
        'duracao_minutos' => max(30, $duracaoMinutos),
        'itens' => $itens
    ];
}

function calcularResumoFinanceiroRelatorio(
    $agendamentosConcluidos,
    $servicos,
    $combos,
    $assinaturas,
    $planos,
    $dataInicio,
    $dataFim
) {
    $resumo = [
        'receita_servicos' => 0,
        'receita_produtos' => 0,
        'receita_planos' => 0,
        'receita_total' => 0,
        'receita_atendimentos' => 0,
        'descontos' => 0,
        'economia_assinatura' => 0,
        'pagamentos_assinatura' => [],
        'pagamentos_estimados' => 0,
        'atendimentos' => count($agendamentosConcluidos)
    ];

    foreach ($agendamentosConcluidos as $agendamento) {
        $valores = calcularValoresAgendamentoRelatorio($agendamento, $servicos, $combos);
        $resumo['receita_servicos'] += $valores['servicos_liquido'];
        $resumo['receita_produtos'] += $valores['produtos'];
        $resumo['receita_atendimentos'] += $valores['total'];
        $resumo['descontos'] += $valores['desconto'];
        if (($agendamento['tipo_desconto'] ?? '') === 'assinatura_vip') {
            $resumo['economia_assinatura'] += $valores['desconto'];
        }
    }

    $pagamentos = getPagamentosAssinaturaPeriodo($dataInicio, $dataFim, $assinaturas, $planos);
    foreach ($pagamentos as $pagamento) {
        $resumo['receita_planos'] += (float)($pagamento['valor'] ?? 0);
        if (!empty($pagamento['estimado'])) {
            $resumo['pagamentos_estimados']++;
        }
    }
    $resumo['pagamentos_assinatura'] = $pagamentos;
    $resumo['receita_total'] = $resumo['receita_atendimentos'] + $resumo['receita_planos'];
    $resumo['ticket_medio'] = $resumo['atendimentos'] > 0
        ? $resumo['receita_atendimentos'] / $resumo['atendimentos']
        : 0;
    return $resumo;
}

/**
 * Faz uma única passagem pelas comandas concluídas do período e devolve:
 * - gorjetas recebidas (100% da equipe, fora do resultado)
 * - CMV (custo dos produtos vendidos, lido da comanda)
 * - caixa por forma de pagamento (serviços líquidos + produtos + gorjeta)
 * - lucratividade por produto (quantidade, receita, custo, margem)
 *
 * Centraliza a lógica que antes vivia duplicada em imprimir_relatorio.php.
 */
function calcularCaixaGorjetasCmv($dataInicio, $dataFim, $servicos, $combos = []) {
    $res = [
        'gorjetas' => 0,
        'cmv' => 0,
        'caixa_por_forma' => [
            'dinheiro' => 0, 'pix' => 0, 'debito' => 0, 'credito' => 0,
            'outro' => 0, 'nao_informado' => 0
        ],
        'total_caixa' => 0,
        'produtos' => []
    ];
    try {
        if (function_exists('garantirColunasComanda')) {
            garantirColunasComanda();
        }
        $stmt = getDB()->prepare("SELECT servicos_ids, produtos_vendidos, desconto_aplicado, gorjeta, forma_pagamento FROM agendamentos WHERE status = 'concluido' AND substr(data,1,10) BETWEEN ? AND ?");
        $stmt->execute([$dataInicio, $dataFim]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $gorjeta = max(0, (float)($row['gorjeta'] ?? 0));
            $res['gorjetas'] += $gorjeta;

            $valorServicos = 0;
            foreach (explode(',', (string)$row['servicos_ids']) as $sid) {
                $sid = trim($sid);
                if (isset($servicos[$sid])) {
                    $valorServicos += (float)$servicos[$sid]['valor'];
                } elseif (isset($combos[$sid])) {
                    $valorServicos += (float)$combos[$sid]['valor'];
                }
            }
            $valorServicos = max(0, $valorServicos - (float)($row['desconto_aplicado'] ?? 0));

            $valorProdutos = 0;
            $itensProduto = json_decode((string)$row['produtos_vendidos'], true);
            if (is_array($itensProduto)) {
                foreach ($itensProduto as $produto) {
                    $valorP = max(0, (float)($produto['valor'] ?? 0));
                    $custoP = max(0, (float)($produto['custo'] ?? 0));
                    $valorProdutos += $valorP;
                    $res['cmv'] += $custoP;
                    $nomeP = trim((string)($produto['nome'] ?? 'Produto')) ?: 'Produto';
                    if (!isset($res['produtos'][$nomeP])) {
                        $res['produtos'][$nomeP] = ['quantidade' => 0, 'receita' => 0, 'custo' => 0];
                    }
                    $res['produtos'][$nomeP]['quantidade'] += (int)($produto['quantidade'] ?? 1) ?: 1;
                    $res['produtos'][$nomeP]['receita'] += $valorP;
                    $res['produtos'][$nomeP]['custo'] += $custoP;
                }
            }

            $totalComanda = $valorServicos + $valorProdutos + $gorjeta;
            $forma = $row['forma_pagamento'] ?: 'nao_informado';
            if (!array_key_exists($forma, $res['caixa_por_forma'])) {
                $forma = 'outro';
            }
            $res['caixa_por_forma'][$forma] += $totalComanda;
        }
    } catch (Exception $e) {
    }

    foreach ($res['produtos'] as &$produto) {
        $produto['margem'] = $produto['receita'] - $produto['custo'];
        $produto['margem_perc'] = $produto['receita'] > 0
            ? round(($produto['margem'] / $produto['receita']) * 100, 1)
            : 0;
    }
    unset($produto);
    uasort($res['produtos'], fn($a, $b) => $b['receita'] <=> $a['receita']);
    $res['total_caixa'] = array_sum($res['caixa_por_forma']);
    return $res;
}

/**
 * Agrupa as despesas já filtradas do período por categoria.
 * Recebe o array 'itens' devolvido por calcularDespesasPeriodoRelatorio().
 */
/**
 * Devolve [inicio, fim] do período imediatamente anterior, com a mesma duração
 * em dias do período informado. Usado para comparativos de crescimento.
 */
function calcularPeriodoAnteriorRelatorio($dataInicio, $dataFim) {
    try {
        $ini = new DateTime($dataInicio);
        $fim = new DateTime($dataFim);
    } catch (Exception $e) {
        return [$dataInicio, $dataFim];
    }
    $dias = $ini->diff($fim)->days + 1;
    $antFim = (clone $ini)->modify('-1 day');
    $antIni = (clone $antFim)->modify('-' . max(0, $dias - 1) . ' day');
    return [$antIni->format('Y-m-d'), $antFim->format('Y-m-d')];
}

/**
 * Calcula a variação percentual entre dois valores, protegendo contra divisão
 * por zero. Retorna null quando não há base de comparação (atual e anterior 0
 * ou base zero), para a UI decidir como exibir.
 */
function calcularVariacaoPercentual($atual, $anterior) {
    $atual = (float)$atual;
    $anterior = (float)$anterior;
    if ($anterior == 0.0) {
        return $atual == 0.0 ? 0.0 : null;
    }
    return round((($atual - $anterior) / abs($anterior)) * 100, 1);
}

function calcularDespesasPorCategoria($itensDespesa) {
    $categorias = [];
    foreach ($itensDespesa as $despesa) {
        $cat = trim((string)($despesa['categoria'] ?? '')) ?: 'Outros';
        if (!isset($categorias[$cat])) {
            $categorias[$cat] = ['total' => 0, 'quantidade' => 0];
        }
        $categorias[$cat]['total'] += (float)($despesa['valor'] ?? 0);
        $categorias[$cat]['quantidade']++;
    }
    uasort($categorias, fn($a, $b) => $b['total'] <=> $a['total']);
    return $categorias;
}

function calcularDespesasPeriodoRelatorio($despesas, $dataInicio, $dataFim) {
    $itens = [];
    $total = 0;
    foreach ($despesas as $despesa) {
        $dataCompetencia = (string)($despesa['data_vencimento'] ?? $despesa['data_despesa'] ?? '');
        if ($dataCompetencia < $dataInicio || $dataCompetencia > $dataFim) {
            continue;
        }
        $valor = max(0, (float)($despesa['valor'] ?? 0));
        $total += $valor;
        $despesa['valor'] = $valor;
        $despesa['data_competencia'] = $dataCompetencia;
        $itens[] = $despesa;
    }
    usort($itens, fn($a, $b) => strcmp($a['data_competencia'], $b['data_competencia']));
    return ['total' => $total, 'itens' => $itens];
}

function calcularEquipeRelatorio($agendamentos, $barbeiros, $servicos, $combos, $dataInicio, $dataFim) {
    $valesPorBarbeiro = [];
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS vales (id TEXT PRIMARY KEY, barbeiro_id TEXT, valor TEXT, data_vale TEXT, mes_referencia TEXT, descricao TEXT)");
        $stmt = $pdo->prepare("SELECT barbeiro_id, valor FROM vales WHERE data_vale BETWEEN ? AND ?");
        $stmt->execute([$dataInicio, $dataFim]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $vale) {
            $barbeiroId = (string)($vale['barbeiro_id'] ?? '');
            $valesPorBarbeiro[$barbeiroId] = ($valesPorBarbeiro[$barbeiroId] ?? 0) + (float)($vale['valor'] ?? 0);
        }
    } catch (Exception $e) {
    }

    $dados = [];
    foreach ($barbeiros as $id => $barbeiro) {
        $dados[$id] = [
            'nome' => $barbeiro['nome'] ?? 'Profissional',
            'atendimentos' => 0,
            'faturamento_total' => 0,
            'comissao_bruta' => 0,
            'comissao_assinatura' => 0,
            'vales' => $valesPorBarbeiro[$id] ?? 0,
            'comissao_liquida' => 0,
            'perc' => (float)($barbeiro['comissao'] ?? 0),
            'regra_assinatura' => descreverComissaoAssinatura($barbeiro)
        ];
    }

    foreach ($agendamentos as $agendamento) {
        $barbeiroId = (string)($agendamento['barbeiro_id'] ?? '');
        if (!isset($dados[$barbeiroId])) {
            continue;
        }
        $valores = calcularValoresAgendamentoRelatorio($agendamento, $servicos, $combos);
        $comissao = calcularComissaoAtendimento(
            $agendamento,
            $barbeiros[$barbeiroId],
            $valores['servicos_bruto'],
            $valores['produtos']
        );
        $dados[$barbeiroId]['atendimentos']++;
        $dados[$barbeiroId]['faturamento_total'] += $valores['total'];
        $dados[$barbeiroId]['comissao_bruta'] += $comissao['comissao_total'];
        if ($comissao['eh_assinatura']) {
            $dados[$barbeiroId]['comissao_assinatura'] += $comissao['comissao_servicos'];
        }
    }

    $totalComissoes = 0;
    foreach ($dados as &$item) {
        $item['comissao_liquida'] = max(0, $item['comissao_bruta'] - $item['vales']);
        $totalComissoes += $item['comissao_bruta'];
    }
    unset($item);
    uasort($dados, fn($a, $b) => $b['faturamento_total'] <=> $a['faturamento_total']);
    return ['total_comissoes' => $totalComissoes, 'profissionais' => $dados];
}

/**
 * Analisa as vendas de produtos em agendamentos concluídos.
 * @param array $agendamentos_filtrados
 * @return array
 */
function getVendasProdutosNoPeriodo($agendamentos_filtrados) {
    $vendas = [];
    foreach ($agendamentos_filtrados as $ag) {
        if ($ag['status'] === 'concluido' && !empty($ag['produtos_vendidos'])) {
            $produtos = json_decode($ag['produtos_vendidos'], true);
            if (is_array($produtos)) {
                foreach ($produtos as $produto) {
                    if (!isset($vendas[$produto['nome']])) {
                        $vendas[$produto['nome']] = ['quantidade' => 0, 'receita' => 0];
                    }
                    $vendas[$produto['nome']]['quantidade']++;
                    $vendas[$produto['nome']]['receita'] += (float)$produto['valor'];
                }
            }
        }
    }
    arsort($vendas);
    return $vendas;
}

/**
 * Analisa o total e a quantidade de descontos por tipo.
 * ATUALIZADO: Inclui suporte para Adesão de Plano e Barbearia por assinatura.
 * @param array $agendamentos_filtrados
 * @return array
 */
function getAnaliseDescontos($agendamentos_filtrados) {
    $descontos = [
        'cupom' => ['total' => 0, 'quantidade' => 0, 'label' => 'Cupons'],
        'voucher' => ['total' => 0, 'quantidade' => 0, 'label' => 'Vouchers'],
        'fidelidade' => ['total' => 0, 'quantidade' => 0, 'label' => 'Fidelidade'],
        'aniversario' => ['total' => 0, 'quantidade' => 0, 'label' => 'Aniversário'],
        'indicacao' => ['total' => 0, 'quantidade' => 0, 'label' => 'Indicação'],
        'adesao_plano' => ['total' => 0, 'quantidade' => 0, 'label' => 'Ajuste Adesão'],
        'assinatura_vip' => ['total' => 0, 'quantidade' => 0, 'label' => 'Assinatura'],
        'outro' => ['total' => 0, 'quantidade' => 0, 'label' => 'Outros']
    ];
    
    foreach ($agendamentos_filtrados as $ag) {
        $valor_desconto = (float)($ag['desconto_aplicado'] ?? 0);
        
        // Considera descontos positivos OU negativos (no caso de adesão onde o plano é mais caro que o serviço)
        if ($valor_desconto != 0) {
            $tipo = $ag['tipo_desconto'] ?? 'outro';
            
            if (isset($descontos[$tipo])) {
                $descontos[$tipo]['total'] += $valor_desconto;
                $descontos[$tipo]['quantidade']++;
            } else {
                $descontos['outro']['total'] += $valor_desconto;
                $descontos['outro']['quantidade']++;
            }
        }
    }
    
    // Retorna apenas os que tiveram alguma movimentação
    return array_filter($descontos, fn($d) => $d['quantidade'] > 0);
}

/**
 * ATUALIZADO (SQLite): Calcula a taxa de ocupação de um barbeiro em um período.
 * @param string $barbeiro_id
 * @param string $data_inicio
 * @param string $data_fim
 * @param array $agendamentos (Todos os agendamentos)
 * @param array $horarios_trabalho (Horários de trabalho vindos do SQLite)
 * @return float
 */
function getTaxaOcupacaoBarbeiro($barbeiro_id, $data_inicio, $data_fim, $agendamentos, $horarios_trabalho, $servicos = [], $combos = []) {
    $total_minutos_trabalhados = 0;
    $total_minutos_disponiveis = 0;

    $agendamentos_barbeiro = array_filter($agendamentos, fn($ag) => $ag['barbeiro_id'] === $barbeiro_id && $ag['data'] >= $data_inicio && $ag['data'] <= $data_fim);

    foreach ($agendamentos_barbeiro as $ag) {
        if (!in_array($ag['status'] ?? '', ['cancelado', 'cancelado_pelo_cliente', 'rejeitado', 'aguardando_pagamento'], true)) {
            $valores = calcularValoresAgendamentoRelatorio($ag, $servicos, $combos);
            $total_minutos_trabalhados += $valores['duracao_minutos'];
        }
    }
    
    $start = new DateTime($data_inicio);
    $end = new DateTime($data_fim);
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end->modify('+1 day'));

    foreach ($period as $date) {
        $dia_semana = $date->format('w');
        foreach ($horarios_trabalho as $ht) {
            
            // Suporta o novo formato SQLite (array associativo) ou fallback para o formato antigo de TXT (string)
            if (is_array($ht)) {
                $ht_barbeiro_id = $ht['barbeiro_id'] ?? $ht['id'] ?? '';
                $ht_dia = $ht['dia'] ?? '';
                $ht_inicio = $ht['inicio'] ?? '';
                $ht_fim = $ht['fim'] ?? '';
            } else {
                $partes = explode('|', $ht);
                if(count($partes) < 4) continue;
                $ht_barbeiro_id = $partes[0];
                $ht_dia = $partes[1];
                $ht_inicio = $partes[2];
                $ht_fim = $partes[3];
            }
            
            if ($ht_barbeiro_id === $barbeiro_id && $ht_dia == $dia_semana) {
                $inicio = new DateTime($ht_inicio);
                $fim = new DateTime($ht_fim);
                $diff = $fim->getTimestamp() - $inicio->getTimestamp();
                $total_minutos_disponiveis += $diff / 60;
            }
        }
    }

    if ($total_minutos_disponiveis == 0) return 0;
    return round(($total_minutos_trabalhados / $total_minutos_disponiveis) * 100, 2);
}

/**
 * Calcula a frequência média (em dias) entre visitas de clientes recorrentes.
 * @param array $agendamentos_filtrados
 * @return string
 */
function getFrequenciaMediaVisitas($agendamentos_filtrados) {
    $visitas_por_cliente = [];
    foreach ($agendamentos_filtrados as $ag) {
        if ($ag['status'] === 'concluido') {
            $cliente_identificador = $ag['email'] . '|' . $ag['telefone'];
            $visitas_por_cliente[$cliente_identificador][] = strtotime($ag['data']);
        }
    }

    $soma_diferencas_dias = 0;
    $total_intervalos = 0;

    foreach ($visitas_por_cliente as $datas) {
        if (count($datas) > 1) {
            sort($datas);
            for ($i = 1; $i < count($datas); $i++) {
                $diferenca = $datas[$i] - $datas[$i-1];
                $soma_diferencas_dias += $diferenca / (60 * 60 * 24);
                $total_intervalos++;
            }
        }
    }
    
    if ($total_intervalos == 0) return "N/D";
    return round($soma_diferencas_dias / $total_intervalos);
}

/**
 * ATUALIZADO: Calcula de forma EXATA quem é novo e quem é recorrente
 * cruzando os clientes que vieram no período com o histórico GLOBAL deles.
 * @param array $agendamentos_filtrados (Só do período)
 * @param array $todos_agendamentos (Toda a base de dados)
 * @return array
 */
function getNovosVsRecorrentes($agendamentos_filtrados, $todos_agendamentos) {
    $primeiraVisita = [];
    foreach ($todos_agendamentos as $ag) {
        if ($ag['status'] === 'concluido') {
            $cliente_id = (string)($ag['cliente_id'] ?? '');
            if ($cliente_id === '') {
                $cliente_id = strtolower(trim((string)($ag['email'] ?? '')))
                    ?: trim(preg_replace('/\D/', '', (string)($ag['telefone'] ?? '')));
            }
            $data = (string)($ag['data'] ?? '');
            if ($cliente_id !== '' && $data !== '' && (!isset($primeiraVisita[$cliente_id]) || $data < $primeiraVisita[$cliente_id])) {
                $primeiraVisita[$cliente_id] = $data;
            }
        }
    }

    $clientes_atendidos_no_periodo = [];
    $inicioPeriodo = null;
    foreach ($agendamentos_filtrados as $ag) {
        $data = (string)($ag['data'] ?? '');
        if ($data !== '' && ($inicioPeriodo === null || $data < $inicioPeriodo)) {
            $inicioPeriodo = $data;
        }
        if ($ag['status'] === 'concluido') {
            $cliente_id = (string)($ag['cliente_id'] ?? '');
            if ($cliente_id === '') {
                $cliente_id = strtolower(trim((string)($ag['email'] ?? '')))
                    ?: trim(preg_replace('/\D/', '', (string)($ag['telefone'] ?? '')));
            }
            if (!empty($cliente_id)) {
                $clientes_atendidos_no_periodo[$cliente_id] = true;
            }
        }
    }

    $novos = 0;
    $recorrentes = 0;
    foreach (array_keys($clientes_atendidos_no_periodo) as $cliente_id) {
        if ($inicioPeriodo !== null && ($primeiraVisita[$cliente_id] ?? $inicioPeriodo) >= $inicioPeriodo) {
            $novos++;
        } else {
            $recorrentes++;
        }
    }
    
    return ['novos' => $novos, 'recorrentes' => $recorrentes];
}

/**
 * Encontra clientes recorrentes que não agendam há um tempo (em risco de churn).
 * Depende de: limparTelefone()
 * @param array $agendamentos (Todos os agendamentos)
 * @param array $clientes (Todos os clientes)
 * @param int $dias_limite (Padrão: 90 dias)
 * @return array
 */
function getClientesEmRisco($agendamentos, $clientes, $dias_limite = 90) {
    $clientes_em_risco = [];
    $limite = time() - ($dias_limite * 24 * 60 * 60);

    foreach ($clientes as $id => $cliente) {
        $agendamentos_cliente = array_filter($agendamentos, fn($ag) => ($ag['email'] === $cliente['email'] || limparTelefone($ag['telefone']) === limparTelefone($cliente['telefone'])) && $ag['status'] === 'concluido');
        
        if (count($agendamentos_cliente) >= 2) { // Considera apenas clientes recorrentes
            $ultimo_agendamento = null;
            $ultima_data = 0;
            foreach($agendamentos_cliente as $ag) {
                $data_ts = strtotime($ag['data']);
                if ($data_ts > $ultima_data) {
                    $ultima_data = $data_ts;
                    $ultimo_agendamento = $ag;
                }
            }
            if ($ultima_data < $limite && $ultima_data > 0) { 
                $clientes_em_risco[$id] = $cliente;
                $clientes_em_risco[$id]['ultimo_agendamento'] = date('Y-m-d', $ultima_data);
            }
        }
    }
    return $clientes_em_risco;
}

/**
 * Encontra as combinações de serviços mais populares.
 * @param array $agendamentos_filtrados
 * @param array $servicos (Array de todos os serviços)
 * @return array
 */
function getServicosMaisCombinados($agendamentos_filtrados, $servicos) {
    $combinacoes = [];
    foreach ($agendamentos_filtrados as $ag) {
        $sids = explode(',', $ag['servicos_ids']);
        if (count($sids) > 1) {
            sort($sids);
            $key = implode('-', $sids);
            if (!isset($combinacoes[$key])) {
                $combinacoes[$key] = ['count' => 0, 'sids' => $sids];
            }
            $combinacoes[$key]['count']++;
        }
    }
    uasort($combinacoes, fn($a, $b) => $b['count'] <=> $a['count']);
    
    $resultado = [];
    foreach (array_slice($combinacoes, 0, 5) as $combo) {
        $nomes = array_map(fn($sid) => $servicos[trim($sid)]['nome'] ?? '?', $combo['sids']);
        $resultado[] = ['combinacao' => implode(' + ', $nomes), 'ocorrencias' => $combo['count']];
    }
    return $resultado;
}


/**
 * Retorna o ranking dos serviços mais realizados.
 * @param array $agendamentos_concluidos
 * @param array $servicosArr
 * @return array
 */
function getRankingServicos($agendamentos_concluidos, $servicosArr, $combosArr = []) {
    $contagem = [];
    foreach ($agendamentos_concluidos as $ag) {
        $sids = explode(',', $ag['servicos_ids']);
        foreach ($sids as $sid) {
            $sid = trim($sid);
            if (isset($servicosArr[$sid]) || isset($combosArr[$sid])) {
                if (!isset($contagem[$sid])) {
                    $contagem[$sid] = 0;
                }
                $contagem[$sid]++;
            }
        }
    }
    arsort($contagem);
    return array_slice($contagem, 0, 5, true);
}

/**
 * Retorna a lista de clientes que fazem aniversário no mês atual.
 * @param array $clientesArr
 * @return array
 */
function getAniversariantesDoMes($clientesArr) {
    $aniversariantes = [];
    $mes_atual = date('m');
    foreach ($clientesArr as $cliente) {
        if (!empty($cliente['data_nascimento'])) {
            $mes_nascimento = date('m', strtotime($cliente['data_nascimento']));
            if ($mes_nascimento === $mes_atual) {
                $aniversariantes[] = $cliente;
            }
        }
    }
    usort($aniversariantes, function($a, $b) {
        return date('d', strtotime($a['data_nascimento'])) <=> date('d', strtotime($b['data_nascimento']));
    });
    return $aniversariantes;
}

/**
 * Retorna a contagem de agendamentos por status.
 * @param array $agendamentos_filtrados
 * @return array
 */
function getContagemStatusAgendamentos($agendamentos_filtrados) {
    $contagem = [
        'concluido' => 0,
        'cancelado' => 0,
        'pendente' => 0,
        'aprovado' => 0,
        'aguardando_pagamento' => 0,
        'rejeitado' => 0,
        'total' => 0
    ];
    
    if (empty($agendamentos_filtrados)) {
        return $contagem;
    }

    foreach ($agendamentos_filtrados as $ag) {
        $status = $ag['status'] ?? 'pendente';
        if (array_key_exists($status, $contagem)) {
            $contagem[$status]++;
        } elseif ($status === 'cancelado_pelo_cliente') {
             $contagem['cancelado']++; 
        } else {
            $contagem['pendente']++;
        }
        $contagem['total']++;
    }
    return $contagem;
}

/**
 * Encontra o barbeiro com maior receita no período.
 * @param array $agendamentos_concluidos
 * @param array $barbeirosArr
 * @param array $servicosArr
 * @return array ['nome' => string, 'valor' => float]
 */
function getMelhorBarbeiro($agendamentos_concluidos, $barbeirosArr, $servicosArr, $combosArr = []) {
    $receitaPorBarbeiro = [];
    
    foreach ($agendamentos_concluidos as $ag) {
        $barbeiro_id = $ag['barbeiro_id'];
        if (!isset($receitaPorBarbeiro[$barbeiro_id])) {
            $receitaPorBarbeiro[$barbeiro_id] = 0;
        }
        
        $valores = calcularValoresAgendamentoRelatorio($ag, $servicosArr, $combosArr);
        $receitaPorBarbeiro[$barbeiro_id] += $valores['total'];
    }
    
    if (empty($receitaPorBarbeiro)) {
        return ['nome' => 'N/A', 'valor' => 0];
    }
    
    arsort($receitaPorBarbeiro);
    $melhor_id = key($receitaPorBarbeiro);
    $melhor_valor = $receitaPorBarbeiro[$melhor_id];
    $melhor_nome = $barbeirosArr[$melhor_id]['nome'] ?? 'Desconhecido';
    
    return ['nome' => $melhor_nome, 'valor' => $melhor_valor];
}

/**
 * Contabiliza agendamentos por hora do dia.
 * @param array $agendamentos_filtrados
 * @return array
 */
function getHorariosDePico($agendamentos_filtrados) {
    $contagem_por_hora = [];
    for ($i = 0; $i < 24; $i++) {
        $hora_label = str_pad($i, 2, '0', STR_PAD_LEFT) . ':00';
        $contagem_por_hora[$hora_label] = 0;
    }

    foreach ($agendamentos_filtrados as $ag) {
        if ($ag['status'] === 'concluido' || $ag['status'] === 'aprovado') {
            $hora = date('H:00', strtotime($ag['hora'])); 
            if (isset($contagem_por_hora[$hora])) {
                $contagem_por_hora[$hora]++;
            }
        }
    }
    return $contagem_por_hora;
}

/**
 * Retorna os top 5 clientes por gasto total no período.
 * @param array $agendamentos_concluidos
 * @param array $servicosArr
 * @return array
 */
function getTopClientesPorGasto($agendamentos_concluidos, $servicosArr, $combosArr = []) {
    $gasto_por_cliente = [];

    foreach ($agendamentos_concluidos as $ag) {
        $sufixoTelefone = substr(preg_replace('/\D/', '', (string)($ag['telefone'] ?? '')), -4);
        $cliente_id = trim((string)($ag['nome'] ?? 'Cliente')) . ($sufixoTelefone !== '' ? ' (' . $sufixoTelefone . ')' : '');
        if (!isset($gasto_por_cliente[$cliente_id])) {
            $gasto_por_cliente[$cliente_id] = 0;
        }

        $valores = calcularValoresAgendamentoRelatorio($ag, $servicosArr, $combosArr);
        $gasto_por_cliente[$cliente_id] += $valores['total'];
    }

    arsort($gasto_por_cliente);
    return array_slice($gasto_por_cliente, 0, 5, true);
}

/**
 * (NOVO) Calcula a popularidade dos planos com base nas assinaturas ativas.
 * @param array $assinaturas
 * @param array $planos
 * @return array
 */
function getPopularidadePlanos($assinaturas, $planos) {
    $stats = [];
    foreach ($assinaturas as $sub) {
        if ($sub['status'] === 'ativo') {
            $planoNome = $planos[$sub['plano_id']]['nome'] ?? 'Plano Removido';
            if (!isset($stats[$planoNome])) {
                $stats[$planoNome] = 0;
            }
            $stats[$planoNome]++;
        }
    }
    arsort($stats);
    return $stats;
}

/**
 * Garante os campos usados para configurar a comissão de atendimentos por assinatura.
 */
function garantirCamposComissaoAssinatura() {
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS barbeiros (id TEXT PRIMARY KEY, nome TEXT, foto TEXT, username TEXT, password TEXT, status TEXT, servicos_ids TEXT, comissao REAL DEFAULT 0, comissao_produtos INTEGER DEFAULT 0)");
        try {
            $pdo->exec("ALTER TABLE barbeiros ADD COLUMN comissao_assinatura_tipo TEXT DEFAULT 'padrao'");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE barbeiros ADD COLUMN comissao_assinatura_valor REAL DEFAULT 0");
        } catch (Exception $e) {
        }
    } catch (Exception $e) {
    }
}

/**
 * Calcula a comissão de um atendimento, respeitando a regra de assinatura do profissional.
 */
function calcularComissaoAtendimento($agendamento, $barbeiro, $valorServicosBruto, $valorProdutos = 0) {
    $valorServicosBruto = max(0, (float)$valorServicosBruto);
    $valorProdutos = max(0, (float)$valorProdutos);
    $desconto = max(0, (float)($agendamento['desconto_aplicado'] ?? 0));
    $valorServicosLiquido = max(0, $valorServicosBruto - $desconto);
    $percentualPadrao = min(100, max(0, (float)($barbeiro['comissao'] ?? 0)));
    $tipoDesconto = (string)($agendamento['tipo_desconto'] ?? '');
    $ehAssinatura = in_array($tipoDesconto, ['adesao_plano', 'assinatura_vip', 'assinatura', 'plano'], true);

    $modoAssinatura = (string)($barbeiro['comissao_assinatura_tipo'] ?? 'padrao');
    if (!in_array($modoAssinatura, ['padrao', 'percentual', 'fixo', 'nenhuma'], true)) {
        $modoAssinatura = 'padrao';
    }
    $valorRegraAssinatura = max(0, (float)($barbeiro['comissao_assinatura_valor'] ?? 0));

    $comissaoServicos = 0;
    $percentualAplicado = $percentualPadrao;

    if ($ehAssinatura) {
        if ($modoAssinatura === 'percentual') {
            $percentualAplicado = min(100, $valorRegraAssinatura);
            $comissaoServicos = $valorServicosBruto * ($percentualAplicado / 100);
        } elseif ($modoAssinatura === 'fixo') {
            $percentualAplicado = null;
            $comissaoServicos = $valorRegraAssinatura;
        } elseif ($modoAssinatura === 'nenhuma') {
            $percentualAplicado = 0;
            $comissaoServicos = 0;
        } else {
            $comissaoServicos = $valorServicosBruto * ($percentualPadrao / 100);
        }
    } else {
        $comissaoServicos = $valorServicosLiquido * ($percentualPadrao / 100);
    }

    $comissaoProdutos = !empty($barbeiro['comissao_produtos'])
        ? $valorProdutos * ($percentualPadrao / 100)
        : 0;

    return [
        'eh_assinatura' => $ehAssinatura,
        'modo_assinatura' => $modoAssinatura,
        'percentual_aplicado' => $percentualAplicado,
        'valor_servicos_liquido' => $valorServicosLiquido,
        'base_comissao_servicos' => $ehAssinatura ? $valorServicosBruto : $valorServicosLiquido,
        'comissao_servicos' => $comissaoServicos,
        'comissao_produtos' => $comissaoProdutos,
        'comissao_total' => $comissaoServicos + $comissaoProdutos
    ];
}

/**
 * Retorna uma descrição curta da regra de comissão usada em assinaturas.
 */
function descreverComissaoAssinatura($barbeiro) {
    $tipo = (string)($barbeiro['comissao_assinatura_tipo'] ?? 'padrao');
    $valor = max(0, (float)($barbeiro['comissao_assinatura_valor'] ?? 0));

    if ($tipo === 'percentual') {
        return number_format(min(100, $valor), 2, ',', '.') . '% sobre a tabela';
    }
    if ($tipo === 'fixo') {
        return 'R$ ' . number_format($valor, 2, ',', '.') . ' por atendimento';
    }
    if ($tipo === 'nenhuma') {
        return 'Sem comissão';
    }

    return number_format(max(0, (float)($barbeiro['comissao'] ?? 0)), 2, ',', '.') . '% sobre a tabela';
}

/**
 * Garante as colunas usadas pelas despesas recorrentes.
 */
function garantirColunasDespesas() {
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS despesas (id TEXT PRIMARY KEY, descricao TEXT, valor TEXT, data_vencimento TEXT, data_pagamento TEXT, status TEXT, categoria TEXT)");
    try { $pdo->exec("ALTER TABLE despesas ADD COLUMN recorrente INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE despesas ADD COLUMN recorrencia_origem TEXT DEFAULT ''"); } catch (Exception $e) {}
}

/**
 * Gera (de forma idempotente) as instâncias de despesas recorrentes para o mês
 * informado (formato 'Y-m'). Cada despesa marcada como recorrente vira um
 * modelo que replica automaticamente nos meses seguintes.
 */
function garantirDespesasRecorrentes($mesAlvo) {
    if (!preg_match('/^\d{4}-\d{2}$/', (string)$mesAlvo)) return;
    garantirColunasDespesas();
    $pdo = getDB();
    try {
        $tpls = $pdo->query("SELECT * FROM despesas WHERE recorrente = 1")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return;
    }
    foreach ($tpls as $t) {
        $mesTpl = substr((string)($t['data_vencimento'] ?? ''), 0, 7);
        if ($mesTpl === '' || $mesTpl >= $mesAlvo) continue; // só replica em meses posteriores ao modelo

        $chk = $pdo->prepare("SELECT COUNT(*) FROM despesas WHERE recorrencia_origem = ? AND substr(data_vencimento,1,7) = ?");
        $chk->execute([$t['id'], $mesAlvo]);
        if ((int)$chk->fetchColumn() > 0) continue; // já gerada

        $dia = substr((string)$t['data_vencimento'], 8, 2) ?: '01';
        $ultimoDia = date('t', strtotime($mesAlvo . '-01'));
        if ((int)$dia > (int)$ultimoDia) $dia = (string)$ultimoDia;
        $novaData = $mesAlvo . '-' . str_pad($dia, 2, '0', STR_PAD_LEFT);

        $ins = $pdo->prepare("INSERT INTO despesas (id, descricao, valor, data_vencimento, data_pagamento, status, categoria, recorrente, recorrencia_origem) VALUES (?, ?, ?, ?, '', 'pendente', ?, 0, ?)");
        $ins->execute([gerarId('desp-'), $t['descricao'], $t['valor'], $novaData, $t['categoria'], $t['id']]);
    }
}
?>
