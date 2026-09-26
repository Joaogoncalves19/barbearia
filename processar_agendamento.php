<?php
// Este arquivo é responsável APENAS pela lógica de submissão do formulário.
// Ele é acionado automaticamente pelo include no agendamento.php.

$nome = trim($_POST['nome']);
$email = trim($_POST['email']);
$telefone = limparTelefone($_POST['telefone']);
$barbeiro_id = $_POST['barbeiro'] ?? '';
$servicos_ids = $_POST['servicos'] ?? ''; 
$data = $_POST['data'] ?? '';
$hora = $_POST['horario'] ?? '';
$cupom_codigo = trim(strtoupper($_POST['cupom'] ?? ''));
$observacoes = trim($_POST['observacoes'] ?? '');
$plano_provisorio_id = $_POST['plano_escolhido_id'] ?? '';
$usar_fidelidade = isset($_POST['usar_fidelidade']);

// ==========================================================================
// ASSINATURA É 100% ONLINE: sem pagamento online configurado, não há adesão.
// Se o cliente tentar aderir a um plano sem o Stripe disponível, a adesão é
// descartada (o agendamento segue normal, sem benefícios) — nunca "acertar na
// barbearia". A opção nem aparece no formulário quando o Stripe está ausente.
// ==========================================================================
$configStripeGuard = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('config_stripe', ['secret_key' => '']) : ['secret_key' => ''];
$stripeDisponivel = !empty(trim($configStripeGuard['secret_key'] ?? ''));
if ($plano_provisorio_id !== '' && !$stripeDisponivel) {
    $plano_provisorio_id = '';
    $_SESSION['assinatura_indisponivel'] = true;
    log_activity('Adesao a plano descartada: Stripe nao configurado (agendamento seguiu sem assinatura).');
}

// Segurança extra: Filtra espaços em branco e remove IDs duplicados enviados pelo frontend
$servicosSelecionadosParaSalvar = $servicos_ids ? array_unique(array_filter(array_map('trim', explode(',', $servicos_ids)))) : [];
if(isset($_POST['horario_barbeiro_id']) && !empty($_POST['horario_barbeiro_id'])) { $barbeiro_id = $_POST['horario_barbeiro_id']; }

$totalSlots = 0;
$combos_selecionados_ids_post = isset($_POST['combos_selecionados']) ? array_unique(array_filter(array_map('trim', explode(',', $_POST['combos_selecionados'])))) : [];
$servicos_ja_em_combo_post = [];

foreach($combos_selecionados_ids_post as $combo_id) {
    if(isset($combosArr[$combo_id])) {
        $totalSlots += (int)$combosArr[$combo_id]['slots'];
        $servicos_do_combo = array_map('trim', explode(',', $combosArr[$combo_id]['servicos_ids']));
        $servicos_ja_em_combo_post = array_merge($servicos_ja_em_combo_post, $servicos_do_combo);
    }
}

foreach($servicosSelecionadosParaSalvar as $sid_limpo) {
    if(strpos($sid_limpo, 'sv-') === 0 && !in_array($sid_limpo, $servicos_ja_em_combo_post) && isset($servicosArr[$sid_limpo])) {
        $slots = (int)($servicosArr[$sid_limpo]['slots'] ?? 1);
        $totalSlots += ($slots > 0) ? $slots : 1;
    }
}
if ($totalSlots == 0 && !empty($servicosSelecionadosParaSalvar)) $totalSlots = 1;


// Regras configuraveis do agendamento. Ate aqui elas so valiam no navegador
// (agendamento.php) e na montagem da lista de horarios (get_horarios.php);
// agora sao reavaliadas tambem na gravacao.
$_cfgAg = function_exists('carregarConfigAgendamento') ? carregarConfigAgendamento() : [];
$_minAntecedencia = (int) ($_cfgAg['antecedencia_minima_minutos'] ?? 0);
$_maxDias         = (int) ($_cfgAg['antecedencia_maxima'] ?? 0);
$_maxServicos     = (int) ($_cfgAg['max_servicos'] ?? 0);

if (empty($nome) || empty($email) || empty($telefone) || empty($barbeiro_id) || empty($servicosSelecionadosParaSalvar) || empty($data) || empty($hora) || $totalSlots === 0) {
    $mensagem = "Por favor, preencha todos os campos do formulário.";
} elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !preg_match('/^\d{2}:\d{2}$/', $hora)) {
    $mensagem = "Data ou horário inválido.";
} elseif (strtotime($data . ' ' . $hora) < time()) {
    // Compara DATA-HORA, nao so a data: antes, as 15h ainda era possivel
    // marcar as 08h do mesmo dia, porque a comparacao era data contra data.
    // O formato ja foi validado acima, entao o strtotime aqui e confiavel.
    $mensagem = "Esse horário já passou. Escolha um horário futuro.";
} elseif ($_minAntecedencia > 0 && strtotime($data . ' ' . $hora) < time() + ($_minAntecedencia * 60)) {
    // Antecedencia minima so era aplicada em get_horarios.php, que monta a
    // lista exibida -- quem enviasse o formulario por outro caminho passava.
    $mensagem = "É necessário agendar com pelo menos " . $_minAntecedencia . " minuto(s) de antecedência.";
} elseif ($_maxDias > 0 && strtotime($data) > strtotime('+' . $_maxDias . ' days', strtotime(date('Y-m-d')))) {
    $mensagem = "Só é possível agendar com até " . $_maxDias . " dia(s) de antecedência.";
} elseif ($_maxServicos > 0 && count($servicosSelecionadosParaSalvar) > $_maxServicos) {
    $mensagem = "Selecione no máximo " . $_maxServicos . " itens por agendamento.";
} elseif (!isset($barbeirosAtivosArr[$barbeiro_id])) {
    $mensagem = "Profissional indisponível para agendamento.";
} else {
    $itensInvalidos = array_filter($servicosSelecionadosParaSalvar, function($itemId) use ($servicosArr, $combosArr) {
        return !isset($servicosArr[$itemId]) && !isset($combosArr[$itemId]);
    });
    $especialidadesBarbeiro = array_filter(array_map('trim', explode(',', $barbeirosAtivosArr[$barbeiro_id]['servicos_ids'] ?? '')));
    $itensNaoAtendidos = array_filter($servicosSelecionadosParaSalvar, function($itemId) use ($especialidadesBarbeiro) {
        return !in_array($itemId, $especialidadesBarbeiro, true);
    });
    $expediente = getHorarioDeTrabalho($barbeiro_id, (int)date('w', strtotime($data)), $data);
    $inicioAgendamento = strtotime($data . ' ' . $hora);
    $fimAgendamento = $inicioAgendamento + ($totalSlots * 30 * 60);
    $inicioExpediente = $expediente ? strtotime($data . ' ' . $expediente['inicio']) : false;
    $fimExpediente = $expediente ? strtotime($data . ' ' . $expediente['fim']) : false;

    $conflito = false;
    $ausenciaProfissional = barbeiroEstaAusente($barbeiro_id, $data);
    if ($ausenciaProfissional) {
        $mensagem = "O profissional está de " . strtolower(rotuloTipoAusencia($ausenciaProfissional['tipo'])) . " nesse dia. Por favor, escolha outra data.";
        $conflito = true;
    } elseif (!empty($itensInvalidos)) {
        $mensagem = "Um ou mais serviços selecionados não existem mais.";
        $conflito = true;
    } elseif (!empty($itensNaoAtendidos)) {
        $mensagem = "O profissional selecionado não realiza todos os itens escolhidos.";
        $conflito = true;
    } elseif (!$expediente || $inicioAgendamento < $inicioExpediente || $fimAgendamento > $fimExpediente || ((int)date('i', $inicioAgendamento) % 30 !== 0)) {
        $mensagem = "O horário escolhido não pertence ao expediente do profissional.";
        $conflito = true;
    }

    $horariosOcupados = getHorariosOcupados($barbeiro_id, $data);
    $startMinutes = intval(explode(':', $hora)[0]) * 60 + intval(explode(':', $hora)[1]);
    
    for ($i = 0; $i < $totalSlots; $i++) {
        $minutes = $startMinutes + $i * 30;
        $formatted = sprintf('%02d:%02d', floor($minutes / 60), $minutes % 60);
        if (in_array($formatted, $horariosOcupados)) {
            $conflito = true;
            $mensagem = "Um ou mais horários selecionados já estão ocupados.";
            break;
        }
    }

    if (!$conflito) {
        $pdo = getDB();
        $descontos = [];
        $tipo_desconto_aplicado = '';
        $id_cupom_usado = null;
        $voucher_usado = null;
        $cliente_id = $_SESSION['cliente_id'];

        // Quem já é assinante não adere de novo. O formulário esconde a opção
        // (agendamento.php só mostra o seletor quando não há assinatura ativa),
        // mas o POST pode ser forjado — e uma segunda adesão abriria uma
        // SEGUNDA assinatura cobrando no Stripe, enquanto o banco só guarda um
        // gateway_subscription_id: a antiga seguiria cobrando o cliente todo mês
        // sem ninguém conseguir cancelá-la pelo painel.
        if ($plano_provisorio_id !== '' && function_exists('getAssinaturaCliente') && getAssinaturaCliente($cliente_id)) {
            $plano_provisorio_id = '';
            log_activity("Adesao a plano ignorada: cliente $cliente_id ja possui assinatura vigente.");
        }

        // 1. CALCULAR O VALOR ORIGINAL TOTAL DOS SERVIÇOS (SEM DESCONTOS)
        $valor_total_servicos = 0;
        $servicos_ja_em_combo_calc = []; 
        
        foreach($combos_selecionados_ids_post as $combo_id) {
            if(isset($combosArr[$combo_id])) {
                $valor_total_servicos += (float) $combosArr[$combo_id]['valor'];
                $servicos_do_combo = array_map('trim', explode(',', $combosArr[$combo_id]['servicos_ids']));
                $servicos_ja_em_combo_calc = array_merge($servicos_ja_em_combo_calc, $servicos_do_combo);
            }
        }
        foreach($servicosSelecionadosParaSalvar as $sid_limpo) {
            if(!in_array($sid_limpo, $servicos_ja_em_combo_calc) && isset($servicosArr[$sid_limpo])) {
                $valor_total_servicos += (float) $servicosArr[$sid_limpo]['valor'];
            }
        }
        
        $desconto_aplicado = 0;
        $valor_plano = 0;
        $total_a_pagar_itens = 0;

        $desconto_fidelidade_calc = 0;
        if ($usar_fidelidade && ($config_fidelidade['ativado'] ?? 0)) {
            $pontos_atuais = getClientFidelityPoints($cliente_id); 
            $pontos_necessarios = $config_fidelidade['pontos_necessarios'] ?? 10;
            
            if ($pontos_atuais >= $pontos_necessarios) {
                $val_servicos_lista = [];
                foreach($servicosSelecionadosParaSalvar as $sid_limpo) {
                    if(isset($servicosArr[$sid_limpo])) { $val_servicos_lista[] = (float) $servicosArr[$sid_limpo]['valor']; }
                }
                $desconto_fidelidade_calc = fidelidadeValorDesconto($val_servicos_lista, $valor_total_servicos);
            }
        }

        // ==========================================
        // LÓGICA INFALÍVEL DE CÁLCULO DE DESCONTO DE ASSINATURA
        // ==========================================
        if (!empty($plano_provisorio_id) && isset($planosArr[$plano_provisorio_id])) {
            $plano = $planosArr[$plano_provisorio_id];
            $valor_plano = (float)$plano['valor'];
            $servicosInclusosNoPlano = array_map('trim', explode(',', $plano['servicos_ids']));
            
            $total_a_pagar_itens = 0;
            
            foreach($combos_selecionados_ids_post as $combo_id) {
                if(isset($combosArr[$combo_id])) {
                    $combo = $combosArr[$combo_id];
                    $servicos_do_combo = array_map('trim', explode(',', $combo['servicos_ids']));
                    
                    $custo_itens_nao_cobertos = 0;
                    $algum_coberto = false;
                    
                    foreach($servicos_do_combo as $sid) {
                        if (isset($servicosArr[$sid])) {
                            if (in_array($sid, $servicosInclusosNoPlano)) {
                                $algum_coberto = true;
                            } else {
                                $custo_itens_nao_cobertos += (float)$servicosArr[$sid]['valor'];
                            }
                        }
                    }
                    
                    if ($algum_coberto) {
                        $total_a_pagar_itens += min((float)$combo['valor'], $custo_itens_nao_cobertos);
                    } else {
                        $total_a_pagar_itens += (float)$combo['valor'];
                    }
                }
            }

            foreach($servicosSelecionadosParaSalvar as $sid_limpo) {
                if (!in_array($sid_limpo, $servicos_ja_em_combo_calc) && isset($servicosArr[$sid_limpo])) {
                    if (!in_array($sid_limpo, $servicosInclusosNoPlano)) {
                        $total_a_pagar_itens += (float)$servicosArr[$sid_limpo]['valor'];
                    }
                }
            }

            // O desconto nos serviços é a diferença entre o que custava e o que ele vai pagar
            $desconto_aplicado = max(0, $valor_total_servicos - $total_a_pagar_itens);
            $tipo_desconto_aplicado = 'adesao_plano';
        
        } elseif ($assinaturaAtiva) {
             $plano = $planosArr[$assinaturaAtiva['plano_id']] ?? null;
             $total_a_pagar_com_assinatura = 0;
             
             if ($plano) {
                $servicosInclusosNoPlano = array_map('trim', explode(',', $plano['servicos_ids']));
                
                // Analisa todos os Combos primeiro
                foreach($combos_selecionados_ids_post as $combo_id) {
                    if(isset($combosArr[$combo_id])) {
                        $combo = $combosArr[$combo_id];
                        $servicos_do_combo = array_map('trim', explode(',', $combo['servicos_ids']));
                        
                        $custo_itens_nao_cobertos = 0;
                        $algum_coberto = false;
                        
                        // Verifica cada serviço dentro do combo
                        foreach($servicos_do_combo as $sid) {
                            if (isset($servicosArr[$sid])) {
                                if (in_array($sid, $servicosInclusosNoPlano)) { 
                                    $algum_coberto = true;
                                } else { 
                                    // Soma o valor da tabela do item que NÃO faz parte do plano
                                    $custo_itens_nao_cobertos += (float)$servicosArr[$sid]['valor']; 
                                }
                            }
                        }
                        
                        if ($algum_coberto) {
                            // O cliente paga pelo que não é coberto, limitado ao preço total do combo
                            $total_a_pagar_com_assinatura += min((float)$combo['valor'], $custo_itens_nao_cobertos);
                        } else {
                            // Se nenhum item for coberto, paga o combo todo
                            $total_a_pagar_com_assinatura += (float)$combo['valor'];
                        }
                    }
                }
                
                // Analisa os Serviços Avulsos
                foreach($servicosSelecionadosParaSalvar as $sid_limpo) {
                    if (!in_array($sid_limpo, $servicos_ja_em_combo_calc) && isset($servicosArr[$sid_limpo])) {
                        if (!in_array($sid_limpo, $servicosInclusosNoPlano)) {
                            $total_a_pagar_com_assinatura += (float)$servicosArr[$sid_limpo]['valor'];
                        }
                    }
                }
             } else {
                 $total_a_pagar_com_assinatura = $valor_total_servicos;
             }
             
             // O desconto que o sistema aplica é a exata diferença
             $desconto_total_assinatura = max(0, $valor_total_servicos - $total_a_pagar_com_assinatura);
             
             if ($desconto_fidelidade_calc > $desconto_total_assinatura) {
                 $desconto_aplicado = $desconto_fidelidade_calc; $tipo_desconto_aplicado = 'fidelidade';
             } else {
                 $desconto_aplicado = $desconto_total_assinatura; $tipo_desconto_aplicado = ($desconto_total_assinatura != 0) ? 'assinatura_vip' : '';
             }
        } else {
            if ($desconto_fidelidade_calc > 0) { $descontos['fidelidade'] = $desconto_fidelidade_calc; }

            if (!empty($cupom_codigo)) {
                $cupom = getCouponByCode($cupom_codigo); $voucher = getVoucherByCode($cupom_codigo);
                if ($cupom && !isset($cupom['error'])) {
                    if (checkIfUserUsedCoupon($cupom['id'], $cliente_id)) { $mensagem = "Você já utilizou este cupom."; $conflito = true;
                    } else {
                        if (($cupom['tipo_desconto'] ?? 'percentual') === 'fixo') {
                            $descontos['cupom'] = min($valor_total_servicos, (float)($cupom['valor_desconto'] ?? 0));
                        } else {
                            $descontos['cupom'] = $valor_total_servicos * ($cupom['desconto_percentual'] / 100);
                        }
                        $id_cupom_usado = $cupom['id'];
                    }
                } elseif ($voucher && !isset($voucher['error'])) {
                    $descontos['voucher'] = $voucher['valor']; $voucher_usado = $voucher;
                } else { $mensagem = $cupom['error'] ?? ($voucher['error'] ?? "Cupom ou Voucher inválido."); $conflito = true; }
            }
            
            // Busca pelo ID da sessao, nao pelo e-mail: a sessao guarda uma copia
            // do e-mail de quando o login aconteceu, e se o admin editar a ficha ela
            // fica desatualizada -- a busca falhava e o cliente perdia o desconto de
            // aniversario sem ninguem perceber. O ID nao muda.
            $cliente = getClientById($cliente_id);
            if (($config_aniversario['ativado'] ?? 0) && !empty($cliente['data_nascimento'])) { 
                if (date('m', strtotime($cliente['data_nascimento'])) == date('m')) { 
                    $mes_atual = date('Y-m');
                    $stmtCheck = $pdo->prepare("SELECT id FROM agendamentos WHERE (cliente_id = ? OR email = ? OR telefone = ?) AND status NOT IN ('cancelado', 'cancelado_pelo_cliente') AND data LIKE ? LIMIT 1");
                    // e-mail/telefone vindos do banco (via $cliente, lido pelo ID),
                    // nao das copias que a sessao guardou no momento do login.
                    $stmtCheck->execute([$cliente_id, $cliente['email'] ?? '', limparTelefone($cliente['telefone'] ?? ''), $mes_atual . '-%']);
                    if (!$stmtCheck->fetch()) { $descontos['aniversario'] = $valor_total_servicos * ($config_aniversario['desconto_percentual'] / 100); }
                } 
            }

            $config_indicacao = getIndicacaoConfig();
            if (($config_indicacao['ativado'] ?? 0) && $cliente && !empty($cliente['indicado_por_id'])) {
                if (isPrimeiroAgendamento($cliente_id)) { 
                    $desc_perc = (float)($config_indicacao['desconto_novo_cliente'] ?? 0);
                    if ($desc_perc > 0) { $descontos['indicacao'] = $valor_total_servicos * ($desc_perc / 100); }
                }
            }
            
            if (!empty($descontos)) {
                $desconto_aplicado = max($descontos); $tipo_desconto_aplicado = array_search($desconto_aplicado, $descontos);
            }
        }

        if (!$conflito) {
            $valor_final_pago = $valor_total_servicos - $desconto_aplicado;
            // Proteção universal para não permitir que descontos tornem o valor pago negativo
            if ($valor_final_pago < 0) { 
                $desconto_aplicado = $valor_total_servicos; 
            }

            $id_agendamento = 'AG-' . strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
            
            $status = 'aprovado';
            if ($tipo_desconto_aplicado === 'adesao_plano' && $valor_plano > 0) {
                $status = 'aguardando_pagamento';
            }
            
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS agendamentos (id TEXT PRIMARY KEY, nome TEXT, email TEXT, telefone TEXT, barbeiro_id TEXT, servicos_ids TEXT, data TEXT, hora TEXT, status TEXT, desconto_aplicado TEXT, tipo_desconto TEXT, observacoes TEXT, produtos_vendidos TEXT, plano_provisorio TEXT, cliente_id TEXT)"); } catch(Exception $e) {}
            // Colunas data_criacao/payment_gateway/gateway_reference garantidas por lib/migrations.php.

            try {
                $pdo->beginTransaction();
                
                if (isset($_SESSION['reagendar_id'])) {
                    $stmtDel = $pdo->prepare("DELETE FROM agendamentos WHERE id = ?");
                    $stmtDel->execute([$_SESSION['reagendar_id']]); unset($_SESSION['reagendar_id']);
                }

                // ------------------------------------------------------------------
                // Reverificacao do horario DENTRO da transacao.
                //
                // A checagem la em cima acontece ~230 linhas antes deste INSERT, e
                // no meio do caminho rodam consultas de cupom, voucher, fidelidade e
                // ate uma chamada HTTP ao Stripe. Nessa janela outro cliente podia
                // fechar o mesmo horario -- e os dois eram gravados.
                //
                // Fica depois do DELETE do reagendamento de proposito: senao o
                // horario antigo contaria como ocupado contra ele mesmo.
                // ------------------------------------------------------------------
                $ocupadosAgora = getHorariosOcupados($barbeiro_id, $data);
                $inicioMin = intval(explode(':', $hora)[0]) * 60 + intval(explode(':', $hora)[1]);
                for ($i = 0; $i < $totalSlots; $i++) {
                    $m = $inicioMin + ($i * 30);
                    $slot = sprintf('%02d:%02d', floor($m / 60), $m % 60);
                    if (in_array($slot, $ocupadosAgora, true)) {
                        throw new RuntimeException('SLOT_OCUPADO');
                    }
                }

                $data_criacao_agora = date('Y-m-d H:i:s');

                $stmtIns = $pdo->prepare("INSERT INTO agendamentos (id, nome, email, telefone, barbeiro_id, servicos_ids, data, hora, status, desconto_aplicado, tipo_desconto, observacoes, produtos_vendidos, plano_provisorio, cliente_id, data_criacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtIns->execute([
                    $id_agendamento, $nome, $email, $telefone, $barbeiro_id, implode(',', $servicosSelecionadosParaSalvar), 
                    $data, $hora, $status, $desconto_aplicado, $tipo_desconto_aplicado, $observacoes, '', $plano_provisorio_id, $cliente_id, $data_criacao_agora
                ]);
                
                $pdo->commit();
                if (function_exists('registrarHistoricoAgenda')) {
                    registrarHistoricoAgenda($id_agendamento, 'Agendamento criado', 'Feito pelo cliente no site', ($nome !== '' ? $nome . ' (cliente)' : 'Cliente (site)'));
                }
            } catch (RuntimeException $e) {
                // Perdeu a corrida: outro cliente fechou o horario nesse meio-tempo.
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $mensagem = "Esse horário acabou de ser reservado por outra pessoa. Escolha outro, por favor.";
                $conflito = true;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                // O indice unico do banco e a ultima linha de defesa da corrida.
                if (stripos($e->getMessage(), 'UNIQUE') !== false) {
                    $mensagem = "Esse horário acabou de ser reservado por outra pessoa. Escolha outro, por favor.";
                    $conflito = true;
                }
                log_activity("Erro SQLite no agendamento: " . $e->getMessage()); }

            // Se a gravacao nao aconteceu (horario tomado na corrida ou erro de
            // banco), NADA daqui para baixo pode rodar: sem checkout no Stripe,
            // sem e-mail de confirmacao, sem redirect de sucesso. Antes o fluxo
            // seguia direto e o cliente via "agendamento confirmado" sem ter um.
            if (!$conflito) {

            if ($tipo_desconto_aplicado === 'adesao_plano' && $valor_plano > 0) {
                $configStripe = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('config_stripe', ['secret_key' => '']) : ['secret_key' => ''];
                $checkoutErro = '';

                if (!empty($configStripe['secret_key'])) {
                    $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['REQUEST_URI']);
                    
                    $line_items = [];
                    $line_items[] = [
                        'price_data' => [
                            'currency' => 'brl',
                            'product_data' => ['name' => 'Barbearia por Assinatura: ' . $plano['nome']],
                            'unit_amount' => round($valor_plano * 100),
                            'recurring' => ['interval' => 'month']
                        ],
                        'quantity' => 1,
                    ];
                    
                    if ($total_a_pagar_itens > 0) {
                        $line_items[] = [
                            'price_data' => [
                                'currency' => 'brl',
                                'product_data' => [
                                    'name' => 'Serviços Adicionais (Pagamento Único)',
                                    'description' => 'Serviços marcados que não fazem parte da assinatura'
                                ],
                                'unit_amount' => round($total_a_pagar_itens * 100),
                            ],
                            'quantity' => 1,
                        ];
                    }

                    $stripe_data = [
                        'payment_method_types' => ['card'],
                        'line_items' => $line_items,
                        'mode' => 'subscription',
                        'success_url' => $base_url . "/agendamento.php?success=1&stripe_success=1&ag_id=" . $id_agendamento,
                        'cancel_url' => $base_url . "/agendamento.php?erro_stripe=1&ag_id=" . $id_agendamento,
                        'client_reference_id' => $cliente_id . "||" . $plano_provisorio_id . "||" . $id_agendamento, 
                        'customer_email' => $email
                    ];

                    $ch = curl_init("https://api.stripe.com/v1/checkout/sessions");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($stripe_data));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $configStripe['secret_key'], "Content-Type: application/x-www-form-urlencoded"]);
                    
                    $response = curl_exec($ch);
                    curl_close($ch);
                    $stripe_result = json_decode($response, true);

                    if (isset($stripe_result['url'])) {
                        $stmtGateway = $pdo->prepare("UPDATE agendamentos SET payment_gateway = ? WHERE id = ?");
                        $stmtGateway->execute(['stripe', $id_agendamento]);
                        header("Location: " . $stripe_result['url']);
                        exit;
                    } else {
                        $checkoutErro = 'Não foi possível criar a sessão de pagamento da Stripe.';
                        log_activity("Erro API Stripe: " . $response);
                    }
                } else {
                    $checkoutErro = 'O pagamento online ainda não está disponível.';
                }

                // A assinatura é exclusivamente online. Se o checkout não pôde ser
                // iniciado, NÃO aprovamos nada nem mandamos "acertar na barbearia":
                // removemos o agendamento pendente (libera o horário) e devolvemos o
                // cliente ao formulário com um aviso para tentar novamente.
                if ($checkoutErro !== '') {
                    try {
                        $stmtLimpa = $pdo->prepare("DELETE FROM agendamentos WHERE id = ? AND status = 'aguardando_pagamento'");
                        $stmtLimpa->execute([$id_agendamento]);
                    } catch (Exception $e) {
                        log_activity('Falha ao remover agendamento pendente apos erro de checkout: ' . $e->getMessage());
                    }
                    $_SESSION['mensagem_erro'] = 'Não foi possível iniciar o pagamento online da assinatura no momento. '
                        . 'Nenhuma cobrança foi feita e o horário não foi reservado. Por favor, tente novamente em instantes.';
                    header('Location: agendamento?erro_stripe=1');
                    exit;
                }
            }
            
            if ($status !== 'aguardando_pagamento') {
                $configAgendamentoEmail = carregarConfigAgendamento();
                if (($configAgendamentoEmail['notif_confirmacao'] ?? 0) == 1) {
                    $dados_email = [
                        'nome_cliente' => $nome, 'data_agendamento' => date('d/m/Y', strtotime($data)), 'hora_agendamento' => $hora, 'status' => $status,
                        'servicos' => array_map(function($sid) use ($servicosArr, $combosArr) {
                            $sid = trim($sid);
                            if (isset($servicosArr[$sid])) return $servicosArr[$sid]['nome'];
                            if (isset($combosArr[$sid])) return $combosArr[$sid]['nome'] . " (Combo)";
                            return 'Serviço/Combo não encontrado';
                        }, $servicosSelecionadosParaSalvar),
                        'barbeiro' => $barbeirosArr[$barbeiro_id]['nome'] ?? 'Não especificado', 'tipo_desconto' => $tipo_desconto_aplicado
                    ];
                    enviarEmail($email, 'Confirmação de Agendamento', 'confirmacao', $dados_email);
                }
                
                // --- NOVA NOTIFICAÇÃO NO SISTEMA ---
                criarNotificacao($cliente_id, "Oba! Seu agendamento para " . date('d/m/Y', strtotime($data)) . " às $hora foi confirmado com sucesso!");
            } else {
                criarNotificacao($cliente_id, "Seu agendamento para " . date('d/m/Y', strtotime($data)) . " às $hora foi criado e está aguardando o pagamento para ser ativado.");
            }

            $mensagem_desconto = '';
            if ($tipo_desconto_aplicado === 'cupom' && $id_cupom_usado) { incrementarUsoCupom($id_cupom_usado); logCouponUsage($id_cupom_usado, $cliente_id); $mensagem_desconto = "Cupom aplicado! "; } 
            elseif ($tipo_desconto_aplicado === 'voucher' && $voucher_usado) {
                // O resgate é atômico e pode falhar se outro agendamento usou o
                // mesmo voucher entre a validação e agora. Como o agendamento já
                // foi gravado COM o desconto, nesse caso desfazemos o desconto na
                // linha recém-criada — senão a barbearia concederia um valor que
                // nenhum voucher cobre.
                if (marcarVoucherComoUtilizado($voucher_usado['id'], $id_agendamento)) {
                    $mensagem_desconto = "Voucher aplicado! ";
                } else {
                    try {
                        $stmtSemDesc = $pdo->prepare("UPDATE agendamentos SET desconto_aplicado = ?, tipo_desconto = ? WHERE id = ?");
                        $stmtSemDesc->execute([0, '', $id_agendamento]);
                    } catch (Exception $e) {
                        log_activity('Falha ao remover desconto de voucher ja utilizado do agendamento ' . $id_agendamento . ': ' . $e->getMessage());
                    }
                    log_activity('Voucher ' . $voucher_usado['id'] . ' ja estava utilizado; agendamento ' . $id_agendamento . ' confirmado sem desconto.');
                    $mensagem_desconto = "O voucher informado já havia sido utilizado, então seu agendamento foi confirmado sem o desconto. ";
                }
            }
            elseif ($tipo_desconto_aplicado === 'fidelidade') { 
                $pontos_atuais = getClientFidelityPoints($cliente_id); updateClientFidelityPoints($cliente_id, $pontos_atuais - $config_fidelidade['pontos_necessarios'], "Resgate de desconto"); 
                $mensagem_desconto = "Desconto fidelidade aplicado! ";
            } 
            elseif ($tipo_desconto_aplicado === 'assinatura_vip') { $mensagem_desconto = "Desconto de Assinante aplicado! "; }
            
            $mensagem_status = "Seu agendamento foi confirmado!";
            if ($status === 'aguardando_pagamento') { $mensagem_status = "Redirecionando para o pagamento seguro..."; }

            if (!empty($_SESSION['assinatura_indisponivel'])) {
                $mensagem_desconto = "A adesão à assinatura está temporariamente indisponível, então seu agendamento foi confirmado sem o plano. ";
                unset($_SESSION['assinatura_indisponivel']);
            }

            $_SESSION['mensagem_sucesso'] = $mensagem_desconto . $mensagem_status . " Acompanhe em 'Minha Conta'.";
            header('Location: agendamento.php?success=1');
            exit;
            }
        }
    }
}
