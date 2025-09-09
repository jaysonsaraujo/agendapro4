<?php
/**
 * Class ServiceManager
 * 
 * Responsável por gerenciar os serviços oferecidos pelo negócio
 */
class ServiceManager {
    private $supabaseUrl;
    private $supabaseKey;
    
    /**
     * Construtor
     * 
     * @param string $supabaseUrl URL do Supabase
     * @param string $supabaseKey Chave do Supabase
     */
    public function __construct($supabaseUrl, $supabaseKey) {
        $this->supabaseUrl = $supabaseUrl;
        $this->supabaseKey = $supabaseKey;
    }
    
    /**
     * Lista todos os serviços disponíveis
     * 
     * @param bool $onlyActive Retornar apenas serviços ativos
     * @return array Lista de serviços
     */
    public function listServices($onlyActive = true) {
        try {
            $queryParams = [
                'select' => '*', 
                'order' => 'name.asc'
            ];
            
            if ($onlyActive) {
                $queryParams['is_active'] = 'eq.true';
            }
            
            $result = $this->makeSupabaseRequest(
                "/rest/v1/services",
                "GET",
                null,
                $queryParams
            );
            
            return [
                'success' => true,
                'data' => $result
            ];
        } catch (Exception $e) {
            error_log("Erro ao listar serviços: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao listar serviços: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtém um serviço específico pelo ID
     * 
     * @param int $serviceId ID do serviço
     * @return array Dados do serviço
     */
    public function getService($serviceId) {
        try {
            $result = $this->makeSupabaseRequest(
                "/rest/v1/services",
                "GET",
                null,
                [
                    'select' => '*',
                    'id' => "eq.$serviceId"
                ]
            );
            
            if (empty($result)) {
                return [
                    'success' => false,
                    'message' => "Serviço não encontrado"
                ];
            }
            
            return [
                'success' => true,
                'data' => $result[0]
            ];
        } catch (Exception $e) {
            error_log("Erro ao buscar serviço: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao buscar serviço: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Cria um novo serviço
     * 
     * @param array $data Dados do serviço
     * @return array Resultado da operação
     */
    public function createService($data) {
        try {
            // Validar dados obrigatórios
            $requiredFields = ['name', 'description', 'duration', 'price'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => "Campo obrigatório não informado: $field"
                    ];
                }
            }
            
            // Preparar dados para inserção
            $serviceData = [
                'name' => $data['name'],
                'description' => $data['description'],
                'duration' => intval($data['duration']),
                'price' => floatval($data['price']),
                'is_active' => $data['is_active'] ?? true,
                'category' => $data['category'] ?? null,
                'created_at' => date('c'),
                'updated_at' => date('c')
            ];
            
            $result = $this->makeSupabaseRequest(
                "/rest/v1/services",
                "POST",
                $serviceData
            );
            
            return [
                'success' => true,
                'message' => "Serviço criado com sucesso",
                'data' => $result[0] ?? null
            ];
        } catch (Exception $e) {
            error_log("Erro ao criar serviço: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao criar serviço: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Atualiza um serviço existente
     * 
     * @param int $serviceId ID do serviço
     * @param array $data Novos dados do serviço
     * @return array Resultado da operação
     */
    public function updateService($serviceId, $data) {
        try {
            // Verificar se o serviço existe
            $service = $this->getService($serviceId);
            if (!$service['success']) {
                return $service;
            }
            
            // Preparar dados para atualização
            $updateData = [
                'updated_at' => date('c')
            ];
            
            // Adicionar apenas campos que foram fornecidos
            $allowedFields = ['name', 'description', 'duration', 'price', 'is_active', 'category'];
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateData[$field] = $data[$field];
                }
            }
            
            $result = $this->makeSupabaseRequest(
                "/rest/v1/services",
                "PATCH",
                $updateData,
                [
                    'id' => "eq.$serviceId"
                ]
            );
            
            return [
                'success' => true,
                'message' => "Serviço atualizado com sucesso",
                'data' => $result[0] ?? null
            ];
        } catch (Exception $e) {
            error_log("Erro ao atualizar serviço: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao atualizar serviço: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Exclui um serviço
     * 
     * @param int $serviceId ID do serviço
     * @return array Resultado da operação
     */
    public function deleteService($serviceId) {
        try {
            // Verificar se o serviço existe
            $service = $this->getService($serviceId);
            if (!$service['success']) {
                return $service;
            }
            
            // Verificar se o serviço está em uso em agendamentos
            $appointments = $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "GET",
                null,
                [
                    'select' => 'id',
                    'service_id' => "eq.$serviceId",
                    'limit' => 1
                ]
            );
            
            if (!empty($appointments)) {
                return [
                    'success' => false,
                    'message' => "Não é possível excluir o serviço pois está em uso em agendamentos"
                ];
            }
            
            // Em vez de excluir, desativar o serviço
            return $this->updateService($serviceId, ['is_active' => false]);
        } catch (Exception $e) {
            error_log("Erro ao excluir serviço: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao excluir serviço: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Faz uma requisição para o Supabase
     * 
     * @param string $endpoint Endpoint da API
     * @param string $method Método HTTP
     * @param mixed $data Dados a serem enviados
     * @param array $queryParams Parâmetros de consulta
     * @return array Resposta da requisição
     */
    private function makeSupabaseRequest($endpoint, $method = 'GET', $data = null, $queryParams = null) {
        $ch = curl_init();
        $url = $this->supabaseUrl . $endpoint;
        
        // Adicionar query params se existirem
        if ($queryParams && is_array($queryParams)) {
            $queryString = http_build_query($queryParams);
            $url .= (strpos($url, '?') === false ? '?' : '&') . $queryString;
        }
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->supabaseKey,
            'apikey: ' . $this->supabaseKey,
            'Prefer: return=representation'
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            if ($data) {
                $options[CURLOPT_POSTFIELDS] = json_encode($data);
            }
        } else if ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ($data) {
                $options[CURLOPT_POSTFIELDS] = json_encode($data);
            }
        }

        curl_setopt_array($ch, $options);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            error_log("Erro CURL: " . curl_error($ch));
        }
        
        curl_close($ch);

        if ($httpCode >= 400) {
            error_log("Erro na requisição Supabase ($httpCode): $response");
            throw new Exception("Erro na requisição Supabase: $httpCode");
        }

        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Erro ao decodificar resposta JSON: " . json_last_error_msg());
            throw new Exception("Erro ao decodificar resposta JSON");
        }

        return $result;
    }
} 