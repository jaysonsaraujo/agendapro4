<?php
/**
 * ConversationManager.php
 * 
 * Responsável por gerenciar o estado e histórico das conversas com clientes.
 * Mantém o contexto da conversa e salva/recupera mensagens do banco de dados.
 */

require_once __DIR__ . '/DateHelper.php';
require_once __DIR__ . '/PhoneNumberUtility.php';

class ConversationManager {
    private $supabaseUrl;
    private $supabaseKey;
    private $remoteJid;
    private $phoneNumber;
    private $currentConversation = null;
    
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
     * Inicializa a conversa para um cliente específico
     * 
     * @param string $remoteJid JID do WhatsApp (ex: 5511999999999@s.whatsapp.net)
     * @return bool Sucesso da operação
     */
    public function initializeConversation($remoteJid) {
        try {
            $this->remoteJid = $remoteJid;
            $this->phoneNumber = $this->extractPhoneNumber($remoteJid);
            
            error_log("Inicializando conversa para: " . $this->remoteJid . " (Telefone: " . $this->phoneNumber . ")");
            
            $this->currentConversation = $this->getOrCreateConversation($remoteJid);
            
            if ($this->currentConversation) {
                error_log("Conversa inicializada com sucesso. ID: " . ($this->currentConversation['id'] ?? 'Desconhecido'));
                return true;
            } else {
                error_log("Falha ao inicializar conversa - objeto de conversa vazio");
                return false;
            }
        } catch (Exception $e) {
            error_log("Erro ao inicializar conversa: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Extrai o número de telefone do JID do WhatsApp
     * 
     * @param string $remoteJid JID do WhatsApp
     * @return string Número de telefone
     */
    private function extractPhoneNumber($remoteJid) {
        // Usar a classe PhoneNumberUtility para obter o número formatado com DDI 55
        return PhoneNumberUtility::extractFromJid($remoteJid);
    }
    
    /**
     * Obtém ou cria uma conversa para o cliente
     * 
     * @param string $remoteJid JID do cliente
     * @return array Informações da conversa
     */
    private function getOrCreateConversation($remoteJid) {
        try {
            // Salvar remoteJid como propriedade da classe para uso em outros métodos
            $this->remoteJid = $remoteJid;
            $this->phoneNumber = $this->extractPhoneNumber($remoteJid);

            // Buscar conversa ativa existente
            $conversations = $this->makeSupabaseRequest(
                "/rest/v1/ai_conversations",
                "GET",
                null,
                [
                    'select' => '*',
                    'title' => "like.%$remoteJid%",
                    'is_active' => 'eq.true',
                    'order' => 'last_message_at.desc',
                    'limit' => 1
                ]
            );

            if (!empty($conversations)) {
                error_log("Conversa existente encontrada com ID: " . ($conversations[0]['id'] ?? 'Desconhecido'));
                return $conversations[0];
            }

            // Gerar UUID para a nova conversa
            $uuid = $this->generateUUID();
            error_log("Gerando novo UUID para conversa: $uuid");
            
            // Obter o ID das configurações de IA
            $aiSettingsId = $this->getAISettingsId();
            error_log("AI Settings ID obtido: " . ($aiSettingsId ?? 'null'));

            // Criar nova conversa - usando apenas campos que existem na tabela
            $newConversationData = [
                'id' => $uuid,
                'title' => "Conversa WhatsApp - " . $remoteJid,
                'is_active' => true,
                'last_message_at' => date('c')
                // As colunas created_at e updated_at têm valores default
            ];
            
            // Adicionar ai_settings_id se disponível
            if ($aiSettingsId) {
                $newConversationData['ai_settings_id'] = $aiSettingsId;
            }

            $newConversation = $this->makeSupabaseRequest(
                "/rest/v1/ai_conversations",
                "POST",
                $newConversationData
            );
            
            if (empty($newConversation)) {
                error_log("Resposta vazia ao criar conversa");
                throw new Exception("Falha ao criar conversa - resposta vazia");
            }
            
            error_log("Nova conversa criada: " . json_encode($newConversation[0] ?? null));
            return $newConversation[0] ?? null;
        } catch (Exception $e) {
            error_log("Erro ao obter/criar conversa: " . $e->getMessage());
            throw new Exception("Falha ao inicializar conversa: " . $e->getMessage());
        }
    }
    
    /**
     * Obtém o ID das configurações de IA
     * 
     * @return string|null ID das configurações ou null se não encontrado
     */
    private function getAISettingsId() {
        try {
            $aiSettings = $this->makeSupabaseRequest(
                "/rest/v1/ai_settings",
                "GET",
                null,
                [
                    'select' => 'id',
                    'limit' => 1
                ]
            );
            
            return !empty($aiSettings) ? $aiSettings[0]['id'] : null;
        } catch (Exception $e) {
            error_log("Erro ao obter AI Settings ID: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Gera um UUID v4
     * 
     * @return string UUID gerado
     */
    private function generateUUID() {
        // Gerar 16 bytes aleatórios
        $data = random_bytes(16);
        
        // Definir a versão do UUID para 4 (aleatório)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Definir o bit de variante para o padrão RFC 4122
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        
        // Formatar como UUID
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    
    /**
     * Obtém o histórico de conversa e contexto para um cliente
     * 
     * @return array Histórico e contexto da conversa
     */
    public function getConversationHistory() {
        try {
            if (!$this->currentConversation) {
                error_log("Não há conversa inicializada para obter histórico");
                return ['messages' => [], 'context' => [], 'conversation_id' => null];
            }
            
            error_log("Buscando histórico para a conversa ID: " . $this->currentConversation['id']);
            
            // Buscar histórico de mensagens
            $result = $this->makeSupabaseRequest(
                "/rest/v1/ai_chat_history",
                "GET",
                null,
                [
                    'conversation_id' => "eq." . $this->currentConversation['id'],
                    'select' => '*',
                    'order' => 'timestamp.asc',
                    'limit' => 50 // Limitar para as últimas 50 mensagens 
                ]
            );
            
            error_log("Quantidade de mensagens encontradas: " . count($result));

            // Formatar mensagens para o formato esperado pelo AIResponseGenerator
            $formattedMessages = [];
            foreach ($result as $msg) {
                $formattedMessages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content'],
                    'timestamp' => $msg['timestamp']
                ];
            }

            // Analisar o histórico para identificar o contexto da conversa
            $context = $this->analyzeConversationContext($result);

            return [
                'messages' => $formattedMessages,
                'context' => $context,
                'conversation_id' => $this->currentConversation['id']
            ];
        } catch (Exception $e) {
            error_log("Erro ao buscar histórico: " . $e->getMessage());
            return ['messages' => [], 'context' => [], 'conversation_id' => null];
        }
    }
    
    /**
     * Analisa o histórico da conversa para extrair contexto e intenções
     * 
     * @param array $messages Mensagens do histórico
     * @return array Contexto extraído
     */
    private function analyzeConversationContext($messages) {
        $context = [
            'last_intent' => null,
            'pending_action' => null,
            'collected_data' => [],
            'confirmation_pending' => false
        ];

        if (empty($messages)) {
            return $context;
        }

        // Analisar as últimas mensagens para identificar o contexto
        foreach ($messages as $msg) {
            // Verificar se é uma mensagem do assistente pedindo confirmação
            if ($msg['role'] === 'assistant' && 
                (strpos(strtolower($msg['content']), 'confirma') !== false || 
                 strpos(strtolower($msg['content']), 'digite 1') !== false)) {
                $context['confirmation_pending'] = true;
            }

            // Verificar se é uma resposta do usuário com confirmação
            if ($msg['role'] === 'user' && $context['confirmation_pending']) {
                if ($msg['content'] === '1' || 
                    strpos(strtolower($msg['content']), 'sim') !== false || 
                    strpos(strtolower($msg['content']), 'confirmo') !== false) {
                    $context['confirmation_pending'] = false;
                    $context['last_intent'] = 'confirmed';
                }
            }

            // Identificar intenções do usuário
            if ($msg['role'] === 'user') {
                $content = strtolower($msg['content']);
                
                // Identificar intenção de agendamento
                if (strpos($content, 'agendar') !== false || 
                    strpos($content, 'marcar') !== false || 
                    strpos($content, 'horário') !== false) {
                    $context['last_intent'] = 'scheduling';
                }
                
                // Identificar consulta de horários
                else if (strpos($content, 'disponível') !== false || 
                         strpos($content, 'disponibilidade') !== false) {
                    $context['last_intent'] = 'availability_check';
                }
                
                // Identificar cancelamento
                else if (strpos($content, 'cancelar') !== false || 
                         strpos($content, 'desmarcar') !== false) {
                    $context['last_intent'] = 'cancellation';
                }
            }

            // Coletar dados importantes mencionados
            $this->extractRelevantData($msg['content'], $context['collected_data']);
        }

        return $context;
    }
    
    /**
     * Extrai dados relevantes da mensagem do cliente
     * 
     * @param string $content Conteúdo da mensagem
     * @param array &$collectedData Array para armazenar dados coletados
     * @return void
     */
    private function extractRelevantData($content, &$collectedData) {
        // Extrair datas mencionadas
        if (preg_match('/\d{2}\/\d{2}\/\d{4}|\d{2}\/\d{2}|\d{1,2}\s+de\s+[a-zA-Z]+/', $content, $matches)) {
            $collectedData['date'] = $matches[0];
        }

        // Extrair horários mencionados
        if (preg_match('/\d{1,2}:\d{2}|\d{1,2}\s*h(?:oras)?/', $content, $matches)) {
            $collectedData['time'] = $matches[0];
        }

        // Extrair possíveis serviços da base de dados
        $services = $this->getAvailableServices();
        if (!empty($services)) {
            foreach ($services as $service) {
                $name = mb_strtolower($service['name']);
                if (stripos($content, $name) !== false) {
                    $collectedData['service'] = $service['name'];
                    $collectedData['service_id'] = $service['id'];
                    break;
                }
            }
        }
    }
    
    /**
     * Obtém a lista de serviços disponíveis no sistema
     * 
     * @return array Lista de serviços
     */
    private function getAvailableServices() {
        try {
            return $this->makeSupabaseRequest(
                "/rest/v1/services",
                "GET",
                null,
                [
                    'select' => 'id,name',
                    'order' => 'name.asc'
                ]
            );
        } catch (Exception $e) {
            error_log("Erro ao buscar serviços disponíveis: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Salva uma mensagem no histórico da conversa
     * 
     * @param string $conversationId ID da conversa
     * @param string $role Papel (user/assistant)
     * @param string $content Conteúdo da mensagem
     * @param array $additionalMetadata Metadados adicionais
     * @return bool Sucesso da operação
     */
    public function saveToHistory($conversationId, $role, $content, $additionalMetadata = []) {
        try {
            // Verificar se temos um ID de conversa válido
            if (empty($conversationId) || !is_string($conversationId)) {
                error_log("ID de conversa inválido: " . json_encode($conversationId));
                return false;
            }

            // Preparar metadados
            $metadata = [
                'source' => 'whatsapp',
                'phone_number' => $this->phoneNumber ?? null
            ];
            
            // Adicionar metadados adicionais se existirem
            if (!empty($additionalMetadata)) {
                $metadata = array_merge($metadata, $additionalMetadata);
            }

            // Gerar UUID para a mensagem
            $uuid = $this->generateUUID();
            error_log("Gerando novo UUID para mensagem no histórico: $uuid");
            
            // Obter o ID das configurações de IA
            $aiSettingsId = $this->getAISettingsId();
            error_log("AI Settings ID para mensagem: " . ($aiSettingsId ?? 'null'));

            // Salvar a mensagem
            $messageData = [
                'id' => $uuid,
                'conversation_id' => $conversationId,
                'role' => $role,
                'content' => $content,
                'timestamp' => date('c')
                // created_at e updated_at têm valores default
            ];
            
            // Adicionar ai_settings_id se disponível
            if ($aiSettingsId) {
                $messageData['ai_settings_id'] = $aiSettingsId;
            }
            
            // Adicionar metadata
            $messageData['metadata'] = json_encode($metadata);

            error_log("Salvando mensagem no histórico: " . json_encode($messageData));

            $result = $this->makeSupabaseRequest(
                "/rest/v1/ai_chat_history",
                "POST",
                $messageData
            );

            if (empty($result)) {
                throw new Exception("Falha ao salvar mensagem no histórico");
            }
            
            error_log("Mensagem salva com sucesso no histórico. ID: $uuid");

            // Atualizar último timestamp da conversa
            $updateResult = $this->makeSupabaseRequest(
                "/rest/v1/ai_conversations",
                "PATCH",
                [
                    'last_message_at' => date('c')
                    // updated_at tem valor default
                ],
                [
                    'id' => "eq.$conversationId"
                ]
            );
            
            error_log("Atualização de timestamp da conversa: " . json_encode($updateResult));

            return true;
        } catch (Exception $e) {
            error_log("Erro ao salvar no histórico: " . $e->getMessage());
            return false;
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
        
        error_log("Supabase Request - URL: $url, Method: $method");
        if ($data) {
            error_log("Supabase Request - Data: " . json_encode($data));
        }
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->supabaseKey,
            'apikey: ' . $this->supabaseKey,
            'Prefer: return=representation'  // Retornar os dados inseridos/atualizados
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
        
        error_log("Supabase Response - HTTP Code: $httpCode");
        error_log("Supabase Response - Body: " . substr($response, 0, 500) . (strlen($response) > 500 ? '...' : ''));
        
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