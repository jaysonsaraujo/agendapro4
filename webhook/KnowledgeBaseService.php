<?php
/**
 * KnowledgeBaseService.php
 * 
 * Responsável por gerenciar a base de conhecimento do sistema.
 * Busca, formata e fornece informações sobre serviços, atendentes,
 * horários de funcionamento e outras informações relevantes.
 */

class KnowledgeBaseService {
    private $supabaseUrl;
    private $supabaseKey;
    private $embeddingApiUrl;
    private $embeddingApiKey;
    private $embeddingModel;
    
    /**
     * Construtor
     * 
     * @param string $supabaseUrl URL do Supabase
     * @param string $supabaseKey Chave do Supabase
     */
    public function __construct($supabaseUrl, $supabaseKey) {
        $this->supabaseUrl = $supabaseUrl;
        $this->supabaseKey = $supabaseKey;
        
        // Carregar configurações da API de embeddings
        $this->loadEmbeddingConfig();
    }
    
    /**
     * Carrega configurações da API de embeddings
     */
    private function loadEmbeddingConfig() {
        try {
            $config = $this->makeSupabaseRequest(
                "/rest/v1/system_configs",
                "GET",
                null,
                [
                    'name' => 'eq.embedding_api',
                    'select' => 'config_value'
                ]
            );
            
            if (!empty($config) && isset($config[0]['config_value'])) {
                $embeddingConfig = json_decode($config[0]['config_value'], true);
                $this->embeddingApiUrl = $embeddingConfig['api_url'] ?? 'https://api.openai.com/v1/embeddings';
                $this->embeddingApiKey = $embeddingConfig['api_key'] ?? '';
                $this->embeddingModel = $embeddingConfig['model'] ?? 'text-embedding-ada-002';
            } else {
                // Configurações padrão
                $this->embeddingApiUrl = 'https://api.openai.com/v1/embeddings';
                $this->embeddingApiKey = '';
                $this->embeddingModel = 'text-embedding-ada-002';
                error_log("Configurações de embedding não encontradas, usando padrões");
            }
        } catch (Exception $e) {
            error_log("Erro ao carregar configurações de embedding: " . $e->getMessage());
            // Configurações padrão em caso de erro
            $this->embeddingApiUrl = 'https://api.openai.com/v1/embeddings';
            $this->embeddingApiKey = '';
            $this->embeddingModel = 'text-embedding-ada-002';
        }
    }
    
    /**
     * Gera contexto dinâmico com informações atualizadas do sistema
     * 
     * @return string Contexto formatado para o prompt da IA
     */
    public function generateContext() {
        $context = "INFORMAÇÕES ATUALIZADAS DO SISTEMA:\n\n";
        
        // Buscar informações do negócio
        $businessInfo = $this->getBusinessInfo();
        if ($businessInfo) {
            $context .= "ESTABELECIMENTO:\n";
            $context .= "Nome: {$businessInfo['trading_name']}\n";
            $context .= "Telefone: {$businessInfo['phone']}\n";
            $context .= "Endereço: {$businessInfo['address']}, {$businessInfo['city']} - {$businessInfo['state']}\n\n";
        }
        
        // Buscar horários de funcionamento
        $businessHours = $this->getBusinessHours();
        if (!empty($businessHours)) {
            $context .= "HORÁRIOS DE FUNCIONAMENTO:\n";
            foreach ($businessHours as $hours) {
                if ($hours['is_closed']) {
                    $context .= "{$hours['day_of_week']}: Fechado\n";
                } else {
                    $openTime = substr($hours['open_time'], 0, 5);
                    $closeTime = substr($hours['close_time'], 0, 5);
                    $context .= "{$hours['day_of_week']}: {$openTime} às {$closeTime}\n";
                }
            }
            $context .= "\n";
        }
        
        // Buscar serviços disponíveis
        $services = $this->getServices();
        if (!empty($services)) {
            $context .= "SERVIÇOS DISPONÍVEIS:\n";
            foreach ($services as $service) {
                $formattedPrice = number_format($service['price'], 2, ',', '.');
                $context .= "ID {$service['id']}: {$service['name']} - R$ {$formattedPrice} - Duração: {$service['duration']} min\n";
            }
            $context .= "\n";
        }
        
        // Buscar atendentes disponíveis
        $attendants = $this->getAttendants();
        if (!empty($attendants)) {
            $context .= "ATENDENTES DISPONÍVEIS:\n";
            $attendantsList = [];
            
            foreach ($attendants as $attendant) {
                $context .= "ID {$attendant['id']}: {$attendant['name']} - {$attendant['position']}\n";
                $attendantsList[] = $attendant;
                
                // Adicionar dias de trabalho
                $days = $attendant['work_days'];
                if (!empty($days)) {
                    $dayNames = $this->formatWorkDays($days);
                    $context .= "   Dias de trabalho: " . implode(', ', $dayNames) . "\n";
                }
                
                // Serviços que o atendente oferece
                $attendantServices = $this->getAttendantServices($attendant['id']);
                if (!empty($attendantServices)) {
                    $serviceNames = array_column($attendantServices, 'service_name');
                    $context .= "   Serviços: " . implode(', ', $serviceNames) . "\n";
                }
            }
            
            $context .= "\n";
            
            // Adicionar lista de referência para seleção
            $context .= "LISTA DE REFERÊNCIA DE ATENDENTES:\n";
            foreach ($attendantsList as $index => $attendant) {
                $context .= ($index + 1) . ". {$attendant['name']} - {$attendant['position']}\n";
            }
            $context .= "\n";
        }
        
        // Próximos agendamentos para hoje
        $today = date('Y-m-d');
        $todayAppointments = $this->getAppointmentsByDate($today);
        if (!empty($todayAppointments)) {
            $context .= "AGENDAMENTOS PARA HOJE:\n";
            foreach ($todayAppointments as $appointment) {
                $time = substr($appointment['appointment_time'], 0, 5);
                $context .= "▸ {$time} - {$appointment['attendant_name']} - {$appointment['service_name']} - Status: {$appointment['status']}\n";
            }
            $context .= "\n";
        }
        
        // Agendamentos para amanhã
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $tomorrowAppointments = $this->getAppointmentsByDate($tomorrow);
        if (!empty($tomorrowAppointments)) {
            $context .= "AGENDAMENTOS PARA AMANHÃ:\n";
            foreach ($tomorrowAppointments as $appointment) {
                $time = substr($appointment['appointment_time'], 0, 5);
                $context .= "▸ {$time} - {$appointment['attendant_name']} - {$appointment['service_name']} - Status: {$appointment['status']}\n";
            }
            $context .= "\n";
        }
        
        // DATA E HORA ATUAL
        $context .= "DATA E HORA ATUAL: " . date('d/m/Y H:i') . "\n\n";
        
        // Instruções específicas para garantir consistência
        $context .= "INSTRUÇÕES IMPORTANTES:\n";
        $context .= "1. Ao listar atendentes, sempre use o formato 'Nome - Cargo' exatamente como listado acima.\n";
        $context .= "2. NUNCA invente nomes de atendentes ou serviços que não estão listados acima.\n";
        $context .= "3. Ao solicitar que o cliente escolha um atendente, liste-os numericamente como na LISTA DE REFERÊNCIA acima.\n";
        $context .= "4. Sempre confirme as escolhas do cliente repetindo o nome e cargo do atendente selecionado.\n";
        $context .= "5. CRÍTICO: Sempre pedir para o cliente confirmar os dados do agendamento antes de criar o agendamento.\n";
        $context .= "6. CRÍTICO: Ao verificar a disponibilidade de um atendente em um dia específico, primeiro confirme nos DIAS DE TRABALHO DOS ATENDENTES se o profissional atende naquele dia da semana. Se não atender, informe isso ao cliente antes de verificar horários.\n";
        $context .= "7. CRÍTICO: Ao confirmar uma data, você DEVE calcular o dia da semana corretamente e verificar se está nos dias de trabalho do atendente.\n";
        $context .= "8. CRÍTICO: Ao perguntar ao cliente qual atendente ele prefere, não liste os atendentes que não oferecem o serviço escolhido.\n";
        $context .= "9. CRÍTICO: Ao perguntar ao cliente qual atendente ele prefere, não liste os atendentes que não estão disponíveis na data escolhida.\n";
        
        return $context;
    }
    
    /**
     * Busca documentos na base de conhecimento relevantes para a consulta
     * 
     * @param string $query Consulta do usuário
     * @param int $limit Número máximo de documentos a retornar
     * @param float $threshold Limite mínimo de similaridade (0-1)
     * @return array Documentos relevantes
     */
    public function searchKnowledgeBase($query, $limit = 3, $threshold = 0.7) {
        try {
            // Se não tiver chave de API para embeddings, usar busca por palavras-chave
            if (empty($this->embeddingApiKey)) {
                return $this->keywordSearch($query, $limit);
            }
            
            // Gerar embedding para a consulta
            $embedding = $this->generateEmbedding($query);
            
            if (empty($embedding)) {
                error_log("Não foi possível gerar embedding para a consulta");
                return $this->keywordSearch($query, $limit);
            }
            
            // Fazer busca por similaridade usando PgVector no Supabase
            $result = $this->makeSupabaseRequest(
                "/rest/v1/rpc/match_documents",
                "POST",
                [
                    'query_embedding' => $embedding,
                    'match_threshold' => $threshold,
                    'match_count' => $limit
                ]
            );
            
            if (empty($result)) {
                return [];
            }
            
            // Formatar resultados
            $documents = [];
            foreach ($result as $item) {
                $documents[] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'content' => $item['content'],
                    'similarity' => $item['similarity']
                ];
            }
            
            return $documents;
            
        } catch (Exception $e) {
            error_log("Erro ao buscar na base de conhecimento: " . $e->getMessage());
            // Em caso de erro, tentar busca por palavras-chave
            return $this->keywordSearch($query, $limit);
        }
    }
    
    /**
     * Realiza busca por palavras-chave na base de conhecimento
     * 
     * @param string $query Consulta do usuário
     * @param int $limit Número máximo de documentos a retornar
     * @return array Documentos relevantes
     */
    private function keywordSearch($query, $limit = 3) {
        try {
            // Extrair palavras-chave (remover palavras comuns)
            $stopWords = ['de', 'a', 'o', 'que', 'e', 'do', 'da', 'em', 'um', 'para', 'é', 'com', 'não', 'uma', 'os', 'no', 'se', 'na', 'por', 'mais', 'as', 'dos', 'como', 'mas', 'foi', 'ao', 'ele', 'das', 'tem', 'à', 'seu', 'sua', 'ou', 'ser', 'quando', 'muito', 'há', 'nos', 'já', 'está', 'eu', 'também', 'só', 'pelo', 'pela', 'até', 'isso', 'ela', 'entre', 'era', 'depois', 'sem', 'mesmo', 'aos', 'ter', 'seus', 'quem', 'nas', 'me', 'esse', 'eles', 'estão', 'você', 'tinha', 'foram', 'essa', 'num', 'nem', 'suas', 'meu', 'às', 'minha', 'têm', 'numa', 'pelos', 'elas', 'havia', 'seja', 'qual', 'será', 'nós', 'tenho', 'lhe', 'deles', 'essas', 'esses', 'pelas', 'este', 'fosse', 'dele', 'tu', 'te', 'vocês', 'vos', 'lhes', 'meus', 'minhas', 'teu', 'tua', 'teus', 'tuas', 'nosso', 'nossa', 'nossos', 'nossas', 'dela', 'delas', 'esta', 'estes', 'estas', 'aquele', 'aquela', 'aqueles', 'aquelas', 'isto', 'aquilo', 'estou', 'está', 'estamos', 'estão', 'estive', 'esteve', 'estivemos', 'estiveram', 'estava', 'estávamos', 'estavam', 'estivera', 'estivéramos', 'esteja', 'estejamos', 'estejam', 'estivesse', 'estivéssemos', 'estivessem', 'estiver', 'estivermos', 'estiverem', 'hei', 'há', 'havemos', 'hão', 'houve', 'houvemos', 'houveram', 'houvera', 'houvéramos', 'haja', 'hajamos', 'hajam', 'houvesse', 'houvéssemos', 'houvessem', 'houver', 'houvermos', 'houverem', 'houverei', 'houverá', 'houveremos', 'houverão', 'houveria', 'houveríamos', 'houveriam', 'sou', 'somos', 'são', 'era', 'éramos', 'eram', 'fui', 'foi', 'fomos', 'foram', 'fora', 'fôramos', 'seja', 'sejamos', 'sejam', 'fosse', 'fôssemos', 'fossem', 'for', 'formos', 'forem', 'serei', 'será', 'seremos', 'serão', 'seria', 'seríamos', 'seriam', 'tenho', 'tem', 'temos', 'tém', 'tinha', 'tínhamos', 'tinham', 'tive', 'teve', 'tivemos', 'tiveram', 'tivera', 'tivéramos', 'tenha', 'tenhamos', 'tenham', 'tivesse', 'tivéssemos', 'tivessem', 'tiver', 'tivermos', 'tiverem', 'terei', 'terá', 'teremos', 'terão', 'teria', 'teríamos', 'teriam'];
            
            $keywords = array_filter(
                array_map('trim', 
                    explode(' ', 
                        preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($query))
                    )
                ),
                function($word) use ($stopWords) {
                    return !in_array($word, $stopWords) && mb_strlen($word) > 2;
                }
            );
            
            if (empty($keywords)) {
                return [];
            }
            
            // Construir condição ILIKE para cada palavra-chave
            $conditions = [];
            foreach ($keywords as $keyword) {
                $conditions[] = "content ilike '%$keyword%' OR title ilike '%$keyword%'";
            }
            
            $query = implode(' OR ', $conditions);
            
            // Buscar documentos
            $result = $this->makeSupabaseRequest(
                "/rest/v1/knowledge_base",
                "GET",
                null,
                [
                    'select' => 'id,title,content',
                    'order' => 'created_at.desc',
                    'limit' => $limit
                ]
            );
            
            if (empty($result)) {
                return [];
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Erro na busca por palavras-chave: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Gera embedding para um texto usando OpenAI
     * 
     * @param string $text Texto para gerar embedding
     * @return array Vector de embedding
     */
    private function generateEmbedding($text) {
        try {
            if (empty($this->embeddingApiKey)) {
                return null;
            }
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->embeddingApiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->embeddingApiKey
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => $this->embeddingModel,
                    'input' => $text
                ])
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                error_log("Erro ao gerar embedding ($httpCode): $response");
                return null;
            }
            
            $result = json_decode($response, true);
            
            if (!isset($result['data'][0]['embedding'])) {
                error_log("Resposta de embedding inválida");
                return null;
            }
            
            return $result['data'][0]['embedding'];
            
        } catch (Exception $e) {
            error_log("Erro ao gerar embedding: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtém informações do negócio
     * 
     * @return array|null Informações do negócio
     */
    public function getBusinessInfo() {
        try {
            $result = $this->makeSupabaseRequest(
                "/rest/v1/business_data",
                "GET",
                null,
                ['limit' => 1]
            );
            
            return !empty($result) ? $result[0] : null;
        } catch (Exception $e) {
            error_log("Erro ao obter informações do negócio: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtém horários de funcionamento
     * 
     * @return array Horários de funcionamento
     */
    public function getBusinessHours() {
        try {
            $result = $this->makeSupabaseRequest(
                "/rest/v1/business_hours",
                "GET",
                null,
                ['order' => 'id.asc']
            );
            
            return $result;
        } catch (Exception $e) {
            error_log("Erro ao obter horários de funcionamento: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtém serviços disponíveis
     * 
     * @return array Serviços disponíveis
     */
    public function getServices() {
        try {
            $result = $this->makeSupabaseRequest(
                "/rest/v1/services",
                "GET",
                null,
                [
                    'select' => '*',
                    'available' => 'eq.true',
                    'order' => 'name.asc'
                ]
            );
            
            return $result;
        } catch (Exception $e) {
            error_log("Erro ao obter serviços: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtém serviço por nome ou parte do nome
     * 
     * @param string $name Nome ou parte do nome do serviço
     * @return array Serviços encontrados
     */
    public function getServiceByName($name) {
        try {
            // Primeiro, tentar busca exata (case insensitive)
            $exactResult = $this->makeSupabaseRequest(
                "/rest/v1/services",
                "GET",
                null,
                [
                    'select' => '*',
                    'name' => 'ilike.' . $name,
                    'available' => 'eq.true'
                ]
            );
            
            if (!empty($exactResult)) {
                return $exactResult;
            }
            
            // Depois, buscar por palavras-chave contidas no nome
            $partialResult = $this->makeSupabaseRequest(
                "/rest/v1/services",
                "GET",
                null,
                [
                    'select' => '*',
                    'name' => 'ilike.%' . $name . '%',
                    'available' => 'eq.true'
                ]
            );
            
            return $partialResult;
        } catch (Exception $e) {
            error_log("Erro ao buscar serviço por nome: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtém atendentes disponíveis
     * 
     * @return array Atendentes disponíveis
     */
    public function getAttendants() {
        try {
            $result = $this->makeSupabaseRequest(
                "/rest/v1/attendants",
                "GET",
                null,
                [
                    'select' => '*',
                    'available' => 'eq.true',
                    'order' => 'name.asc'
                ]
            );
            
            return $result;
        } catch (Exception $e) {
            error_log("Erro ao obter atendentes: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtém atendentes que oferecem um serviço específico
     * 
     * @param string $serviceId ID do serviço
     * @return array Atendentes que oferecem o serviço
     */
    public function getAttendantsByService($serviceId) {
        try {
            $result = $this->makeSupabaseRequest(
                "/rest/v1/service_assignments",
                "GET",
                null,
                [
                    'select' => 'attendant_id,attendant_name,attendant_position',
                    'service_id' => "eq.$serviceId"
                ]
            );
            
            // Se quiser trazer mais informações sobre os atendentes, pode fazer um join via código
            if (!empty($result)) {
                $attendants = [];
                $uniqueIds = [];
                
                foreach ($result as $assignment) {
                    // Garantir que não há duplicatas
                    if (!in_array($assignment['attendant_id'], $uniqueIds)) {
                        $uniqueIds[] = $assignment['attendant_id'];
                        
                        // Buscar informações completas do atendente
                        $attendantInfo = $this->makeSupabaseRequest(
                            "/rest/v1/attendants",
                            "GET",
                            null,
                            [
                                'select' => '*',
                                'id' => "eq.{$assignment['attendant_id']}",
                                'available' => 'eq.true'
                            ]
                        );
                        
                        if (!empty($attendantInfo)) {
                            $attendants[] = $attendantInfo[0];
                        }
                    }
                }
                
                return $attendants;
            }
            
            return [];
        } catch (Exception $e) {
            error_log("Erro ao obter atendentes por serviço: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtém serviços oferecidos por um atendente
     * 
     * @param string $attendantId ID do atendente
     * @return array Serviços oferecidos pelo atendente
     */
    public function getAttendantServices($attendantId) {
        try {
            $result = $this->makeSupabaseRequest(
                "/rest/v1/service_assignments",
                "GET",
                null,
                [
                    'select' => 'service_id,service_name,service_price,service_duration',
                    'attendant_id' => "eq.$attendantId"
                ]
            );
            
            return $result;
        } catch (Exception $e) {
            error_log("Erro ao obter serviços do atendente: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Formata os dias de trabalho para nomes legíveis
     * 
     * @param array $days Array de números dos dias (0=Domingo, 1=Segunda, etc)
     * @return array Array com nomes dos dias
     */
    private function formatWorkDays($days) {
        $dayNames = [
            '0' => 'Domingo',
            '1' => 'Segunda-feira',
            '2' => 'Terça-feira',
            '3' => 'Quarta-feira',
            '4' => 'Quinta-feira',
            '5' => 'Sexta-feira',
            '6' => 'Sábado'
        ];
        
        $result = [];
        foreach ($days as $day) {
            if (isset($dayNames["$day"])) {
                $result[] = $dayNames["$day"];
            }
        }
        
        return $result;
    }
    
    /**
     * Obtém agendamentos por data
     * 
     * @param string $date Data no formato YYYY-MM-DD
     * @return array Agendamentos da data especificada
     */
    public function getAppointmentsByDate($date) {
        try {
            $result = $this->makeSupabaseRequest(
                "/rest/v1/appointments",
                "GET",
                null,
                [
                    'select' => '*',
                    'appointment_date' => "eq.$date",
                    'order' => 'appointment_time.asc'
                ]
            );
            
            return $result;
        } catch (Exception $e) {
            error_log("Erro ao obter agendamentos por data: " . $e->getMessage());
            return [];
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