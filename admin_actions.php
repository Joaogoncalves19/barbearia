<?php
// barbearia/admin_actions.php
require_once __DIR__ . '/functions.php';
iniciarSessaoSegura();

// Bloqueio Crítico: Verifica se o utilizador está autenticado antes de processar ações
if (!isset($_SESSION['loggedin']) && !isset($_SESSION['barbeiro_loggedin'])) {
    header('HTTP/1.1 403 Forbidden');
    die('Acesso negado. Sessão inválida ou expirada.');
}

$keys_agendamentos_completo = [
    'id', 'nome', 'email', 'telefone', 'barbeiro_id', 'servicos_ids', 
    'data', 'hora', 'status', 'desconto_aplicado', 'tipo_desconto', 
    'observacoes', 'produtos_vendidos', 'plano_provisorio', 'cliente_id'
];

// CORREÇÃO: Permitir a receção da ação tanto via POST quanto via URL (GET)
// pois os botões na interface (concluir, cancelar, excluir) são links (tags <a>)
$action = $_REQUEST['action'] ?? null;
$id = $_REQUEST['id'] ?? null;

if (!empty($action)) {
    $rotas = [
        'enviar_lembretes_amanha'     => 'agendamentos.php',
        'salvar_config_lembretes'     => 'agendamentos.php',
        'adicionar_produto'           => 'agendamentos.php',
        'cancelar'                    => 'agendamentos.php',
        'rejeitar'                    => 'agendamentos.php',
        'concluir'                    => 'agendamentos.php',
        'salvar_reagendamento'        => 'agendamentos.php',
        'excluir_agendamento'         => 'agendamentos.php',
        'salvar_agendamento_manual'   => 'agendamentos.php',
        'limpar_agendamentos_antigos' => 'agendamentos.php',
        'agenda_acao_lote'             => 'agenda_admin.php',
        'agenda_marcar_confirmacao'    => 'agenda_admin.php',
        'enviar_newsletter'           => 'marketing.php',
        'gerar_texto_ia'              => 'marketing.php',
        'enviar_reativacao'           => 'marketing.php',
        'campanha_contar'             => 'marketing.php',
        'campanha_preview'            => 'marketing.php',
        'campanha_teste'              => 'marketing.php',
        'campanha_iniciar'            => 'marketing.php',
        'campanha_reativacao_iniciar' => 'marketing.php',
        'campanha_lote'               => 'marketing.php',
        'toggle_cupom'                => 'marketing.php',
        'gerar_voucher'               => 'marketing.php',
        'excluir_voucher'             => 'marketing.php',
        'salvar_voucher_editado'      => 'marketing.php',
        'salvar_config_indicacao'     => 'marketing.php',
        'salvar_config_fidelidade'    => 'marketing.php',
        'ajustar_pontos'              => 'marketing.php',
        'exportar_fidelidade'         => 'marketing.php',
        'salvar_cupom'                => 'marketing.php',
        'excluir_cupom'               => 'marketing.php',
        'salvar_config_aniversario'   => 'marketing.php',
        'enviar_cupom_aniversario'    => 'marketing.php',
        'enviar_lembretes_avaliacao'  => 'avaliacoes.php',
        'toggle_destaque_avaliacao'   => 'avaliacoes.php',
        'salvar_resposta_avaliacao'   => 'avaliacoes.php',
        'excluir_resposta_avaliacao'  => 'avaliacoes.php',
        'excluir_avaliacao'           => 'avaliacoes.php',
        'lembrete_iniciar'            => 'avaliacoes.php',
        'lembrete_individual'         => 'avaliacoes.php',
        'exportar_avaliacoes'         => 'avaliacoes.php',
        'salvar_bloqueios'            => 'barbeiros.php',
        'acao_mes_inteiro'            => 'barbeiros.php',
        'salvar_barbeiro'             => 'barbeiros.php',
        'excluir_barbeiro'            => 'barbeiros.php',
        'salvar_semana_horarios'      => 'barbeiros.php',
        'salvar_ausencia'             => 'barbeiros.php',
        'excluir_ausencia'            => 'barbeiros.php',
        'salvar_categoria'            => 'servicos.php',
        'excluir_categoria'           => 'servicos.php',
        'salvar_combo'                => 'servicos.php',
        'excluir_combo'               => 'servicos.php',
        'salvar_servico'              => 'servicos.php',
        'excluir_servico'             => 'servicos.php',
        'salvar_plano'                => 'servicos.php',
        'excluir_plano'               => 'servicos.php',
        'salvar_produto'              => 'servicos.php', 
        'excluir_produto'             => 'servicos.php', 
        'movimentar_estoque'          => 'servicos.php',
        'salvar_despesa'              => 'financeiro.php',
        'excluir_despesa'             => 'financeiro.php',
        'marcar_despesa_paga'         => 'financeiro.php',
        'pagar_comissao'              => 'financeiro.php',
        'excluir_pagamento_comissao'  => 'financeiro.php',
        'salvar_vale'                 => 'financeiro.php',
        'excluir_vale'                => 'financeiro.php',
        'salvar_meta_financeira'      => 'financeiro.php',
        'salvar_landing_page'         => 'configuracoes.php',
        'salvar_config_geral'         => 'configuracoes.php',
        'salvar_config_email'         => 'configuracoes.php',
        'salvar_config_agendamento'   => 'configuracoes.php',
        'salvar_config_stripe'        => 'configuracoes.php',
        'salvar_config_pagamentos'    => 'configuracoes.php',
        'salvar_config_chatbot'       => 'configuracoes.php',
        'backup_dados'                => 'configuracoes.php',
        'backup_sqlite'               => 'configuracoes.php',
        'limpar_dados'                => 'configuracoes.php',
        'limpar_por_periodo'          => 'configuracoes.php',
        'otimizar_banco'              => 'configuracoes.php',
        'salvar_tema'                 => 'configuracoes.php',
        'salvar_usuario'              => 'configuracoes.php',
        'excluir_usuario'             => 'configuracoes.php',
        'salvar_cliente'              => 'clientes.php',
        'excluir_cliente'             => 'clientes.php',
        'toggle_cliente_status'       => 'clientes.php',
        'salvar_anotacao'             => 'clientes.php',
        'ativar_assinatura'           => 'clientes.php',
        'cancelar_assinatura'         => 'clientes.php',
        'reportar_bug'                => 'suporte.php',
        'fechar_comanda'              => 'agendamentos.php',
        'gestao_salvar_espera'        => 'agenda_admin.php',
        'gestao_status_espera'        => 'agenda_admin.php',
        'gestao_reagendar_rapido'     => 'gestao.php',
        'gestao_salvar_crm'            => 'gestao.php',
        'gestao_salvar_meta'           => 'gestao.php',
        'gestao_salvar_retencao'       => 'gestao.php',
        'gestao_conciliar_pagamento'   => 'gestao.php',
        'gestao_salvar_perfil_usuario' => 'gestao.php',
    ];

    if (array_key_exists($action, $rotas)) {
        // Ações que uma sessão de BARBEIRO pode disparar por aqui. Todo o resto
        // exige sessão de ADMIN.
        //
        // Sem esta lista o dispatcher aceitava qualquer sessão autenticada, e
        // adminPodeAcessarAba() liberava tudo: um barbeiro não tem
        // $_SESSION['admin_role'], então caía no default 'proprietario' e podia
        // executar excluir_usuario, limpar_dados, salvar_config_stripe,
        // pagar_comissao, alterar a própria comissão etc. — com um csrf_token
        // legítimo, porque a sessão dele também tem um.
        $acoesPermitidasBarbeiro = ['reportar_bug'];

        if (empty($_SESSION['loggedin']) && !in_array($action, $acoesPermitidasBarbeiro, true)) {
            header('HTTP/1.1 403 Forbidden');
            die('Acesso negado. Esta ação exige uma sessão de administrador.');
        }

        // CORREÇÃO: Capturar o token CSRF via $_REQUEST (GET ou POST)
        $token_recebido = $_REQUEST['csrf_token'] ?? '';
        
        if (!verify_csrf_token($token_recebido)) {
            $aba_atual = $_GET['tab'] ?? 'dashboard';
            header("Location: admin.php?tab={$aba_atual}&error=" . urlencode('Ação bloqueada por segurança (Token inválido ou expirado). Recarregue a página e tente novamente.'));
            exit;
        }

        $abaRota = pathinfo($rotas[$action], PATHINFO_FILENAME);
        $abasRota = [
            'configuracoes' => 'configuracoes',
            'financeiro' => 'financeiro',
            'barbeiros' => 'barbeiros',
            'clientes' => 'clientes',
            'agendamentos' => 'agendamentos',
            'agenda_admin' => 'agendamentos',
            'marketing' => 'marketing',
            'avaliacoes' => 'avaliacoes',
            'servicos' => 'servicos',
            'gestao' => 'dashboard',
        ];
        // Ações cuja permissão NÃO é a do arquivo que as executa. Assinatura é
        // dinheiro recorrente e mora na aba Assinaturas, mas o código roda em
        // clientes.php — sem este mapa, um perfil com acesso a Clientes (ex.:
        // Recepção, que nem enxerga a aba Assinaturas) conseguia cancelar a
        // cobrança de um assinante por URL.
        $abaPorAcao = [
            'ativar_assinatura'   => 'assinaturas',
            'cancelar_assinatura' => 'assinaturas',
        ];
        $abaExigida = $abaPorAcao[$action] ?? ($abasRota[$abaRota] ?? null);
        if ($abaExigida !== null && !adminPodeAcessarAba($abaExigida)) {
            header('Location: admin.php?tab=dashboard&error=' . urlencode('Seu perfil não possui permissão para esta ação.'));
            exit;
        }

        if (isset($_SESSION['loggedin']) && function_exists('registrarAtividadeGestao')) {
            registrarAtividadeGestao('acao_admin', 'Acao executada: ' . $action);
        }

        require_once __DIR__ . '/actions/' . $rotas[$action];
    } else {
        header('Location: admin.php?error=' . urlencode('Ação inválida ou não encontrada.'));
        exit;
    }
}
?>
