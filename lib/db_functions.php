<?php
// lib/db_functions.php
// Contém as funções principais de interação com o banco de dados SQLite.

// Define o caminho do banco de dados SQLite (utilizando a constante DATA_DIR já existente)
if (!defined('SQLITE_DB_PATH')) {
    define('SQLITE_DB_PATH', DATA_DIR . 'database.sqlite');
}

// Migrations centralizadas do schema (substituem os ALTER TABLE espalhados).
require_once __DIR__ . '/migrations.php';

/**
 * Retorna a conexão PDO com o banco de dados SQLite
 * Garante que apenas uma conexão seja aberta por requisição (Singleton)
 */
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            // Cria ou abre o arquivo database.sqlite no diretório de dados
            $pdo = new PDO('sqlite:' . SQLITE_DB_PATH);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if (function_exists('log_activity')) {
                log_activity("FALHA CRÍTICA: Erro ao conectar ao banco de dados SQLite: " . $e->getMessage());
            }
            die("Erro interno: Não foi possível conectar ao banco de dados.");
        }

        // Aplica migrations pendentes uma única vez por conexão (idempotente e
        // barata: não faz nada se o schema já está na versão-alvo).
        if (function_exists('aplicarMigracoes')) {
            aplicarMigracoes($pdo);
        }
    }
    return $pdo;
}

/**
 * LÊ DADOS DO SQLITE
 * Lê dados de uma tabela SQLite de forma segura, mantendo a compatibilidade com a estrutura antiga.
 * @param string $fileName O nome do arquivo original (ex: 'barbeiros.txt' será convertido para a tabela 'barbeiros').
 * @param array $keys Um array com os nomes das colunas (ex: ['id', 'nome', 'foto']).
 * @return array Um array associativo onde a chave é o ID (primeira coluna).
 */
function lerDados($fileName, $keys) {
    $pdo = getDB();
    // Deduz o nome da tabela removendo a extensão .txt e aplicando regex para garantir segurança contra SQL Injection no nome da tabela
    $tableNameRaw = str_replace('.txt', '', $fileName);
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableNameRaw);
    
    try {
        // Prepara os nomes das colunas, evitando qualquer injeção nas chaves
        $columns = implode(', ', array_map(function($key) {
            return preg_replace('/[^a-zA-Z0-9_]/', '', $key);
        }, $keys));
        
        $stmt = $pdo->query("SELECT $columns FROM $tableName");
        $resultados = $stmt->fetchAll();
        
        $dados = [];
        foreach ($resultados as $row) {
            // A primeira chave passada no array $keys funciona como o ID (índice do array de retorno)
            $idKey = $keys[0]; 
            if (isset($row[$idKey]) && $row[$idKey] !== '') {
                // Monta o item garantindo que apenas as chaves solicitadas sejam retornadas
                $item = [];
                foreach ($keys as $key) {
                    $item[$key] = $row[$key] ?? '';
                }
                $dados[$row[$idKey]] = $item;
            }
        }
        return $dados;
    } catch (PDOException $e) {
        // Se a tabela não existir ainda ou houver outro erro, retorna array vazio (comportamento original)
        if (function_exists('log_activity')) {
            log_activity("AVISO: Falha ao ler dados da tabela $tableName (Pode não existir ainda). Erro: " . $e->getMessage());
        }
        return [];
    }
}

/**
 * Lê a tabela de anotações e retorna um array [cliente_id => anotacao].
 * @return array
 */
function lerAnotacoesTodosClientes() { 
    $anotacoesRaw = lerDados('anotacoes_clientes.txt', ['id', 'anotacao']);
    
    $anotacoes = [];
    foreach ($anotacoesRaw as $id => $data) {
        if(isset($data['anotacao'])) {
            $anotacoes[$id] = $data['anotacao'];
        }
    }
    return $anotacoes;
}

/**
 * SALVA ANOTAÇÃO DO CLIENTE NO SQLITE
 * Salva (ou atualiza) a anotação de um cliente específico.
 * @param string $cliente_id ID do cliente.
 * @param string $anotacao Texto da anotação.
 */
function salvarAnotacaoCliente($cliente_id, $anotacao) { 
    $pdo = getDB();
    $tableName = 'anotacoes_clientes'; 
    $anotacaoLimpa = str_replace(["\r", "\n"], ' ', $anotacao);
    
    try {
        if (empty($anotacaoLimpa)) {
            // Se a anotação estiver vazia, remove a entrada
            $stmt = $pdo->prepare("DELETE FROM $tableName WHERE id = ?");
            $stmt->execute([$cliente_id]);
        } else {
            // Verifica se a anotação já existe
            $stmt = $pdo->prepare("SELECT id FROM $tableName WHERE id = ?");
            $stmt->execute([$cliente_id]);
            
            if ($stmt->fetch()) {
                // Atualiza se existir
                $updateStmt = $pdo->prepare("UPDATE $tableName SET anotacao = ? WHERE id = ?");
                $updateStmt->execute([$anotacaoLimpa, $cliente_id]);
            } else {
                // Insere se não existir
                $insertStmt = $pdo->prepare("INSERT INTO $tableName (id, anotacao) VALUES (?, ?)");
                $insertStmt->execute([$cliente_id, $anotacaoLimpa]);
            }
        }
    } catch (PDOException $e) {
        if (function_exists('log_activity')) {
            log_activity("FALHA: Erro ao salvar anotação do cliente $cliente_id. Erro: " . $e->getMessage());
        }
    }
}

?>