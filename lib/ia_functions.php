<?php
// lib/ia_functions.php
// Camada de IA: Groq como provedor primário e Gemini como fallback grátis (ambos
// via API compatível com OpenAI), com múltiplas chaves (rodízio/failover). Se uma
// chave falhar/esgotar (429/TPM), tenta a próxima chave e, por fim, o Gemini.
// As chaves vêm das configurações do painel (Configurações → Inteligência Artificial).

/**
 * Reúne as chaves da Groq (uma por linha), removendo vazias/duplicadas.
 */
function _iaColetarChaves($provedor = 'groq', $rodizio = false) {
    // $provedor define de qual campo lemos as chaves: 'groq' (primário) ou
    // 'gemini' (fallback grátis). Ambos usam API compatível com OpenAI.
    $cfg = function_exists('carregarConfigChatbot') ? carregarConfigChatbot() : [];
    $campo = $provedor . '_keys'; // 'groq_keys' | 'gemini_keys'
    $raw = (string)($cfg[$campo] ?? '');
    // Compat: se não houver gemini_keys, reaproveita a chave Gemini legada (config_gemini.api_key).
    if ($raw === '' && $provedor === 'gemini' && function_exists('_lerConfigSQLite')) {
        $leg = _lerConfigSQLite('config_gemini', ['api_key' => '']);
        $raw = (string)($leg['api_key'] ?? '');
    }
    $chaves = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $k) {
        $k = trim($k); if ($k !== '') $chaves[] = $k;
    }
    $chaves = array_values(array_unique($chaves));
    // Rodízio (round-robin): cada requisição começa numa chave diferente, para
    // ESPALHAR a carga entre as chaves em vez de sempre martelar a primeira.
    // Depois disso, o failover em ordem continua (se a escolhida falhar, tenta a próxima).
    if ($rodizio && count($chaves) > 1) {
        $off = _iaProximoIndiceRodizio(count($chaves));
        $chaves = array_merge(array_slice($chaves, $off), array_slice($chaves, 0, $off));
    }
    return $chaves;
}

/**
 * Contador persistente (arquivo com lock) para o rodízio round-robin das chaves.
 * Se não for possível persistir, cai para um início aleatório (também distribui).
 * @return int índice inicial em [0, $n-1]
 */
function _iaProximoIndiceRodizio($n) {
    if ($n <= 1) return 0;
    $arquivo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'barbearia_groq_rr.txt';
    $fp = @fopen($arquivo, 'c+');
    if ($fp) {
        $idx = 0;
        if (flock($fp, LOCK_EX)) {
            $atual = (int) trim((string) fgets($fp));
            $idx = $atual % $n;
            rewind($fp); ftruncate($fp, 0);
            fwrite($fp, (string) (($atual + 1) % 1000000000));
            fflush($fp); flock($fp, LOCK_UN);
        }
        fclose($fp);
        return $idx;
    }
    try { return random_int(0, $n - 1); } catch (Exception $e) { return 0; }
}

/** Endpoint (chat/completions) do provedor. Ambos compatíveis com OpenAI. */
function _iaEndpoint($provedor = 'groq') {
    if ($provedor === 'gemini') return 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions';
    return 'https://api.groq.com/openai/v1/chat/completions';
}

/** Modelo configurado para o provedor. */
function _iaModelo($provedor, $cfg) {
    if ($provedor === 'gemini') return $cfg['gemini_modelo'] ?? 'gemini-flash-lite-latest';
    return $cfg['groq_modelo'] ?? 'openai/gpt-oss-20b';
}

/**
 * Chamada única a um provedor (API compatível com OpenAI). Retorna [ok, textoOuErro].
 * $prompt pode ser uma string (mensagem única) OU um array de mensagens no
 * formato do Chat Completions ([['role'=>'system','content'=>...], ...]),
 * permitindo conversas com memória (histórico) e prompt de sistema. $url permite
 * apontar para outro provedor (ex.: Gemini) sem duplicar código.
 */
function _iaChamarGroq($chave, $prompt, $modelo, $url = null) {
    $url = $url ?: _iaEndpoint('groq');
    $messages = is_array($prompt) ? array_values($prompt) : [['role' => 'user', 'content' => (string)$prompt]];
    $payload = [
        'model' => $modelo ?: 'openai/gpt-oss-20b',
        'messages' => $messages,
        'temperature' => 0.4,
        'max_tokens' => 700,
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . trim($chave)],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 25,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) return [false, 'conexao'];
    if ($code === 200) {
        $j = json_decode($resp, true);
        $txt = $j['choices'][0]['message']['content'] ?? '';
        if (trim($txt) !== '') return [true, trim($txt)];
        return [false, 'formato'];
    }
    return [false, ($code === 429 ? 'quota' : 'erro_' . $code)];
}

/**
 * Envia um prompt à IA (Groq) com rodízio automático entre as chaves.
 * $prompt pode ser uma string OU um array de mensagens (system/user/assistant)
 * para conversas com memória — ver _iaChamarGroq().
 * @return array ['success'=>bool, 'resposta'=>string, 'provedor'=>'groq'] | ['success'=>false, 'error'=>string]
 */
function chamarIAComFallback($prompt) {
    $cfg = function_exists('carregarConfigChatbot') ? carregarConfigChatbot() : [];

    $tentativas = 0;
    // Groq (primário) e, ao esgotar/limitar, Gemini (fallback grátis).
    foreach (['groq', 'gemini'] as $prov) {
        $modelo = _iaModelo($prov, $cfg);
        $url = _iaEndpoint($prov);
        foreach (_iaColetarChaves($prov, true) as $chave) { // true = rodízio round-robin
            $tentativas++;
            [$ok, $res] = _iaChamarGroq($chave, $prompt, $modelo, $url);
            if ($ok) return ['success' => true, 'resposta' => $res, 'provedor' => $prov];
            // qualquer erro (quota/chave/conexão) → tenta a próxima chave/provedor
        }
    }
    return ['success' => false, 'error' => ($tentativas === 0 ? 'Nenhuma chave de IA configurada.' : 'A IA está indisponível no momento.')];
}

/**
 * Traduz o código de erro interno em texto legível para o painel.
 */
function _iaTraduzErro($cod) {
    if (strpos($cod, 'erro_') === 0) {
        $http = substr($cod, 5);
        $map = [
            '400' => 'Requisição inválida (400) — modelo ou formato incorreto',
            '401' => 'Chave inválida ou não autorizada (401)',
            '403' => 'Acesso negado (403) — chave sem permissão/faturamento',
            '404' => 'Modelo não encontrado (404) — nome do modelo errado',
            '429' => 'Limite/quota atingido (429)',
            '500' => 'Erro no servidor do provedor (500)',
            '503' => 'Provedor indisponível (503)',
        ];
        return $map[$http] ?? ('Erro HTTP ' . $http);
    }
    $t = ['conexao' => 'Falha de conexão (sem internet/firewall)', 'quota' => 'Limite/quota atingido (429)', 'formato' => 'Resposta em formato inesperado'];
    return $t[$cod] ?? $cod;
}

/**
 * Testa TODAS as chaves Groq configuradas e devolve um relatório por chave.
 * @return array lista de ['provedor','chave','modelo','ok'(bool),'detalhe']
 */
function iaDiagnostico() {
    $cfg = function_exists('carregarConfigChatbot') ? carregarConfigChatbot() : [];
    $prompt = 'Responda apenas: OK';
    $rel = [];
    foreach (['groq', 'gemini'] as $prov) {
        $modelo = _iaModelo($prov, $cfg);
        $url = _iaEndpoint($prov);
        foreach (_iaColetarChaves($prov) as $chave) {
            [$ok, $res] = _iaChamarGroq($chave, $prompt, $modelo, $url);
            $mask = strlen($chave) > 10 ? substr($chave, 0, 6) . '…' . substr($chave, -4) : '••••';
            $rel[] = [
                'provedor' => $prov, 'chave' => $mask, 'modelo' => $modelo,
                'ok' => $ok, 'detalhe' => $ok ? 'Funcionando' : _iaTraduzErro($res),
            ];
        }
    }
    return $rel;
}

/**
 * Indica se há ao menos uma chave de IA configurada (Groq primário OU Gemini
 * fallback). Assim, as funções de IA do painel funcionam mesmo que só um dos
 * provedores esteja com chave.
 */
function iaTemChaveConfigurada() {
    return count(_iaColetarChaves('groq')) > 0 || count(_iaColetarChaves('gemini')) > 0;
}
