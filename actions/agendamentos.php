<?php
// --- AÇÃO: ENVIAR LEMBRETES DE AMANHÃ ---
// Idempotente (não reenvia duplicado), multicanal (e-mail + notificação no app)
// e com relatório detalhado. Lógica central em enviarLembretesAgendamentos().
if ($action === 'enviar_lembretes_amanha') {
    $r = enviarLembretesAgendamentos(null, 'manual');

    if ($r['total'] === 0) {
        header('Location: admin.php?tab=agendamentos&success=' . urlencode('Nenhum agendamento novo para lembrar amanhã (os já lembrados não recebem de novo).'));
        exit;
    }

    $partes = [];
    if ($r['email'] > 0)       $partes[] = "{$r['email']} por e-mail";
    if ($r['app'] > 0)         $partes[] = "{$r['app']} no app";
    if ($r['sem_contato'] > 0) $partes[] = "{$r['sem_contato']} sem e-mail/conta (não avisados)";
    if ($r['falhas'] > 0)      $partes[] = "{$r['falhas']} falha(s) de envio";
    $resumo = $partes ? implode(', ', $partes) . '.' : 'Nada a enviar.';
    header('Location: admin.php?tab=agendamentos&success=' . urlencode("Lembretes de amanhã: $resumo"));
    exit;
}

// --- AÇÃO: SALVAR CONFIGURAÇÃO DE LEMBRETES (véspera + horas antes) ---
if ($action === 'salvar_config_lembretes') {
    $cfg = [
        'dia_antes_ativo'  => (($_POST['lb_dia_ativo'] ?? '1') === '1') ? 1 : 0,
        'dia_hora_envio'   => max(0, min(23, (int)($_POST['lb_dia_hora'] ?? 9))),
        'hora_antes_ativo' => (($_POST['lb_hora_ativo'] ?? '1') === '1') ? 1 : 0,
        'horas_antes'      => max(1, min(24, (int)($_POST['lb_horas_antes'] ?? 2))),
    ];
    _salvarConfigSQLite('config_lembretes', $cfg);
    header('Location: admin.php?tab=agendamentos&success=' . urlencode('Configuração de lembretes automáticos salva!'));
    exit;
}

// --- AÇÃO: ADICIONAR PRODUTO AO AGENDAMENTO (COM BAIXA DE ESTOQUE E LOG KARDEX) ---
if ($action === 'adicionar_produto') {
    $agendamento_id = $_POST['agendamento_id'];
    $produto_id = $_POST['produto_id'];
    $qtd_vendida = (int)($_POST['qtd_vendida'] ?? 1);

    if (!empty($agendamento_id) && !empty($produto_id) && $qtd_vendida > 0) {
        $pdo = getDB();
        
        $stmt = $pdo->prepare("SELECT * FROM produtos WHERE id = ?");
        $stmt->execute([$produto_id]);
        $produto = $stmt->fetch();

        if ($produto && $produto['quantidade'] >= $qtd_vendida) {
            $nome_produto = $produto['nome'] . " (" . $qtd_vendida . "x)";
            $valor_total = (float)$produto['valor'] * $qtd_vendida;

            $stmtAg = $pdo->prepare("SELECT produtos_vendidos FROM agendamentos WHERE id = ?");
            $stmtAg->execute([$agendamento_id]);
            $agRow = $stmtAg->fetch();
            
            if ($agRow) {
                $produtos_atuais = !empty($agRow['produtos_vendidos']) ? json_decode($agRow['produtos_vendidos'], true) : [];
                if (!is_array($produtos_atuais)) {
                    $produtos_atuais = [];
                }
                
                $produtos_atuais[] = [
                    'nome' => $nome_produto,
                    'valor' => $valor_total,
                    'custo' => (float)($produto['custo'] ?? 0) * $qtd_vendida,
                    'produto_id' => $produto_id
                ];
                
                $json_produtos = json_encode($produtos_atuais);
                
                $stmtUpAg = $pdo->prepare("UPDATE agendamentos SET produtos_vendidos = ? WHERE id = ?");
                $stmtUpAg->execute([$json_produtos, $agendamento_id]);
                
                $nova_qtd = $produto['quantidade'] - $qtd_vendida;
                $stmtUpProd = $pdo->prepare("UPDATE produtos SET quantidade = ? WHERE id = ?");
                $stmtUpProd->execute([$nova_qtd, $produto_id]);

                $log_id = gerarId('log-');
                $motivo = "Venda no Agendamento " . htmlspecialchars($agendamento_id);
                $data_hora = date('Y-m-d H:i:s');
                $usuario = $_SESSION['username'] ?? 'Admin';
                
                $pdo->exec("CREATE TABLE IF NOT EXISTS estoque_logs (id TEXT PRIMARY KEY, produto_id TEXT, tipo TEXT, quantidade INTEGER, motivo TEXT, data_hora TEXT, usuario TEXT)");
                
                $stmtLog = $pdo->prepare("INSERT INTO estoque_logs (id, produto_id, tipo, quantidade, motivo, data_hora, usuario) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmtLog->execute([$log_id, $produto_id, 'saida', $qtd_vendida, $motivo, $data_hora, $usuario]);

                header('Location: admin.php?tab=agendamentos&success=' . urlencode('Produto adicionado ao agendamento e estoque atualizado com sucesso!'));
                exit;
            }
        } else {
            header('Location: admin.php?tab=agendamentos&error=' . urlencode('Estoque insuficiente para a quantidade solicitada.'));
            exit;
        }
    }
    header('Location: admin.php?tab=agendamentos&error=' . urlencode('Dados inválidos para venda de produto.'));
    exit;
}

// --- AÇÃO: LIMPAR AGENDAMENTOS ANTIGOS ---
if ($action === 'limpar_agendamentos_antigos') { 
    $pdo = getDB();
    $umAnoAtras = date('Y-m-d', strtotime('-1 year'));
    
    try {
        $stmt = $pdo->prepare("DELETE FROM agendamentos WHERE (status = 'concluido' OR status = 'cancelado' OR status = 'cancelado_pelo_cliente') AND data < ?");
        $stmt->execute([$umAnoAtras]);
    } catch (PDOException $e) {
        log_activity("Erro ao limpar agendamentos antigos: " . $e->getMessage());
    }
    
    header('Location: admin.php?tab=configuracoes&subtab=sistema&success=' . urlencode('Agendamentos antigos limpos com sucesso!')); 
    exit; 
}

// --- AÇÃO: STATUS AGENDAMENTO (CANCELAR/CONCLUIR) ---
if (($action === 'cancelar' || $action === 'concluir') && !empty($id)) {
    
    $pdo = getDB();
    $novoStatus = '';
    if ($action === 'cancelar') $novoStatus = 'cancelado';
    if ($action === 'concluir') $novoStatus = 'concluido';

    if (!empty($novoStatus)) {
        $stmtGet = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ?");
        $stmtGet->execute([$id]);
        $agendamento = $stmtGet->fetch();
        
        if ($agendamento) {
            $status_anterior = $agendamento['status'];

            $stmtUp = $pdo->prepare("UPDATE agendamentos SET status = ? WHERE id = ?");
            $stmtUp->execute([$novoStatus, $id]);
            if (function_exists('registrarHistoricoAgenda')) {
                registrarHistoricoAgenda($id, 'Status atualizado', 'Novo status: ' . $novoStatus);
            }

            // Ao concluir direto (sem passar pela comanda), grava o benefício da
            // assinatura para que serviços cobertos fiquem zerados nos relatórios.
            if ($novoStatus === 'concluido' && $status_anterior !== 'concluido') {
                $assinaturaConclusao = calcularDescontoAssinaturaCliente($agendamento['cliente_id'] ?? '', (string)($agendamento['servicos_ids'] ?? ''));
                $descontoGravado = max(0, (float)($agendamento['desconto_aplicado'] ?? 0));
                if ((float)$assinaturaConclusao['desconto'] > $descontoGravado) {
                    $pdo->prepare("UPDATE agendamentos SET desconto_aplicado = ?, tipo_desconto = ? WHERE id = ?")
                        ->execute([(float)$assinaturaConclusao['desconto'], 'assinatura_vip', $id]);
                    $agendamento['desconto_aplicado'] = (float)$assinaturaConclusao['desconto'];
                    $agendamento['tipo_desconto'] = 'assinatura_vip';
                }
            }

            $keys_agendamentos_completo = ['id', 'nome', 'email', 'telefone', 'barbeiro_id', 'servicos_ids', 'data', 'hora', 'status', 'desconto_aplicado', 'tipo_desconto', 'observacoes', 'produtos_vendidos', 'plano_provisorio', 'cliente_id'];

            $clientesArr = lerDados('clientes', ['id', 'nome', 'email', 'telefone', 'indicado_por_id']);
            $barbeirosArr = lerDados('barbeiros', ['id', 'nome']);
            $servicosArr = lerDados('servicos', ['id', 'nome']);
            $combosArr = lerDados('combos', ['id', 'nome']);
            $agendamentosTotais = lerDados('agendamentos', $keys_agendamentos_completo);

            $cliente = null;
            if (!empty($agendamento['cliente_id']) && isset($clientesArr[$agendamento['cliente_id']])) {
                $cliente = $clientesArr[$agendamento['cliente_id']];
            } else {
                $cliente = getClientePorEmailTelefoneOuCPF($agendamento['telefone']);
                if (!$cliente) { $cliente = getClientePorEmailTelefoneOuCPF($agendamento['email']); }
            }

            if ($novoStatus === 'cancelado' && filter_var($agendamento['email'], FILTER_VALIDATE_EMAIL)) {
                $nomes_servicos = array_map(function($sid) use ($servicosArr, $combosArr) { 
                    $sid = trim($sid);
                    if (isset($servicosArr[$sid])) return $servicosArr[$sid]['nome'];
                    if (isset($combosArr[$sid])) return $combosArr[$sid]['nome'] . " (Combo)";
                    return 'Item (removido)';
                }, explode(',', $agendamento['servicos_ids']));

                $dados_email = [
                    'nome_cliente' => $agendamento['nome'],
                    'data_agendamento' => date('d/m/Y', strtotime($agendamento['data'])),
                    'hora_agendamento' => $agendamento['hora'],
                    'servicos' => $nomes_servicos,
                    'barbeiro' => $barbeirosArr[$agendamento['barbeiro_id']]['nome'] ?? 'Não especificado',
                    'tipo_desconto' => $agendamento['tipo_desconto'] ?? ''
                ];
                enviarEmail($agendamento['email'], 'Informações sobre seu Agendamento', 'cancelado', $dados_email);
            }

            // --- NOVO: DISPARO DE E-MAIL DE AVALIAÇÃO (CONCLUIR) ---
            if ($novoStatus === 'concluido' && $status_anterior !== 'concluido') {
                if (!empty($agendamento['email']) && filter_var($agendamento['email'], FILTER_VALIDATE_EMAIL)) {
                    $barbeiroNomeAval = $barbeirosArr[$agendamento['barbeiro_id']]['nome'] ?? 'nosso time';
                    $dados_email_aval = [
                        'nome_cliente' => $agendamento['nome'],
                        'data_agendamento' => date('d/m/Y', strtotime($agendamento['data'])),
                        'barbeiro' => $barbeiroNomeAval,
                        'link_avaliacao' => (function_exists('avaliacaoLink') && $id) ? avaliacaoLink($id) : (defined('BASE_URL') ? BASE_URL . 'cliente.php' : 'cliente.php')
                    ];
                    enviarEmail($agendamento['email'], 'Como foi sua experiência? Deixe sua avaliação!', 'lembrete_avaliacao', $dados_email_aval);
                }
            }
            
            if ($cliente) { 
                $cliente_id_notif = $cliente['id'];
                $data_formatada = date('d/m/Y', strtotime($agendamento['data']));
                if ($novoStatus === 'cancelado') { criarNotificacao($cliente_id_notif, "Seu agendamento para {$data_formatada} às {$agendamento['hora']} foi CANCELADO pelo estabelecimento."); } 
                else if ($novoStatus === 'concluido') { criarNotificacao($cliente_id_notif, "Obrigado pela sua visita! Seu atendimento em {$data_formatada} foi concluído. Não se esqueça de avaliar!"); } 
            }

            if ($novoStatus === 'concluido' && $status_anterior !== 'concluido' && $cliente) { 
                $configFidelidade = getFidelityConfig();
                if (($configFidelidade['ativado'] ?? 0)) {
                    $assinatura = getAssinaturaCliente($cliente['id']);
                    $tem_assinatura = ($assinatura && $assinatura['status'] === 'ativo');
                    if (!$tem_assinatura) {
                        $ganho = fidelidadePontosPorAtendimento($pdo, $agendamento);
                        if ($ganho > 0) {
                            $pontos_atuais = getClientFidelityPoints($cliente['id']);
                            updateClientFidelityPoints($cliente['id'], $pontos_atuais + $ganho, "Agendamento concluído");
                        }
                    }
                }
            }

            if ($novoStatus === 'concluido' && $status_anterior !== 'concluido' && $cliente && !empty($cliente['indicado_por_id'])) {
                $concluidosCount = 0;
                foreach ($agendamentosTotais as $ag_check) {
                    $ag_cliente_id = $ag_check['cliente_id'] ?? '';
                    if (!empty($ag_cliente_id) && $ag_cliente_id === $cliente['id']) {
                        if (($ag_check['status'] ?? '') === 'concluido') {
                            $concluidosCount++;
                        }
                    } elseif (empty($ag_cliente_id)) {
                        $emailCheck = $ag_check['email'] ?? '';
                        $telCheck = limparTelefone($ag_check['telefone'] ?? '');
                        $emailCli = $cliente['email'] ?? '';
                        $telCli = limparTelefone($cliente['telefone'] ?? '');
                        if (($emailCheck === $emailCli || (!empty($telCli) && $telCheck === $telCli))) {
                            if (($ag_check['status'] ?? '') === 'concluido') {
                                $concluidosCount++;
                            }
                        }
                    }
                }

                if ($concluidosCount === 1) { 
                    processarRecompensaIndicacao($cliente['indicado_por_id'], $cliente['nome']);
                }
            }
        }
    }
    header('Location: admin.php?tab=agendamentos&success=' . urlencode('Status do agendamento atualizado com sucesso!'));
    exit;
}

// --- AÇÃO: FECHAR COMANDA (ADMIN) ---
if ($action === 'fechar_comanda') {
    $agendamento_id  = $_POST['agendamento_id'] ?? '';
    $servicos_extras = array_values(array_unique(array_filter(array_map('trim', (array)($_POST['servicos_extras'] ?? [])))));
    $gorjeta         = max(0, (float)str_replace(',', '.', (string)($_POST['gorjeta'] ?? 0)));
    $forma_pagamento = (string)($_POST['forma_pagamento'] ?? '');
    if (!in_array($forma_pagamento, ['dinheiro', 'pix', 'debito', 'credito', 'outro', ''], true)) {
        $forma_pagamento = '';
    }

    $pdo = getDB();
    if (function_exists('garantirColunasComanda')) garantirColunasComanda();

    $stmtGet = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ?");
    $stmtGet->execute([$agendamento_id]);
    $agendamento = $stmtGet->fetch(PDO::FETCH_ASSOC);

    if (!$agendamento) {
        header('Location: admin.php?tab=agendamentos&error=' . urlencode('Atendimento não encontrado.'));
        exit;
    }

    // Valida serviços extras: precisam existir (e ser do repertório do barbeiro, se houver).
    if (!empty($servicos_extras)) {
        $servicosArr = lerDados('servicos', ['id', 'nome']);
        $espec = [];
        if (!empty($agendamento['barbeiro_id'])) {
            $stmtB = $pdo->prepare("SELECT servicos_ids FROM barbeiros WHERE id = ?");
            $stmtB->execute([$agendamento['barbeiro_id']]);
            $espec = array_filter(array_map('trim', explode(',', (string)($stmtB->fetchColumn() ?: ''))));
        }
        $servicos_extras = array_values(array_filter($servicos_extras, function ($sid) use ($servicosArr, $espec) {
            return isset($servicosArr[$sid]) && (empty($espec) || in_array($sid, $espec, true));
        }));
    }

    $idsAtuais = array_filter(array_map('trim', explode(',', (string)($agendamento['servicos_ids'] ?? ''))));
    $servicos_ids_final = implode(',', array_values(array_unique(array_merge($idsAtuais, $servicos_extras))));
    $status_anterior = $agendamento['status'] ?? '';

    // Recalcula o benefício da assinatura sobre os serviços finais e persiste o
    // desconto, mantendo um desconto gravado maior (fidelidade/cupom) se houver.
    $assinaturaFechamento = calcularDescontoAssinaturaCliente($agendamento['cliente_id'] ?? '', $servicos_ids_final);
    $descontoGravado = max(0, (float)($agendamento['desconto_aplicado'] ?? 0));
    if ((float)$assinaturaFechamento['desconto'] > $descontoGravado) {
        $desconto_final = (float)$assinaturaFechamento['desconto'];
        $tipo_desconto_final = 'assinatura_vip';
    } else {
        $desconto_final = $descontoGravado;
        $tipo_desconto_final = (string)($agendamento['tipo_desconto'] ?? '');
    }

    $stmtUp = $pdo->prepare("UPDATE agendamentos SET servicos_ids = ?, gorjeta = ?, forma_pagamento = ?, comanda_fechada_em = ?, desconto_aplicado = ?, tipo_desconto = ?, status = 'concluido' WHERE id = ?");
    $stmtUp->execute([$servicos_ids_final, $gorjeta, $forma_pagamento, date('Y-m-d H:i:s'), $desconto_final, $tipo_desconto_final, $agendamento_id]);
    if (function_exists('registrarHistoricoAgenda')) {
        registrarHistoricoAgenda($agendamento_id, 'Comanda fechada', 'Atendimento concluído via comanda');
    }

    if ($status_anterior !== 'concluido') {
        $clientesArr = lerDados('clientes', ['id', 'nome', 'email', 'telefone', 'indicado_por_id']);
        $cliente = null;
        if (!empty($agendamento['cliente_id']) && isset($clientesArr[$agendamento['cliente_id']])) {
            $cliente = $clientesArr[$agendamento['cliente_id']];
        } else {
            $cliente = getClientePorEmailTelefoneOuCPF($agendamento['telefone']);
            if (!$cliente) { $cliente = getClientePorEmailTelefoneOuCPF($agendamento['email']); }
        }
        $agendamento['servicos_ids'] = $servicos_ids_final;
        aplicarEfeitosConclusaoAtendimento($pdo, $agendamento, $cliente);
        if ($cliente) {
            criarNotificacao($cliente['id'], "Obrigado pela sua visita! Seu atendimento em " . date('d/m/Y', strtotime($agendamento['data'])) . " foi concluído. Não se esqueça de avaliar!");
        }
    }

    header('Location: admin.php?tab=agendamentos&success=' . urlencode('Comanda fechada e atendimento concluído!'));
    exit;
}

// --- AÇÃO: REAGENDAR ---
if ($action === 'salvar_reagendamento') {
    $agendamento_id = $_POST['reagendar_agendamento_id'];
    $nova_data = $_POST['reagendar_data'];
    $nova_hora = $_POST['reagendar_horario'];
    
    if (!empty($agendamento_id)) {
        $pdo = getDB();
        
        $stmtGet = $pdo->prepare("SELECT * FROM agendamentos WHERE id = ?");
        $stmtGet->execute([$agendamento_id]);
        $agendamento_original = $stmtGet->fetch();
        
        if ($agendamento_original) {
            $stmtUp = $pdo->prepare("UPDATE agendamentos SET data = ?, hora = ?, status = 'aprovado' WHERE id = ?");
            $stmtUp->execute([$nova_data, $nova_hora, $agendamento_id]);
            if (function_exists('registrarHistoricoAgenda')) {
                registrarHistoricoAgenda($agendamento_id, 'Agendamento reagendado', date('d/m/Y', strtotime($nova_data)) . ' às ' . $nova_hora);
            }
            
            $clientesArr = lerDados('clientes', ['id', 'email', 'telefone']);
            $cliente = null;
            if (!empty($agendamento_original['cliente_id']) && isset($clientesArr[$agendamento_original['cliente_id']])) {
                $cliente = $clientesArr[$agendamento_original['cliente_id']];
            } else {
                $cliente = getClientePorEmailTelefoneOuCPF($agendamento_original['telefone']);
                if (!$cliente) { $cliente = getClientePorEmailTelefoneOuCPF($agendamento_original['email']); }
            }

            if ($cliente) { 
                criarNotificacao($cliente['id'], "Seu agendamento foi REAGENDADO para ".date('d/m/Y', strtotime($nova_data))." às ".$nova_hora."."); 
            }

            // --- DISPARO FORÇADO DO E-MAIL DE REAGENDAMENTO ---
            if (filter_var($agendamento_original['email'], FILTER_VALIDATE_EMAIL)) {
                $barbeirosArr = lerDados('barbeiros', ['id', 'nome']);
                $servicosArr = lerDados('servicos', ['id', 'nome']);
                $combosArr = lerDados('combos', ['id', 'nome']);

                $servicos_nomes = [];
                $ids = explode(',', $agendamento_original['servicos_ids']);
                foreach ($ids as $sid) {
                    $sid = trim($sid);
                    if(isset($servicosArr[$sid])) $servicos_nomes[] = $servicosArr[$sid]['nome'];
                    elseif(isset($combosArr[$sid])) $servicos_nomes[] = $combosArr[$sid]['nome'] . " (Combo)";
                }

                $dados_email = [
                    'nome_cliente' => $agendamento_original['nome'],
                    'data_agendamento' => date('d/m/Y', strtotime($nova_data)),
                    'hora_agendamento' => $nova_hora,
                    'servicos' => $servicos_nomes,
                    'barbeiro' => $barbeirosArr[$agendamento_original['barbeiro_id']]['nome'] ?? 'Não especificado',
                    'tipo_desconto' => $agendamento_original['tipo_desconto'] ?? ''
                ];
                // Alterado de 'aprovado' para 'reagendamento'
                enviarEmail($agendamento_original['email'], 'Seu Agendamento foi Reagendado!', 'reagendamento', $dados_email);
            }
        }
    }
    header('Location: admin.php?tab=agendamentos&success=' . urlencode('Agendamento remarcado e cliente notificado com sucesso!'));
    exit;
}

// --- AÇÃO: EXCLUIR AGENDAMENTO ---
if ($action === 'excluir_agendamento' && !empty($id)) {
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM agendamentos WHERE id = ?");
    $stmt->execute([$id]);
    try {
        $pdo->prepare("DELETE FROM agenda_operacao WHERE agendamento_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM agenda_historico WHERE agendamento_id = ?")->execute([$id]);
    } catch (PDOException $e) { }
    header('Location: admin.php?tab=agendamentos&success=' . urlencode('Agendamento excluído permanentemente.')); 
    exit; 
}

// --- AÇÃO: SALVAR AGENDAMENTO MANUAL ---
if ($action === 'salvar_agendamento_manual') {
    $cliente_id = $_POST['manual_cliente_id'] ?? ''; 
    $servicos_ids = $_POST['manual_servicos'] ?? ''; 
    $data = $_POST['manual_data'] ?? ''; 
    $hora = $_POST['manual_horario'] ?? ''; 
    $barbeiro_id = $_POST['manual_barbeiro'] ?? ''; 
    
    $pdo = getDB();
    $nome_cliente = ''; $telefone_cliente = ''; $email_cliente = 'manual@admin.com'; 
    
    if (!empty($cliente_id)) {
        $stmtCli = $pdo->prepare("SELECT nome, telefone, email FROM clientes WHERE id = ?");
        $stmtCli->execute([$cliente_id]);
        if ($cli = $stmtCli->fetch()) {
            $nome_cliente = $cli['nome'] ?? ''; 
            $telefone_cliente = limparTelefone($cli['telefone'] ?? ''); 
            $email_cliente = $cli['email'] ?? 'manual@admin.com'; 
        }
    }
    
    if (empty($nome_cliente)) { $nome_cliente = trim($_POST['manual_nome'] ?? ''); }
    if (empty($telefone_cliente)) { $telefone_cliente = limparTelefone($_POST['manual_telefone'] ?? ''); }
    
    $erros = [];
    if (empty($nome_cliente)) $erros[] = "Nome do Cliente";
    if (empty($telefone_cliente)) $erros[] = "Telefone";
    if (empty($barbeiro_id)) $erros[] = "Profissional";
    if (empty($servicos_ids)) $erros[] = "Serviços";
    if (empty($data)) $erros[] = "Data";
    if (empty($hora)) $erros[] = "Horário (Você esqueceu de clicar em um botão de horário)";
    
    if (!empty($erros)) { 
        header('Location: admin.php?tab=agendamentos&error=' . urlencode('Falha: Os seguintes campos são obrigatórios: ' . implode(', ', $erros))); 
        exit; 
    }
    
    // Desconto de assinatura: serviços cobertos pelo plano ativo do cliente entram
    // zerados (cobre também assinantes com cancelamento agendado). Centralizado no
    // helper canônico para bater com a comanda e os relatórios.
    $assinaturaManual = calcularDescontoAssinaturaCliente($cliente_id, $servicos_ids);
    $desconto_aplicado = $assinaturaManual['desconto'];
    $tipo_desconto = $assinaturaManual['tipo'];
    $plano_provisorio_para_salvar = '';

    $servicosDisponibilidade = lerDados('servicos', ['id', 'slots']);
    $combosDisponibilidade = lerDados('combos', ['id', 'servicos_ids']);
    $slotsNecessarios = 0;
    foreach (array_filter(array_map('trim', explode(',', $servicos_ids))) as $itemId) {
        if (isset($servicosDisponibilidade[$itemId])) {
            $slotsNecessarios += max(1, (int)($servicosDisponibilidade[$itemId]['slots'] ?? 1));
        } elseif (isset($combosDisponibilidade[$itemId])) {
            foreach (array_filter(array_map('trim', explode(',', $combosDisponibilidade[$itemId]['servicos_ids'] ?? ''))) as $servicoComboId) {
                $slotsNecessarios += max(1, (int)($servicosDisponibilidade[$servicoComboId]['slots'] ?? 1));
            }
        } else {
            $slotsNecessarios++;
        }
    }
    $slotsNecessarios = max(1, $slotsNecessarios);
    $horariosOcupados = getHorariosOcupados($barbeiro_id, $data);
    $inicioMinutos = ((int)substr($hora, 0, 2) * 60) + (int)substr($hora, 3, 2);
    for ($slot = 0; $slot < $slotsNecessarios; $slot++) {
        $minutos = $inicioMinutos + ($slot * 30);
        $horaSlot = sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
        if (in_array($horaSlot, $horariosOcupados, true)) {
            header('Location: admin.php?tab=agendamentos&filtro_data=' . urlencode($data) . '&error=' . urlencode('O horário escolhido ficou indisponível. Selecione outra opção.'));
            exit;
        }
    }

    $timestamp_agendamento = strtotime("$data $hora");
    $status_inicial = ($timestamp_agendamento < time()) ? 'concluido' : 'aprovado';
    $id_agendamento = 'AG-' . strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS agendamentos (id TEXT PRIMARY KEY, nome TEXT, email TEXT, telefone TEXT, barbeiro_id TEXT, servicos_ids TEXT, data TEXT, hora TEXT, status TEXT, desconto_aplicado TEXT, tipo_desconto TEXT, observacoes TEXT, produtos_vendidos TEXT, plano_provisorio TEXT, cliente_id TEXT, data_criacao TEXT)");

    $data_criacao_agora = date('Y-m-d H:i:s');
    
    $stmtIns = $pdo->prepare("INSERT INTO agendamentos (id, nome, email, telefone, barbeiro_id, servicos_ids, data, hora, status, desconto_aplicado, tipo_desconto, observacoes, produtos_vendidos, plano_provisorio, cliente_id, data_criacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtIns->execute([$id_agendamento, $nome_cliente, $email_cliente, $telefone_cliente, $barbeiro_id, $servicos_ids, $data, $hora, $status_inicial, $desconto_aplicado, $tipo_desconto, '', '', $plano_provisorio_para_salvar, $cliente_id, $data_criacao_agora]);
    if (function_exists('registrarHistoricoAgenda')) {
        registrarHistoricoAgenda($id_agendamento, 'Agendamento criado', 'Cadastro manual pela administração');
    }

    header('Location: admin.php?tab=agendamentos&success=' . urlencode('Agendamento manual criado com sucesso!')); 
    exit;
}
?>
