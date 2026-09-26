<?php
// admin_modals.php — Modais do painel administrativo.
// O conteúdo foi dividido em partials por tema sob admin_modals/, mantendo a
// MESMA ordem e, portanto, saída HTML idêntica à versão de arquivo único.
// Evita acesso direto ao arquivo.
if (!isset($_SESSION['loggedin'])) {
    exit;
}

foreach ([
    'p1_produtos.php',
    'p2_agendamento_combo.php',
    'p3_clientes.php',
    'p4_barbeiros.php',
    'p5_servicos_categorias_planos.php',
    'p6_usuarios_cupons_agenda.php',
    'p7_comanda_ausencias.php',
    'p8_avaliacoes_bug_estoque.php',
] as $__parcial) {
    include __DIR__ . '/admin_modals/' . $__parcial;
}
