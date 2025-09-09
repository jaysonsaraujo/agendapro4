<?php
/**
 * AppointmentService.php
 * 
 * Responsável por gerenciar operações relacionadas a agendamentos.
 * Consulta, cria, atualiza e cancela agendamentos.
 */

class AppointmentService {
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
     * Verifica a disponibilidade de horários para um atendente em uma data específica
     * 
     * @param string $attendantId ID do atendente
     * @param string $date Data no formato YYYY-MM-DD
     * @return array Informações de disponibilidade
     */
    public function checkAvailability($attendantId, $date) {
        try {
            // Verificar se a data é futura
            $timestamp = strtotime($date);
            $today = strtotime(date('Y-m-d'));
            
            if ($timestamp < $today) {
                return [
                    'success' => false,
                    'message' => 'Não é possível agendar para datas passadas.'
                ];
            }
            
            // Verificar se a data está dentro do limite de agendamento (30 dias)
            $limit = strtotime('+30 days');
            if ($timestamp > $limit) {
                return [
                    'success' => false,
                    'message' => 'Agendamentos só podem ser feitos com até 30 dias de antecedência.'
                ];
            }
            
            // Verificar se o atendente existe e está disponível
            $attendant = $this->getAttendantById($attendantId);
            if (!$attendant) {
                return [
                    'success' => false,
                    'message' => 'Atendente não encontrado ou indisponível.'
                ];
            }
            
            // Verificar se o atendente trabalha no dia da semana
            $dayOfWeek = date('w', $timestamp);
            if (!in_array($dayOfWeek, $attendant['work_days'])) {
                return [
                    'success' => false,
                    'message' => "O atendente {$attendant['name']} não atende neste dia da semana."
                ];
            }
            
            // Verificar se o estabelecimento está aberto neste dia
            $businessHours = $this->getBusinessHoursForDay($dayOfWeek);
            if (empty($businessHours) || $businessHours['is_closed']) {
                return [
                    'success' => false,
                    'message' => 'O estabelecimento está fechado neste dia.'
                ];
            }
            
            // Buscar horários já agendados para o atendente nesta data
            $bookedTimes = $this->getBookedTimesByAttendantAndDate($attendantId, $date);
            
            // Determinar os horários disponíveis
            $availableTimes = $this->calculateAvailableTimes($businessHours, $bookedTimes, $date);
            
            return [
                'success' => true,
                'attendant' => $attendant,
                'date' => $date,
                'formatted_date' => date('d/m/Y', $timestamp),
                'day_of_week' => $this->getDayName($dayOfWeek),
                'business_hours' => $businessHours,
                'available_times' => $availableTimes,
                'message' => empty($availableTimes) ? 'Não há horários disponíveis para esta data.' : 'Horários disponíveis encontrados.'
            ];
        } catch (Exception $e) {
            error_log("Erro ao verificar disponibilidade: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erro ao verificar disponibilidade: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Busca agendamentos de um cliente por telefone
     * 
     * @param string $phone Número de telefone do cliente
     * @return array Agendamentos do cliente
     */
    public function getClientAppointments($phone) {
        try {
            // Limpar o número de telefone
            $phone = preg_replace('/\D/', '', $phone);
            
            // Buscar agendamentos futuros do cliente
            $result = $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "GET",
                null,
                [
                    'select' => '*',
                    'client_phone' => "eq.$phone",
                    'order' => 'appointment_datetime.asc'
                ]
            );
            
            // Filtrar somente agendamentos futuros ou em andamento
            $currentDateTime = date('Y-m-d H:i:s');
            $upcomingAppointments = array_filter($result, function($appointment) use ($currentDateTime) {
                $appointmentDateTime = $appointment['appointment_date'] . ' ' . $appointment['appointment_time'];
                return $appointmentDateTime >= $currentDateTime && 
                       $appointment['status'] != 'Cancelado' && 
                       $appointment['status'] != 'Concluído';
            });
            
            return [
                'success' => true,
                'appointments' => array_values($upcomingAppointments),
                'count' => count($upcomingAppointments),
                'message' => count($upcomingAppointments) > 0 ? 'Agendamentos encontrados.' : 'Nenhum agendamento futuro encontrado.'
            ];
        } catch (Exception $e) {
            error_log("Erro ao buscar agendamentos do cliente: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erro ao buscar agendamentos: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Cria um novo agendamento
     * 
     * @param array $data Dados do agendamento
     * @return array Resultado da operação
     */
    public function createAppointment($data) {
        try {
            // Validar dados obrigatórios
            $requiredFields = [
                'client_name', 'client_phone', 'attendant_id', 
                'service_id', 'appointment_date', 'appointment_time'
            ];
            
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => "Campo obrigatório não informado: $field"
                    ];
                }
            }
            
            // Verificar disponibilidade do horário
            $availabilityCheck = $this->checkAvailability(
                $data['attendant_id'], 
                $data['appointment_date']
            );
            
            if (!$availabilityCheck['success']) {
                return [
                    'success' => false,
                    'message' => $availabilityCheck['message']
                ];
            }
            
            // Verificar se o horário escolhido está disponível
            $time = $data['appointment_time'];
            if (!in_array($time, $availabilityCheck['available_times'])) {
                return [
                    'success' => false,
                    'message' => "O horário escolhido não está disponível. Por favor, escolha outro horário."
                ];
            }
            
            // Obter detalhes do atendente
            $attendant = $this->getAttendantById($data['attendant_id']);
            if (!$attendant) {
                return [
                    'success' => false,
                    'message' => "Atendente não encontrado ou indisponível."
                ];
            }
            
            // Obter detalhes do serviço
            $service = $this->getServiceById($data['service_id']);
            if (!$service) {
                return [
                    'success' => false,
                    'message' => "Serviço não encontrado ou indisponível."
                ];
            }
            
            // Preparar dados para inserção
            $appointmentData = [
                'client_name' => $data['client_name'],
                'client_phone' => $data['client_phone'],
                'attendant_id' => $data['attendant_id'],
                'attendant_name' => $attendant['name'],
                'service_id' => $data['service_id'],
                'service_name' => $service['name'],
                'service_price' => $service['price'],
                'service_duration' => $service['duration'],
                'appointment_date' => $data['appointment_date'],
                'appointment_time' => $data['appointment_time'],
                'appointment_datetime' => $data['appointment_date'] . ' ' . $data['appointment_time'],
                'notes' => $data['notes'] ?? '',
                'status' => 'Agendado',
                'created_at' => date('c'),
                'updated_at' => date('c')
            ];
            
            // Inserir agendamento
            $result = $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "POST",
                $appointmentData
            );
            
            if (empty($result)) {
                throw new Exception("Falha ao criar agendamento");
            }
            
            return [
                'success' => true,
                'appointment' => $result[0],
                'message' => "Agendamento criado com sucesso para {$data['appointment_date']} às {$data['appointment_time']}."
            ];
        } catch (Exception $e) {
            error_log("Erro ao criar agendamento: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao criar agendamento: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Cancela um agendamento
     * 
     * @param string $appointmentId ID do agendamento
     * @return array Resultado da operação
     */
    public function cancelAppointment($appointmentId) {
        try {
            // Verificar se o agendamento existe
            $appointment = $this->getAppointmentById($appointmentId);
            if (!$appointment) {
                return [
                    'success' => false,
                    'message' => "Agendamento não encontrado."
                ];
            }
            
            // Verificar se já não está cancelado
            if ($appointment['status'] == 'Cancelado') {
                return [
                    'success' => false,
                    'message' => "Este agendamento já está cancelado."
                ];
            }
            
            // Verificar se já foi concluído
            if ($appointment['status'] == 'Concluído') {
                return [
                    'success' => false,
                    'message' => "Não é possível cancelar um agendamento já concluído."
                ];
            }
            
            // Atualizar o status para cancelado
            $result = $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "PATCH",
                [
                    'status' => 'Cancelado',
                    'updated_at' => date('c')
                ],
                [
                    'id' => "eq.$appointmentId"
                ]
            );
            
            return [
                'success' => true,
                'message' => "Agendamento cancelado com sucesso."
            ];
        } catch (Exception $e) {
            error_log("Erro ao cancelar agendamento: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao cancelar agendamento: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtém um atendente pelo ID
     * 
     * @param string $attendantId ID do atendente
     * @return array|null Dados do atendente
     */
    private function getAttendantById($attendantId) {
        try {
            $result = $this->makeSupabaseRequest(
                "/rest/v1/attendants",
                "GET",
                null,
                [
                    'select' => '*',
                    'id' => "eq.$attendantId",
                    'available' => 'eq.true'
                ]
            );
            
            return !empty($result) ? $result[0] : null;
        } catch (Exception $e) {
            error_log("Erro ao buscar atendente: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtém um serviço pelo ID
     * 
     * @param string $serviceId ID do serviço
     * @return array|null Dados do serviço
     */
    private function getServiceById($serviceId) {
        try {
            $result = $this->makeSupabaseRequest(
                "/rest/v1/services",
                "GET",
                null,
                [
                    'select' => '*',
                    'id' => "eq.$serviceId",
                    'available' => 'eq.true'
                ]
            );
            
            return !empty($result) ? $result[0] : null;
        } catch (Exception $e) {
            error_log("Erro ao buscar serviço: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtém um agendamento pelo ID
     * 
     * @param string $appointmentId ID do agendamento
     * @return array|null Dados do agendamento
     */
    private function getAppointmentById($appointmentId) {
        try {
            $result = $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "GET",
                null,
                [
                    'select' => '*',
                    'id' => "eq.$appointmentId"
                ]
            );
            
            return !empty($result) ? $result[0] : null;
        } catch (Exception $e) {
            error_log("Erro ao buscar agendamento: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtém os horários de funcionamento para um dia da semana
     * 
     * @param int $dayOfWeek Dia da semana (0=Domingo, 1=Segunda, etc)
     * @return array|null Horários de funcionamento
     */
    private function getBusinessHoursForDay($dayOfWeek) {
        try {
            // Mapear número do dia para nome em português
            $dayNames = [
                '0' => 'Domingo',
                '1' => 'Segunda-feira',
                '2' => 'Terça-feira',
                '3' => 'Quarta-feira',
                '4' => 'Quinta-feira',
                '5' => 'Sexta-feira',
                '6' => 'Sábado'
            ];
            
            $dayName = $dayNames["$dayOfWeek"];
            
            $result = $this->makeSupabaseRequest(
                "/rest/v1/business_hours",
                "GET",
                null,
                [
                    'select' => '*',
                    'day_of_week' => "eq.$dayName"
                ]
            );
            
            return !empty($result) ? $result[0] : null;
        } catch (Exception $e) {
            error_log("Erro ao buscar horários de funcionamento: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtém os horários agendados para um atendente em uma data
     * 
     * @param string $attendantId ID do atendente
     * @param string $date Data no formato YYYY-MM-DD
     * @return array Horários agendados
     */
    private function getBookedTimesByAttendantAndDate($attendantId, $date) {
        try {
            $result = $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "GET",
                null,
                [
                    'select' => 'appointment_time,service_duration',
                    'attendant_id' => "eq.$attendantId",
                    'appointment_date' => "eq.$date",
                    'status' => "neq.Cancelado"
                ]
            );
            
            // Extrair apenas os horários
            $bookedTimes = [];
            foreach ($result as $appointment) {
                $bookedTimes[] = $appointment['appointment_time'];
                
                // Adicionar intervalos com base na duração do serviço
                $duration = $appointment['service_duration'];
                if ($duration > 0) {
                    $startTime = strtotime($appointment['appointment_time']);
                    $timeSlotMinutes = 30; // Intervalo padrão de 30 minutos
                    
                    // Calcular quantos intervalos o serviço ocupa
                    $slots = ceil($duration / $timeSlotMinutes);
                    
                    // Adicionar os horários bloqueados
                    for ($i = 1; $i < $slots; $i++) {
                        $blockedTime = date('H:i:s', $startTime + ($i * $timeSlotMinutes * 60));
                        $bookedTimes[] = $blockedTime;
                    }
                }
            }
            
            return $bookedTimes;
        } catch (Exception $e) {
            error_log("Erro ao buscar horários agendados: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Calcula os horários disponíveis com base nos horários de funcionamento
     * e nos horários já agendados
     * 
     * @param array $businessHours Horários de funcionamento
     * @param array $bookedTimes Horários já agendados
     * @param string $date Data para verificação
     * @return array Horários disponíveis
     */
    private function calculateAvailableTimes($businessHours, $bookedTimes, $date) {
        try {
            if (empty($businessHours) || $businessHours['is_closed']) {
                return [];
            }
            
            $openTime = strtotime($businessHours['open_time']);
            $closeTime = strtotime($businessHours['close_time']);
            $timeSlotMinutes = 30; // Intervalo padrão de 30 minutos
            $isToday = ($date == date('Y-m-d'));
            $currentTime = strtotime(date('H:i:s'));
            
            $availableTimes = [];
            
            // Gerar intervalos de 30 minutos
            for ($time = $openTime; $time < $closeTime; $time += $timeSlotMinutes * 60) {
                $timeStr = date('H:i:s', $time);
                
                // Se for hoje, não mostrar horários que já passaram
                if ($isToday && $time < $currentTime) {
                    continue;
                }
                
                // Verificar se o horário não está ocupado
                if (!in_array($timeStr, $bookedTimes)) {
                    $availableTimes[] = $timeStr;
                }
            }
            
            return $availableTimes;
        } catch (Exception $e) {
            error_log("Erro ao calcular horários disponíveis: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtém o nome do dia da semana em português
     * 
     * @param int $dayNumber Número do dia da semana (0-6)
     * @return string Nome do dia da semana
     */
    private function getDayName($dayNumber) {
        $days = [
            0 => 'Domingo',
            1 => 'Segunda-feira',
            2 => 'Terça-feira',
            3 => 'Quarta-feira',
            4 => 'Quinta-feira',
            5 => 'Sexta-feira',
            6 => 'Sábado'
        ];
        
        return isset($days[$dayNumber]) ? $days[$dayNumber] : '';
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