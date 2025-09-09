<?php
require_once __DIR__ . '/ConversationManager.php';
require_once __DIR__ . '/MessageProcessor.php';
require_once __DIR__ . '/AIResponseGenerator.php';
require_once __DIR__ . '/KnowledgeBaseService.php';
require_once __DIR__ . '/AppointmentService.php';
require_once __DIR__ . '/PhoneNumberUtility.php';

class WhatsAppHandler {
    private $supabaseUrl;
    private $supabaseKey;
    private $conversationManager;
    private $messageProcessor;
    private $aiResponseGenerator;
    private $knowledgeBaseService;
    private $appointmentService;

    public function __construct($supabaseUrl, $supabaseKey) {
        $this->supabaseUrl = $supabaseUrl;
        $this->supabaseKey = $supabaseKey;
        
        // Inicializar os serviços
        $this->conversationManager = new ConversationManager($supabaseUrl, $supabaseKey);
        $this->messageProcessor = new MessageProcessor();
        $this->aiResponseGenerator = new AIResponseGenerator($supabaseUrl, $supabaseKey);
        $this->knowledgeBaseService = new KnowledgeBaseService($supabaseUrl, $supabaseKey);
        $this->appointmentService = new AppointmentService($supabaseUrl, $supabaseKey);
    }

    public function handleEvent($webhookData) {
        $eventType = $webhookData['event'];
        $instanceName = $webhookData['instance'];
        $success = false;
        $message = '';

        try {
            switch ($eventType) {
                case 'messages.upsert':
                    $result = $this->handleMessage($webhookData['data'], $instanceName);
                    $success = $result['success'];
                    $message = $result['message'];
                    break;

                case 'connection.update':
                    $result = $this->handleConnectionUpdate($webhookData['data'], $instanceName);
                    $success = $result['success'];
                    $message = $result['message'];
                    break;

                default:
                    error_log("Evento não implementado: $eventType");
                    $success = true;
                    $message = "Evento não processado: $eventType";
                    break;
            }

            return [
                'success' => $success,
                'message' => $message
            ];

        } catch (Exception $e) {
            error_log("Erro ao processar evento: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Erro ao processar evento: " . $e->getMessage()
            ];
        }
    }

    private function handleMessage($messageData, $instanceName) {
        // Processar dados da mensagem
        $processedMessage = $this->messageProcessor->processMessageData($messageData);
        
        if (!$processedMessage['success']) {
            error_log("Falha ao processar dados da mensagem: " . ($processedMessage['message'] ?? 'Erro desconhecido'));
            return [
                'success' => false,
                'message' => $processedMessage['message']
            ];
        }

        // Se a mensagem foi processada mas deve ser ignorada
        if ($processedMessage['success'] && isset($processedMessage['processed']) && !$processedMessage['processed']) {
                error_log("Mensagem processada mas ignorada: " . ($processedMessage['message'] ?? 'Sem motivo especificado'));
                return [
                    'success' => true,
                'message' => $processedMessage['message']
                ];
            }

        $remoteJid = $processedMessage['remoteJid'];
        $content = $processedMessage['content'];
        $messageType = $processedMessage['type'];
        
        error_log("Processando mensagem - JID: $remoteJid, Tipo: $messageType");
        
        // Inicializar gerenciador de conversa com este cliente
        $initResult = $this->conversationManager->initializeConversation($remoteJid);
        if (!$initResult) {
            error_log("Falha ao inicializar conversa para: $remoteJid");
        }
            
        // Verificar se é uma mensagem de texto ou outro tipo
        if ($messageType !== 'text') {
            error_log("Processando mensagem de tipo: $messageType");
            // Gerar resposta para mensagem de mídia
            $response = $this->messageProcessor->getMediaTypeResponse($messageType);
            
            // Enviar resposta
            $this->sendResponse($instanceName, $remoteJid, $response);
                
            // Registrar no histórico da conversa
            try {
                $conversationData = $this->conversationManager->getConversationHistory();
                error_log("Histórico obtido - ID da conversa: " . ($conversationData['conversation_id'] ?? 'Nenhum ID'));
                    
                if ($conversationData['conversation_id']) {
                    // Salvar mensagem do usuário com tipo
                    $userSaveResult = $this->conversationManager->saveToHistory(
                        $conversationData['conversation_id'], 
                        'user', 
                        "[Mensagem do tipo: $messageType]",
                        ['message_type' => $messageType]
                    );
                    
                    error_log("Salvar mensagem do usuário (mídia): " . ($userSaveResult ? "Sucesso" : "Falha"));
                        
                    // Salvar resposta do assistente
                    $assistantSaveResult = $this->conversationManager->saveToHistory(
                        $conversationData['conversation_id'], 
                        'assistant', 
                        $response
                    );
                    
                    error_log("Salvar resposta do assistente (mídia): " . ($assistantSaveResult ? "Sucesso" : "Falha"));
                } else {
                    error_log("ID de conversa não disponível, histórico não salvo");
                }
            } catch (Exception $e) {
                error_log("Erro ao salvar histórico para mensagem de mídia: " . $e->getMessage());
            }
                
            return [
                'success' => true,
                'message' => 'Resposta enviada para mensagem do tipo: ' . $messageType
            ];
        }
            
        if (empty($content)) {
            error_log("Mensagem de texto vazia ignorada");
            return [
                'success' => true,
                'message' => 'Mensagem sem conteúdo ignorada'
            ];
        }

        error_log("Processando mensagem de texto: " . substr($content, 0, 50) . (strlen($content) > 50 ? '...' : ''));

        // Processar a mensagem de texto com o agente de IA
        $conversationData = $this->conversationManager->getConversationHistory();
        error_log("Histórico carregado - ID da conversa: " . ($conversationData['conversation_id'] ?? 'Nenhum ID'));
        error_log("Quantidade de mensagens no histórico: " . count($conversationData['messages'] ?? []));
        
        // Buscar documentos relevantes da base de conhecimento
        $relevantDocs = $this->knowledgeBaseService->searchKnowledgeBase($content);
        $knowledgeContext = "";
        if (!empty($relevantDocs)) {
            error_log("Encontrados " . count($relevantDocs) . " documentos relevantes na base de conhecimento");
            $knowledgeContext = $this->formatKnowledgeContext($relevantDocs);
        } else {
            error_log("Nenhum documento específico encontrado, usando contexto completo");
            // Se não encontrou documentos específicos, usar o contexto completo
            $knowledgeContext = $this->knowledgeBaseService->generateContext();
        }
        
        // Processar a mensagem com a IA e obter resposta
        error_log("Enviando mensagem para processamento com IA");
        $response = $this->aiResponseGenerator->processWithAI($content, $remoteJid, $conversationData, $knowledgeContext);
        error_log("Resposta gerada pela IA: " . substr($response, 0, 50) . (strlen($response) > 50 ? '...' : ''));
            
        // Enviar resposta via Evolution API
        $this->sendResponse($instanceName, $remoteJid, $response);
            
        // Salvar mensagens no histórico
        if ($conversationData['conversation_id']) {
            $userSaveResult = $this->conversationManager->saveToHistory($conversationData['conversation_id'], 'user', $content);
            error_log("Salvar mensagem do usuário (texto): " . ($userSaveResult ? "Sucesso" : "Falha"));
            
            $assistantSaveResult = $this->conversationManager->saveToHistory($conversationData['conversation_id'], 'assistant', $response);
            error_log("Salvar resposta do assistente (texto): " . ($assistantSaveResult ? "Sucesso" : "Falha"));
        } else {
            error_log("ID de conversa não disponível, histórico não salvo");
        }

        return [
            'success' => true,
            'message' => 'Mensagem processada com sucesso'
        ];
    }
    
    /**
     * Formata o contexto da base de conhecimento
     * 
     * @param array $relevantDocs Documentos relevantes
     * @return string Contexto formatado
     */
    private function formatKnowledgeContext($relevantDocs) {
                $knowledgeContext = "\nInformações relevantes da base de conhecimento:\n\n";
                foreach ($relevantDocs as $doc) {
                    $knowledgeContext .= "### " . $doc['title'] . "\n" . $doc['content'] . "\n\n";
                }
        return $knowledgeContext;
            }

    /**
     * Converte formatação Markdown para formatação WhatsApp
     * 
     * @param string $message Mensagem com formatação Markdown
     * @return string Mensagem com formatação WhatsApp
     */
    private function convertMarkdownToWhatsApp($message) {
        if (empty($message)) {
            return $message;
        }
        
        // Converter negrito: Markdown usa **texto**, WhatsApp usa *texto*
        $message = preg_replace('/\*\*(.*?)\*\*/', '*$1*', $message);
        
        // Converter itálico: Markdown usa _texto_ ou *texto*, WhatsApp usa _texto_
        $message = preg_replace('/(?<!\*)\*(.*?)\*(?!\*)/', '_$1_', $message);
        
        // Converter riscado: Markdown usa ~~texto~~, WhatsApp usa ~texto~
        $message = preg_replace('/~~(.*?)~~/', '~$1~', $message);
        
        // Converter código: Markdown usa `texto`, WhatsApp usa ```texto```
        $message = preg_replace('/`([^`]+)`/', '```$1```', $message);
        
        // Remover formatação de blocos de código que não funcionam no WhatsApp
        $message = preg_replace('/```(\w+)\s*\n/', '```', $message);
        
        return $message;
    }

    /**
     * Divide uma mensagem em partes lógicas para envio
     * 
     * @param string $message A mensagem completa
     * @return array Array com as partes da mensagem
     */
    private function splitMessageIntoParts($message) {
        // Se a mensagem for pequena, não precisa dividir
        if (strlen($message) < 500) {
            return [$message];
        }
        
        $parts = [];
        
        // Primeiro tentar encontrar listas com marcadores
        if (preg_match('/^(.*?)(\n-\s.*?)(\n\n.*|$)/s', $message, $main_parts)) {
            // Introdução antes da lista
            if (!empty(trim($main_parts[1]))) {
                $parts[] = trim($main_parts[1]);
            }
            
            // A lista propriamente dita
            if (!empty(trim($main_parts[2]))) {
                $parts[] = trim($main_parts[2]);
            }
            
            // O restante do texto depois da lista
            if (isset($main_parts[3]) && !empty(trim($main_parts[3]))) {
                // Pode ter mais parágrafos
                $extra_paragraphs = preg_split('/\n\n+/', trim($main_parts[3]));
                foreach ($extra_paragraphs as $paragraph) {
                    if (!empty(trim($paragraph))) {
                        $parts[] = trim($paragraph);
                    }
                }
            }
        } else {
            // Se não encontrou lista, dividir por parágrafos
            $paragraphs = preg_split('/\n\n+/', $message);
            foreach ($paragraphs as $paragraph) {
                if (!empty(trim($paragraph))) {
                    $parts[] = trim($paragraph);
                }
            }
        }
        
        // Se não conseguiu dividir, enviar a mensagem completa
        if (empty($parts)) {
            $parts[] = $message;
        }
        
        error_log("Mensagem dividida em " . count($parts) . " partes");
        
        return $parts;
    }

    /**
     * Envia uma resposta para o WhatsApp
     * 
     * @param string $instanceName Nome da instância
     * @param string $remoteJid JID do destinatário
     * @param string $message Mensagem a ser enviada
     * @return bool Sucesso da operação
     */
    private function sendResponse($instanceName, $remoteJid, $message) {
        global $EVOLUTION_API_BASE_URL, $EVOLUTION_API_KEY;

        try {
            error_log("Preparando envio de resposta para $remoteJid");
            
            // Verificar se a mensagem está vazia
            if (empty($message)) {
                error_log("Mensagem vazia, não enviando resposta");
                return false;
            }
            
            // Converter formatação Markdown para WhatsApp
            $message = $this->convertMarkdownToWhatsApp($message);
            
            // Dividir a mensagem em partes se for muito longa
            $parts = $this->splitMessageIntoParts($message);
            $totalParts = count($parts);
            
            error_log("Mensagem dividida em $totalParts parte(s)");
            
            // Extrair apenas o número de telefone do JID, sem o @s.whatsapp.net
            // Usar PhoneNumberUtility para garantir que o número seja formatado corretamente com DDI 55
            $phoneNumber = PhoneNumberUtility::extractFromJid($remoteJid);
            
            $allPartsSent = true;
            
            foreach ($parts as $index => $part) {
                error_log("Enviando parte da mensagem para $remoteJid: " . substr($part, 0, 50) . (strlen($part) > 50 ? "..." : ""));
                
                // Configurar a requisição para a API Evolution
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => "$EVOLUTION_API_BASE_URL/message/sendText/$instanceName",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'apikey: ' . $EVOLUTION_API_KEY
                    ],
                    CURLOPT_POSTFIELDS => json_encode([
                        'number' => $remoteJid,
                        'text' => $part,
                        'delay' => 1200
                    ])
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode !== 200 && $httpCode !== 201) {
                    error_log("Erro ao enviar parte da mensagem: $httpCode");
                    $allPartsSent = false;
                }
                
                // Pausa entre as mensagens para evitar bloqueio e melhorar a experiência
                if (count($parts) > 1) {
                    usleep(1200000); // 1.2 segundos
                }
            }
            
            if (!$allPartsSent) {
                error_log("Erro ao enviar algumas partes da mensagem, tentando enviar a mensagem completa como fallback");
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => "$EVOLUTION_API_BASE_URL/message/sendText/$instanceName",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'apikey: ' . $EVOLUTION_API_KEY
                    ],
                    CURLOPT_POSTFIELDS => json_encode([
                        'number' => $remoteJid,
                        'text' => $message,
                        'delay' => 1200
                    ])
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode !== 200 && $httpCode !== 201) {
                    throw new Exception("Erro ao enviar mensagem: $httpCode");
                }
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Erro ao enviar mensagem: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Trata atualizações de status da conexão
     * 
     * @param array $data Dados da atualização
     * @param string $instanceName Nome da instância
     * @return array Resultado do processamento
     */
    private function handleConnectionUpdate($data, $instanceName) {
        try {
            $state = $data['state'] ?? 'unknown';
            
            // Atualizar status da conexão no Supabase
            $this->makeSupabaseRequest(
                "/rest/v1/evolution_api_instances",
                "PATCH",
                [
                    'connection_status' => $state
                ],
                [
                    'instance_name' => "eq.$instanceName"
                ]
            );

            return [
                'success' => true,
                'message' => "Status de conexão atualizado para: $state"
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "Erro ao atualizar status de conexão: " . $e->getMessage()
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