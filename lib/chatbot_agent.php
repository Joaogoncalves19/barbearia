<?php
// lib/chatbot_agent.php
// Agente conversacional do assistente: a IA (Groq) recebe "ferramentas" e o PHP
// as executa com segurança, dando ao chatbot a capacidade de AGIR (consultar,
// agendar, cancelar, remarcar, ver pontos/assinatura...) por linguagem natural.
//
// Depende de funções definidas em assistente.php e no core (lerDados,
// getHorarioDeTrabalho, getHorariosOcupados, _botCriarAgendamento,
// _botAgendamentosCliente, limparTelefone, etc.). É incluído por assistente.php.

/**
 * Esquema das ferramentas expostas ao modelo (formato OpenAI/Groq).
 * $logado define quais ferramentas pessoais/de escrita ficam disponíveis.
 */
function _agentTools($logado) {
    $obj = new stdClass();
    $tools = [];
    $add = function ($name, $desc, $props = [], $req = []) use (&$tools, $obj) {
        $tools[] = ['type' => 'function', 'function' => [
            'name' => $name, 'description' => $desc,
            'parameters' => ['type' => 'object', 'properties' => ($props ?: $obj), 'required' => $req],
        ]];
    };

    // ---- Consulta (sempre disponíveis) — descrições curtas p/ economizar tokens ----
    $add('listar_servicos', 'Serviços com preço/duração.');
    $add('listar_planos', 'Planos de assinatura.');
    $add('listar_profissionais', 'Barbeiros ativos (opcional: filtra por servico_id).', [
        'servico_id' => ['type' => 'string'],
    ]);
    $add('horarios_livres', 'Horários livres de um profissional numa data. Use antes de agendar.', [
        'barbeiro' => ['type' => 'string', 'description' => 'ID ou nome.'],
        'data' => ['type' => 'string', 'description' => 'AAAA-MM-DD.'],
    ], ['barbeiro', 'data']);
    $add('info_barbearia', 'Endereço, telefone, WhatsApp, funcionamento e pagamento.');

    if ($logado) {
        // ---- Pessoais (leitura) ----
        $add('meus_dados', 'Nome, telefone e e-mail do cliente.');
        $add('meus_agendamentos', 'Agendamentos futuros (com IDs).');
        $add('meu_historico', 'Últimos atendimentos concluídos.');
        $add('meus_pontos', 'Pontos de fidelidade.');
        $add('minha_assinatura', 'Assinatura/plano do cliente.');
        $add('meu_codigo_indicacao', 'Código de indicação.');

        // ---- Ações (escrita) — chamar 1x sem confirmar (resumo), depois confirmar=true ----
        $add('criar_agendamento', 'Agenda. Chame sem confirmar p/ resumo; confirmar=true após o SIM.', [
            'servico' => ['type' => 'string'], 'barbeiro' => ['type' => 'string'],
            'data' => ['type' => 'string'], 'hora' => ['type' => 'string'],
            'confirmar' => ['type' => 'boolean'],
        ], ['servico', 'barbeiro', 'data', 'hora']);
        $add('cancelar_agendamento', 'Cancela. Confirme antes.', [
            'agendamento_id' => ['type' => 'string'], 'confirmar' => ['type' => 'boolean'],
        ], ['agendamento_id']);
        $add('reagendar_agendamento', 'Remarca. Confirme antes.', [
            'agendamento_id' => ['type' => 'string'],
            'nova_data' => ['type' => 'string'], 'novo_horario' => ['type' => 'string'],
            'confirmar' => ['type' => 'boolean'],
        ], ['agendamento_id', 'nova_data', 'novo_horario']);
        $add('atualizar_perfil', 'Atualiza nome/telefone. Confirme antes.', [
            'nome' => ['type' => 'string'], 'telefone' => ['type' => 'string'],
            'confirmar' => ['type' => 'boolean'],
        ]);
        // Sensíveis: NÃO executam pelo chat, apenas orientam.
        $add('orientar_alterar_senha', 'Como trocar a senha (feito na conta).');
        $add('orientar_excluir_conta', 'Como excluir a conta (feito na conta).');
        $add('orientar_avaliar', 'Como avaliar um atendimento.');
    } else {
        $add('preciso_login', 'Use se o visitante pedir algo pessoal (agendar, pontos, agendamentos).');
    }
    return $tools;
}

/** Resolve um serviço por ID ou nome. Retorna [id, dados] ou [null, null]. */
function _agentResolverServico($ref) {
    $servicos = lerDados('servicos', ['id', 'nome', 'valor', 'slots']);
    $ref = trim((string)$ref);
    if (isset($servicos[$ref])) return [$ref, $servicos[$ref]];
    $refN = _botNorm($ref);
    foreach ($servicos as $id => $s) {
        if (_botNorm($s['nome']) === $refN) return [$id, $s];
    }
    foreach ($servicos as $id => $s) {
        if ($refN !== '' && mb_strpos(_botNorm($s['nome']), $refN) !== false) return [$id, $s];
    }
    return [null, null];
}

/** Resolve um barbeiro por ID ou nome. Retorna [id, dados] ou [null, null]. */
function _agentResolverBarbeiro($ref) {
    $barbeiros = lerDados('barbeiros', ['id', 'nome', 'status', 'servicos_ids']);
    $ref = trim((string)$ref);
    if (isset($barbeiros[$ref])) return [$ref, $barbeiros[$ref]];
    $refN = _botNorm($ref);
    foreach ($barbeiros as $id => $b) {
        if (_botNorm($b['nome']) === $refN) return [$id, $b];
    }
    foreach ($barbeiros as $id => $b) {
        if ($refN !== '' && mb_strpos(_botNorm($b['nome']), $refN) !== false) return [$id, $b];
    }
    return [null, null];
}

/** Verifica se um agendamento pertence ao cliente da sessão (anti-IDOR). */
function _agentPossuiAgendamento($ag, $cli) {
    if (!$ag) return false;
    if (!empty($ag['cliente_id'])) return $ag['cliente_id'] === ($cli['id'] ?? '');
    $telAg = limparTelefone((string)($ag['telefone'] ?? ''));
    $telCli = limparTelefone((string)($cli['telefone'] ?? ''));
    return $telAg !== '' && $telAg === $telCli;
}

/**
 * Executa uma ferramenta chamada pelo modelo. Retorna um array (vira JSON no
 * papel 'tool'). $ctx traz 'cliente', 'config', 'nome_barbearia' e acumula
 * flags como 'did_write'.
 */
function _agentExec($name, $args, &$ctx) {
    $cli = $ctx['cliente'] ?? null;
    $cfg = $ctx['config'] ?? [];
    if (!is_array($args)) $args = [];

    switch ($name) {
        case 'listar_servicos': {
            $servicos = lerDados('servicos', ['id', 'nome', 'valor', 'slots']);
            $out = [];
            foreach ($servicos as $id => $s) {
                $out[] = ['id' => $id, 'nome' => $s['nome'],
                    'preco' => 'R$ ' . number_format((float)$s['valor'], 2, ',', '.'),
                    'duracao_min' => max(1, (int)($s['slots'] ?? 1)) * 30];
            }
            return $out ? ['servicos' => $out] : ['aviso' => 'Nenhum serviço cadastrado.'];
        }
        case 'listar_planos': {
            $planos = lerDados('planos', ['id', 'nome', 'valor']);
            $out = [];
            foreach ($planos as $id => $p) $out[] = ['id' => $id, 'nome' => $p['nome'], 'preco_mensal' => 'R$ ' . number_format((float)$p['valor'], 2, ',', '.')];
            return $out ? ['planos' => $out] : ['aviso' => 'Nenhum plano de assinatura ativo.'];
        }
        case 'listar_profissionais': {
            $barbeiros = lerDados('barbeiros', ['id', 'nome', 'status', 'servicos_ids']);
            $sid = trim((string)($args['servico_id'] ?? ''));
            if ($sid !== '') { [$sid2] = _agentResolverServico($sid); if ($sid2) $sid = $sid2; }
            $out = [];
            foreach ($barbeiros as $id => $b) {
                if (($b['status'] ?? 'ativo') === 'inativo') continue;
                if ($sid !== '') {
                    $esp = array_filter(array_map('trim', explode(',', (string)($b['servicos_ids'] ?? ''))));
                    if (!in_array($sid, $esp, true)) continue;
                }
                $out[] = ['id' => $id, 'nome' => $b['nome']];
            }
            return $out ? ['profissionais' => $out] : ['aviso' => 'Nenhum profissional disponível para esse critério.'];
        }
        case 'horarios_livres': {
            [$bid, $b] = _agentResolverBarbeiro($args['barbeiro'] ?? '');
            if (!$bid) return ['erro' => 'Profissional não encontrado. Use listar_profissionais.'];
            $data = _botParseData((string)($args['data'] ?? ''));
            if ($data === '') return ['erro' => 'Data inválida. Use o formato AAAA-MM-DD.'];
            $exp = getHorarioDeTrabalho($bid, (int)date('w', strtotime($data)), $data);
            if (!$exp) return ['barbeiro' => $b['nome'], 'data' => $data, 'livres' => [], 'aviso' => 'O profissional não atende nesse dia.'];
            $ocupados = getHorariosOcupados($bid, $data);
            $livres = [];
            for ($c = strtotime($exp['inicio']); $c < strtotime($exp['fim']); $c = strtotime('+30 minutes', $c)) {
                $h = date('H:i', $c);
                if ($data === date('Y-m-d') && $h <= date('H:i')) continue;
                if (!in_array($h, $ocupados, true)) $livres[] = $h;
            }
            return ['barbeiro' => $b['nome'], 'barbeiro_id' => $bid, 'data' => $data,
                'livres' => array_slice($livres, 0, 30),
                'aviso' => empty($livres) ? 'Sem horários livres nesse dia.' : null];
        }
        case 'info_barbearia': {
            $diasN = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
            $func = [];
            try {
                foreach (getDB()->query("SELECT dia, MIN(inicio) i, MAX(fim) f FROM horarios_trabalho GROUP BY dia") as $r) {
                    $func[$diasN[(int)$r['dia']]] = substr((string)$r['i'], 0, 5) . '-' . substr((string)$r['f'], 0, 5);
                }
            } catch (Exception $e) {}
            return [
                'endereco' => $cfg['endereco'] ?? 'não informado',
                'telefone' => $cfg['telefone_contato'] ?? 'não informado',
                'whatsapp' => !empty($cfg['link_whatsapp']) ? preg_replace('/\D/', '', $cfg['link_whatsapp']) : '',
                'funcionamento' => $func ?: 'consultar',
                'formas_pagamento' => 'Dinheiro, PIX e Cartão (débito/crédito)',
            ];
        }
        case 'meus_dados': {
            if (!$cli) return ['erro' => 'Cliente não está logado.'];
            return ['nome' => $cli['nome'] ?? '', 'telefone' => $cli['telefone'] ?? '', 'email' => $cli['email'] ?? ''];
        }
        case 'meus_agendamentos': {
            if (!$cli) return ['erro' => 'Cliente não está logado.'];
            $ags = _botAgendamentosCliente($cli, true);
            $out = [];
            foreach ($ags as $a) {
                $out[] = ['id' => $a['id'], 'data' => $a['data'], 'hora' => substr((string)$a['hora'], 0, 5),
                    'servico' => $a['servicos'], 'profissional' => $a['barbeiro'], 'status' => $a['status']];
            }
            return ['agendamentos' => $out, 'total' => count($out)];
        }
        case 'meu_historico': {
            if (!$cli) return ['erro' => 'Cliente não está logado.'];
            $servicos = lerDados('servicos', ['id', 'nome']);
            $combos = lerDados('combos', ['id', 'nome']);
            $barbeiros = lerDados('barbeiros', ['id', 'nome']);
            $ags = lerDados('agendamentos', ['id', 'telefone', 'barbeiro_id', 'servicos_ids', 'data', 'hora', 'status', 'cliente_id']);
            $tel = limparTelefone((string)($cli['telefone'] ?? ''));
            $out = [];
            foreach ($ags as $ag) {
                $meu = !empty($ag['cliente_id']) ? ($ag['cliente_id'] === $cli['id']) : ($tel !== '' && limparTelefone((string)($ag['telefone'] ?? '')) === $tel);
                if (!$meu || ($ag['status'] ?? '') !== 'concluido') continue;
                $nomes = [];
                foreach (explode(',', (string)($ag['servicos_ids'] ?? '')) as $sid) {
                    $sid = trim($sid);
                    if (isset($servicos[$sid])) $nomes[] = $servicos[$sid]['nome'];
                    elseif (isset($combos[$sid])) $nomes[] = $combos[$sid]['nome'];
                }
                $out[] = ['data' => $ag['data'], 'servico' => $nomes ? implode(', ', $nomes) : 'Serviço',
                    'profissional' => $barbeiros[$ag['barbeiro_id']]['nome'] ?? '—', 'ts' => strtotime(($ag['data'] ?? '') . ' ' . ($ag['hora'] ?? ''))];
            }
            usort($out, fn($a, $b) => $b['ts'] - $a['ts']);
            foreach ($out as &$o) unset($o['ts']);
            return ['historico' => array_slice($out, 0, 8), 'total_concluidos' => count($out)];
        }
        case 'meus_pontos': {
            if (!$cli) return ['erro' => 'Cliente não está logado.'];
            $r = function_exists('getFidelidadeRegras') ? getFidelidadeRegras() : ['ativado' => 0, 'pontos_necessarios' => 10];
            if (empty($r['ativado'])) return ['fidelidade_ativa' => false, 'aviso' => 'Programa de fidelidade não está ativo.'];
            $pt = (int) getClientFidelityPoints($cli['id']);
            $nec = max(1, (int)$r['pontos_necessarios']);
            return ['fidelidade_ativa' => true, 'pontos' => $pt, 'necessarios' => $nec, 'faltam' => max(0, $nec - $pt)];
        }
        case 'minha_assinatura': {
            if (!$cli) return ['erro' => 'Cliente não está logado.'];
            $ass = function_exists('getAssinaturaCliente') ? getAssinaturaCliente($cli['id']) : null;
            if (!$ass) return ['tem_assinatura' => false];
            $planos = lerDados('planos', ['id', 'nome']);
            return ['tem_assinatura' => true, 'plano' => $planos[$ass['plano_id']]['nome'] ?? 'Assinatura', 'valida_ate' => date('d/m/Y', strtotime($ass['data_fim']))];
        }
        case 'meu_codigo_indicacao': {
            if (!$cli) return ['erro' => 'Cliente não está logado.'];
            $full = function_exists('getClientById') ? getClientById($cli['id']) : [];
            $cod = $full['codigo_indicacao'] ?? '';
            return $cod ? ['codigo' => $cod] : ['aviso' => 'Sem código de indicação cadastrado.'];
        }

        // ---------- ESCRITA ----------
        case 'criar_agendamento': {
            if (!$cli) return ['erro' => 'Precisa estar logado para agendar.'];
            [$sid, $serv] = _agentResolverServico($args['servico'] ?? '');
            [$bid, $barb] = _agentResolverBarbeiro($args['barbeiro'] ?? '');
            if (!$sid) return ['erro' => 'Serviço não encontrado. Use listar_servicos.'];
            if (!$bid) return ['erro' => 'Profissional não encontrado. Use listar_profissionais.'];
            $data = _botParseData((string)($args['data'] ?? ''));
            $hora = trim((string)($args['hora'] ?? ''));
            if ($data === '' || !preg_match('/^\d{1,2}:\d{2}$/', $hora)) return ['erro' => 'Data ou hora inválida (use AAAA-MM-DD e HH:MM).'];
            $hora = sprintf('%02d:%02d', (int)substr($hora, 0, strpos($hora, ':')), (int)substr($hora, strpos($hora, ':') + 1));
            // Assinante: o preço do resumo precisa ser o que ele realmente paga
            // (zerado quando o plano cobre o serviço), senão o assistente confirma
            // um valor que o agendamento não vai cobrar.
            $benef = function_exists('calcularDescontoAssinaturaCliente')
                ? calcularDescontoAssinaturaCliente($cli['id'], $sid)
                : ['desconto' => 0.0, 'plano_nome' => '', 'a_pagar' => (float)$serv['valor']];
            $coberto = !empty($benef['desconto']) && $benef['desconto'] > 0;
            $aPagar = $coberto ? (float)$benef['a_pagar'] : (float)$serv['valor'];
            $resumo = ['servico' => $serv['nome'], 'profissional' => $barb['nome'],
                'data' => date('d/m/Y', strtotime($data)), 'hora' => $hora,
                'preco' => 'R$ ' . number_format($aPagar, 2, ',', '.')];
            if ($coberto) {
                $resumo['preco_tabela'] = 'R$ ' . number_format((float)$serv['valor'], 2, ',', '.');
                $resumo['incluso_na_assinatura'] = true;
                $resumo['plano'] = (string)($benef['plano_nome'] ?? 'sua assinatura');
            }
            if (empty($args['confirmar'])) {
                return ['status' => 'precisa_confirmacao', 'resumo' => $resumo,
                    'instrucao' => 'Mostre este resumo e peça a confirmação do cliente. Só chame de novo com confirmar=true após o "sim".'];
            }
            $tel = limparTelefone((string)($cli['telefone'] ?? ''));
            if (strlen($tel) < 10) return ['status' => 'precisa_telefone', 'instrucao' => 'A conta não tem telefone válido. Peça o telefone com DDD e oriente o cliente a atualizar o perfil (atualizar_perfil) antes de agendar.'];
            $payload = ['servico_id' => $sid, 'barbeiro_id' => $bid, 'servico_slots' => max(1, (int)($serv['slots'] ?? 1)),
                'data' => $data, 'hora' => $hora, 'cliente_nome' => $cli['nome'], 'cliente_telefone' => $tel];
            $res = _botCriarAgendamento($payload);
            if (!empty($res['ok'])) {
                $ctx['did_write'] = true;
                return ['status' => 'agendado', 'id' => $res['id'], 'resumo' => $resumo];
            }
            return ['status' => 'falha', 'erro' => $res['erro'] ?? 'Não foi possível agendar.'];
        }
        case 'cancelar_agendamento': {
            if (!$cli) return ['erro' => 'Precisa estar logado.'];
            $id = trim((string)($args['agendamento_id'] ?? ''));
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ?");
            $stmt->execute([$id]);
            $ag = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!_agentPossuiAgendamento($ag, $cli)) return ['erro' => 'Agendamento não encontrado ou não pertence a você.'];
            if (in_array($ag['status'] ?? '', ['cancelado_pelo_cliente', 'cancelado', 'concluido'], true)) return ['aviso' => 'Esse agendamento não está mais ativo.'];
            $resumo = ['data' => date('d/m/Y', strtotime($ag['data'])), 'hora' => substr((string)$ag['hora'], 0, 5)];
            if (empty($args['confirmar'])) return ['status' => 'precisa_confirmacao', 'resumo' => $resumo, 'instrucao' => 'Confirme o cancelamento com o cliente antes de chamar com confirmar=true.'];
            $up = $pdo->prepare("UPDATE agendamentos SET status = 'cancelado_pelo_cliente' WHERE id = ?");
            $up->execute([$id]);
            if (function_exists('registrarHistoricoAgenda')) registrarHistoricoAgenda($id, 'Agendamento cancelado', 'Cancelado pelo cliente via assistente', ($cli['nome'] ?? 'Cliente') . ' (assistente)');
            if (function_exists('criarNotificacao')) criarNotificacao($cli['id'], "Você cancelou seu agendamento de " . date('d/m/Y', strtotime($ag['data'])) . " às " . substr((string)$ag['hora'], 0, 5) . ".");
            $ctx['did_write'] = true;
            return ['status' => 'cancelado', 'resumo' => $resumo];
        }
        case 'reagendar_agendamento': {
            if (!$cli) return ['erro' => 'Precisa estar logado.'];
            $id = trim((string)($args['agendamento_id'] ?? ''));
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ?");
            $stmt->execute([$id]);
            $ag = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!_agentPossuiAgendamento($ag, $cli)) return ['erro' => 'Agendamento não encontrado ou não pertence a você.'];
            $novaData = _botParseData((string)($args['nova_data'] ?? ''));
            $novoHora = trim((string)($args['novo_horario'] ?? ''));
            if ($novaData === '' || !preg_match('/^\d{1,2}:\d{2}$/', $novoHora)) return ['erro' => 'Nova data/hora inválida.'];
            $novoHora = sprintf('%02d:%02d', (int)substr($novoHora, 0, strpos($novoHora, ':')), (int)substr($novoHora, strpos($novoHora, ':') + 1));
            if (strtotime("$novaData $novoHora") < time()) return ['erro' => 'Esse horário já passou.'];
            $bid = (string)$ag['barbeiro_id'];
            $exp = getHorarioDeTrabalho($bid, (int)date('w', strtotime($novaData)), $novaData);
            if (!$exp) return ['erro' => 'O profissional não atende nesse dia.'];
            $ocupados = getHorariosOcupados($bid, $novaData);
            if (in_array($novoHora, $ocupados, true)) return ['erro' => 'Esse horário está ocupado. Consulte horarios_livres.'];
            $resumo = ['de' => date('d/m/Y', strtotime($ag['data'])) . ' ' . substr((string)$ag['hora'], 0, 5),
                'para' => date('d/m/Y', strtotime($novaData)) . ' ' . $novoHora];
            if (empty($args['confirmar'])) return ['status' => 'precisa_confirmacao', 'resumo' => $resumo, 'instrucao' => 'Confirme a remarcação antes de chamar com confirmar=true.'];
            $up = $pdo->prepare("UPDATE agendamentos SET data = ?, hora = ?, status = 'aprovado' WHERE id = ?");
            $up->execute([$novaData, $novoHora, $id]);
            if (function_exists('registrarHistoricoAgenda')) registrarHistoricoAgenda($id, 'Agendamento reagendado', 'Novo horário: ' . date('d/m/Y', strtotime($novaData)) . ' às ' . $novoHora . ' (assistente)', ($cli['nome'] ?? 'Cliente') . ' (assistente)');
            if (function_exists('criarNotificacao')) criarNotificacao($cli['id'], "Agendamento reagendado para " . date('d/m/Y', strtotime($novaData)) . " às $novoHora.");
            $ctx['did_write'] = true;
            return ['status' => 'reagendado', 'resumo' => $resumo];
        }
        case 'atualizar_perfil': {
            if (!$cli) return ['erro' => 'Precisa estar logado.'];
            $novoNome = isset($args['nome']) ? trim(preg_replace('/\s+/', ' ', (string)$args['nome'])) : '';
            $novoTel = isset($args['telefone']) ? limparTelefone((string)$args['telefone']) : '';
            if ($novoNome === '' && $novoTel === '') return ['erro' => 'Informe nome e/ou telefone para atualizar.'];
            if ($novoNome !== '' && (mb_strlen($novoNome) < 3 || mb_strpos($novoNome, ' ') === false)) return ['erro' => 'Nome deve conter nome e sobrenome.'];
            if ($novoTel !== '' && strlen($novoTel) < 10) return ['erro' => 'Telefone incompleto (use DDD).'];
            $resumo = array_filter(['nome' => $novoNome ?: null, 'telefone' => $novoTel ?: null]);
            if (empty($args['confirmar'])) return ['status' => 'precisa_confirmacao', 'resumo' => $resumo, 'instrucao' => 'Confirme a alteração com o cliente antes de chamar com confirmar=true.'];
            $pdo = getDB();
            $atual = getClientById($cli['id']);
            $nomeFinal = $novoNome !== '' ? htmlspecialchars($novoNome) : $atual['nome'];
            $telFinal = $novoTel !== '' ? $novoTel : $atual['telefone'];
            $up = $pdo->prepare("UPDATE clientes SET nome = ?, telefone = ? WHERE id = ?");
            $up->execute([$nomeFinal, $telFinal, $cli['id']]);
            $pdo->prepare("UPDATE agendamentos SET nome = ?, telefone = ? WHERE cliente_id = ?")->execute([$nomeFinal, $telFinal, $cli['id']]);
            $_SESSION['cliente_nome'] = $nomeFinal;
            $_SESSION['cliente_telefone'] = $telFinal;
            $ctx['cliente']['nome'] = $nomeFinal;
            $ctx['cliente']['telefone'] = $telFinal;
            $ctx['did_write'] = true;
            return ['status' => 'atualizado', 'resumo' => ['nome' => $nomeFinal, 'telefone' => $telFinal]];
        }

        // ---------- Sensíveis: orientar, não executar ----------
        case 'orientar_alterar_senha':
            return ['instrucao_ao_cliente' => 'Por segurança, a troca de senha é feita na área da conta, em Meus Dados. Oriente o cliente a acessar "Minha Conta" e usar a opção de alterar senha.', 'link' => 'cliente'];
        case 'orientar_excluir_conta':
            return ['instrucao_ao_cliente' => 'A exclusão de conta é permanente e feita apenas na área da conta (Meus Dados), com senha e confirmação. Oriente o cliente a acessar "Minha Conta".', 'link' => 'cliente'];
        case 'orientar_avaliar':
            return ['instrucao_ao_cliente' => 'As avaliações ficam na área da conta, na aba Avaliações, para atendimentos já concluídos. Oriente o cliente a acessar "Minha Conta".', 'link' => 'cliente'];
        case 'preciso_login':
            return ['status' => 'login_necessario', 'instrucao' => 'Explique gentilmente que é preciso entrar na conta e ofereça a opção de login.'];
    }
    return ['erro' => 'Ferramenta desconhecida.'];
}

/**
 * Loop do agente: chama o Groq com ferramentas e executa os tool_calls até o
 * modelo dar a resposta final. Retorna ['success','reply','did_write','login_necessario'].
 */
function agentResponder($messages, $ctx) {
    if (!function_exists('_iaColetarChaves')) return ['success' => false];
    // Provedores em ordem de preferência: Groq (primário) e Gemini (fallback
    // grátis). O rodízio round-robin espalha a carga entre as chaves de cada um;
    // a ordem é calculada UMA vez e reutilizada nas rodadas de ferramenta desta
    // conversa. Se o Groq limitar (429/TPM), a mesma chamada segue no Gemini.
    $provedores = _agentProvedores();
    if (empty($provedores)) return ['success' => false];
    $tools = _agentTools(!empty($ctx['cliente']));

    $loginNecessario = false;
    $didWrite = false; // se já houve escrita, não podemos deixar a resposta "sumir"
    for ($iter = 0; $iter < 5; $iter++) {
        $r = _agentGroqCall($messages, $tools, $provedores);
        if (!empty($r['rate'])) return _agentRespRate($ctx, $didWrite || !empty($ctx['did_write']));
        if (empty($r['message'])) return ['success' => false];
        $msg = $r['message'];

        $toolCalls = $msg['tool_calls'] ?? [];
        if (empty($toolCalls)) {
            $texto = trim((string)($msg['content'] ?? ''));
            if ($texto === '') $texto = 'Certo! 😊';
            return ['success' => true, 'reply' => $texto, 'did_write' => !empty($ctx['did_write']), 'login_necessario' => $loginNecessario];
        }

        // Anexa a mensagem do assistente (com os tool_calls) e executa cada uma.
        $messages[] = ['role' => 'assistant', 'content' => $msg['content'] ?? null, 'tool_calls' => $toolCalls];
        foreach ($toolCalls as $tc) {
            $fn = $tc['function']['name'] ?? '';
            $args = json_decode((string)($tc['function']['arguments'] ?? '{}'), true);
            $resultado = _agentExec($fn, is_array($args) ? $args : [], $ctx);
            if (($resultado['status'] ?? '') === 'login_necessario') $loginNecessario = true;
            $messages[] = ['role' => 'tool', 'tool_call_id' => $tc['id'] ?? '', 'name' => $fn,
                'content' => json_encode($resultado, JSON_UNESCAPED_UNICODE)];
        }
        $didWrite = $didWrite || !empty($ctx['did_write']);
    }
    // Excedeu iterações: força uma resposta em texto (mantém o schema, tool_choice=none).
    $r = _agentGroqCall($messages, $tools, $provedores, 'none');
    if (!empty($r['rate'])) return _agentRespRate($ctx, $didWrite || !empty($ctx['did_write']));
    if (!empty($r['message']) && trim((string)($r['message']['content'] ?? '')) !== '') {
        return ['success' => true, 'reply' => trim($r['message']['content']), 'did_write' => !empty($ctx['did_write']), 'login_necessario' => $loginNecessario];
    }
    return ['success' => false];
}

/** Resposta amigável quando o Groq limita a taxa (free tier: 8000 tokens/min). */
function _agentRespRate($ctx, $didWrite) {
    if ($didWrite) {
        return ['success' => true, 'did_write' => true, 'login_necessario' => false,
            'reply' => 'Pronto, sua solicitação foi registrada! ✅ (No momento estou com muitas conversas — se precisar de mais detalhes, me chame em instantes.)'];
    }
    return ['success' => true, 'did_write' => false, 'login_necessario' => false,
        'reply' => 'Estou recebendo muitas mensagens agora e preciso de alguns segundos. 🙏 Tente de novo em instantes, por favor.'];
}

/**
 * Provedores do agente em ordem de preferência: Groq (primário) e Gemini
 * (fallback grátis, API compatível com OpenAI). Cada item traz url/keys/modelo.
 * As chaves entram em rodízio round-robin para espalhar a carga.
 */
function _agentProvedores() {
    $cfg = function_exists('carregarConfigChatbot') ? carregarConfigChatbot() : [];
    $out = [];
    foreach (['groq', 'gemini'] as $prov) {
        $keys = _iaColetarChaves($prov, true);
        if (!empty($keys)) {
            $out[] = ['url' => _iaEndpoint($prov), 'keys' => $keys, 'modelo' => _iaModelo($prov, $cfg)];
        }
    }
    return $out;
}

/**
 * Uma chamada de chat/completions com suporte a ferramentas, tentando os
 * provedores em ordem (Groq → Cerebras) e, dentro de cada um, o rodízio de
 * chaves. Retorna ['message'=>array] em sucesso, ou ['rate'=>true] (todos os
 * provedores limitaram a taxa) / ['erro'=>true].
 */
function _agentGroqCall($messages, $tools, $provedores, $toolChoice = 'auto') {
    $rate = false;
    foreach ($provedores as $prov) {
        $payload = [
            'model' => $prov['modelo'] ?: 'openai/gpt-oss-20b',
            'messages' => array_values($messages),
            'temperature' => 0.3,
            'max_tokens' => 600,
        ];
        if (!empty($tools)) { $payload['tools'] = $tools; $payload['tool_choice'] = $toolChoice; }
        foreach ($prov['keys'] as $chave) {
            $ch = curl_init($prov['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . trim($chave)],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 30,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp === false) continue;
            if ($code === 200) {
                $j = json_decode($resp, true);
                $m = $j['choices'][0]['message'] ?? null;
                if (is_array($m)) return ['message' => $m];
                continue; // resposta 200 sem message: tenta próxima chave/provedor
            }
            if ($code === 429) { $rate = true; continue; } // limite: tenta próxima chave, depois o fallback
            // 401/403/5xx → tenta a próxima chave/provedor.
        }
    }
    return $rate ? ['rate' => true] : ['erro' => true];
}
