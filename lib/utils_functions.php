<?php
// lib/utils_functions.php
// Contém funções utilitárias gerais usadas em todo o sistema.

/**
 * Gera um identificador único para registros.
 * Usa random_bytes (imprevisível e sem colisão sob concorrência), diferente de
 * uniqid(), que é baseado em microtime e pode colidir em requisições simultâneas.
 *
 * @param string $prefixo Prefixo opcional mantido para compatibilidade (ex.: 'av-').
 * @return string
 */
function gerarId($prefixo = '') {
    try {
        $rand = bin2hex(random_bytes(9)); // 18 chars hex
    } catch (Exception $e) {
        // Fallback extremamente raro (falta de fonte de entropia)
        $rand = bin2hex(pack('NnN', time(), mt_rand(0, 0xffff), mt_rand()));
    }
    return $prefixo . $rand;
}

/**
 * Registra uma mensagem no arquivo de log global.
 * Nota: Os logs de sistema permanecem em formato de texto para segurança, 
 * mesmo com o uso do SQLite no restante do sistema.
 * @param string $message A mensagem a ser registrada.
 */
function log_activity($message) {
    if (!defined('LOG_FILE')) {
        // Fallback caso a constante não esteja definida
        define('LOG_FILE', __DIR__ . '/../_logs/app_log.txt');
    }
    
    if (!file_exists(dirname(LOG_FILE))) {
        mkdir(dirname(LOG_FILE), 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$timestamp] $message\n", FILE_APPEND);
}

/**
 * Retorna a abreviação do mês (3 letras) em português, sem depender do
 * setlocale/strftime (removido/obsoleto no PHP 8.1+).
 * @param int $mes Número do mês (1-12).
 * @return string Ex.: 'jan', 'fev', ...
 */
function mesAbrevPt($mes) {
    static $meses = [1=>'jan',2=>'fev',3=>'mar',4=>'abr',5=>'mai',6=>'jun',
                     7=>'jul',8=>'ago',9=>'set',10=>'out',11=>'nov',12=>'dez'];
    $mes = (int)$mes;
    return $meses[$mes] ?? '';
}

/**
 * Remove todos os caracteres não numéricos de uma string.
 * @param string $telefone O número de telefone ou CPF a ser limpo.
 * @return string A string contendo apenas números.
 */
function limparTelefone($telefone) {
    return preg_replace('/\D/', '', $telefone);
}

/**
 * Valida um número de CPF brasileiro.
 * @param string $cpf O CPF (pode conter máscara).
 * @return bool True se o CPF for válido, False caso contrário.
 */
function validarCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/is', '', $cpf);
    if (strlen($cpf) != 11) { return false; }
    if (preg_match('/(\d)\1{10}/', $cpf)) { return false; }
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) {
            return false;
        }
    }
    return true;
}

/**
 * Obtém os detalhes dos serviços de um combo.
 * @param array $combo O array do combo.
 * @param array $servicosArr O array de todos os serviços oriundos do SQLite.
 * @return array Um array contendo os serviços do combo.
 */
function getServicesFromCombo($combo, $servicosArr) { 
    $services = []; 
    if (!isset($combo['servicos_ids'])) return $services; 
    $service_ids = explode(',', $combo['servicos_ids']); 
    foreach ($service_ids as $sid) { 
        $sid = trim($sid); 
        if (isset($servicosArr[$sid])) { 
            $services[] = $servicosArr[$sid]; 
        } 
    } 
    return $services; 
}
/**
 * Versiona um asset local pelo mtime do arquivo, para cache-busting.
 *
 * Antes cada pagina carregava um "?v=20260824c" escrito a mao. Isso divergiu:
 * havia 5 datas diferentes em uso e 12 assets sem versao nenhuma — entao um
 * deploy podia entregar JS novo com CSS velho (ou o inverso) ao usuario que ja
 * tinha o arquivo em cache. Derivando do mtime, cada arquivo se versiona
 * sozinho e ninguem precisa lembrar de atualizar nada.
 *
 * NAO usar em css/custom_theme.css.php: o conteudo dele vem do banco, nao do
 * arquivo, e ele ja resolve o cache com no-cache + ETag proprio.
 *
 * @param  string $caminho Caminho relativo a raiz do app (ex.: 'css/style.css').
 * @return string O mesmo caminho com ?v=<mtime>, ou sem sufixo se o arquivo sumir.
 */
function assetUrl($caminho) {
    static $cache = [];
    if (isset($cache[$caminho])) {
        return $cache[$caminho];
    }
    $semQuery = preg_replace('/\?.*$/', '', $caminho);
    $absoluto = __DIR__ . '/../' . ltrim($semQuery, '/');
    $mtime    = is_file($absoluto) ? (int) @filemtime($absoluto) : 0;
    return $cache[$caminho] = $semQuery . ($mtime ? '?v=' . $mtime : '');
}

/**
 * URL base do app, INCLUINDO a subpasta em que ele esta instalado.
 *
 * Antes o $baseUrl do index.php era so esquema://host, o que quebrava
 * qualquer instalacao em subpasta: og:image apontava para /uploads/logo.png
 * na raiz do dominio (404), e o preview de link no WhatsApp/Facebook saia
 * sem imagem. Deriva do SCRIPT_NAME, entao funciona na raiz e em subpasta.
 *
 * @return string Ex.: 'https://site.com' ou 'https://site.com/barbearia'.
 *                Sem barra no fim. String vazia se o host for desconhecido.
 */
function appBaseUrl() {
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    if ($host === '') {
        return '';
    }
    $esquema = (function_exists('requisicaoEhHttps') && requisicaoEhHttps()) ? 'https' : 'http';
    $script  = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/';
    // Recorta ate a ultima barra em vez de usar dirname(): SCRIPT_NAME sempre
    // usa barra normal, e o dirname() do Windows pode devolver barra invertida.
    $corte   = strrpos($script, '/');
    $dir     = ($corte === false) ? '' : substr($script, 0, $corte);
    $dir     = rtrim($dir, '/'); // '' na raiz, '/subpasta' em subpasta
    return $esquema . '://' . $host . $dir;
}

/**
 * Renderiza uma imagem servindo a versao .webp quando existir um irmao de mesmo
 * nome ao lado do arquivo original.
 *
 * Serve para imagens cujo caminho vem do BANCO (ex.: config_geral.logo_path).
 * Trocar a extensao no banco quebraria assim que a barbearia subisse um logo
 * novo em PNG; com <picture>, o navegador pega o .webp se existir e cai no
 * arquivo original caso contrario -- sem tocar em dado nenhum.
 *
 * @param string $src     Caminho relativo a raiz do app (ex.: 'uploads/logo.png').
 * @param array  $attrs   Atributos extras da tag img (class, alt, width, ...).
 * @return string HTML pronto.
 */
function imgComWebp($src, array $attrs = []) {
    $src   = ltrim((string) $src, '/');
    $raiz  = __DIR__ . '/../';
    $webp  = preg_replace('/\.(png|jpe?g)$/i', '.webp', $src);

    $html = '';
    foreach ($attrs as $k => $v) {
        $html .= ' ' . htmlspecialchars($k) . '="' . htmlspecialchars((string) $v) . '"';
    }
    $img = '<img src="' . htmlspecialchars($src) . '"' . $html . '>';

    if ($webp === $src || !is_file($raiz . $webp)) {
        return $img;
    }
    return '<picture><source srcset="' . htmlspecialchars($webp) . '" type="image/webp">'
         . $img . '</picture>';
}

?>