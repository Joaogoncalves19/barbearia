<?php
// ajax_gemini.php
// Responsável por receber o pedido via AJAX e comunicar com a API do Gemini
require_once 'functions.php';
iniciarSessaoSegura();
header('Content-Type: application/json');

// Proteção para garantir que apenas administradores ou barbeiros autenticados podem acessar
if (!isset($_SESSION['loggedin']) && !isset($_SESSION['barbeiro_loggedin'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

// ==========================================
// INCLUIR FUNÇÕES CORE DO SISTEMA
// ==========================================
if (file_exists('functions.php')) {
    require_once 'functions.php';
} elseif (file_exists('lib/config_functions.php')) {
    if (file_exists('lib/db_functions.php')) require_once 'lib/db_functions.php';
    require_once 'lib/config_functions.php';
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'responder';

// ==========================================
// DIAGNÓSTICO: testa cada chave e reporta o erro real (somente admin)
// ==========================================
if ($action === 'testar_ia') {
    if (empty($_SESSION['loggedin'])) { echo json_encode(['success' => false, 'error' => 'Não autorizado']); exit; }
    if (!function_exists('iaDiagnostico')) { echo json_encode(['success' => false, 'error' => 'Função de diagnóstico indisponível.']); exit; }
    $rel = iaDiagnostico();
    echo json_encode(['success' => true, 'resultados' => $rel, 'total' => count($rel)]);
    exit;
}

// ==========================================
// VERIFICA SE HÁ AO MENOS UMA CHAVE DE IA (Gemini OU Groq) CONFIGURADA
// ==========================================
if (!function_exists('iaTemChaveConfigurada') || !iaTemChaveConfigurada()) {
    echo json_encode(['success' => false, 'error' => 'Nenhuma chave da Groq foi configurada no painel.']);
    exit;
}

$prompt = "";

// ==========================================
// ROTEAMENTO DE AÇÕES DA IA
// ==========================================
if ($action === 'resumo_geral') {
    try {
        $pdo = getDB();
        $stmt = $pdo->query("SELECT rating, comment FROM avaliacoes WHERE comment IS NOT NULL AND comment != '' ORDER BY timestamp DESC LIMIT 20");
        $avaliacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($avaliacoes) < 2) {
            echo json_encode(['success' => true, 'resposta' => 'Ainda não existem comentários textuais suficientes para gerar um resumo significativo.']);
            exit;
        }

        $texto_avaliacoes = "";
        foreach ($avaliacoes as $av) {
            $texto_avaliacoes .= "- Nota: {$av['rating']}/5 | Comentário: \"{$av['comment']}\"\n";
        }

        $prompt = "Aja como um consultor de negócios experiente especializado na área da estética e barbearias. Analise os seguintes feedbacks recentes deixados pelos clientes na nossa barbearia: \n\n" . 
                  $texto_avaliacoes . 
                  "\n\nFaça um resumo executivo direto (máximo de 2 parágrafos). Destaque o que os clientes mais elogiam e identifique os principais pontos de atenção ou reclamações, se existirem. Seja claro, profissional, use um tom construtivo e não invente dados, baseie-se estritamente nos comentários acima.";

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Erro ao buscar avaliações no banco de dados: ' . $e->getMessage()]);
        exit;
    }

} elseif ($action === 'raio_x_cliente') {
    $dados_cliente = $input['dados_cliente'] ?? '';
    
    $prompt = "Aja como um consultor de marketing e retenção especializado em barbearias. 
Analise o seguinte resumo do histórico e comportamento de um cliente:

$dados_cliente

Escreva um 'Raio-X Comportamental' rápido (máximo de 2 parágrafos curtos).
No primeiro parágrafo, defina o perfil do cliente de forma direta (ex: cliente muito fiel, cliente esporádico, focado em barba, alto valor gasto, em risco de abandono, etc.) e o seu padrão de visitas.
No segundo parágrafo, dê uma sugestão acionável e criativa de marketing ou abordagem para o barbeiro usar com este cliente (ex: oferecer uma promoção para o trazer de volta, sugerir um combo ou produto baseado no histórico, etc.).
Seja direto, profissional e focado em aumentar a retenção e o ticket médio.";

} elseif ($action === 'consultor_dashboard') {
    $dados_dashboard = $input['dados_dashboard'] ?? '';
    
    $prompt = "Aja como um consultor de negócios e gestor financeiro de topo, especializado no setor da beleza e barbearias.
Analise os seguintes indicadores de desempenho (KPIs) da nossa barbearia referentes ao período selecionado:

$dados_dashboard

Escreva um 'Resumo Executivo e Estratégico' direto (máximo de 2 parágrafos curtos).
No primeiro parágrafo, faça uma leitura rápida da saúde do negócio com base nestes números (elogiando o que está bem e apontando o que está abaixo do esperado, relacionando o volume de atendimentos com o ticket médio).
No segundo parágrafo, dê 1 ou 2 sugestões práticas e criativas de marketing, gestão de equipe ou precificação para otimizar os lucros no próximo mês.
Seja direto, altamente profissional e focado na rentabilidade.";

} elseif ($action === 'conselheiro_financeiro') {
    $dados_financeiros = $input['dados_financeiros'] ?? '';
    
    $prompt = "Aja como um Diretor Financeiro (CFO) e auditor especializado no setor de salões de beleza e barbearias.
Analise os seguintes dados do fechamento de caixa do nosso estabelecimento para o mês atual:

$dados_financeiros

Escreva um 'Parecer Financeiro' direto (máximo de 2 parágrafos curtos).
No primeiro parágrafo, faça uma auditoria rápida da saúde financeira: analise a margem de lucro, o peso que as comissões estão gerando sobre a receita bruta e se as despesas operacionais estão controladas.
No segundo parágrafo, dê 1 ou 2 conselhos rigorosos e práticos para enxugar custos, ajustar o repasse de comissões ou melhorar a precificação dos serviços para alavancar a margem no próximo mês.
Seja direto, profissional, realista e estritamente focado no fluxo de caixa e no controle de despesas.";

} elseif ($action === 'avaliacao_360_barbeiro') {
    $dados_barbeiro = $input['dados_barbeiro'] ?? '';
    
    $prompt = "Aja como um experiente Gerente de Recursos Humanos (RH) focado em salões de beleza e barbearias.
Você precisa preparar um feedback de desempenho rápido e objetivo para a próxima reunião de alinhamento com este profissional da nossa equipe.

Aqui estão os dados recentes dele:
$dados_barbeiro

Escreva uma 'Avaliação de Desempenho 360º' direta (máximo de 2 parágrafos curtos).
No primeiro parágrafo, elogie os pontos fortes demonstrados nos números e nos comentários dos clientes, focando no que ele está fazendo bem.
No segundo parágrafo, aponte construtivamente um ponto de atenção ou dê uma sugestão de melhoria acionável (ex: tentar vender mais produtos para aumentar o faturamento, melhorar a pontualidade se houver queixas, ou fidelizar mais se os números forem baixos).
Seja profissional, empático, motivador, mas orientado a resultados.";

} elseif ($action === 'mensagem_whatsapp_barbeiro') {
    // NOVA AÇÃO: Assistente de WhatsApp para o Barbeiro
    $nome_cliente = $input['nome_cliente'] ?? 'Cliente';
    $nome_barbeiro = $input['nome_barbeiro'] ?? 'Barbeiro';
    $motivo = $input['motivo'] ?? '';
    
    $prompt = "Aja como um assistente de comunicação premium para barbearias. 
O barbeiro chamado '$nome_barbeiro' precisa enviar uma mensagem de WhatsApp para o cliente chamado '$nome_cliente'.
O motivo/contexto da mensagem é: '$motivo'.

Escreva uma mensagem curta, muito educada, simpática e profissional para ser enviada no WhatsApp. 
Use emojis com moderação (1 ou 2). A mensagem já deve estar pronta para copiar e colar, não inclua introduções como 'Aqui está a mensagem:'.";

} elseif ($action === 'reativacao_cliente_ia') {
    $dados_cliente = $input['dados_cliente'] ?? '';
    $configGeralR = function_exists('carregarConfigGeral') ? carregarConfigGeral() : ['nome_barbearia' => 'a barbearia'];
    $nomeBarbeariaR = $configGeralR['nome_barbearia'] ?? 'a barbearia';

    $prompt = "Aja como um especialista em relacionamento e retenção de clientes de uma barbearia chamada '$nomeBarbeariaR'.
Preciso reconquistar um cliente que está há um tempo sem voltar. Dados do cliente:

$dados_cliente

Escreva UMA mensagem curta de WhatsApp para reativar este cliente: calorosa, pessoal e simpática, dizendo que sentimos a falta dele e convidando-o a voltar. Se fizer sentido, mencione sutilmente um incentivo para retornar. Use no máximo 2 emojis. A mensagem deve estar pronta para copiar e colar — não inclua introduções como 'Aqui está a mensagem:' nem aspas.";

} elseif ($action === 'briefing_dia') {
    $dados_dia = $input['dados_dia'] ?? '';

    $prompt = "Aja como um gerente operacional experiente de uma barbearia.
Com base no resumo do dia abaixo, escreva um 'briefing' matinal curto e direto para a equipe:

$dados_dia

Faça um resumo objetivo (máximo de 1 parágrafo curto ou 3 a 4 tópicos): destaque o volume de atendimentos, clientes importantes/assinantes, horário de pico, o que exige atenção (estoque a repor, pendências) e oportunidades do dia (ex.: aniversariantes). Tom prático e motivador. Não invente dados — baseie-se estritamente no que foi informado.";

} elseif ($action === 'gerar_descricao_item') {
    $nome_item = $input['nome_item'] ?? '';
    $tipo_item = $input['tipo_item'] ?? 'serviço';
    $contexto_item = $input['contexto_item'] ?? '';

    $prompt = "Aja como um copywriter especializado em barbearias.
Escreva uma descrição de venda curta e atraente (1 a 2 frases, no máximo 200 caracteres) para o seguinte $tipo_item que será exibido no site da barbearia:

Nome: $nome_item
$contexto_item

A descrição deve ser envolvente, destacar o benefício/experiência para o cliente e incentivar a escolha. Escreva em português do Brasil, tom moderno e profissional. Responda APENAS com o texto da descrição, sem aspas e sem introduções.";

} elseif ($action === 'relatorio_executivo') {
    $dados_relatorio = $input['dados_relatorio'] ?? '';

    $prompt = "Aja como um consultor de negócios sênior do setor de barbearias.
Analise os indicadores do período abaixo e escreva um 'Resumo Executivo':

$dados_relatorio

Escreva no máximo 2 parágrafos curtos. No primeiro, aponte os destaques positivos e os principais riscos/pontos de atenção do período. No segundo, dê de 2 a 3 recomendações práticas e priorizadas para o próximo período. Seja direto, profissional e focado em rentabilidade e crescimento. Não invente dados.";

} else {
    // AÇÃO PADRÃO: RESPONDER A UMA ÚNICA AVALIAÇÃO
    
    // Puxa o nome real da barbearia dinamicamente das configurações
    $configGeral = function_exists('carregarConfigGeral') ? carregarConfigGeral() : ['nome_barbearia' => 'Nossa Barbearia'];
    $nome_barbearia = $configGeral['nome_barbearia'] ?? 'Nossa Barbearia';

    // Aceita as variáveis tanto do formato antigo quanto do novo modal
    $rating = $input['nota'] ?? $input['rating'] ?? '';
    $comment = $input['comentario'] ?? $input['comment'] ?? '';
    
    $texto_comentario = empty($comment) ? "O cliente não deixou nenhum comentário escrito, apenas a nota." : "Comentário do cliente: \"$comment\"";

    $prompt = "Aja como o gerente simpático e profissional de uma barbearia chamada '$nome_barbearia'.
O cliente deixou a seguinte avaliação com nota de $rating de 5 estrelas.
$texto_comentario

Escreva uma resposta curta (máximo de 2 parágrafos) agradecendo o feedback.
Se a nota for 5 ou 4, agradeça a preferência de forma empática e diga que esperamos vê-lo novamente.
Se a nota for 3 ou inferior, peça desculpas de forma profissional, diga que valorizamos o feedback para melhorar os serviços e nos colocamos à disposição para resolver o problema.
Aja com naturalidade, seja cordial e use emojis com moderação (máximo de 2).
Responda APENAS com o texto da resposta que será enviada ao cliente, sem aspas adicionais e sem explicações.";
}

// ==========================================
// CHAMADA À IA COM FAILOVER (Groq + Gemini, múltiplas chaves)
// ==========================================
$resultadoIA = chamarIAComFallback($prompt);
if (!empty($resultadoIA['success'])) {
    echo json_encode(['success' => true, 'resposta' => trim($resultadoIA['resposta']), 'provedor' => $resultadoIA['provedor'] ?? '']);
} else {
    echo json_encode(['success' => false, 'error' => $resultadoIA['error'] ?? 'A IA (Groq) está indisponível no momento.']);
}
?>
