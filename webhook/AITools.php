<?php
/**
 * AITools.php
 * 
 * Classe responsável por implementar as ferramentas (tools) que podem ser utilizadas
 * pelo assistente de IA durante as conversas com os usuários.
 */

require_once __DIR__ . '/DateHelper.php';
require_once __DIR__ . '/ServiceManager.php';
require_once __DIR__ . '/AttendantManager.php';
require_once __DIR__ . '/ScheduleService.php';
require_once __DIR__ . '/PhoneNumberUtility.php';

class AITools {
    private $supabaseUrl;
    private $supabaseKey;
    private $appointmentService;
    private $serviceManager;
    private $attendantManager;
    private $scheduleService;
    
    /**
     * Construtor
     * 
     * @param string $supabaseUrl URL do Supabase
     * @param string $supabaseKey Chave do Supabase
     */
    public function __construct($supabaseUrl, $supabaseKey) {
        // Sempre inicializar o timezone
        DateHelper::init();
        
        $this->supabaseUrl = $supabaseUrl;
        $this->supabaseKey = $supabaseKey;
        
        // Inicializar serviços necessários
        require_once __DIR__ . '/AppointmentService.php';
        require_once __DIR__ . '/ServiceManager.php';
        require_once __DIR__ . '/AttendantManager.php';
        require_once __DIR__ . '/ScheduleService.php';
        
        $this->appointmentService = new AppointmentService($supabaseUrl, $supabaseKey);
        $this->serviceManager = new ServiceManager($supabaseUrl, $supabaseKey);
        $this->attendantManager = new AttendantManager($supabaseUrl, $supabaseKey);
        $this->scheduleService = new ScheduleService($supabaseUrl, $supabaseKey);
    }
    
    /**
     * Listar todos os serviços disponíveis
     * 
     * @return string Resposta formatada com a lista de serviços
     */
    public function listar_servicos() {
        try {
            $result = $this->serviceManager->listServices(true);
            
            if (!$result['success'] || empty($result['data'])) {
                return "Desculpe, não encontrei nenhum serviço disponível no momento.";
            }
            
            $response = "Aqui estão os serviços disponíveis:\n\n";
            
            foreach ($result['data'] as $service) {
                $preco = number_format($service['price'], 2, ',', '.');
                $duracao = $service['duration'] . ' minutos';
                $response .= "- **{$service['name']}**\n";
                if (isset($service['description'])) {
                    $response .= "  Descrição: {$service['description']}\n";
                }
                $response .= "  Preço: R$ {$preco}\n";
                $response .= "  Duração: {$duracao}\n\n";
            }
            
            $response .= "Por favor, me informe qual serviço você deseja agendar.";
            
            return $response;
            
        } catch (Exception $e) {
            error_log("Erro ao listar serviços: " . $e->getMessage());
            return "Desculpe, ocorreu um erro ao buscar a lista de serviços. Por favor, tente novamente mais tarde.";
        }
    }
    
    /**
     * Processar a escolha de serviço do cliente
     * 
     * @param string $input Entrada do cliente contendo a escolha do serviço
     * @return string Resposta formatada com o serviço selecionado
     */
    public function processar_servicos($input) {
        try {
            // Verifica se o input parece um UUID
            $isUUID = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($input));
            
            if ($isUUID) {
                $serviceId = trim($input);
                $service = $this->makeSupabaseRequest(
                    "/rest/v1/services",
                    "GET",
                    null,
                    [
                        'id' => "eq.$serviceId",
                        'limit' => 1
                    ]
                );
                
                if (empty($service)) {
                    return "Desculpe, não encontrei um serviço com o ID {$serviceId}. Por favor, escolha um serviço válido.";
                }
                
                $service = $service[0];
                $preco = number_format($service['price'], 2, ',', '.');
                
                return "Você selecionou o serviço **{$service['name']}**.\n" .
                       "Preço: R$ {$preco}\n" .
                       "Duração: {$service['duration']} minutos\n\n" .
                       "Vamos prosseguir com o agendamento deste serviço.";
            }
            
            // Caso contrário, procurar por nome
            $servicos = $this->makeSupabaseRequest(
                "/rest/v1/services",
                "GET",
                null,
                [
                    'is_active' => 'eq.true'
                ]
            );
            
            if (empty($servicos)) {
                return "Desculpe, ocorreu um erro ao buscar os serviços. Por favor, tente novamente mais tarde.";
            }
            
            $matchedService = null;
            $input = strtolower($input);
            
            foreach ($servicos as $service) {
                if (strpos(strtolower($service['name']), $input) !== false) {
                    $matchedService = $service;
                    break;
                }
            }
            
            if ($matchedService) {
                $preco = number_format($matchedService['price'], 2, ',', '.');
                
                return "Você selecionou o serviço **{$matchedService['name']}**.\n" .
                       "Preço: R$ {$preco}\n" .
                       "Duração: {$matchedService['duration']} minutos\n\n" .
                       "Vamos prosseguir com o agendamento deste serviço.";
            }
            
            return "Desculpe, não encontrei um serviço correspondente a '{$input}'. Por favor, escolha um dos serviços disponíveis.";
            
        } catch (Exception $e) {
            error_log("Erro ao processar serviço: " . $e->getMessage());
            return "Desculpe, ocorreu um erro ao processar sua escolha de serviço. Por favor, tente novamente mais tarde.";
        }
    }
    
    /**
     * Listar atendentes disponíveis para um serviço
     * 
     * @param string $serviceId ID do serviço (opcional)
     * @return string Resposta formatada com a lista de atendentes
     */
    public function listar_atendentes($serviceId = null) {
        try {
            // Se tiver serviceId, filtrar atendentes que oferecem esse serviço
            if ($serviceId) {
                // Verificar se o serviço existe
                $serviceResult = $this->makeSupabaseRequest(
                    "/rest/v1/services",
                    "GET",
                    null,
                    [
                        'id' => "eq.$serviceId",
                        'limit' => 1
                    ]
                );
                
                if (empty($serviceResult)) {
                    return "Desculpe, o serviço com ID {$serviceId} não foi encontrado.";
                }
                
                $serviceName = $serviceResult[0]['name'];
                
                // Buscar atendentes que oferecem este serviço
                $atendentes = $this->makeSupabaseRequest(
                    "/rest/v1/service_assignments",
                    "GET",
                    null,
                    [
                        'service_id' => "eq.$serviceId"
                    ]
                );
                
                if (empty($atendentes)) {
                    return "Desculpe, não encontrei atendentes disponíveis para o serviço de {$serviceName}.";
                }
                
                $response = "Aqui estão os profissionais disponíveis para {$serviceName}:\n\n";
                
                foreach ($atendentes as $assignment) {
                    // Verificar se o atendente está disponível
                    $attendant = $this->makeSupabaseRequest(
                        "/rest/v1/attendants",
                        "GET",
                        null,
                        [
                            'id' => "eq.{$assignment['attendant_id']}",
                            'available' => 'eq.true',
                            'limit' => 1
                        ]
                    );
                    
                    if (!empty($attendant)) {
                        $response .= "- **{$attendant[0]['name']}**\n";
                        if (!empty($attendant[0]['position'])) {
                            $response .= "  Cargo: {$attendant[0]['position']}\n";
                        }
                        $response .= "\n";
                    }
                }
            } else {
                // Listar todos os atendentes disponíveis
                $atendentes = $this->makeSupabaseRequest(
                    "/rest/v1/attendants",
                    "GET",
                    null,
                    [
                        'available' => 'eq.true',
                        'order' => 'name.asc'
                    ]
                );
                
                if (empty($atendentes)) {
                    return "Desculpe, não encontrei nenhum profissional disponível no momento.";
                }
                
                $response = "Aqui estão os profissionais disponíveis:\n\n";
                
                foreach ($atendentes as $attendant) {
                    $response .= "- **{$attendant['name']}**\n";
                    if (!empty($attendant['position'])) {
                        $response .= "  Cargo: {$attendant['position']}\n";
                    }
                    $response .= "\n";
                }
            }
            
            // Verificar se algum atendente foi encontrado
            if (strpos($response, "**") === false) {
                return "Desculpe, não encontrei nenhum profissional disponível para este serviço no momento.";
            }
            
            $response .= "Por favor, me informe com qual profissional você deseja agendar.";
            
            return $response;
            
        } catch (Exception $e) {
            error_log("Erro ao listar atendentes: " . $e->getMessage());
            return "Desculpe, ocorreu um erro ao buscar a lista de profissionais. Por favor, tente novamente mais tarde.";
        }
    }
    
    /**
     * Verificar disponibilidade para um atendente, serviço e data
     * 
     * @param string $attendantId ID do atendente
     * @param string $date Data em formato natural (DD/MM/YYYY ou expressão)
     * @param string $serviceId ID do serviço (opcional)
     * @return string Resposta formatada com a disponibilidade
     */
    public function verificar_disponibilidade($attendantId, $date, $serviceId = null) {
        try {
            // Validar os parâmetros
            if (empty($attendantId) || empty($date)) {
                return "Parâmetros insuficientes. Por favor, forneça o ID do profissional e a data.";
            }
            
            // Processar e validar a data usando o DateHelper
            $processedDate = DateHelper::processDateExpression($date);
            
            if (!$processedDate['success']) {
                return "Data inválida ou não reconhecida. Por favor, forneça uma data válida.";
            }
            
            // Obter data formatada para uso interno (YYYY-MM-DD)
            $formattedDate = $processedDate['iso_date'];
            // Obter data formatada para exibição (DD/MM/YYYY)
            $displayDate = $processedDate['date'];
            // Obter dia da semana
            $dayOfWeek = $processedDate['day_of_week'];
            
            error_log("Verificando disponibilidade para o atendente ID: $attendantId na data: $displayDate ($dayOfWeek) [formato interno: $formattedDate]");
            
            // Verificar se o atendente existe
            $attendant = $this->makeSupabaseRequest(
                "/rest/v1/attendants",
                "GET",
                null,
                [
                    'id' => "eq.$attendantId",
                    'limit' => 1
                ]
            );
            
            if (empty($attendant)) {
                error_log("Atendente não encontrado com ID: $attendantId");
                return "Desculpe, não encontrei um profissional com o ID {$attendantId}.";
            }
            
            $attendantName = $attendant[0]['name'];
            error_log("Atendente encontrado: $attendantName (ID: $attendantId)");
            
            // Verificar se o atendente trabalha no dia da semana da data
            $weekday = date('l', strtotime($formattedDate));
            $weekdayPt = $this->translateWeekday($weekday);
            $weekdayPtFull = $weekdayPt . "-feira";
            if ($weekdayPt == 'Sábado' || $weekdayPt == 'Domingo') {
                $weekdayPtFull = $weekdayPt;
            }
            
            // Verificar se está nos dias de trabalho do atendente
            $workDays = $attendant[0]['work_days'];
            if (!in_array($weekdayPt, $workDays)) {
                error_log("Atendente $attendantName não trabalha em $weekdayPt");
                return "Desculpe, {$attendantName} não atende no dia $displayDate ($dayOfWeek).";
            }
            
            // Duração do serviço (se fornecido)
            $serviceDuration = 30; // Duração padrão
            if ($serviceId) {
                $service = $this->makeSupabaseRequest(
                    "/rest/v1/services",
                    "GET",
                    null,
                    [
                        'id' => "eq.$serviceId",
                        'limit' => 1
                    ]
                );
                
                if (!empty($service) && isset($service[0]['duration'])) {
                    $serviceDuration = $service[0]['duration'];
                    error_log("Serviço encontrado: {$service[0]['name']} com duração de {$serviceDuration} minutos");
                }
            }
            
            // 1. Verificar o horário de funcionamento do estabelecimento para o dia
            $businessHours = $this->makeSupabaseRequest(
                "/rest/v1/business_hours",
                "GET",
                null,
                [
                    'day_of_week' => "eq.$weekdayPtFull",
                    'limit' => 1
                ]
            );
            
            if (empty($businessHours) || $businessHours[0]['is_closed']) {
                error_log("Estabelecimento fechado em $weekdayPtFull");
                return "Desculpe, o estabelecimento não funciona no dia $displayDate ($dayOfWeek).";
            }
            
            // 2. Obter os horários de trabalho do atendente através de schedule_assignments
            $scheduleAssignments = $this->makeSupabaseRequest(
                "/rest/v1/schedule_assignments",
                "GET",
                null,
                [
                    'attendant_id' => "eq.$attendantId"
                ]
            );
            
            if (empty($scheduleAssignments)) {
                error_log("Nenhum horário configurado para o atendente $attendantName");
                return "Desculpe, não encontrei horários de trabalho cadastrados para {$attendantName}.";
            }
            
            // Registro para debug
            error_log("Obtido " . count($scheduleAssignments) . " atribuições de horário para o atendente $attendantName");
            
            // Obter os IDs dos schedules
            $scheduleIds = [];
            foreach ($scheduleAssignments as $assignment) {
                $scheduleIds[] = $assignment['schedule_id'];
                error_log("Schedule assignment: " . $assignment['schedule_info'] . " (ID: " . $assignment['schedule_id'] . ")");
            }
            
            if (empty($scheduleIds)) {
                error_log("Nenhum ID de horário configurado para o atendente $attendantName");
                return "Desculpe, não encontrei horários configurados para {$attendantName}.";
            }
            
            // 3. Obter todos os schedules disponíveis
            $allSchedules = $this->makeSupabaseRequest(
                "/rest/v1/schedules",
                "GET",
                null,
                [
                    'available' => 'eq.true' // Apenas horários marcados como disponíveis
                ]
            );
            
            error_log("Total de schedules no sistema: " . count($allSchedules));
            
            // Filtrar schedules pelo ID do atendente
            $filteredSchedules = [];
            foreach ($allSchedules as $schedule) {
                if (in_array($schedule['id'], $scheduleIds)) {
                    $filteredSchedules[] = $schedule;
                    error_log("Incluindo schedule ID: " . $schedule['id'] . " - Horário: " . $schedule['start_time'] . " - Dias: " . json_encode($schedule['days']));
                }
            }
            
            error_log("Obtido " . count($filteredSchedules) . " schedules filtrados para o atendente $attendantName");
            
            if (empty($filteredSchedules)) {
                error_log("Nenhum horário disponível para o atendente $attendantName");
                return "Desculpe, não encontrei horários disponíveis para {$attendantName}.";
            }
            
            // 4. Filtrar schedules que são válidos para o dia da semana
            $validSchedules = [];
            
            foreach ($filteredSchedules as $schedule) {
                $scheduleDays = $schedule['days'] ?? [];
                $scheduleDay = $schedule['day'] ?? null;
                
                error_log("Verificando schedule para dia " . $weekdayPt . ": " . json_encode($scheduleDays) . " ou " . $scheduleDay);
                
                // Verificar se o dia atual está na lista de dias do schedule
                if (!empty($scheduleDays) && in_array($weekdayPt, $scheduleDays)) {
                    error_log("Adicionando schedule ID " . $schedule['id'] . " - Dia encontrado em days[]");
                    $validSchedules[] = $schedule;
                } 
                // Ou verificar se o campo 'day' corresponde ao dia atual
                else if ($scheduleDay == $weekdayPt) {
                    error_log("Adicionando schedule ID " . $schedule['id'] . " - Dia encontrado em day");
                    $validSchedules[] = $schedule;
                }
            }
            
            error_log("Após filtrar por dia da semana, obtive " . count($validSchedules) . " schedules válidos");
            
            if (empty($validSchedules)) {
                error_log("Nenhum horário válido para o dia $weekdayPt");
                return "Desculpe, {$attendantName} não tem horários disponíveis para $dayOfWeek.";
            }
            
            // 5. Obter os horários disponíveis do atendente
            $availableTimes = [];
            foreach ($validSchedules as $schedule) {
                $startTime = substr($schedule['start_time'], 0, 5); // HH:MM formato
                $availableTimes[$startTime] = [
                    'time' => $startTime,
                    'duration' => isset($schedule['duration']) ? $schedule['duration'] : $serviceDuration
                ];
            }
            
            error_log("Horários disponíveis antes de verificar agendamentos: " . json_encode(array_keys($availableTimes)));
            
            // 6. Buscar TODOS os agendamentos existentes para este atendente neste dia (não apenas os que estão com status específico)
            $existingAppointments = $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "GET",
                null,
                [
                    'attendant_id' => "eq.$attendantId",
                    'appointment_date' => "eq.$formattedDate"
                ]
            );
            
            error_log("Encontrado " . count($existingAppointments) . " agendamentos existentes para $attendantName em $formattedDate");
            
            // Filtrar apenas os agendamentos ativos (não cancelados)
            $activeAppointments = [];
            foreach ($existingAppointments as $appointment) {
                if ($appointment['status'] != 'agendamento_cancelado') {
                    $activeAppointments[] = $appointment;
                    error_log("Agendamento ativo encontrado: " . $appointment['id'] . 
                              " - Cliente: " . $appointment['client_name'] . 
                              " - Status: " . $appointment['status'] . 
                              " - Horário: " . $appointment['appointment_time']);
                }
            }
            
            error_log("Após filtrar cancelados, restaram " . count($activeAppointments) . " agendamentos ativos");
            
            // 7. Remover horários já agendados considerando a duração do serviço
            foreach ($activeAppointments as $appointment) {
                $appointmentTime = substr($appointment['appointment_time'], 0, 5); // HH:MM formato
                $appointmentDuration = isset($appointment['service_duration']) ? $appointment['service_duration'] : 30;
                
                error_log("Verificando conflito para agendamento às $appointmentTime com duração de $appointmentDuration minutos");
                
                // Converter horas para minutos para cálculos
                list($appointmentHour, $appointmentMinute) = explode(':', $appointmentTime);
                $appointmentStartMinutes = ($appointmentHour * 60) + $appointmentMinute;
                $appointmentEndMinutes = $appointmentStartMinutes + $appointmentDuration;
                
                // Verificar conflitos com os horários disponíveis
                foreach ($availableTimes as $time => $slot) {
                    list($slotHour, $slotMinute) = explode(':', $time);
                    $slotStartMinutes = ($slotHour * 60) + $slotMinute;
                    $slotEndMinutes = $slotStartMinutes + $slot['duration'];
                    
                    // Verificar sobreposição:
                    // 1. Se o início do slot está dentro do período do agendamento
                    // 2. Se o fim do slot está dentro do período do agendamento
                    // 3. Se o slot contém completamente o agendamento
                    if (
                        ($slotStartMinutes >= $appointmentStartMinutes && $slotStartMinutes < $appointmentEndMinutes) || 
                        ($slotEndMinutes > $appointmentStartMinutes && $slotEndMinutes <= $appointmentEndMinutes) ||
                        ($slotStartMinutes <= $appointmentStartMinutes && $slotEndMinutes >= $appointmentEndMinutes)
                    ) {
                        // Remover este slot por conflito
                        error_log("Conflito detectado: removendo horário $time (conflito com agendamento às $appointmentTime)");
                        unset($availableTimes[$time]);
                    }
                }
            }
            
            error_log("Horários disponíveis após verificar conflitos: " . json_encode(array_keys($availableTimes)));
            
            // 8. Verificar se há horários disponíveis após remoção de conflitos
            if (empty($availableTimes)) {
                error_log("Nenhum horário disponível após verificar conflitos");
                return "Desculpe, não há horários disponíveis para {$attendantName} na data $displayDate ($dayOfWeek).";
            }
            
            // 9. Ordenar os horários disponíveis
            ksort($availableTimes);
            
            error_log("Horários finais ordenados: " . json_encode(array_keys($availableTimes)));
            
            // IMPORTANTE: Verificar agendamentos existentes para exibi-los no início da resposta
            $bookedTimesMessage = "";
            if (!empty($activeAppointments)) {
                $bookedTimes = [];
                foreach ($activeAppointments as $appointment) {
                    $time = substr($appointment['appointment_time'], 0, 5); // HH:MM formato
                    $bookedTimes[] = $time;
                }
                sort($bookedTimes);
                $bookedTimesMessage = "O {$attendantName} já possui agendamentos para $displayDate às " . implode(' e ', $bookedTimes) . ". ";
            }
            
            $response = "Disponibilidade para {$attendantName} em $displayDate ($dayOfWeek):\n\n";
            
            if (!empty($bookedTimesMessage)) {
                $response = $bookedTimesMessage . "Os horários disponíveis para o " . (isset($service[0]['name']) ? $service[0]['name'] : "serviço") . " são:\n\n";
            }
            
            foreach ($availableTimes as $timeSlot) {
                $response .= "- {$timeSlot['time']}\n";
            }
            
            $response .= "\nPor favor, escolha um dos horários disponíveis.";
            
            return $response;
            
        } catch (Exception $e) {
            error_log("Erro ao verificar disponibilidade: " . $e->getMessage());
            return "Desculpe, ocorreu um erro ao verificar a disponibilidade: " . $e->getMessage();
        }
    }
    
    /**
     * Listar horários disponíveis para um atendente em uma data específica
     * 
     * @param string $attendantId ID do atendente
     * @param string $date Data no formato YYYY-MM-DD
     * @param string $serviceId ID do serviço (opcional)
     * @return string Resposta formatada com os horários disponíveis
     */
    public function listar_horarios($attendantId, $date, $serviceId = null) {
        // Listar horários é essencialmente verificar disponibilidade
        return $this->verificar_disponibilidade($attendantId, $date, $serviceId);
    }
    
    /**
     * Criar um novo agendamento
     * 
     * @param string $clientName Nome do cliente
     * @param string $clientPhone Telefone do cliente
     * @param string $serviceId ID do serviço
     * @param string $attendantId ID do atendente
     * @param string $date Data no formato DD/MM/YYYY
     * @param string $time Horário no formato HH:MM
     * @param string $notes Observações (opcional)
     * @return string Resposta formatada com o resultado do agendamento
     */
    public function criar_agendamento($clientName, $clientPhone, $serviceId, $attendantId, $date, $time, $notes = '') {
        try {
            // Validar os parâmetros
            if (empty($clientName) || empty($clientPhone) || empty($serviceId) || 
                empty($attendantId) || empty($date) || empty($time)) {
                return "Parâmetros insuficientes. Por favor, forneça todos os dados necessários para o agendamento.";
            }
            
            // Processar a data usando o DateHelper
            if (DateHelper::isValidDate($date)) {
                $formattedDate = DateHelper::convertFormat($date, 'd/m/Y', 'Y-m-d');
                $displayDate = $date;
                $dayOfWeek = DateHelper::getDayOfWeek($date);
            } else {
                $processedDate = DateHelper::processDateExpression($date);
                if (!$processedDate['success']) {
                    return "Data inválida. Por favor, forneça uma data no formato DD/MM/YYYY.";
                }
                $formattedDate = $processedDate['iso_date'];
                $displayDate = $processedDate['date'];
                $dayOfWeek = $processedDate['day_of_week'];
            }
            
            // Verificar se o serviço existe
            $service = $this->makeSupabaseRequest(
                "/rest/v1/services",
                "GET",
                null,
                [
                    'id' => "eq.$serviceId",
                    'limit' => 1
                ]
            );
            
            if (empty($service)) {
                return "Desculpe, o serviço com ID {$serviceId} não foi encontrado.";
            }
            
            // Verificar se o atendente existe
            $attendant = $this->makeSupabaseRequest(
                "/rest/v1/attendants",
                "GET",
                null,
                [
                    'id' => "eq.$attendantId",
                    'limit' => 1
                ]
            );
            
            if (empty($attendant)) {
                return "Desculpe, não encontrei um profissional com o ID {$attendantId}.";
            }
            
            // Formatar o telefone (remover qualquer caractere não numérico e adicionar DDI 55 se necessário)
            $clientPhone = PhoneNumberUtility::formatPhoneNumber($clientPhone);
            
            // Formatando a data e hora completa
            $appointmentDatetime = date('Y-m-d H:i:s', strtotime("$formattedDate $time"));
            
            // Preparar os dados para o agendamento
            $appointmentData = [
                'id' => $this->generateUUID(),
                'client_name' => $clientName,
                'client_phone' => $clientPhone,
                'service_id' => $serviceId,
                'service_name' => $service[0]['name'],
                'service_price' => $service[0]['price'],
                'service_duration' => $service[0]['duration'],
                'attendant_id' => $attendantId,
                'attendant_name' => $attendant[0]['name'],
                'appointment_date' => $formattedDate,
                'appointment_time' => $time,
                'appointment_datetime' => $appointmentDatetime,
                'notes' => $notes,
                'status' => 'aguardando_atendimento',
                'created_at' => date('c'),
                'updated_at' => date('c')
            ];
            
            // Criar o agendamento
            $result = $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "POST",
                $appointmentData
            );
            
            if (empty($result)) {
                return "Desculpe, ocorreu um erro ao criar o agendamento.";
            }
            
            $response = "Ótimo! Seu agendamento foi confirmado com sucesso.\n\n" .
                       "**Detalhes do Agendamento:**\n" .
                       "- **Serviço**: {$service[0]['name']}\n" .
                       "- **Profissional**: {$attendant[0]['name']}\n" .
                       "- **Data**: $displayDate ($dayOfWeek)\n" .
                       "- **Horário**: {$time}\n" .
                       "- **Nome**: {$clientName}\n" .
                       "- **Telefone**: {$clientPhone}\n";
            
            if (!empty($notes)) {
                $response .= "- **Observações**: {$notes}\n";
            }
            
            $response .= "\nLembre-se de chegar com 10 minutos de antecedência. Caso precise remarcar ou cancelar, entre em contato conosco pelo menos 4 horas antes do horário agendado.";
            
            return $response;
            
        } catch (Exception $e) {
            error_log("Erro ao criar agendamento: " . $e->getMessage());
            return "Desculpe, ocorreu um erro ao criar o agendamento. Por favor, tente novamente mais tarde.";
        }
    }
    
    /**
     * Consultar agendamentos de um cliente pelo telefone
     * 
     * @param string $clientPhone Telefone do cliente
     * @return string Resposta formatada com os agendamentos do cliente
     */
    public function consultar_agendamentos($clientPhone) {
        try {
            // Formatar o telefone (remover qualquer caractere não numérico e adicionar DDI 55 se necessário)
            $clientPhone = PhoneNumberUtility::formatPhoneNumber($clientPhone);
            
            // Consultar agendamentos diretamente do Supabase
            $appointments = $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "GET",
                null,
                [
                    'client_phone' => "eq.$clientPhone",
                    'order' => 'appointment_datetime.desc'
                ]
            );
            
            if (empty($appointments)) {
                return "Não encontrei nenhum agendamento para o telefone {$clientPhone}.";
            }
            
            // Traduzir o status para algo mais amigável ao usuário
            $statusTranslation = [
                'aguardando_atendimento' => 'Aguardando Atendimento',
                'atendimento_iniciado' => 'Atendimento Iniciado',
                'atendimento_finalizado' => 'Atendimento Finalizado',
                'agendamento_cancelado' => 'Cancelado',
                'archived' => 'Arquivado'
            ];
            
            $response = "Encontrei os seguintes agendamentos para o telefone {$clientPhone}:\n\n";
            
            foreach ($appointments as $index => $appointment) {
                // Formatar a data com dia da semana
                $dateObj = DateHelper::processDateExpression($appointment['appointment_date']);
                $displayDate = $dateObj['date'];
                $dayOfWeek = $dateObj['day_of_week'];
                
                // Obter status traduzido
                $statusDisplay = isset($statusTranslation[$appointment['status']]) ? 
                                    $statusTranslation[$appointment['status']] : 
                                    ucfirst($appointment['status']);
                
                $response .= "📅 **Agendamento " . ($index + 1) . "**\n" .
                           "- **Serviço**: {$appointment['service_name']}\n" .
                           "- **Profissional**: {$appointment['attendant_name']}\n" .
                           "- **Data**: {$displayDate} ({$dayOfWeek})\n" .
                           "- **Horário**: " . substr($appointment['appointment_time'], 0, 5) . "\n" .
                           "- **Status**: {$statusDisplay}\n\n";
            }
            
            $response .= "Posso ajudar com mais alguma coisa relacionada a esses agendamentos?";
            
            return $response;
            
        } catch (Exception $e) {
            error_log("Erro ao consultar agendamentos: " . $e->getMessage());
            return "Desculpe, ocorreu um erro ao consultar os agendamentos. Por favor, tente novamente mais tarde.";
        }
    }
    
    /**
     * Cancelar um agendamento
     * 
     * @param string $appointmentId ID do agendamento
     * @param string $reason Motivo do cancelamento (opcional)
     * @return string Resposta formatada com o resultado do cancelamento
     */
    public function cancelar_agendamento($appointmentId, $reason = '') {
        try {
            // Validar os parâmetros
            if (empty($appointmentId)) {
                return "Por favor, forneça o ID do agendamento que deseja cancelar.";
            }
            
            // Verificar se o agendamento existe
            $existingAppointment = $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "GET",
                null,
                [
                    'id' => "eq.$appointmentId",
                    'limit' => 1
                ]
            );
            
            if (empty($existingAppointment)) {
                return "Desculpe, não encontrei um agendamento com o ID {$appointmentId}.";
            }
            
            $appointment = $existingAppointment[0];
            
            // Atualizar o status para cancelado
            $updateData = [
                'status' => 'agendamento_cancelado',
                'updated_at' => date('c')
            ];
            
            // Adicionar motivo nas notes, em vez de usar uma coluna separada
            if (!empty($reason)) {
                $notes = $appointment['notes'] ?? '';
                $updateData['notes'] = $notes . "\nMotivo do cancelamento: " . $reason;
            }
            
            $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "PATCH",
                $updateData,
                [
                    'id' => "eq.$appointmentId"
                ]
            );
            
            // Formatar a data com dia da semana para a resposta
            $dateObj = DateHelper::processDateExpression($appointment['appointment_date']);
            $displayDate = $dateObj['date'];
            $dayOfWeek = $dateObj['day_of_week'];
            $displayTime = substr($appointment['appointment_time'], 0, 5);
            
            $response = "✅ Agendamento cancelado com sucesso!\n\n";
            $response .= "**Detalhes do agendamento cancelado:**\n";
            $response .= "📅 {$displayDate} ({$dayOfWeek}) às {$displayTime}\n";
            $response .= "👤 Cliente: {$appointment['client_name']}\n";
            $response .= "✂️ Serviço: {$appointment['service_name']}\n";
            $response .= "👨‍💼 Profissional: {$appointment['attendant_name']}\n";
            
            if (!empty($reason)) {
                $response .= "📝 Motivo: {$reason}\n";
            }
            
            $response .= "\nO horário agora está disponível para outros clientes. Se desejar realizar um novo agendamento, estou à disposição para ajudar.";
            
            return $response;
            
        } catch (Exception $e) {
            error_log("Erro ao cancelar agendamento: " . $e->getMessage());
            return "Desculpe, ocorreu um erro ao cancelar o agendamento. Por favor, tente novamente mais tarde.";
        }
    }
    
    /**
     * Cancelar um agendamento pelo telefone do cliente
     * 
     * @param string $clientPhone Telefone do cliente
     * @param string $reason Motivo do cancelamento (opcional)
     * @return string Resposta formatada com o resultado do cancelamento
     */
    public function cancelar_agendamento_por_telefone($clientPhone, $reason = '') {
        try {
            // Formatar o telefone (remover qualquer caractere não numérico e adicionar DDI 55 se necessário)
            $clientPhone = PhoneNumberUtility::formatPhoneNumber($clientPhone);
            
            if (empty($clientPhone)) {
                return "Por favor, forneça o número de telefone para cancelar o agendamento.";
            }
            
            // Consultar agendamentos ativos pelo telefone
            $appointments = $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "GET",
                null,
                [
                    'client_phone' => "eq.$clientPhone",
                    'status' => "neq.agendamento_cancelado",
                    'order' => 'appointment_datetime.asc'
                ]
            );
            
            if (empty($appointments)) {
                return "Não encontrei agendamentos ativos para o telefone {$clientPhone}.";
            }
            
            // Se houver mais de um agendamento, listar todos para o cliente escolher
            if (count($appointments) > 1) {
                $response = "Encontrei " . count($appointments) . " agendamentos para este telefone:\n\n";
                
                // Criar um mapeamento temporário de números para IDs
                $_SESSION['appointment_map'] = [];
                $_SESSION['appointment_phone'] = $clientPhone;
                
                foreach ($appointments as $index => $appointment) {
                    // Formatar a data com dia da semana
                    $dateObj = DateHelper::processDateExpression($appointment['appointment_date']);
                    $displayDate = $dateObj['date'];
                    $dayOfWeek = $dateObj['day_of_week'];
                    
                    // Guardar o mapeamento do número sequencial para o ID real
                    $appointmentNumber = $index + 1;
                    $_SESSION['appointment_map'][$appointmentNumber] = $appointment['id'];
                    
                    $response .= "{$appointmentNumber}. *{$appointment['service_name']}* com {$appointment['attendant_name']}\n";
                    $response .= "   📅 {$displayDate} ({$dayOfWeek}) às " . substr($appointment['appointment_time'], 0, 5) . "\n\n";
                }
                
                $response .= "Por favor, informe o número do agendamento que deseja cancelar (1-" . count($appointments) . ").";
                return $response;
            }
            
            // Se houver apenas um agendamento, cancelá-lo diretamente
            $appointment = $appointments[0];
            $appointmentId = $appointment['id'];
            
            // Atualizar o status para cancelado
            $updateData = [
                'status' => 'agendamento_cancelado',
                'updated_at' => date('c')
            ];
            
            // Adicionar motivo nas notes
            if (!empty($reason)) {
                $notes = $appointment['notes'] ?? '';
                $updateData['notes'] = $notes . "\nMotivo do cancelamento: " . $reason;
            }
            
            $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "PATCH",
                $updateData,
                [
                    'id' => "eq.$appointmentId"
                ]
            );
            
            // Formatar a data com dia da semana para a resposta
            $dateObj = DateHelper::processDateExpression($appointment['appointment_date']);
            $displayDate = $dateObj['date'];
            $dayOfWeek = $dateObj['day_of_week'];
            $displayTime = substr($appointment['appointment_time'], 0, 5);
            
            $response = "✅ Agendamento cancelado com sucesso!\n\n";
            $response .= "**Detalhes do agendamento cancelado:**\n";
            $response .= "📅 {$displayDate} ({$dayOfWeek}) às {$displayTime}\n";
            $response .= "👤 Cliente: {$appointment['client_name']}\n";
            $response .= "✂️ Serviço: {$appointment['service_name']}\n";
            $response .= "👨‍💼 Profissional: {$appointment['attendant_name']}\n";
            
            if (!empty($reason)) {
                $response .= "📝 Motivo: {$reason}\n";
            }
            
            $response .= "\nO horário agora está disponível para outros clientes. Se desejar realizar um novo agendamento, estou à disposição para ajudar.";
            
            return $response;
            
        } catch (Exception $e) {
            error_log("Erro ao cancelar agendamento por telefone: " . $e->getMessage());
            return "Desculpe, ocorreu um erro ao cancelar o agendamento. Por favor, tente novamente mais tarde.";
        }
    }
    
    /**
     * Verificar o status de um agendamento
     * 
     * @param string $appointmentId ID do agendamento
     * @return string Resposta formatada com o status do agendamento
     */
    public function verificar_status_agendamento($appointmentId) {
        try {
            // Validar os parâmetros
            if (empty($appointmentId)) {
                return "Por favor, forneça o ID do agendamento que deseja verificar.";
            }
            
            // Obter o agendamento
            $appointment = $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "GET",
                null,
                [
                    'id' => "eq.{$appointmentId}",
                    'select' => '*'
                ]
            );
            
            if (empty($appointment)) {
                return "Desculpe, não encontrei um agendamento com o ID {$appointmentId}.";
            }
            
            $appointment = $appointment[0];
            
            // Obter detalhes do serviço
            $serviceResult = $this->serviceManager->getService($appointment['service_id']);
            $serviceName = $serviceResult['success'] ? $serviceResult['data']['name'] : "Serviço ID: {$appointment['service_id']}";
            
            // Obter detalhes do atendente
            $attendantResult = $this->attendantManager->getAttendant($appointment['attendant_id']);
            $attendantName = $attendantResult['success'] ? $attendantResult['data']['name'] : "Profissional ID: {$appointment['attendant_id']}";
            
            // Formatar a data com dia da semana
            $dateInfo = DateHelper::processDateExpression($appointment['appointment_date']);
            $displayDate = $dateInfo['date'];
            $dayOfWeek = $dateInfo['day_of_week'];
            
            // Traduzir o status para algo mais amigável ao usuário
            $statusTranslation = [
                'aguardando_atendimento' => 'Aguardando Atendimento',
                'atendimento_iniciado' => 'Atendimento Iniciado',
                'atendimento_finalizado' => 'Atendimento Finalizado',
                'agendamento_cancelado' => 'Cancelado',
                'archived' => 'Arquivado'
            ];
            
            $statusDisplay = isset($statusTranslation[$appointment['status']]) ? 
                                $statusTranslation[$appointment['status']] : 
                                ucfirst($appointment['status']);
            
            $response = "**Detalhes do Agendamento #{$appointmentId}**\n\n" .
                       "- **Cliente**: {$appointment['client_name']}\n" .
                       "- **Telefone**: {$appointment['client_phone']}\n" .
                       "- **Serviço**: {$serviceName}\n" .
                       "- **Profissional**: {$attendantName}\n" .
                       "- **Data**: {$displayDate} ({$dayOfWeek})\n" .
                       "- **Horário**: " . substr($appointment['appointment_time'], 0, 5) . "\n" .
                       "- **Status**: {$statusDisplay}\n";
            
            if (!empty($appointment['notes'])) {
                $response .= "- **Observações**: {$appointment['notes']}\n";
            }
            
            return $response;
            
        } catch (Exception $e) {
            error_log("Erro ao verificar status do agendamento: " . $e->getMessage());
            return "Desculpe, ocorreu um erro ao verificar o status do agendamento. Por favor, tente novamente mais tarde.";
        }
    }
    
    /**
     * Cancelar um agendamento pelo número na lista
     * 
     * @param int $appointmentNumber Número do agendamento na lista exibida
     * @param string $reason Motivo do cancelamento (opcional)
     * @return string Resposta formatada com o resultado do cancelamento
     */
    public function cancelar_agendamento_por_numero($appointmentNumber, $reason = '') {
        try {
            // Verificar se o mapeamento existe na sessão
            if (!isset($_SESSION['appointment_map']) || !isset($_SESSION['appointment_phone'])) {
                return "Por favor, liste os agendamentos primeiro usando o telefone do cliente.";
            }
            
            // Verificar se o número informado existe no mapeamento
            if (!isset($_SESSION['appointment_map'][$appointmentNumber])) {
                return "Número de agendamento inválido. Por favor, escolha um número entre 1 e " . count($_SESSION['appointment_map']) . ".";
            }
            
            // Obter o ID real do agendamento a partir do mapeamento
            $appointmentId = $_SESSION['appointment_map'][$appointmentNumber];
            $clientPhone = $_SESSION['appointment_phone'];
            
            // Obter os detalhes do agendamento
            $appointments = $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "GET",
                null,
                [
                    'id' => "eq.$appointmentId",
                    'limit' => 1
                ]
            );
            
            if (empty($appointments)) {
                return "Desculpe, não foi possível encontrar o agendamento selecionado.";
            }
            
            $appointment = $appointments[0];
            
            // Atualizar o status para cancelado
            $updateData = [
                'status' => 'agendamento_cancelado',
                'updated_at' => date('c')
            ];
            
            // Adicionar motivo nas notes
            if (!empty($reason)) {
                $notes = $appointment['notes'] ?? '';
                $updateData['notes'] = $notes . "\nMotivo do cancelamento: " . $reason;
            }
            
            $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "PATCH",
                $updateData,
                [
                    'id' => "eq.$appointmentId"
                ]
            );
            
            // Formatar a data com dia da semana para a resposta
            $dateObj = DateHelper::processDateExpression($appointment['appointment_date']);
            $displayDate = $dateObj['date'];
            $dayOfWeek = $dateObj['day_of_week'];
            $displayTime = substr($appointment['appointment_time'], 0, 5);
            
            $response = "✅ Agendamento cancelado com sucesso!\n\n";
            $response .= "**Detalhes do agendamento cancelado:**\n";
            $response .= "📅 {$displayDate} ({$dayOfWeek}) às {$displayTime}\n";
            $response .= "👤 Cliente: {$appointment['client_name']}\n";
            $response .= "✂️ Serviço: {$appointment['service_name']}\n";
            $response .= "👨‍💼 Profissional: {$appointment['attendant_name']}\n";
            
            if (!empty($reason)) {
                $response .= "📝 Motivo: {$reason}\n";
            }
            
            $response .= "\nO horário agora está disponível para outros clientes. Se desejar realizar um novo agendamento, estou à disposição para ajudar.";
            
            // Limpar o mapeamento da sessão após o cancelamento
            unset($_SESSION['appointment_map']);
            unset($_SESSION['appointment_phone']);
            
            return $response;
            
        } catch (Exception $e) {
            error_log("Erro ao cancelar agendamento por número: " . $e->getMessage());
            return "Desculpe, ocorreu um erro ao cancelar o agendamento. Por favor, tente novamente mais tarde.";
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
    
    /**
     * Obtém o nome do dia da semana a partir do número
     * 
     * @param int $dayNumber Número do dia da semana (1-7)
     * @return string Nome do dia da semana
     */
    private function getDayName($dayNumber) {
        $days = [
            1 => 'Segunda-feira',
            2 => 'Terça-feira',
            3 => 'Quarta-feira',
            4 => 'Quinta-feira',
            5 => 'Sexta-feira',
            6 => 'Sábado',
            7 => 'Domingo'
        ];
        
        return $days[$dayNumber] ?? 'Desconhecido';
    }
    
    /**
     * Gera um UUID v4
     * 
     * @return string UUID
     */
    private function generateUUID() {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    
    /**
     * Traduz o nome do dia da semana de inglês para português
     * 
     * @param string $weekday Nome do dia em inglês
     * @return string Nome do dia em português
     */
    private function translateWeekday($weekday) {
        $translation = [
            'Monday' => 'Segunda',
            'Tuesday' => 'Terça',
            'Wednesday' => 'Quarta',
            'Thursday' => 'Quinta',
            'Friday' => 'Sexta',
            'Saturday' => 'Sábado',
            'Sunday' => 'Domingo'
        ];
        
        return $translation[$weekday] ?? $weekday;
    }
} 