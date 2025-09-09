<?php
/**
 * Arquivo principal de entrada da aplicação
 * 
 * Este arquivo inicializa a aplicação e direciona as requisições
 * para os manipuladores apropriados.
 */

// Configuração inicial
require_once 'config.php';
require_once 'WhatsAppWebhook.php';

// Defina cabeçalhos para permitir CORS se necessário
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Se for uma requisição OPTIONS (preflight), encerre aqui
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Obter a rota da URL
$requestUri = $_SERVER['REQUEST_URI'];
$basePath = '/'; // Ajuste conforme necessário
$route = str_replace($basePath, '', $requestUri);
$route = strtok($route, '?'); // Remover query string

// Log da requisição recebida
error_log("Requisição recebida: " . $route . " [" . $_SERVER['REQUEST_METHOD'] . "]");

try {
    // Roteamento das requisições
    switch ($route) {
        case 'webhook':
            // Processar requisição do webhook do WhatsApp
            $webhook = new WhatsAppWebhook($SUPABASE_URL, $SUPABASE_KEY);
            $result = $webhook->handleRequest();
            echo json_encode($result);
            break;
            
        case 'health':
            // Endpoint para verificar se o sistema está funcionando
            echo json_encode([
                'status' => 'ok',
                'timestamp' => date('c'),
                'version' => '1.0.0'
            ]);
            break;
            
        case 'api/appointments':
            // Rota para gerenciar agendamentos via API
            handleAppointmentsAPI();
            break;
            
        case 'api/services':
            // Rota para gerenciar serviços via API
            handleServicesAPI();
            break;
            
        case 'api/attendants':
            // Rota para gerenciar atendentes via API
            handleAttendantsAPI();
            break;
            
        default:
            // Rota não encontrada
            http_response_code(404);
            echo json_encode([
                'error' => 'Rota não encontrada',
                'route' => $route
            ]);
            break;
    }
} catch (Exception $e) {
    // Tratamento de erros
    http_response_code(500);
    echo json_encode([
        'error' => 'Erro interno do servidor',
        'message' => $e->getMessage()
    ]);
    error_log("Erro na aplicação: " . $e->getMessage());
}

/**
 * Manipula requisições de API para agendamentos
 */
function handleAppointmentsAPI() {
    global $SUPABASE_URL, $SUPABASE_KEY;
    
    $appointmentService = new AppointmentService($SUPABASE_URL, $SUPABASE_KEY);
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            // Verificar se é para listar ou obter um específico
            if (isset($_GET['id'])) {
                $appointment = $appointmentService->getAppointment($_GET['id']);
                echo json_encode($appointment);
            } elseif (isset($_GET['phone'])) {
                $appointments = $appointmentService->getClientAppointments($_GET['phone']);
                echo json_encode($appointments);
            } else {
                // Listar todos com paginação
                $page = $_GET['page'] ?? 1;
                $limit = $_GET['limit'] ?? 10;
                $appointments = $appointmentService->listAppointments($page, $limit);
                echo json_encode($appointments);
            }
            break;
            
        case 'POST':
            // Criar novo agendamento
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $appointmentService->createAppointment($data);
            echo json_encode($result);
            break;
            
        case 'PUT':
            // Atualizar agendamento existente
            $data = json_decode(file_get_contents('php://input'), true);
            if (!isset($data['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'ID do agendamento não informado']);
                break;
            }
            $result = $appointmentService->updateAppointment($data['id'], $data);
            echo json_encode($result);
            break;
            
        case 'DELETE':
            // Cancelar agendamento
            $data = json_decode(file_get_contents('php://input'), true);
            if (!isset($data['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'ID do agendamento não informado']);
                break;
            }
            $result = $appointmentService->cancelAppointment($data['id']);
            echo json_encode($result);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Método não permitido']);
            break;
    }
}

/**
 * Manipula requisições de API para serviços
 */
function handleServicesAPI() {
    global $SUPABASE_URL, $SUPABASE_KEY;
    
    require_once 'ServiceManager.php';
    $serviceManager = new ServiceManager($SUPABASE_URL, $SUPABASE_KEY);
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            // Verificar se é para listar ou obter um específico
            if (isset($_GET['id'])) {
                $service = $serviceManager->getService($_GET['id']);
                echo json_encode($service);
            } else {
                // Listar todos
                $services = $serviceManager->listServices();
                echo json_encode($services);
            }
            break;
            
        case 'POST':
            // Criar novo serviço
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $serviceManager->createService($data);
            echo json_encode($result);
            break;
            
        case 'PUT':
            // Atualizar serviço existente
            $data = json_decode(file_get_contents('php://input'), true);
            if (!isset($data['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'ID do serviço não informado']);
                break;
            }
            $result = $serviceManager->updateService($data['id'], $data);
            echo json_encode($result);
            break;
            
        case 'DELETE':
            // Remover serviço
            $data = json_decode(file_get_contents('php://input'), true);
            if (!isset($data['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'ID do serviço não informado']);
                break;
            }
            $result = $serviceManager->deleteService($data['id']);
            echo json_encode($result);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Método não permitido']);
            break;
    }
}

/**
 * Manipula requisições de API para atendentes
 */
function handleAttendantsAPI() {
    global $SUPABASE_URL, $SUPABASE_KEY;
    
    require_once 'AttendantManager.php';
    $attendantManager = new AttendantManager($SUPABASE_URL, $SUPABASE_KEY);
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            // Verificar se é para listar ou obter um específico
            if (isset($_GET['id'])) {
                $attendant = $attendantManager->getAttendant($_GET['id']);
                echo json_encode($attendant);
            } else {
                // Listar todos
                $attendants = $attendantManager->listAttendants();
                echo json_encode($attendants);
            }
            break;
            
        case 'POST':
            // Criar novo atendente
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $attendantManager->createAttendant($data);
            echo json_encode($result);
            break;
            
        case 'PUT':
            // Atualizar atendente existente
            $data = json_decode(file_get_contents('php://input'), true);
            if (!isset($data['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'ID do atendente não informado']);
                break;
            }
            $result = $attendantManager->updateAttendant($data['id'], $data);
            echo json_encode($result);
            break;
            
        case 'DELETE':
            // Remover atendente
            $data = json_decode(file_get_contents('php://input'), true);
            if (!isset($data['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'ID do atendente não informado']);
                break;
            }
            $result = $attendantManager->deleteAttendant($data['id']);
            echo json_encode($result);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Método não permitido']);
            break;
    }
} 