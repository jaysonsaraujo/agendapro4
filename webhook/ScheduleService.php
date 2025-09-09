<?php
/**
 * Class ScheduleService
 * 
 * Responsável por gerenciar agendas, horários disponíveis e consultas de disponibilidade
 * com base nas tabelas schedules, schedule_assignments e appointments.
 */
class ScheduleService {
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
     * Obter todos os horários disponíveis para um atendente específico em uma data
     * 
     * @param string $attendantId ID do atendente
     * @param string $date Data no formato YYYY-MM-DD
     * @param string $serviceId ID do serviço (opcional)
     * @return array Horários disponíveis
     */
    public function getAvailableTimeSlots($attendantId, $date, $serviceId = null) {
        try {
            // Verificar formato da data
            if (!$this->isValidDate($date)) {
                return [
                    'success' => false,
                    'message' => 'Formato de data inválido. Use YYYY-MM-DD'
                ];
            }

            // Converter a data para um objeto DateTime para obter o dia da semana
            $dateObj = new DateTime($date);
            $dayOfWeek = $this->getDayName($dateObj->format('N'));
            
            // Verificar se a data é no passado
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            
            if ($dateObj < $today) {
                return [
                    'success' => false,
                    'message' => 'A data selecionada está no passado'
                ];
            }
            
            // Verificar se o atendente existe
            $attendant = $this->getAttendantById($attendantId);
            if (!$attendant) {
                return [
                    'success' => false,
                    'message' => 'Atendente não encontrado'
                ];
            }
            
            // Verificar se o atendente trabalha no dia especificado
            if (!in_array($dayOfWeek, $attendant['work_days'])) {
                return [
                    'success' => false,
                    'message' => "O atendente não trabalha às {$dayOfWeek}s"
                ];
            }
            
            // Obter duração do serviço se fornecido
            $serviceDuration = 30; // Duração padrão
            if ($serviceId) {
                $service = $this->getServiceById($serviceId);
                if ($service && isset($service['duration'])) {
                    $serviceDuration = $service['duration'];
                }
            }
            
            // Obter os horários disponíveis para o atendente a partir da tabela schedule_assignments
            $scheduleAssignments = $this->getAttendantSchedules($attendantId, $dayOfWeek);
            
            if (empty($scheduleAssignments)) {
                return [
                    'success' => false,
                    'message' => "O atendente não possui horários configurados para {$dayOfWeek}"
                ];
            }
            
            // Obter horários já agendados para o atendente na data especificada
            $bookedAppointments = $this->getBookedAppointments($attendantId, $date);
            
            // Calcular slots disponíveis
            $availableSlots = $this->calculateAvailableSlots($scheduleAssignments, $bookedAppointments, $serviceDuration);
            
            return [
                'success' => true,
                'date' => $date,
                'day_of_week' => $dayOfWeek,
                'attendant' => $attendant['name'],
                'service_duration' => $serviceDuration,
                'available_slots' => $availableSlots
            ];
            
        } catch (Exception $e) {
            error_log("Erro ao obter horários disponíveis: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao obter horários disponíveis: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Obter calendário de disponibilidade para um período
     * 
     * @param string $attendantId ID do atendente
     * @param string $startDate Data inicial (YYYY-MM-DD)
     * @param string $endDate Data final (YYYY-MM-DD)
     * @param string $serviceId ID do serviço (opcional)
     * @return array Disponibilidade por dia no período
     */
    public function getAvailabilityCalendar($attendantId, $startDate, $endDate, $serviceId = null) {
        try {
            // Validar datas
            if (!$this->isValidDate($startDate) || !$this->isValidDate($endDate)) {
                return [
                    'success' => false,
                    'message' => 'Formato de data inválido. Use YYYY-MM-DD'
                ];
            }
            
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            
            // Limitar o período para no máximo 31 dias
            $maxEnd = clone $start;
            $maxEnd->modify('+30 days');
            
            if ($end > $maxEnd) {
                $end = $maxEnd;
            }
            
            // Verificar se o atendente existe
            $attendant = $this->getAttendantById($attendantId);
            if (!$attendant) {
                return [
                    'success' => false,
                    'message' => 'Atendente não encontrado'
                ];
            }
            
            $calendar = [];
            $currentDate = clone $start;
            
            // Para cada dia no intervalo de datas
            while ($currentDate <= $end) {
                $date = $currentDate->format('Y-m-d');
                $dayOfWeek = $this->getDayName($currentDate->format('N'));
                
                // Verificar se o atendente trabalha neste dia
                $hasAvailability = in_array($dayOfWeek, $attendant['work_days']);
                
                // Se trabalha neste dia, verificar quantos horários estão disponíveis
                $availableSlotsCount = 0;
                if ($hasAvailability) {
                    $availability = $this->getAvailableTimeSlots($attendantId, $date, $serviceId);
                    $availableSlotsCount = $availability['success'] ? count($availability['available_slots']) : 0;
                }
                
                $calendar[] = [
                    'date' => $date,
                    'day_of_week' => $dayOfWeek,
                    'has_availability' => $hasAvailability && $availableSlotsCount > 0,
                    'available_slots_count' => $availableSlotsCount
                ];
                
                $currentDate->modify('+1 day');
            }
            
            return [
                'success' => true,
                'attendant' => $attendant['name'],
                'calendar' => $calendar
            ];
            
        } catch (Exception $e) {
            error_log("Erro ao obter calendário de disponibilidade: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao obter calendário de disponibilidade: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Verifica disponibilidade para um horário específico
     * 
     * @param string $attendantId ID do atendente
     * @param string $date Data (YYYY-MM-DD)
     * @param string $time Horário (HH:MM)
     * @param string $serviceId ID do serviço (opcional)
     * @return array Resultado da verificação
     */
    public function checkSpecificTimeAvailability($attendantId, $date, $time, $serviceId = null) {
        try {
            // Validar data e hora
            if (!$this->isValidDate($date)) {
                return [
                    'success' => false,
                    'available' => false,
                    'message' => 'Formato de data inválido. Use YYYY-MM-DD'
                ];
            }
            
            if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $time)) {
                return [
                    'success' => false,
                    'available' => false,
                    'message' => 'Formato de hora inválido. Use HH:MM (24h)'
                ];
            }
            
            // Obter duração do serviço se fornecido
            $serviceDuration = 30; // Duração padrão
            if ($serviceId) {
                $service = $this->getServiceById($serviceId);
                if ($service && isset($service['duration'])) {
                    $serviceDuration = $service['duration'];
                }
            }
            
            // Obter horários disponíveis para a data
            $availability = $this->getAvailableTimeSlots($attendantId, $date, $serviceId);
            
            if (!$availability['success']) {
                return [
                    'success' => false,
                    'available' => false,
                    'message' => $availability['message']
                ];
            }
            
            // Verificar se o horário específico está disponível
            $timeFormatted = $time . ':00'; // Adicionar segundos para comparação
            $isAvailable = false;
            
            foreach ($availability['available_slots'] as $slot) {
                if ($slot['time'] === $timeFormatted) {
                    $isAvailable = true;
                    break;
                }
            }
            
            if ($isAvailable) {
                return [
                    'success' => true,
                    'available' => true,
                    'message' => "Horário disponível",
                    'date' => $date,
                    'time' => $time,
                    'attendant' => $availability['attendant'],
                    'service_duration' => $serviceDuration
                ];
            } else {
                return [
                    'success' => true,
                    'available' => false,
                    'message' => "Horário não disponível",
                    'date' => $date,
                    'time' => $time,
                    'attendant' => $availability['attendant']
                ];
            }
            
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
     * Obter todos os atendentes com horários disponíveis em uma data
     * 
     * @param string $date Data (YYYY-MM-DD)
     * @param string $serviceId ID do serviço (opcional)
     * @return array Atendentes disponíveis com seus horários
     */
    public function getAvailableAttendants($date, $serviceId = null) {
        try {
            // Validar data
            if (!$this->isValidDate($date)) {
                return [
                    'success' => false,
                    'message' => 'Formato de data inválido. Use YYYY-MM-DD'
                ];
            }
            
            // Obter o dia da semana
            $dateObj = new DateTime($date);
            $dayOfWeek = $this->getDayName($dateObj->format('N'));
            
            // Listar todos os atendentes
            $attendants = $this->listAttendants();
            
            if (empty($attendants)) {
                return [
                    'success' => false,
                    'message' => 'Nenhum atendente cadastrado'
                ];
            }
            
            $result = [
                'success' => true,
                'date' => $date,
                'day_of_week' => $dayOfWeek,
                'attendants' => []
            ];
            
            // Para cada atendente, verificar disponibilidade
            foreach ($attendants as $attendant) {
                // Verificar se o atendente trabalha neste dia
                if (in_array($dayOfWeek, $attendant['work_days'])) {
                    // Verificar disponibilidade
                    $availability = $this->getAvailableTimeSlots($attendant['id'], $date, $serviceId);
                    
                    if ($availability['success'] && !empty($availability['available_slots'])) {
                        $result['attendants'][] = [
                            'id' => $attendant['id'],
                            'name' => $attendant['name'],
                            'position' => $attendant['position'],
                            'available_slots' => $availability['available_slots']
                        ];
                    }
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Erro ao obter atendentes disponíveis: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao obter atendentes disponíveis: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtém os horários configurados para um atendente em um determinado dia da semana
     * 
     * @param string $attendantId ID do atendente
     * @param string $dayOfWeek Dia da semana (Segunda, Terça, etc.)
     * @return array Horários configurados
     */
    private function getAttendantSchedules($attendantId, $dayOfWeek) {
        try {
            // Obter todos os schedule_assignments para o atendente
            $scheduleAssignments = $this->makeSupabaseRequest(
                "/rest/v1/schedule_assignments",
                "GET",
                null,
                [
                    'select' => 'id,schedule_id,schedule_info',
                    'attendant_id' => "eq.$attendantId"
                ]
            );
            
            if (empty($scheduleAssignments)) {
                error_log("Nenhum schedule_assignment encontrado para o atendente $attendantId");
                return [];
            }
            
            $scheduleIds = array_map(function($assignment) {
                return $assignment['schedule_id'];
            }, $scheduleAssignments);
            
            if (empty($scheduleIds)) {
                error_log("Nenhum schedule_id encontrado nos assignments");
                return [];
            }
            
            // Consultar cada schedule individualmente para evitar problemas de formato
            $validSchedules = [];
            
            foreach ($scheduleIds as $scheduleId) {
                // Obter o schedule e verificar se está disponível
                $scheduleResult = $this->makeSupabaseRequest(
                    "/rest/v1/schedules",
                    "GET",
                    null,
                    [
                        'select' => '*',
                        'id' => "eq.$scheduleId",
                        'available' => 'eq.true'
                    ]
                );
                
                // Se encontrou o schedule e está disponível para o dia da semana
                if (!empty($scheduleResult) && isset($scheduleResult[0])) {
                    $schedule = $scheduleResult[0];
                    
                    // Verificar se este horário é para o dia da semana especificado
                    if (
                        (isset($schedule['days']) && is_array($schedule['days']) && in_array($dayOfWeek, $schedule['days'])) || 
                        (isset($schedule['day']) && $schedule['day'] === $dayOfWeek)
                    ) {
                        $validSchedules[] = $schedule;
                    }
                }
            }
            
            error_log("Encontrados " . count($validSchedules) . " horários válidos para $attendantId em $dayOfWeek");
            return $validSchedules;
            
        } catch (Exception $e) {
            error_log("Erro ao obter horários do atendente: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtém os agendamentos existentes para um atendente em uma data específica
     * 
     * @param string $attendantId ID do atendente
     * @param string $date Data (YYYY-MM-DD)
     * @return array Agendamentos encontrados
     */
    private function getBookedAppointments($attendantId, $date) {
        try {
            return $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "GET",
                null,
                [
                    'select' => 'id,appointment_time,service_duration,status',
                    'attendant_id' => "eq.$attendantId",
                    'appointment_date' => "eq.$date",
                    'status' => "in.(aguardando_atendimento,atendimento_iniciado)" // Apenas agendamentos ativos
                ]
            );
            
        } catch (Exception $e) {
            error_log("Erro ao obter agendamentos: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Calcula os slots de horário disponíveis com base nos horários do atendente e agendamentos existentes
     * 
     * @param array $schedules Horários do atendente
     * @param array $bookedAppointments Agendamentos existentes
     * @param int $serviceDuration Duração do serviço em minutos
     * @return array Slots disponíveis
     */
    private function calculateAvailableSlots($schedules, $bookedAppointments, $serviceDuration) {
        $availableSlots = [];
        $today = date('Y-m-d');
        $isToday = false;
        $currentDateTime = new DateTime();
        $minimumTime = clone $currentDateTime;
        $minimumTime->modify('+30 minutes'); // Margem de 30 minutos para agendamentos
        
        // Verificar se a data é hoje para considerar o horário atual
        if (isset($schedules[0]['date']) && $schedules[0]['date'] === $today) {
            $isToday = true;
        }
        
        // Log para diagnóstico
        error_log("Calculando slots disponíveis para " . count($schedules) . " horários e " . count($bookedAppointments) . " agendamentos existentes");
        
        // Para cada horário configurado
        foreach ($schedules as $schedule) {
            // Verificar se o horário está definido e tem formato válido
            if (!isset($schedule['start_time']) || empty($schedule['start_time'])) {
                continue;
            }
            
            $startTime = $schedule['start_time'];
            $duration = isset($schedule['duration']) ? $schedule['duration'] : $serviceDuration;
            
            // Criar slot para este horário
            try {
                $slotDateTime = new DateTime($startTime);
                
                // Verificar se o horário já passou (para o dia de hoje)
                if ($isToday && $slotDateTime < $minimumTime) {
                    // Pular este horário se já passou
                    continue;
                }
                
                // Verificar se o slot conflita com algum agendamento existente
                $isAvailable = true;
                
                foreach ($bookedAppointments as $appointment) {
                    if (!isset($appointment['appointment_time'])) {
                        continue;
                    }
                    
                    $appointmentTime = $appointment['appointment_time'];
                    $appointmentDuration = isset($appointment['service_duration']) ? $appointment['service_duration'] : 30;
                    
                    try {
                        $appointmentStart = new DateTime($appointmentTime);
                        $appointmentEnd = clone $appointmentStart;
                        $appointmentEnd->modify("+{$appointmentDuration} minutes");
                        
                        $slotEnd = clone $slotDateTime;
                        $slotEnd->modify("+{$serviceDuration} minutes");
                        
                        // Verificar sobreposição de horários
                        if (
                            ($slotDateTime >= $appointmentStart && $slotDateTime < $appointmentEnd) ||
                            ($slotEnd > $appointmentStart && $slotEnd <= $appointmentEnd) ||
                            ($slotDateTime <= $appointmentStart && $slotEnd >= $appointmentEnd)
                        ) {
                            $isAvailable = false;
                            error_log("Conflito detectado: slot " . $startTime . " conflita com agendamento " . $appointmentTime);
                            break;
                        }
                    } catch (Exception $e) {
                        error_log("Erro ao processar horário de agendamento: " . $e->getMessage());
                        continue;
                    }
                }
                
                if ($isAvailable) {
                    $availableSlots[] = [
                        'time' => $startTime,
                        'duration' => $serviceDuration,
                        'formatted_time' => $this->formatTime($startTime)
                    ];
                }
            } catch (Exception $e) {
                error_log("Erro ao processar horário de agenda: " . $e->getMessage());
                continue;
            }
        }
        
        // Ordenar por horário
        usort($availableSlots, function($a, $b) {
            return strcmp($a['time'], $b['time']);
        });
        
        error_log("Total de " . count($availableSlots) . " slots disponíveis encontrados");
        return $availableSlots;
    }
    
    /**
     * Obtém informações de um atendente pelo ID
     * 
     * @param string $attendantId ID do atendente
     * @return array|null Dados do atendente ou null se não encontrado
     */
    private function getAttendantById($attendantId) {
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
            
            return !empty($result) ? $result[0] : null;
            
        } catch (Exception $e) {
            error_log("Erro ao buscar atendente: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtém informações de um serviço pelo ID
     * 
     * @param string $serviceId ID do serviço
     * @return array|null Dados do serviço ou null se não encontrado
     */
    private function getServiceById($serviceId) {
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
            
            return !empty($result) ? $result[0] : null;
            
        } catch (Exception $e) {
            error_log("Erro ao buscar serviço: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Lista todos os atendentes ativos
     * 
     * @return array Lista de atendentes
     */
    private function listAttendants() {
        try {
            return $this->makeSupabaseRequest(
                "/rest/v1/attendants",
                "GET",
                null,
                [
                    'select' => '*',
                    'available' => 'eq.true',
                    'order' => 'name.asc'
                ]
            );
            
        } catch (Exception $e) {
            error_log("Erro ao listar atendentes: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Valida se uma string está no formato de data YYYY-MM-DD
     * 
     * @param string $date Data a ser validada
     * @return bool Resultado da validação
     */
    private function isValidDate($date) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        
        $dateTime = DateTime::createFromFormat('Y-m-d', $date);
        return $dateTime && $dateTime->format('Y-m-d') === $date;
    }
    
    /**
     * Formata o horário para exibição amigável
     * 
     * @param string $time Horário no formato HH:MM:SS
     * @return string Horário formatado
     */
    private function formatTime($time) {
        $timeObj = DateTime::createFromFormat('H:i:s', $time);
        if (!$timeObj) {
            return $time;
        }
        
        return $timeObj->format('H:i');
    }
    
    /**
     * Converte o número do dia da semana para o nome em português
     * 
     * @param int $dayNumber Número do dia (1-7, sendo 1=Segunda e 7=Domingo)
     * @return string Nome do dia da semana
     */
    private function getDayName($dayNumber) {
        $days = [
            1 => 'Segunda',
            2 => 'Terça',
            3 => 'Quarta',
            4 => 'Quinta',
            5 => 'Sexta',
            6 => 'Sábado',
            7 => 'Domingo'
        ];
        
        return $days[$dayNumber] ?? 'Desconhecido';
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