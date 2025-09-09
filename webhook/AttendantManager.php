<?php
/**
 * Class AttendantManager
 * 
 * Responsável por gerenciar os atendentes/profissionais do negócio
 */
class AttendantManager {
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
     * Lista todos os atendentes disponíveis
     * 
     * @param bool $onlyActive Retornar apenas atendentes ativos
     * @return array Lista de atendentes
     */
    public function listAttendants($onlyActive = true) {
        try {
            $queryParams = [
                'select' => '*', 
                'order' => 'name.asc'
            ];
            
            if ($onlyActive) {
                $queryParams['is_active'] = 'eq.true';
            }
            
            $result = $this->makeSupabaseRequest(
                "/rest/v1/attendants",
                "GET",
                null,
                $queryParams
            );
            
            return [
                'success' => true,
                'data' => $result
            ];
        } catch (Exception $e) {
            error_log("Erro ao listar atendentes: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao listar atendentes: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtém um atendente específico pelo ID
     * 
     * @param int $attendantId ID do atendente
     * @return array Dados do atendente
     */
    public function getAttendant($attendantId) {
        try {
            $result = $this->makeSupabaseRequest(
                "/rest/v1/attendants",
                "GET",
                null,
                [
                    'select' => '*',
                    'id' => "eq.$attendantId"
                ]
            );
            
            if (empty($result)) {
                return [
                    'success' => false,
                    'message' => "Atendente não encontrado"
                ];
            }
            
            // Buscar horários de trabalho do atendente
            $workingHours = $this->makeSupabaseRequest(
                "/rest/v1/attendant_working_hours",
                "GET",
                null,
                [
                    'select' => '*',
                    'attendant_id' => "eq.$attendantId",
                    'order' => 'day_of_week.asc'
                ]
            );
            
            // Buscar serviços que o atendente realiza
            $services = $this->makeSupabaseRequest(
                "/rest/v1/attendant_services",
                "GET",
                null,
                [
                    'select' => 'service_id',
                    'attendant_id' => "eq.$attendantId"
                ]
            );
            
            $serviceIds = array_map(function($item) {
                return $item['service_id'];
            }, $services);
            
            $attendant = $result[0];
            $attendant['working_hours'] = $workingHours;
            $attendant['service_ids'] = $serviceIds;
            
            return [
                'success' => true,
                'data' => $attendant
            ];
        } catch (Exception $e) {
            error_log("Erro ao buscar atendente: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao buscar atendente: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Cria um novo atendente
     * 
     * @param array $data Dados do atendente
     * @return array Resultado da operação
     */
    public function createAttendant($data) {
        try {
            // Validar dados obrigatórios
            $requiredFields = ['name', 'email', 'phone'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => "Campo obrigatório não informado: $field"
                    ];
                }
            }
            
            // Iniciar transação
            $attendantData = [
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'bio' => $data['bio'] ?? '',
                'photo_url' => $data['photo_url'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'created_at' => date('c'),
                'updated_at' => date('c')
            ];
            
            // Inserir atendente
            $result = $this->makeSupabaseRequest(
                "/rest/v1/attendants",
                "POST",
                $attendantData
            );
            
            if (empty($result)) {
                throw new Exception("Falha ao criar atendente");
            }
            
            $attendantId = $result[0]['id'];
            
            // Inserir horários de trabalho se fornecidos
            if (isset($data['working_hours']) && is_array($data['working_hours'])) {
                foreach ($data['working_hours'] as $wh) {
                    if (isset($wh['day_of_week'], $wh['start_time'], $wh['end_time'])) {
                        $workingHourData = [
                            'attendant_id' => $attendantId,
                            'day_of_week' => intval($wh['day_of_week']),
                            'start_time' => $wh['start_time'],
                            'end_time' => $wh['end_time'],
                            'is_active' => $wh['is_active'] ?? true
                        ];
                        
                        $this->makeSupabaseRequest(
                            "/rest/v1/attendant_working_hours",
                            "POST",
                            $workingHourData
                        );
                    }
                }
            }
            
            // Associar serviços se fornecidos
            if (isset($data['service_ids']) && is_array($data['service_ids'])) {
                foreach ($data['service_ids'] as $serviceId) {
                    $serviceData = [
                        'attendant_id' => $attendantId,
                        'service_id' => $serviceId
                    ];
                    
                    $this->makeSupabaseRequest(
                        "/rest/v1/attendant_services",
                        "POST",
                        $serviceData
                    );
                }
            }
            
            return [
                'success' => true,
                'message' => "Atendente criado com sucesso",
                'data' => ['id' => $attendantId]
            ];
        } catch (Exception $e) {
            error_log("Erro ao criar atendente: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao criar atendente: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Atualiza um atendente existente
     * 
     * @param int $attendantId ID do atendente
     * @param array $data Novos dados do atendente
     * @return array Resultado da operação
     */
    public function updateAttendant($attendantId, $data) {
        try {
            // Verificar se o atendente existe
            $attendant = $this->getAttendant($attendantId);
            if (!$attendant['success']) {
                return $attendant;
            }
            
            // Preparar dados para atualização
            $updateData = [
                'updated_at' => date('c')
            ];
            
            // Adicionar apenas campos que foram fornecidos
            $allowedFields = ['name', 'email', 'phone', 'bio', 'photo_url', 'is_active'];
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateData[$field] = $data[$field];
                }
            }
            
            // Atualizar atendente
            $result = $this->makeSupabaseRequest(
                "/rest/v1/attendants",
                "PATCH",
                $updateData,
                [
                    'id' => "eq.$attendantId"
                ]
            );
            
            // Atualizar horários de trabalho se fornecidos
            if (isset($data['working_hours']) && is_array($data['working_hours'])) {
                // Remover horários existentes
                $this->makeSupabaseRequest(
                    "/rest/v1/attendant_working_hours",
                    "DELETE",
                    null,
                    [
                        'attendant_id' => "eq.$attendantId"
                    ]
                );
                
                // Inserir novos horários
                foreach ($data['working_hours'] as $wh) {
                    if (isset($wh['day_of_week'], $wh['start_time'], $wh['end_time'])) {
                        $workingHourData = [
                            'attendant_id' => $attendantId,
                            'day_of_week' => intval($wh['day_of_week']),
                            'start_time' => $wh['start_time'],
                            'end_time' => $wh['end_time'],
                            'is_active' => $wh['is_active'] ?? true
                        ];
                        
                        $this->makeSupabaseRequest(
                            "/rest/v1/attendant_working_hours",
                            "POST",
                            $workingHourData
                        );
                    }
                }
            }
            
            // Atualizar serviços se fornecidos
            if (isset($data['service_ids']) && is_array($data['service_ids'])) {
                // Remover associações existentes
                $this->makeSupabaseRequest(
                    "/rest/v1/attendant_services",
                    "DELETE",
                    null,
                    [
                        'attendant_id' => "eq.$attendantId"
                    ]
                );
                
                // Inserir novas associações
                foreach ($data['service_ids'] as $serviceId) {
                    $serviceData = [
                        'attendant_id' => $attendantId,
                        'service_id' => $serviceId
                    ];
                    
                    $this->makeSupabaseRequest(
                        "/rest/v1/attendant_services",
                        "POST",
                        $serviceData
                    );
                }
            }
            
            return [
                'success' => true,
                'message' => "Atendente atualizado com sucesso"
            ];
        } catch (Exception $e) {
            error_log("Erro ao atualizar atendente: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao atualizar atendente: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Exclui um atendente
     * 
     * @param int $attendantId ID do atendente
     * @return array Resultado da operação
     */
    public function deleteAttendant($attendantId) {
        try {
            // Verificar se o atendente existe
            $attendant = $this->getAttendant($attendantId);
            if (!$attendant['success']) {
                return $attendant;
            }
            
            // Verificar se o atendente tem agendamentos futuros
            $currentDate = date('Y-m-d');
            $appointments = $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "GET",
                null,
                [
                    'select' => 'id',
                    'attendant_id' => "eq.$attendantId",
                    'appointment_date' => "gte.$currentDate",
                    'status' => "neq.canceled",
                    'limit' => 1
                ]
            );
            
            if (!empty($appointments)) {
                return [
                    'success' => false,
                    'message' => "Não é possível excluir o atendente pois possui agendamentos futuros"
                ];
            }
            
            // Em vez de excluir, desativar o atendente
            return $this->updateAttendant($attendantId, ['is_active' => false]);
        } catch (Exception $e) {
            error_log("Erro ao excluir atendente: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao excluir atendente: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Verifica disponibilidade do atendente para uma data e horário específicos
     * 
     * @param int $attendantId ID do atendente
     * @param string $date Data no formato YYYY-MM-DD
     * @param string $time Horário no formato HH:MM
     * @param int $duration Duração em minutos
     * @return array Resultado da verificação
     */
    public function checkAvailability($attendantId, $date, $time, $duration = 60) {
        try {
            // Verificar se o atendente existe e está ativo
            $attendant = $this->getAttendant($attendantId);
            if (!$attendant['success'] || !$attendant['data']['is_active']) {
                return [
                    'success' => false,
                    'available' => false,
                    'message' => "Atendente não encontrado ou inativo"
                ];
            }
            
            // Verificar se a data é futura
            $appointmentDate = new DateTime($date);
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            
            if ($appointmentDate < $today) {
                return [
                    'success' => false,
                    'available' => false,
                    'message' => "A data selecionada é no passado"
                ];
            }
            
            // Verificar se o dia da semana é um dia de trabalho para o atendente
            $dayOfWeek = $appointmentDate->format('N'); // 1 (Segunda) a 7 (Domingo)
            
            $workingHours = array_filter($attendant['data']['working_hours'], function($wh) use ($dayOfWeek) {
                return $wh['day_of_week'] == $dayOfWeek && $wh['is_active'];
            });
            
            if (empty($workingHours)) {
                return [
                    'success' => false,
                    'available' => false,
                    'message' => "Atendente não trabalha neste dia da semana"
                ];
            }
            
            // Verificar se o horário está dentro do horário de trabalho
            $requestedTime = new DateTime($time);
            $requestedTimeStr = $requestedTime->format('H:i:s');
            
            $withinWorkingHours = false;
            foreach ($workingHours as $wh) {
                $startTime = $wh['start_time'];
                $endTime = $wh['end_time'];
                
                if ($requestedTimeStr >= $startTime && $requestedTimeStr <= $endTime) {
                    $withinWorkingHours = true;
                    break;
                }
            }
            
            if (!$withinWorkingHours) {
                return [
                    'success' => false,
                    'available' => false,
                    'message' => "Horário fora do período de trabalho do atendente"
                ];
            }
            
            // Verificar se o atendente já tem agendamentos que conflitam
            $appointmentEndTime = clone $requestedTime;
            $appointmentEndTime->modify("+{$duration} minutes");
            
            $requestedEndTimeStr = $appointmentEndTime->format('H:i:s');
            
            // Buscar agendamentos para a data
            $appointments = $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "GET",
                null,
                [
                    'select' => 'appointment_time,duration',
                    'attendant_id' => "eq.$attendantId",
                    'appointment_date' => "eq.$date",
                    'status' => "neq.canceled"
                ]
            );
            
            foreach ($appointments as $appointment) {
                $apptTime = $appointment['appointment_time'];
                $apptDuration = $appointment['duration'] ?? 60;
                
                $apptStartTime = new DateTime($apptTime);
                $apptEndTime = clone $apptStartTime;
                $apptEndTime->modify("+{$apptDuration} minutes");
                
                $apptStartStr = $apptStartTime->format('H:i:s');
                $apptEndStr = $apptEndTime->format('H:i:s');
                
                // Verificar se há sobreposição
                if (
                    ($requestedTimeStr >= $apptStartStr && $requestedTimeStr < $apptEndStr) ||
                    ($requestedEndTimeStr > $apptStartStr && $requestedEndTimeStr <= $apptEndStr) ||
                    ($requestedTimeStr <= $apptStartStr && $requestedEndTimeStr >= $apptEndStr)
                ) {
                    return [
                        'success' => false,
                        'available' => false,
                        'message' => "Atendente já possui agendamento neste horário"
                    ];
                }
            }
            
            // Se passou por todas as verificações, está disponível
            return [
                'success' => true,
                'available' => true,
                'message' => "Horário disponível"
            ];
            
        } catch (Exception $e) {
            error_log("Erro ao verificar disponibilidade: " . $e->getMessage());
            return [
                'success' => false,
                'available' => false,
                'message' => "Erro ao verificar disponibilidade: " . $e->getMessage()
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