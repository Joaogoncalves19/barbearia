<?php
// lib/config_functions.php
// Contém todas as funções para carregar e salvar os arquivos de configuração do sistema (Atualizado para SQLite).

/**
 * Helper interno para ler configurações do banco de dados SQLite.
 * Salva e lê em formato JSON dentro do banco para preservar perfeitamente os tipos (int, bool, string).
 * @param string $secao Nome da seção de configuração.
 * @param array $defaults Array de valores padrão.
 * @return array
 */
function _lerConfigSQLite($secao, $defaults) {
    if (!function_exists('getDB')) return $defaults;
    $pdo = getDB();
    try {
        // Cria a tabela genérica de configurações se não existir
        $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (
            secao TEXT PRIMARY KEY,
            dados_json TEXT
        )");

        $stmt = $pdo->prepare("SELECT dados_json FROM configuracoes WHERE secao = ?");
        $stmt->execute([$secao]);
        
        if ($row = $stmt->fetch()) {
            $decoded = json_decode($row['dados_json'], true);
            if (is_array($decoded)) {
                return array_merge($defaults, $decoded);
            }
        }

        // Se não existir, insere os padrões iniciais no banco
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO configuracoes (secao, dados_json) VALUES (?, ?)");
        $stmt->execute([$secao, json_encode($defaults, JSON_UNESCAPED_UNICODE)]);
        
        return $defaults;
    } catch (PDOException $e) {
        if (function_exists('log_activity')) {
            log_activity("FALHA: Erro ao ler config $secao do SQLite. Erro: " . $e->getMessage());
        }
        return $defaults;
    }
}

/**
 * Helper interno para salvar configurações no banco de dados SQLite.
 * @param string $secao Nome da seção de configuração.
 * @param array $data O array de dados a ser salvo.
 */
function _salvarConfigSQLite($secao, $data) {
    if (!function_exists('getDB')) return;
    $pdo = getDB();
    try {
        // Garante que a tabela exista antes de salvar
        $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (
            secao TEXT PRIMARY KEY,
            dados_json TEXT
        )");
        
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO configuracoes (secao, dados_json) VALUES (?, ?)");
        $stmt->execute([$secao, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    } catch (PDOException $e) {
        if (function_exists('log_activity')) {
            log_activity("FALHA: Erro ao salvar config $secao no SQLite. Erro: " . $e->getMessage());
        }
    }
}

/**
 * ATUALIZADO: Mantido por compatibilidade caso algum arquivo externo antigo chame esta função diretamente.
 * @param string $configFile Caminho completo para o arquivo
 * @param array $defaults Array de valores padrão
 * @return array
 */
function _lerConfigSeguroINI($configFile, $defaults) {
    $secao = basename($configFile);
    $secao = str_replace(['.txt', '.ini', '.json'], '', $secao);
    return _lerConfigSQLite($secao, $defaults);
}

/**
 * ATUALIZADO: Carrega as configurações de E-mail (SMTP) de forma segura no SQLite.
 * @return array
 */
function carregarConfigEmail() {
    $defaults = [
        'host' => 'smtp.gmail.com',
        'username' => '',
        'password' => '',
        'port' => 465,
        'smtp_secure' => 'ssl',
        'nome_remetente' => 'Barbearia'
    ];
    return _lerConfigSQLite('config_email', $defaults); 
}

/**
 * ATUALIZADO: Carrega as configurações da API do Google Gemini (Inteligência Artificial) do SQLite.
 * @return array
 */
function carregarConfigGemini() {
    $defaults = [
        'api_key' => ''
    ];
    return _lerConfigSQLite('config_gemini', $defaults);
}

/**
 * Configuração do Chatbot do site. Funciona por regras (sem IA) por padrão;
 * a IA (Gemini/Groq) é opcional e usada como reforço, com failover entre
 * várias chaves. As chaves são gerenciadas no painel de Configurações.
 */
/**
 * Configuração dos lembretes de agendamento (véspera e "algumas horas antes").
 */
function carregarConfigLembretes() {
    $defaults = [
        'dia_antes_ativo'  => 1,   // envia lembrete no dia anterior
        'dia_hora_envio'   => 9,   // hora (0-23) do disparo automático da véspera
        'hora_antes_ativo' => 1,   // envia lembrete algumas horas antes do horário
        'horas_antes'      => 2,   // quantas horas antes (1-24)
    ];
    $cfg = _lerConfigSQLite('config_lembretes', $defaults);
    // Normaliza tipos/limites.
    $cfg['dia_antes_ativo']  = !empty($cfg['dia_antes_ativo']) ? 1 : 0;
    $cfg['hora_antes_ativo'] = !empty($cfg['hora_antes_ativo']) ? 1 : 0;
    $cfg['dia_hora_envio']   = max(0, min(23, (int)$cfg['dia_hora_envio']));
    $cfg['horas_antes']      = max(1, min(24, (int)$cfg['horas_antes']));
    return $cfg;
}

/**
 * URL base absoluta e confiável do site (com barra final). Em contexto web com
 * host real, persiste o valor; no CLI/cron (sem HTTP_HOST) usa o valor salvo.
 * Necessário para montar links em e-mails disparados pelo cron.
 */
function siteUrl() {
    $atual = defined('BASE_URL') ? BASE_URL : '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $ehWebReal = (php_sapi_name() !== 'cli') && $host !== ''
        && stripos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false;
    $cfg = _lerConfigSQLite('config_site', ['site_url' => '']);
    if ($ehWebReal && $atual !== '') {
        if (($cfg['site_url'] ?? '') !== $atual) _salvarConfigSQLite('config_site', ['site_url' => $atual]);
        return $atual;
    }
    return !empty($cfg['site_url']) ? $cfg['site_url'] : $atual;
}

/**
 * Token secreto para acionar rotinas via URL (ex.: cron_lembretes.php pela web).
 * Gera e persiste na primeira chamada. Uso via CLI não exige token.
 */
function getCronToken() {
    $cfg = _lerConfigSQLite('config_cron', ['token' => '']);
    $token = trim((string)($cfg['token'] ?? ''));
    if ($token === '') {
        try { $token = bin2hex(random_bytes(16)); } catch (Exception $e) { $token = md5(uniqid('', true)); }
        if (function_exists('_salvarConfigSQLite')) _salvarConfigSQLite('config_cron', ['token' => $token]);
    }
    return $token;
}

function carregarConfigChatbot() {
    $defaults = [
        'ativo'          => 1,   // exibe o chatbot no site
        'modo_ia'        => 0,   // usa IA (Groq) como reforço quando não houver resposta por regras
        'modo_agente'    => 1,   // com IA ligada, a IA EXECUTA ações (agendar/cancelar/etc). 0 = só responde
        'exigir_login_visitante' => 0, // 1 = visitante precisa entrar antes de conversar por texto livre
        'provedor_ordem' => 'groq', // Groq é o primário; Cerebras é fallback quando a Groq limita (429/TPM)
        'groq_keys'      => '',  // uma chave por linha
        'groq_modelo'    => 'openai/gpt-oss-20b',
        'gemini_keys'    => '',  // fallback grátis (Gemini via API compatível c/ OpenAI) — uma chave por linha; se vazio, usa a chave legada config_gemini.api_key
        'gemini_modelo'  => 'gemini-flash-lite-latest', // alias estável, free-tier, suporta function-calling
        'saudacao'       => '',  // mensagem inicial personalizada (opcional)
    ];
    return _lerConfigSQLite('config_chatbot', $defaults);
}

/**
 * ATUALIZADO: Carrega as configurações do programa de Fidelidade de forma segura no SQLite.
 * @return array
 */
function getFidelityConfig() {
    $defaults = [
        'ativado' => 1,
        'pontos_necessarios' => 10,
        'desconto_percentual' => 50,
        // --- Regras de ganho de pontos ---
        'modo_ganho' => 'visita',        // 'visita' = pontos fixos por atendimento | 'valor' = por valor gasto
        'pontos_por_visita' => 1,        // pontos ganhos por atendimento concluído (modo 'visita')
        'real_por_ponto' => 0,           // no modo 'valor': ganha 1 ponto a cada R$ deste valor
        // --- Regras de recompensa (resgate) ---
        'tipo_recompensa' => 'percentual', // 'percentual' | 'valor_fixo' | 'servico_gratis'
        'base_desconto' => 'mais_barato',  // onde o % incide: 'mais_barato' | 'mais_caro' | 'total'
        'valor_desconto_fixo' => 0,        // desconto em R$ quando tipo_recompensa = 'valor_fixo'
    ];
    return _lerConfigSQLite('fidelidade_config', $defaults);
}

/**
 * ATUALIZADO: Carrega as configurações da promoção de Aniversário de forma segura no SQLite.
 * @return array
 */
function getAniversarioConfig() { 
    $defaults = [ 'ativado' => 0, 'desconto_percentual' => 15 ]; 
    return _lerConfigSQLite('config_aniversario', $defaults); 
}

/**
 * ATUALIZADO: Carrega as configurações do programa de Indicação de forma segura no SQLite.
 * @return array
 */
function getIndicacaoConfig() { 
    $defaults = [ 'ativado' => 0, 'pontos_indicacao' => 1, 'desconto_novo_cliente' => 10 ]; 
    return _lerConfigSQLite('config_indicacao', $defaults); 
}

/**
 * ATUALIZADO: Carrega as configurações Gerais da barbearia de forma segura no SQLite.
 * @return array
 */
function carregarConfigGeral() {
    $defaults = [
        'nome_barbearia' => 'Sua Barbearia',
        'telefone_contato' => '(00) 00000-0000',
        'endereco' => 'Rua Exemplo, 123 - Centro, Sua Cidade',
        'link_instagram' => '',
        'link_facebook' => '',
        'link_whatsapp' => '',
        'logo_path' => 'uploads/logo.png',
        'header_slogan' => '',
        'header_logo_format' => 'auto'
    ];
    return _lerConfigSQLite('config_geral', $defaults);
}

/**
 * ATUALIZADO: Carrega as configurações de regras de Agendamento de forma segura no SQLite.
 * @return array
 */
function carregarConfigAgendamento() {
    $defaults = [
        'antecedencia_minima_minutos' => 120,
        'antecedencia_maxima' => 30,
        'max_servicos' => 4,
        'notif_confirmacao' => 1,
        'notif_aprovacao' => 1,
        'notif_lembrete' => 1
    ];
    return _lerConfigSQLite('config_agendamento', $defaults);
}

/**
 * ATUALIZADO: Carrega os textos e configurações da Landing Page de forma segura no SQLite.
 * @return array
 */
function carregarLandingPageConfig() {
    $defaults = [
        "hero_title" => "Barbearia Fictícia",
        "hero_subtitle" => "Agende seu horário com os melhores profissionais da região e viva uma experiência premium.",
        "hero_cta_button" => "Agendar Agora",
        "hero_secondary_button" => "Conheça nossos Barbeiros",
        "about_title" => "Nossa História",
        "about_text" => "Somos especialistas em cortes clássicos e modernos, além de cuidados completos com a barba. Nossa missão é elevar sua autoestima em um ambiente descontraído e confortável.",
        "stat1_number" => "1500",
        "stat1_label" => "Clientes Satisfeitos",
        "stat2_number" => "10",
        "stat2_label" => "Anos de Experiência",
        "stat3_number" => "5000",
        "stat3_label" => "Cortes Realizados",
        "services_title" => "Nossos Serviços",
        "team_title" => "Nossa Equipe",
        "featured_title" => "Barbeiro em Destaque",
        "featured_subtitle" => "Nosso profissional com a maior média de avaliações até o momento.",
        "testimonials_title" => "O que dizem nossos clientes",
        "cta_title" => "Pronto para o Próximo Nível?",
        "cta_text" => "Sua cadeira está esperando por você. Transforme seu visual e sua confiança hoje mesmo.",
        "cta_button" => "Agendar Meu Horário",
        "contact_title" => "Venha nos Visitar",
        "terms_of_use" => "<h3>1. Aceitação dos Termos...</h3><p>Texto padrão dos termos de uso.</p>",
        "privacy_policy" => "<h3>1. Política de Privacidade...</h3><p>Texto padrão da política de privacidade.</p>",
        "hero_video_path" => "uploads/bg_video.mp4" // VÍDEO PADRÃO APLICADO AQUI!
    ];

    return _lerConfigSQLite('landing_page', $defaults);
}

/**
 * ATUALIZADO: Salva as configurações da Landing Page de forma segura no SQLite.
 * @param array $data O array de dados a ser salvo.
 */
function salvarLandingPageConfig($data) {
    // Garante que a cor dourada não seja salva (mantido do original)
    if (isset($data['gold_accent_color'])) {
        unset($data['gold_accent_color']);
    }
    
    foreach($data as &$value) {
        // Remove barras invertidas que podem ter sido adicionadas por formulários
        $value = stripslashes($value);
    }
    
    _salvarConfigSQLite('landing_page', $data);
}
?>
