<?php
header('Content-Type: application/json');

// Habilitar log de erros para depuração
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Criar arquivo de log
$logFile = __DIR__ . '/webhook.log';

function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    
    // Garantir que o diretório de logs exista
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    // Verificar se o arquivo pode ser escrito
    if (!is_writable($logFile) && file_exists($logFile)) {
        error_log("Arquivo de log não é gravável: $logFile");
        return false;
    }
    
    // Tentativa de escrever no arquivo
    $result = file_put_contents($logFile, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
    
    // Registrar também com error_log padrão para garantir que a mensagem não seja perdida
    error_log("WEBHOOK: $message");
    
    return $result !== false;
}

// Carregar configurações do Supabase
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/WhatsAppHandler.php';

try {
    // Receber dados do webhook
    $rawData = file_get_contents('php://input');
    logMessage("Dados recebidos: $rawData");

    if (empty($rawData)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nenhum dado recebido']);
        logMessage("Erro: Nenhum dado recebido");
        exit;
    }

    // Decodificar JSON
    $webhookData = json_decode($rawData, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'JSON inválido: ' . json_last_error_msg()]);
        logMessage("Erro: JSON inválido: " . json_last_error_msg());
        exit;
    }

    // Verificar se temos o nome da instância e evento
    if (empty($webhookData['instance']) || empty($webhookData['event'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Dados incompletos: instância ou evento não especificados']);
        logMessage("Erro: Dados incompletos");
        exit;
    }

    // Processar o evento
    $handler = new WhatsAppHandler($SUPABASE_URL, $SUPABASE_KEY);
    $result = $handler->handleEvent($webhookData);

    // Responder com sucesso
    http_response_code($result['success'] ? 200 : 500);
    echo json_encode([
        'success' => $result['success'],
        'message' => $result['message'],
        'timestamp' => date('c'),
        'event' => $webhookData['event'],
        'instance' => $webhookData['instance']
    ]);

    logMessage("Resposta enviada: " . json_encode($result));

} catch (Exception $e) {
    logMessage("Erro crítico: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno do servidor: ' . $e->getMessage()
    ]);
} 