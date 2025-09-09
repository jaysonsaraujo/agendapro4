<?php
class ConfigManager {
    private $supabaseUrl;
    private $supabaseKey;
    private static $instance = null;
    private $configs = [];
    private $knowledgeBase = [];

    private function __construct($supabaseUrl, $supabaseKey) {
        $this->supabaseUrl = $supabaseUrl;
        $this->supabaseKey = $supabaseKey;
        $this->loadConfigs();
        $this->loadKnowledgeBase();
    }

    public static function getInstance($supabaseUrl = null, $supabaseKey = null) {
        if (self::$instance === null) {
            if ($supabaseUrl === null || $supabaseKey === null) {
                throw new Exception("Supabase credentials are required for first initialization");
            }
            self::$instance = new self($supabaseUrl, $supabaseKey);
        }
        return self::$instance;
    }

    private function loadConfigs() {
        try {
            // Carregar configurações da Evolution API
            $evolutionSettings = $this->makeSupabaseRequest("/rest/v1/evolution_api_settings");
            if (!empty($evolutionSettings)) {
                $this->configs['evolution'] = [
                    'base_url' => $evolutionSettings[0]['base_url'],
                    'api_key' => $evolutionSettings[0]['api_key']
                ];
            } else {
                throw new Exception("Evolution API settings not found");
            }

            // Carregar configurações da OpenAI
            $aiSettings = $this->makeSupabaseRequest("/rest/v1/ai_settings");
            if (!empty($aiSettings)) {
                $this->configs['openai'] = [
                    'api_key' => $aiSettings[0]['openai_api_key'],
                    'model' => $aiSettings[0]['model'] ?? 'gpt-3.5-turbo',
                    'temperature' => $aiSettings[0]['temperature'] ?? 0.7,
                    'max_tokens' => $aiSettings[0]['max_tokens'] ?? 500,
                    'system_prompt' => $aiSettings[0]['system_prompt'] ?? ''
                ];
                
                // Armazenar o ID das configurações de IA para uso na base de conhecimento
                $this->configs['ai_settings_id'] = $aiSettings[0]['id'];
            } else {
                throw new Exception("OpenAI settings not found");
            }

        } catch (Exception $e) {
            error_log("Error loading configurations: " . $e->getMessage());
            throw $e;
        }
    }

    private function loadKnowledgeBase() {
        try {
            // Carregar documentos da base de conhecimento associados às configurações de IA atuais
            $query = "/rest/v1/ai_knowledge_base?ai_settings_id=eq." . $this->configs['ai_settings_id'];
            $knowledgeDocuments = $this->makeSupabaseRequest($query);

            if (!empty($knowledgeDocuments)) {
                foreach ($knowledgeDocuments as $doc) {
                    $this->knowledgeBase[] = [
                        'id' => $doc['id'],
                        'title' => $doc['title'],
                        'content' => $doc['content'],
                        'metadata' => $doc['metadata'] ?? [],
                        'created_at' => $doc['created_at'],
                        'updated_at' => $doc['updated_at']
                    ];
                }
            }

            error_log("Loaded " . count($this->knowledgeBase) . " knowledge base documents");
        } catch (Exception $e) {
            error_log("Error loading knowledge base: " . $e->getMessage());
            // Não lançar exceção aqui para permitir que o sistema funcione mesmo sem a base de conhecimento
        }
    }

    private function makeSupabaseRequest($endpoint, $method = 'GET', $data = null) {
        $ch = curl_init();
        $url = $this->supabaseUrl . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->supabaseKey,
            'apikey: ' . $this->supabaseKey
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
        }
        
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception("Supabase request error: $httpCode");
        }
        
        return json_decode($response, true);
    }

    public function getEvolutionConfig($key = null) {
        if ($key === null) {
            return $this->configs['evolution'];
        }
        return $this->configs['evolution'][$key] ?? null;
    }

    public function getOpenAIConfig($key = null) {
        if ($key === null) {
            return $this->configs['openai'];
        }
        return $this->configs['openai'][$key] ?? null;
    }

    public function getKnowledgeBase() {
        return $this->knowledgeBase;
    }

    public function getKnowledgeBaseContent() {
        $content = "";
        foreach ($this->knowledgeBase as $doc) {
            $content .= "### " . $doc['title'] . "\n\n";
            $content .= $doc['content'] . "\n\n";
        }
        return $content;
    }

    public function searchKnowledgeBase($query) {
        $results = [];
        $query = mb_strtolower($query);
        
        foreach ($this->knowledgeBase as $doc) {
            $title = mb_strtolower($doc['title']);
            $content = mb_strtolower($doc['content']);
            
            // Verificar se a query está no título ou conteúdo
            if (strpos($title, $query) !== false || strpos($content, $query) !== false) {
                $results[] = $doc;
            }
        }
        
        return $results;
    }

    public function refreshConfigs() {
        $this->loadConfigs();
        $this->loadKnowledgeBase();
    }

    public function getSystemPromptWithKnowledge() {
        $basePrompt = $this->configs['openai']['system_prompt'];
        $knowledgeContent = $this->getKnowledgeBaseContent();
        
        if (!empty($knowledgeContent)) {
            return $basePrompt . "\n\nBase de Conhecimento:\n" . $knowledgeContent;
        }
        
        return $basePrompt;
    }
} 