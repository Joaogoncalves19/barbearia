<?php
// exportar_relatorio.php
// Exporta um relatório específico da Central de Relatórios em CSV (UTF-8 com BOM,
// separador ";" — compatível com Excel/Google Sheets em pt-BR).
require_once 'functions.php';
iniciarSessaoSegura();
date_default_timezone_set('America/Sao_Paulo');

if (empty($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

// --- Carregamento de dados (espelha imprimir_relatorio.php) ---
$barbeirosArr = lerDados('barbeiros', [
    'id', 'nome', 'foto', 'username', 'password', 'status', 'servicos_ids',
    'comissao', 'comissao_produtos', 'comissao_assinatura_tipo', 'comissao_assinatura_valor'
]);
$servicosArr = lerDados('servicos', ['id', 'nome', 'valor', 'slots', 'categoria_id']);
$combosArr = lerDados('combos', ['id', 'nome', 'servicos_ids', 'valor', 'categoria_id']);
$despesasArr = lerDados('despesas', ['id', 'descricao', 'valor', 'data_vencimento', 'data_pagamento', 'status', 'categoria']);
$agendamentosArr = lerDados('agendamentos', [
    'id', 'nome', 'email', 'telefone', 'barbeiro_id', 'servicos_ids', 'data',
    'hora', 'status', 'desconto_aplicado', 'tipo_desconto', 'observacoes',
    'produtos_vendidos', 'plano_provisorio', 'cliente_id'
]);
$clientesArr = lerDados('clientes', [
    'id', 'nome', 'email', 'telefone', 'password_hash', 'data_nascimento',
    'foto_perfil', 'codigo_indicacao', 'cpf', 'indicado_por_id', 'status', 'confirmation_token'
]);
$planosArr = lerDados('planos', ['id', 'nome', 'valor', 'servicos_ids']);
$assinaturasClientesArr = lerDados('clientes_assinaturas', [
    'cliente_id', 'plano_id', 'data_inicio', 'data_fim', 'status', 'gateway',
    'gateway_subscription_id', 'gateway_status', 'ultimo_pagamento_id', 'cancelamento_em'
]);

[$dataInicio, $dataFim] = normalizarPeriodoRelatorio(
    $_GET['data_inicio'] ?? date('Y-m-01'),
    $_GET['data_fim'] ?? date('Y-m-t')
);
$tipo = preg_replace('/[^a-z_]/', '', (string)($_GET['tipo'] ?? ''));
$hoje = date('Y-m-d');

$agendamentosFiltrados = array_filter($agendamentosArr, fn($a) =>
    ($a['data'] ?? '') >= $dataInicio && ($a['data'] ?? '') <= $dataFim);
$agendamentosConcluidos = array_filter($agendamentosFiltrados, fn($a) =>
    ($a['status'] ?? '') === 'concluido');

// Helper de formatação de moeda no padrão brasileiro.
$moeda = fn($v) => number_format((float)$v, 2, ',', '.');

// Monta o dataset (cabeçalho + linhas) conforme o tipo solicitado.
$titulo = 'relatorio';
$cabecalho = [];
$linhas = [];

switch ($tipo) {
    case 'financeiro':
        $resumo = calcularResumoFinanceiroRelatorio($agendamentosConcluidos, $servicosArr, $combosArr, $assinaturasClientesArr, $planosArr, $dataInicio, $dataFim);
        $despesas = calcularDespesasPeriodoRelatorio($despesasArr, $dataInicio, $dataFim);
        $equipe = calcularEquipeRelatorio($agendamentosConcluidos, $barbeirosArr, $servicosArr, $combosArr, $dataInicio, $dataFim);
        $caixa = calcularCaixaGorjetasCmv($dataInicio, $dataFim, $servicosArr, $combosArr);
        $lucro = $resumo['receita_total'] - $equipe['total_comissoes'] - $despesas['total'] - $caixa['cmv'];
        $titulo = 'dre_financeiro';
        $cabecalho = ['Linha', 'Tipo', 'Valor (R$)'];
        $linhas = [
            ['Serviços', 'Entrada', $moeda($resumo['receita_servicos'])],
            ['Assinaturas', 'Entrada', $moeda($resumo['receita_planos'])],
            ['Produtos', 'Entrada', $moeda($resumo['receita_produtos'])],
            ['Receita Bruta Total', 'Subtotal', $moeda($resumo['receita_total'])],
            ['Comissões', 'Saída', $moeda($equipe['total_comissoes'])],
            ['Despesas Operacionais', 'Saída', $moeda($despesas['total'])],
            ['Custo de Produtos (CMV)', 'Saída', $moeda($caixa['cmv'])],
            ['Lucro Líquido', 'Resultado', $moeda($lucro)],
            ['Gorjetas (fora do resultado)', 'Informativo', $moeda($caixa['gorjetas'])],
        ];
        break;

    case 'formas_pagamento':
        $caixa = calcularCaixaGorjetasCmv($dataInicio, $dataFim, $servicosArr, $combosArr);
        $rotulos = ['dinheiro' => 'Dinheiro', 'pix' => 'PIX', 'debito' => 'Cartão de débito', 'credito' => 'Cartão de crédito', 'outro' => 'Outro', 'nao_informado' => 'Não informado'];
        $total = $caixa['total_caixa'];
        $titulo = 'formas_pagamento';
        $cabecalho = ['Forma de pagamento', '% do total', 'Valor (R$)'];
        foreach ($rotulos as $k => $label) {
            if (($caixa['caixa_por_forma'][$k] ?? 0) <= 0) continue;
            $pct = $total > 0 ? number_format(($caixa['caixa_por_forma'][$k] / $total) * 100, 1, ',', '.') : '0';
            $linhas[] = [$label, $pct . '%', $moeda($caixa['caixa_por_forma'][$k])];
        }
        break;

    case 'despesas':
        $despesas = calcularDespesasPeriodoRelatorio($despesasArr, $dataInicio, $dataFim);
        $titulo = 'despesas_detalhadas';
        $cabecalho = ['Vencimento', 'Descrição', 'Categoria', 'Status', 'Valor (R$)'];
        foreach ($despesas['itens'] as $d) {
            $linhas[] = [
                date('d/m/Y', strtotime($d['data_competencia'])),
                (string)($d['descricao'] ?? 'Despesa'),
                (string)($d['categoria'] ?? 'Outros'),
                ucfirst((string)($d['status'] ?? 'pendente')),
                $moeda($d['valor'])
            ];
        }
        break;

    case 'despesas_categoria':
        $despesas = calcularDespesasPeriodoRelatorio($despesasArr, $dataInicio, $dataFim);
        $porCat = calcularDespesasPorCategoria($despesas['itens']);
        $titulo = 'despesas_por_categoria';
        $cabecalho = ['Categoria', 'Lançamentos', 'Valor (R$)'];
        foreach ($porCat as $cat => $info) {
            $linhas[] = [$cat, (int)$info['quantidade'], $moeda($info['total'])];
        }
        break;

    case 'equipe':
        $equipe = calcularEquipeRelatorio($agendamentosConcluidos, $barbeirosArr, $servicosArr, $combosArr, $dataInicio, $dataFim);
        $titulo = 'desempenho_equipe';
        $cabecalho = ['Profissional', 'Atendimentos', 'Faturamento (R$)', 'Ticket médio (R$)', 'Comissão bruta (R$)', 'Vales (R$)', 'Líquido (R$)', 'Regra assinatura'];
        foreach ($equipe['profissionais'] as $p) {
            $ticket = $p['atendimentos'] > 0 ? $p['faturamento_total'] / $p['atendimentos'] : 0;
            $linhas[] = [
                (string)$p['nome'], (int)$p['atendimentos'], $moeda($p['faturamento_total']),
                $moeda($ticket), $moeda($p['comissao_bruta']), $moeda($p['vales']),
                $moeda($p['comissao_liquida']), (string)$p['regra_assinatura']
            ];
        }
        break;

    case 'clientes':
        // Gasto total por cliente no período (lista completa).
        $gasto = [];
        foreach ($agendamentosConcluidos as $ag) {
            $sufixo = substr(preg_replace('/\D/', '', (string)($ag['telefone'] ?? '')), -4);
            $chave = trim((string)($ag['nome'] ?? 'Cliente')) . ($sufixo !== '' ? ' (' . $sufixo . ')' : '');
            $valores = calcularValoresAgendamentoRelatorio($ag, $servicosArr, $combosArr);
            $gasto[$chave] = ($gasto[$chave] ?? 0) + $valores['total'];
        }
        arsort($gasto);
        $titulo = 'maiores_compradores';
        $cabecalho = ['Posição', 'Cliente', 'Total gasto (R$)'];
        $pos = 1;
        foreach ($gasto as $nome => $valor) {
            $linhas[] = [$pos++, (string)$nome, $moeda($valor)];
        }
        break;

    case 'clientes_risco':
        $risco = getClientesEmRisco($agendamentosArr, $clientesArr, 90);
        $titulo = 'clientes_em_risco';
        $cabecalho = ['Cliente', 'Telefone', 'E-mail', 'Último atendimento'];
        foreach ($risco as $c) {
            $linhas[] = [
                (string)($c['nome'] ?? 'Cliente'), (string)($c['telefone'] ?? ''),
                (string)($c['email'] ?? ''),
                isset($c['ultimo_agendamento']) ? date('d/m/Y', strtotime($c['ultimo_agendamento'])) : ''
            ];
        }
        break;

    case 'aniversariantes':
        $aniv = getAniversariantesDoMes($clientesArr);
        $titulo = 'aniversariantes_do_mes';
        $cabecalho = ['Cliente', 'Telefone', 'E-mail', 'Aniversário'];
        foreach ($aniv as $c) {
            $linhas[] = [
                (string)($c['nome'] ?? 'Cliente'), (string)($c['telefone'] ?? ''),
                (string)($c['email'] ?? ''), date('d/m', strtotime($c['data_nascimento']))
            ];
        }
        break;

    case 'servicos':
        // Ranking completo de serviços/combos concluídos no período.
        $contagem = [];
        foreach ($agendamentosConcluidos as $ag) {
            foreach (explode(',', (string)($ag['servicos_ids'] ?? '')) as $sid) {
                $sid = trim($sid);
                if (isset($servicosArr[$sid]) || isset($combosArr[$sid])) {
                    $contagem[$sid] = ($contagem[$sid] ?? 0) + 1;
                }
            }
        }
        arsort($contagem);
        $titulo = 'servicos_mais_realizados';
        $cabecalho = ['Serviço / Combo', 'Realizações', 'Faturamento tabela (R$)'];
        foreach ($contagem as $sid => $qtd) {
            $serv = $servicosArr[$sid] ?? $combosArr[$sid] ?? null;
            if (!$serv) continue;
            $linhas[] = [(string)$serv['nome'], (int)$qtd, $moeda((float)$serv['valor'] * $qtd)];
        }
        break;

    case 'produtos':
        $caixa = calcularCaixaGorjetasCmv($dataInicio, $dataFim, $servicosArr, $combosArr);
        $titulo = 'vendas_produtos';
        $cabecalho = ['Produto', 'Quantidade', 'Receita (R$)', 'Custo/CMV (R$)', 'Margem (R$)', 'Margem (%)'];
        foreach ($caixa['produtos'] as $nome => $info) {
            $linhas[] = [
                (string)$nome, (int)$info['quantidade'], $moeda($info['receita']),
                $moeda($info['custo']), $moeda($info['margem']),
                number_format($info['margem_perc'], 1, ',', '.') . '%'
            ];
        }
        break;

    case 'pagamentos_assinatura':
        $resumo = calcularResumoFinanceiroRelatorio($agendamentosConcluidos, $servicosArr, $combosArr, $assinaturasClientesArr, $planosArr, $dataInicio, $dataFim);
        $titulo = 'pagamentos_assinatura';
        $cabecalho = ['Data', 'Cliente', 'Plano', 'Origem', 'Estimado', 'Valor (R$)'];
        foreach ($resumo['pagamentos_assinatura'] as $pag) {
            $linhas[] = [
                date('d/m/Y', strtotime($pag['data_pagamento'])),
                (string)($clientesArr[$pag['cliente_id']]['nome'] ?? 'Cliente'),
                (string)($planosArr[$pag['plano_id']]['nome'] ?? 'Plano removido'),
                ucfirst((string)($pag['gateway'] ?? 'manual')),
                !empty($pag['estimado']) ? 'Sim' : 'Não',
                $moeda($pag['valor'])
            ];
        }
        break;

    default:
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Tipo de relatório inválido.';
        exit;
}

// --- Emissão do CSV ---
$nomeArquivo = $titulo . '_' . $dataInicio . '_a_' . $dataFim . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$saida = fopen('php://output', 'w');
fwrite($saida, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel reconhecer acentuação.

// Linha de contexto do período.
fputcsv($saida, ['Período', date('d/m/Y', strtotime($dataInicio)) . ' a ' . date('d/m/Y', strtotime($dataFim))], ';');
fputcsv($saida, [], ';');

fputcsv($saida, $cabecalho, ';');
if (empty($linhas)) {
    fputcsv($saida, ['Sem dados no período.'], ';');
} else {
    foreach ($linhas as $linha) {
        fputcsv($saida, $linha, ';');
    }
}
fclose($saida);
exit;
