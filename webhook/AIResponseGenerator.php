<?php
/**
 * AIResponseGenerator.php
 * 
 * Responsável por gerar respostas usando OpenAI.
 * Faz a integração com a API da OpenAI e formata as mensagens.
 */

require_once __DIR__ . '/AIToolHandler.php';
require_once __DIR__ . '/DateHelper.php';

class AIResponseGenerator {
    private $supabaseUrl;
    private $supabaseKey;
    private $openAIApiKey;
    private $openAIModel;
    private $toolHandler;
    
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
        
        // Inicializar o processador de ferramentas
        $this->toolHandler = new AIToolHandler($supabaseUrl, $supabaseKey);
        
        // Carregar as configurações da OpenAI
        $this->loadOpenAIConfig();
    }
    
    /**
     * Carrega configurações da OpenAI do Supabase
     * 
     * @return void
     */
    private function loadOpenAIConfig() {
        try {
            $aiSettings = $this->makeSupabaseRequest(
                "/rest/v1/ai_settings",
                "GET",
                null,
                ['limit' => 1]
            );
            
            if (!empty($aiSettings)) {
                $this->openAIApiKey = $aiSettings[0]['openai_api_key'];
                $this->openAIModel = $aiSettings[0]['model'] ?? 'gpt-3.5-turbo';
            } else {
                throw new Exception("Configurações da OpenAI não encontradas");
            }
        } catch (Exception $e) {
            error_log("Erro ao carregar configurações da OpenAI: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Gera uma resposta usando a OpenAI com base nas mensagens
     * 
     * @param array $messages Array de mensagens no formato da OpenAI
     * @param array $options Opções adicionais para a requisição
     * @return string Resposta gerada pela IA
     */
    public function generateResponse($messages, $options = []) {
        try {
            // Verificar se temos a chave da API
            if (empty($this->openAIApiKey)) {
                throw new Exception("Chave da API OpenAI não configurada");
            }
            
            // Configurar opções padrão
            $temperature = $options['temperature'] ?? 0.7;
            $maxTokens = $options['max_tokens'] ?? 500;
            $model = $options['model'] ?? $this->openAIModel;
            
            // Criar a requisição para a OpenAI
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api.openai.com/v1/chat/completions',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->openAIApiKey
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                    'presence_penalty' => 0.6, // Ajuda a manter o contexto
                    'frequency_penalty' => 0.3  // Evita repetições
                ])
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_errno($ch)) {
                throw new Exception("Erro CURL: " . curl_error($ch));
            }
            
            curl_close($ch);

            if ($httpCode !== 200) {
                error_log("Erro na requisição OpenAI ($httpCode): $response");
                throw new Exception("Erro na requisição OpenAI: $httpCode");
            }

            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Erro ao decodificar resposta da OpenAI: " . json_last_error_msg());
            }
            
            if (empty($data['choices'][0]['message']['content'])) {
                throw new Exception("Resposta vazia da OpenAI");
            }

            $responseContent = $data['choices'][0]['message']['content'];
            
            // Verificar e corrigir respostas com problemas conhecidos
            $responseContent = $this->checkForKnownIssues($responseContent);
            
            // Processar ferramentas na resposta
            $processedResponse = $this->toolHandler->processText($responseContent);
            
            return $processedResponse;
        } catch (Exception $e) {
            error_log("Erro ao gerar resposta da OpenAI: " . $e->getMessage());
            return "Desculpe, estou com dificuldades técnicas no momento. Por favor, tente novamente em alguns minutos.";
        }
    }
    
    /**
     * Verifica e corrige problemas conhecidos nas respostas da IA
     * 
     * @param string $response Resposta original da IA
     * @return string Resposta corrigida
     */
    private function checkForKnownIssues($response) {
        // Verificar se há mensagem de erro sobre data inválida
        if (strpos($response, "Data inválida ou não reconhecida") !== false) {
            error_log("Detectado problema de interpretação de data na resposta. Tentando corrigir...");
            
            // Verificar se é um problema com "amanhã"
            if (preg_match('/amanh[ãa]/i', $response)) {
                $today = new \DateTime();
                $tomorrow = clone $today;
                $tomorrow->modify('+1 day');
                $tomorrowFormatted = $tomorrow->format('d/m/Y');
                $dayOfWeek = DateHelper::getDayOfWeek($tomorrow);
                
                error_log("Substituindo referência de 'data inválida' por data formatada de amanhã: $tomorrowFormatted ($dayOfWeek)");
                
                // Substituir a mensagem de erro por uma formatação correta da data
                $response = preg_replace(
                    '/Data inv[áa]lida ou n[ãa]o reconhecida\. Por favor, forne[çc]a uma data v[áa]lida\./i',
                    "Amanhã é dia $tomorrowFormatted, $dayOfWeek. Vou verificar os horários disponíveis para você.",
                    $response
                );
            }
        }
        
        return $response;
    }
    
    /**
     * Prepara as mensagens para processamento pelo OpenAI
     * 
     * @param string $content Mensagem do usuário
     * @param string $remoteJid ID do WhatsApp do usuário
     * @param array $conversationHistory Histórico de conversa
     * @return string Resposta da IA
     */
    public function processWithAI($content, $remoteJid, $conversationHistory = [], $knowledgeBaseContext = "") {
        try {
            $phoneNumber = substr($remoteJid, 0, strpos($remoteJid, '@'));
            
            error_log("Processando mensagem com IA para telefone: $phoneNumber");
            error_log("Histórico de conversa disponível: " . (!empty($conversationHistory['messages']) ? count($conversationHistory['messages']) . " mensagens" : "Nenhum"));
            
            // Preparar mensagens para o OpenAI com contexto
            $messages = [
                [
                    'role' => 'system',
                    'content' => $this->getSystemPrompt($phoneNumber, $knowledgeBaseContext, $conversationHistory['context'] ?? [])
                ]
            ];

            // Adicionar histórico de conversa relevante (se existir)
            if (!empty($conversationHistory['messages'])) {
                error_log("Adicionando " . count($conversationHistory['messages']) . " mensagens do histórico");
                foreach ($conversationHistory['messages'] as $msg) {
                    $messages[] = [
                        'role' => $msg['role'],
                        'content' => $msg['content']
                    ];
                }
            } else {
                error_log("Nenhum histórico de mensagens disponível para adicionar ao contexto");
            }

            // Adicionar mensagem atual
            $messages[] = [
                'role' => 'user',
                'content' => $content
            ];
            
            error_log("Total de mensagens enviadas para a IA: " . count($messages));

            // Fazer requisição para OpenAI
            return $this->generateResponse($messages);
        } catch (Exception $e) {
            error_log("Erro ao processar com IA: " . $e->getMessage());
            return "Olá! No momento estou com algumas limitações técnicas. Por favor, tente novamente em alguns instantes ou entre em contato através de outro canal.";
        }
    }
    
    /**
     * Prepara o prompt de sistema com contexto
     * 
     * @param string $phoneNumber Número de telefone do cliente
     * @param string $knowledgeContext Contexto da base de conhecimento
     * @param array $conversationContext Contexto da conversa
     * @return string Prompt de sistema formatado
     */
    private function getSystemPrompt($phoneNumber, $knowledgeContext = '', $conversationContext = []) {
        // Buscar prompt de sistema nas configurações
        try {
            $aiSettings = $this->makeSupabaseRequest(
                "/rest/v1/ai_settings",
                "GET",
                null,
                ['limit' => 1]
            );
            
            $basePrompt = !empty($aiSettings) && isset($aiSettings[0]['system_prompt']) 
                ? $aiSettings[0]['system_prompt'] 
                : "Você é um assistente virtual especializado em agendamentos para um sistema profissional.";
            
            // Obter data e hora atual no formato correto com dia da semana
            $now = DateHelper::now('d/m/Y H:i:s');
            $today = DateHelper::processDateExpression('hoje');
            $dayOfWeek = $today['day_of_week'];
                
            $prompt = $basePrompt . "\n\n" .
                   "Instruções importantes:\n" .
                   "1. Seja sempre cordial e profissional\n" .
                   "2. Use linguagem clara e direta\n" .
                   "3. Ao receber um número '1', entenda como uma confirmação do cliente\n" .
                   "4. Mantenha as respostas concisas e objetivas\n" .
                   "5. Se não tiver certeza sobre alguma informação, peça esclarecimento\n" .
                   "6. Nunca invente informações sobre horários ou serviços\n" .
                   "7. Se perceber que o cliente está confuso, ofereça ajuda de forma mais detalhada\n" .
                   "8. Em caso de problemas técnicos, peça para o cliente tentar novamente em alguns minutos\n" .
                   "9. Ao lidar com datas, SEMPRE informe o dia da semana correspondente\n" .
                   "10. SEMPRE verifique se a data mencionada é válida e está correta antes de prosseguir\n\n" .
                   "Cliente atual: " . $phoneNumber . "\n" .
                   "Data/Hora atual: " . $now . " (" . $dayOfWeek . ")\n";
    
            // Adicionar informações do contexto atual
            if (!empty($conversationContext)) {
                $prompt .= "\nContexto da conversa:\n";
                if (!empty($conversationContext['last_intent'])) {
                    $prompt .= "- Última intenção: " . $conversationContext['last_intent'] . "\n";
                }
                if (!empty($conversationContext['confirmation_pending'])) {
                    $prompt .= "- Aguardando confirmação do cliente\n";
                }
                if (!empty($conversationContext['collected_data'])) {
                    $prompt .= "- Dados coletados:\n";
                    foreach ($conversationContext['collected_data'] as $key => $value) {
                        $prompt .= "  * $key: $value\n";
                    }
                }
            }
    
            // Adicionar contexto da base de conhecimento se existir
            if (!empty($knowledgeContext)) {
                $prompt .= "\n" . $knowledgeContext;
            }
    
            return $prompt;
        } catch (Exception $e) {
            error_log("Erro ao gerar prompt do sistema: " . $e->getMessage());
            return "Você é um assistente virtual especializado em agendamentos. Seja cordial e profissional.";
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