<?php
/**
 * MessageProcessor.php
 * 
 * Responsável por processar e formatar mensagens do WhatsApp.
 * Extrai conteúdo, identifica tipos de mensagem e faz análise inicial.
 */

class MessageProcessor {
    /**
     * Processa o conteúdo da mensagem recebida.
     * 
     * @param array $messageData Dados da mensagem do WhatsApp
     * @return array Informações da mensagem processada
     */
    public function processMessageData($messageData) {
        if (empty($messageData['key'])) {
            return [
                'success' => false,
                'message' => 'Dados da mensagem inválidos'
            ];
        }

        try {
            // Extrair informações da mensagem
            $remoteJid = $messageData['key']['remoteJid'];
            $fromMe = $messageData['key']['fromMe'] ?? false;
            $messageId = $messageData['key']['id'];

            // Se a mensagem for do próprio bot, ignorar
            if ($fromMe) {
                return [
                    'success' => true,
                    'processed' => false,
                    'message' => 'Mensagem do bot ignorada'
                ];
            }

            // Extrair o conteúdo e tipo da mensagem
            $extractedData = $this->extractMessageContent($messageData['message'] ?? []);
            
            return [
                'success' => true,
                'processed' => true,
                'remoteJid' => $remoteJid,
                'messageId' => $messageId,
                'content' => $extractedData['content'],
                'type' => $extractedData['type']
            ];
        } catch (Exception $e) {
            error_log("Erro ao processar dados da mensagem: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao processar dados da mensagem: " . $e->getMessage()
            ];
        }
    }

    /**
     * Extrai o conteúdo da mensagem com base no tipo
     * 
     * @param array $message Objeto de mensagem do WhatsApp
     * @return array Conteúdo e tipo da mensagem
     */
    public function extractMessageContent($message) {
        // Determinar o tipo de mensagem
        $messageType = $this->determineMessageType($message);
        
        // Extrair conteúdo baseado no tipo
        if ($messageType === 'text') {
            if (isset($message['conversation']) && !empty($message['conversation'])) {
                return ['content' => $message['conversation'], 'type' => 'text'];
            }
            
            if (isset($message['extendedTextMessage']) && !empty($message['extendedTextMessage']['text'])) {
                return ['content' => $message['extendedTextMessage']['text'], 'type' => 'text'];
            }
        } else {
            // Retornar informação do tipo de mídia
            return ['content' => null, 'type' => $messageType];
        }

        // Mensagem vazia ou tipo não suportado
        return ['content' => null, 'type' => 'unknown'];
    }
    
    /**
     * Determina o tipo de mensagem
     * 
     * @param array $message Objeto de mensagem do WhatsApp
     * @return string Tipo da mensagem
     */
    public function determineMessageType($message) {
        if (isset($message['conversation']) || isset($message['extendedTextMessage'])) {
            return 'text';
        }
        
        if (isset($message['imageMessage'])) {
            return 'image';
        }
        
        if (isset($message['audioMessage'])) {
            return 'audio';
        }
        
        if (isset($message['videoMessage'])) {
            return 'video';
        }
        
        if (isset($message['documentMessage'])) {
            return 'document';
        }
        
        if (isset($message['stickerMessage'])) {
            return 'sticker';
        }
        
        if (isset($message['locationMessage'])) {
            return 'location';
        }
        
        if (isset($message['contactMessage']) || isset($message['contactsArrayMessage'])) {
            return 'contact';
        }
        
        return 'unknown';
    }
    
    /**
     * Gera mensagem de resposta para tipos de mídia não processáveis
     * 
     * @param string $messageType Tipo de mensagem
     * @return string Mensagem de resposta
     */
    public function getMediaTypeResponse($messageType) {
        switch($messageType) {
            case 'audio':
                return "Olá! Percebi que você enviou um áudio. Por enquanto, só consigo processar mensagens de texto. Por favor, digite sua mensagem para que eu possa te ajudar melhor! 😊";
            case 'image':
                return "Olá! Vi que você enviou uma imagem. Infelizmente, só consigo processar mensagens de texto no momento. Por favor, digite sua dúvida ou solicitação para que eu possa te ajudar! 📝";
            case 'video':
                return "Olá! Percebi que você enviou um vídeo. Atualmente, só consigo processar mensagens de texto. Por favor, digite sua mensagem para que eu possa te ajudar! 💬";
            case 'document':
                return "Olá! Vi que você enviou um documento. Por enquanto, só consigo processar mensagens de texto. Por favor, digite sua dúvida ou solicitação para que eu possa te ajudar! 📝";
            case 'sticker':
                return "Olá! Vi que você enviou um sticker. Que tal me enviar uma mensagem de texto para que eu possa te ajudar melhor? 😊";
            case 'location':
                return "Olá! Percebi que você compartilhou uma localização. Por enquanto, só consigo processar mensagens de texto. Por favor, me explique em texto como posso te ajudar! 🗺️";
            case 'contact':
                return "Olá! Vi que você compartilhou um contato. Atualmente, só consigo processar mensagens de texto. Por favor, digite sua solicitação para que eu possa te ajudar! 📞";
            default:
                return "Olá! Notei que você enviou um tipo de mensagem que não consigo processar. Por favor, envie sua mensagem como texto para que eu possa te ajudar melhor! 🙂";
        }
    }
    
    /**
     * Verifica se a mensagem contém uma intenção específica
     * 
     * @param string $message Mensagem do usuário
     * @param array $keywords Palavras-chave para verificar
     * @return bool Verdadeiro se a mensagem contém a intenção
     */
    public function hasIntent($message, $keywords) {
        $message = mb_strtolower(trim($message));
        
        foreach ($keywords as $keyword) {
            if (strpos($message, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Analisa a mensagem do usuário para identificar a intenção inicial
     * 
     * @param string $message Mensagem do usuário
     * @return array Informações da intenção identificada
     */
    public function analyzeInitialIntent($message) {
        $message = mb_strtolower(trim($message));
        $result = [
            'intent' => 'unknown',
            'entity' => null,
            'parameters' => []
        ];
        
        // Verificar saudações
        $greetings = ['oi', 'olá', 'ola', 'bom dia', 'boa tarde', 'boa noite', 'ei', 'hey'];
        foreach ($greetings as $greeting) {
            if (strpos($message, $greeting) !== false) {
                $result['intent'] = 'greeting';
                return $result;
            }
        }
        
        // Verificar intenção de agendamento com serviço específico
        if (preg_match('/(quero|desejo|preciso|gostaria|pode|posso|vou|preciso)\s+(de\s+)?(marcar|agendar|fazer|reservar)\s+(um|uma)?\s*([\w\s]+?)(?:\s+para|$|\s*\?)/ui', $message, $matches)) {
            $service = mb_strtolower(trim($matches[5]));
            $result['intent'] = 'scheduling';
            $result['entity'] = 'service';
            $result['parameters']['service'] = $service;
            return $result;
        }
        
        // Verificar intenção de agendamento genérica
        if (preg_match('/(quero|desejo|preciso|gostaria|pode|posso|vou|preciso)\s+(de\s+)?(marcar|agendar|fazer|reservar)/ui', $message)) {
            $result['intent'] = 'scheduling';
            return $result;
        }
        
        // Verificar consulta de serviços
        if (preg_match('/(quais|que|ver|saber|mostrar|listar)\s+(os\s+)?(serviços|servicos|procedimentos|tratamentos)/ui', $message)) {
            $result['intent'] = 'list_services';
            return $result;
        }
        
        // Verificar consulta de horários
        if (preg_match('/(horários|horarios|horas|agenda|disponibilidade|disponível|disponivel)/ui', $message)) {
            $result['intent'] = 'check_availability';
            return $result;
        }
        
        // Verificar consulta de agendamentos
        if (preg_match('/(meus|meu|ver|consultar|mostrar)\s+(agendamentos?|horários?|horarios?|compromissos?)/ui', $message)) {
            $result['intent'] = 'check_appointments';
            return $result;
        }
        
        // Verificar intenção de cancelamento
        if (preg_match('/(cancelar|desmarcar|remover)\s+(agendamento|horário|horario|compromisso)/ui', $message)) {
            $result['intent'] = 'cancel_appointment';
            return $result;
        }
        
        return $result;
    }
    
    /**
     * Extrai informações de data de uma mensagem
     * 
     * @param string $message Mensagem do usuário
     * @return array Informações de data extraídas
     */
    public function extractDateInfo($message) {
        $message = mb_strtolower(trim($message));
        $result = [
            'found' => false,
            'date' => null,
            'formatted_date' => null,
            'day_of_week' => null
        ];
        
        // Padrões de data
        $patterns = [
            // DD/MM/YYYY
            '/(\d{1,2})\/(\d{1,2})\/(\d{4})/' => function($matches) {
                return sprintf('%04d-%02d-%02d', $matches[3], $matches[2], $matches[1]);
            },
            // DD/MM
            '/(\d{1,2})\/(\d{1,2})(?!\/)/' => function($matches) {
                $year = date('Y');
                return sprintf('%04d-%02d-%02d', $year, $matches[2], $matches[1]);
            },
            // DD de Mês
            '/(\d{1,2})\s+de\s+(janeiro|fevereiro|março|marco|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)/' => function($matches) {
                $months = [
                    'janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'marco' => 3,
                    'abril' => 4, 'maio' => 5, 'junho' => 6, 'julho' => 7,
                    'agosto' => 8, 'setembro' => 9, 'outubro' => 10,
                    'novembro' => 11, 'dezembro' => 12
                ];
                $day = $matches[1];
                $month = $months[$matches[2]];
                $year = date('Y');
                
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        ];
        
        // Expressões relativas de data
        $relative_dates = [
            'hoje' => '+0 day',
            'amanhã' => '+1 day',
            'amanha' => '+1 day',
            'depois de amanhã' => '+2 days',
            'depois de amanha' => '+2 days',
            'próxima segunda' => 'next monday',
            'proxima segunda' => 'next monday',
            'próxima terça' => 'next tuesday',
            'proxima terca' => 'next tuesday',
            'próxima quarta' => 'next wednesday',
            'proxima quarta' => 'next wednesday',
            'próxima quinta' => 'next thursday',
            'proxima quinta' => 'next thursday',
            'próxima sexta' => 'next friday',
            'proxima sexta' => 'next friday',
            'próximo sábado' => 'next saturday',
            'proximo sabado' => 'next saturday',
            'próximo domingo' => 'next sunday',
            'proximo domingo' => 'next sunday'
        ];
        
        // Verificar padrões de data
        foreach ($patterns as $pattern => $formatter) {
            if (preg_match($pattern, $message, $matches)) {
                $result['found'] = true;
                $result['date'] = $formatter($matches);
                $timestamp = strtotime($result['date']);
                $result['formatted_date'] = date('d/m/Y', $timestamp);
                $result['day_of_week'] = date('w', $timestamp);
                return $result;
            }
        }
        
        // Verificar expressões relativas
        foreach ($relative_dates as $expression => $modifier) {
            if (strpos($message, $expression) !== false) {
                $result['found'] = true;
                $timestamp = strtotime($modifier);
                $result['date'] = date('Y-m-d', $timestamp);
                $result['formatted_date'] = date('d/m/Y', $timestamp);
                $result['day_of_week'] = date('w', $timestamp);
                return $result;
            }
        }
        
        // Verificar dias da semana isolados
        $weekdays = [
            'segunda' => 1,
            'terça' => 2,
            'terca' => 2,
            'quarta' => 3,
            'quinta' => 4,
            'sexta' => 5,
            'sábado' => 6,
            'sabado' => 6,
            'domingo' => 0
        ];
        
        foreach ($weekdays as $day => $day_num) {
            if (strpos($message, $day) !== false) {
                $current_day = date('w');
                $days_to_add = ($day_num - $current_day + 7) % 7;
                if ($days_to_add === 0) $days_to_add = 7; // Se for o mesmo dia da semana, pegar próxima semana
                
                $result['found'] = true;
                $timestamp = strtotime("+$days_to_add days");
                $result['date'] = date('Y-m-d', $timestamp);
                $result['formatted_date'] = date('d/m/Y', $timestamp);
                $result['day_of_week'] = $day_num;
                return $result;
            }
        }
        
        return $result;
    }
    
    /**
     * Extrai informações de horário de uma mensagem
     * 
     * @param string $message Mensagem do usuário
     * @return array Informações de horário extraídas
     */
    public function extractTimeInfo($message) {
        $message = mb_strtolower(trim($message));
        $result = [
            'found' => false,
            'time' => null,
            'formatted_time' => null
        ];
        
        // Padrões de horário
        $patterns = [
            // HH:MM
            '/(\d{1,2}):(\d{2})/' => function($matches) {
                return sprintf('%02d:%02d:00', $matches[1], $matches[2]);
            },
            // HH:MM:SS
            '/(\d{1,2}):(\d{2}):(\d{2})/' => function($matches) {
                return sprintf('%02d:%02d:%02d', $matches[1], $matches[2], $matches[3]);
            },
            // HHh ou HH horas
            '/(\d{1,2})\s*h(?:oras)?(?!\d)/' => function($matches) {
                return sprintf('%02d:00:00', $matches[1]);
            },
            // HHhMM
            '/(\d{1,2})h(\d{2})/' => function($matches) {
                return sprintf('%02d:%02d:00', $matches[1], $matches[2]);
            }
        ];
        
        // Verificar padrões de horário
        foreach ($patterns as $pattern => $formatter) {
            if (preg_match($pattern, $message, $matches)) {
                $result['found'] = true;
                $result['time'] = $formatter($matches);
                $time_parts = explode(':', $result['time']);
                $result['formatted_time'] = $time_parts[0] . ':' . $time_parts[1];
                return $result;
            }
        }
        
        return $result;
    }
} 