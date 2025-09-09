<?php
/**
 * AIToolHandler.php
 * 
 * Classe responsável por detectar e processar as chamadas de ferramentas (tools)
 * nas respostas geradas pela IA.
 */

require_once __DIR__ . '/DateHelper.php';

class AIToolHandler {
    private $supabaseUrl;
    private $supabaseKey;
    private $aiTools;
    
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
        
        // Inicializar AITools
        require_once __DIR__ . '/AITools.php';
        $this->aiTools = new AITools($supabaseUrl, $supabaseKey);
    }
    
    /**
     * Processa o texto da resposta e identifica chamadas de ferramentas
     * 
     * @param string $text Texto da resposta da IA
     * @return string Texto processado com as ferramentas executadas
     */
    public function processText($text) {
        // Padrão para identificar chamadas de ferramentas: [TOOL:nome_da_ferramenta] parâmetros
        // O novo padrão captura a ferramenta e seus parâmetros até uma quebra de linha dupla,
        // ou o início de outra ferramenta, ou o fim da linha, ou o fim do texto
        $pattern = '/\[TOOL:([\w_]+)\](?:\s+(.*?))?(?=\[TOOL:|\n\n|\n(?=[^\n])|\s*$)/s';
        
        // Procurar por todas as ocorrências no texto
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            error_log("Ferramentas encontradas na resposta: " . count($matches));
            
            // Processamos de trás para frente para não afetar as posições das outras ocorrências
            $matches = array_reverse($matches);
            
            foreach ($matches as $match) {
                $fullMatch = $match[0][0];
                $fullMatchPos = $match[0][1];
                $toolName = $match[1][0];
                $params = isset($match[2]) ? trim($match[2][0]) : '';
                
                error_log("Processando ferramenta: $toolName com parâmetros: $params");
                
                // Verificar se é uma ferramenta relacionada a disponibilidade ou horários
                if ($toolName === 'verificar_disponibilidade' || $toolName === 'listar_horarios') {
                    // Verificar consistência entre nome e ID
                    $correctedParams = $this->checkAttendantConsistency($text, $fullMatchPos, $params);
                    
                    if ($correctedParams !== $params) {
                        error_log("CORREÇÃO APLICADA: Parâmetros alterados de '$params' para '$correctedParams'");
                        $params = $correctedParams;
                    }
                }
                
                // Executar a ferramenta e obter o resultado
                $toolResult = $this->executeTool($toolName, $params);
                
                // Substituir a chamada da ferramenta pelo resultado no texto original
                $text = substr_replace($text, $toolResult, $fullMatchPos, strlen($fullMatch));
            }
        }
        
        return $text;
    }
    
    /**
     * Verifica a consistência entre o nome do atendente mencionado no texto e o ID passado
     * 
     * @param string $text Texto completo
     * @param int $toolPosition Posição da ferramenta no texto
     * @param string $params Parâmetros da ferramenta
     * @return string Parâmetros corrigidos
     */
    private function checkAttendantConsistency($text, $toolPosition, $params) {
        // Extrair os parâmetros atuais
        $parameters = $this->parseParameters($params);
        
        if (count($parameters) < 1) {
            return $params; // Sem parâmetros suficientes para analisar
        }
        
        $currentId = $parameters[0];
        
        // Extrair texto de contexto anterior à chamada da ferramenta para análise
        $contextText = substr($text, 0, $toolPosition);
        
        error_log("Contexto extraído para análise de consistência: " . $contextText);
        
        try {
            // Consultar todos os atendentes ativos do Supabase
            $url = $this->supabaseUrl . '/rest/v1/attendants';
            $queryParams = ['select' => 'id,name', 'available' => 'eq.true'];
            
            $ch = curl_init();
            $queryString = http_build_query($queryParams);
            $fullUrl = $url . '?' . $queryString;
            
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->supabaseKey,
                'apikey: ' . $this->supabaseKey
            ];
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $fullUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers
            ]);
            
            $response = curl_exec($ch);
            
            if (curl_errno($ch)) {
                error_log("Erro na consulta de atendentes: " . curl_error($ch));
                curl_close($ch);
                return $params; // Em caso de erro, manter os parâmetros originais
            }
            
            curl_close($ch);
            
            $attendants = json_decode($response, true);
            
            if (!is_array($attendants)) {
                error_log("Resposta inválida na consulta de atendentes: " . $response);
                return $params;
            }
            
            error_log("Atendentes encontrados: " . count($attendants));
            
            // Verificar se o ID atual está entre os atendentes
            $currentAttendant = null;
            $attendantsById = [];
            
            foreach ($attendants as $attendant) {
                $attendantsById[$attendant['id']] = $attendant['name'];
                
                if ($attendant['id'] === $currentId) {
                    $currentAttendant = $attendant;
                }
            }
            
            if (!$currentAttendant) {
                error_log("ID de atendente não encontrado: $currentId");
                return $params; // ID não encontrado, manter os parâmetros originais
            }
            
            // Verificar menções a nomes de atendentes no texto
            $mentionedAttendants = [];
            
            foreach ($attendants as $attendant) {
                // Criar padrão de regex para o nome
                $namePattern = $this->createNamePattern($attendant['name']);
                
                if (preg_match($namePattern, $contextText)) {
                    $mentionedAttendants[$attendant['id']] = $attendant['name'];
                    error_log("Atendente mencionado no texto: {$attendant['name']} (ID: {$attendant['id']})");
                }
            }
            
            // Se apenas um atendente foi mencionado e é diferente do atendente atual
            if (count($mentionedAttendants) === 1 && !isset($mentionedAttendants[$currentId])) {
                // Resolver a inconsistência
                $mentionedId = array_key_first($mentionedAttendants);
                $mentionedName = $mentionedAttendants[$mentionedId];
                $currentName = $currentAttendant['name'];
                
                error_log("CORREÇÃO: Texto menciona $mentionedName mas está usando ID de $currentName");
                
                // Substituir o ID no parâmetro
                $parameters[0] = $mentionedId;
                return implode(', ', $parameters);
            }
            
        } catch (Exception $e) {
            error_log("Erro ao verificar consistência de atendente: " . $e->getMessage());
        }
        
        // Sem inconsistência detectada ou não é possível determinar claramente
        return $params;
    }
    
    /**
     * Cria um padrão de regex para detectar menções ao nome de um atendente
     * 
     * @param string $name Nome do atendente
     * @return string Padrão de regex
     */
    private function createNamePattern($name) {
        // Converter para minúsculas
        $name = mb_strtolower($name);
        
        // Criar padrões para possíveis variações com/sem acentos
        $patterns = [$name];
        
        // Mapeamento geral de caracteres acentuados para não-acentuados
        $map = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n'
        ];
        
        // Verificar se o nome tem caracteres acentuados
        $hasAccents = false;
        foreach ($map as $accented => $nonAccented) {
            if (mb_strpos($name, $accented) !== false) {
                $hasAccents = true;
                // Adicionar variante sem acento
                $patterns[] = str_replace($accented, $nonAccented, $name);
            }
        }
        
        // Se não encontramos acentos, verificar se podemos criar variante com acento
        if (!$hasAccents) {
            $reverseMap = array_flip($map);
            foreach ($reverseMap as $nonAccented => $accented) {
                if (mb_strpos($name, $nonAccented) !== false) {
                    // Pode haver múltiplas variantes com acentos, mas adicionamos apenas uma por simplicidade
                    $patterns[] = str_replace($nonAccented, $accented, $name);
                    break; // apenas uma variante é suficiente
                }
            }
        }
        
        // Criar regex para cada padrão, considerando apenas palavras completas
        $regexPatterns = array_map(function($pattern) {
            return preg_quote($pattern, '/');
        }, $patterns);
        
        // Combinar todos os padrões em um regex, considerando palavras completas
        // A flag 'i' torna a busca case-insensitive, detectando maiúsculas e minúsculas
        return '/\b(' . implode('|', $regexPatterns) . ')\b/iu';
    }
    
    /**
     * Executa uma ferramenta específica com seus parâmetros
     * 
     * @param string $toolName Nome da ferramenta
     * @param string $params Parâmetros da ferramenta
     * @return string Resultado da execução da ferramenta
     */
    private function executeTool($toolName, $params) {
        try {
            // Verificar se a ferramenta existe
            if (!method_exists($this->aiTools, $toolName)) {
                error_log("Ferramenta não encontrada: $toolName");
                return "[Erro: Ferramenta '$toolName' não encontrada]";
            }
            
            error_log("Executando ferramenta: $toolName");
            
            // Processar parâmetros de acordo com a ferramenta
            switch ($toolName) {
                case 'listar_servicos':
                    // Não precisa de parâmetros
                    return $this->aiTools->listar_servicos();
                    
                case 'processar_servicos':
                    return $this->aiTools->processar_servicos($params);
                    
                case 'listar_atendentes':
                    // Pode ter um parâmetro opcional (ID do serviço)
                    $serviceId = trim($params);
                    return $this->aiTools->listar_atendentes($serviceId ? $serviceId : null);
                    
                case 'verificar_disponibilidade':
                case 'listar_horarios':
                    // Extrair parâmetros: attendantId, date, serviceId (opcional)
                    $parameters = $this->parseParameters($params);
                    
                    error_log("Parâmetros após parse para $toolName: " . print_r($parameters, true));
                    
                    if (count($parameters) < 2) {
                        error_log("Parâmetros insuficientes: " . count($parameters));
                        return "Parâmetros insuficientes para $toolName. Formato: [TOOL:$toolName] attendantId, date, serviceId(opcional)";
                    }
                    
                    $attendantId = $parameters[0];
                    $date = $parameters[1];
                    $serviceId = isset($parameters[2]) ? $parameters[2] : null;
                    
                    error_log("Chamando $toolName com: attendantId='$attendantId', date='$date', serviceId='$serviceId'");
                    
                    return $this->aiTools->$toolName($attendantId, $date, $serviceId);
                    
                case 'criar_agendamento':
                    // Extrair parâmetros: clientName, clientPhone, serviceId, attendantId, date, time, notes(opcional)
                    $parameters = $this->parseParameters($params);
                    
                    if (count($parameters) < 6) {
                        return "Parâmetros insuficientes para criar_agendamento. Formato: [TOOL:criar_agendamento] clientName, clientPhone, serviceId, attendantId, date, time, notes(opcional)";
                    }
                    
                    $clientName = $parameters[0];
                    $clientPhone = $parameters[1];
                    $serviceId = $parameters[2];
                    $attendantId = $parameters[3];
                    $date = $parameters[4];
                    $time = $parameters[5];
                    $notes = isset($parameters[6]) ? $parameters[6] : '';
                    
                    return $this->aiTools->criar_agendamento($clientName, $clientPhone, $serviceId, $attendantId, $date, $time, $notes);
                    
                case 'consultar_agendamentos':
                    // Extrair parâmetro: clientPhone
                    $clientPhone = trim($params);
                    
                    if (empty($clientPhone)) {
                        return "Parâmetro insuficiente para consultar_agendamentos. Formato: [TOOL:consultar_agendamentos] clientPhone";
                    }
                    
                    return $this->aiTools->consultar_agendamentos($clientPhone);
                    
                case 'cancelar_agendamento':
                    // Extrair parâmetros: appointmentId, reason(opcional)
                    $parameters = $this->parseParameters($params);
                    
                    if (count($parameters) < 1) {
                        return "Parâmetro insuficiente para cancelar_agendamento. Formato: [TOOL:cancelar_agendamento] appointmentId, reason(opcional)";
                    }
                    
                    $appointmentId = $parameters[0];
                    $reason = isset($parameters[1]) ? $parameters[1] : '';
                    
                    return $this->aiTools->cancelar_agendamento($appointmentId, $reason);
                    
                case 'verificar_status_agendamento':
                    // Extrair parâmetro: appointmentId
                    $appointmentId = trim($params);
                    
                    if (empty($appointmentId)) {
                        return "Parâmetro insuficiente para verificar_status_agendamento. Formato: [TOOL:verificar_status_agendamento] appointmentId";
                    }
                    
                    return $this->aiTools->verificar_status_agendamento($appointmentId);
                    
                default:
                    return "[Erro: Implementação da ferramenta '$toolName' não encontrada]";
            }
            
        } catch (Exception $e) {
            error_log("Erro ao executar ferramenta $toolName: " . $e->getMessage());
            return "[Erro ao executar ferramenta $toolName: " . $e->getMessage() . "]";
        }
    }
    
    /**
     * Analisa uma string de parâmetros e a divide em um array
     * 
     * @param string $paramsString String de parâmetros separados por vírgula
     * @return array Array de parâmetros
     */
    private function parseParameters($paramsString) {
        error_log("parseParameters recebeu: '$paramsString'");
        
        // Limpar possíveis quebras de linha no final
        $paramsString = preg_replace('/\n.*$/s', '', $paramsString);
        
        error_log("parseParameters após limpeza: '$paramsString'");
        
        // Se paramsString não contém vírgulas, retornar como um único parâmetro
        if (strpos($paramsString, ',') === false) {
            error_log("Sem vírgulas, retornando como parâmetro único");
            return [$paramsString];
        }
        
        // Dividir por vírgula e remover espaços extras
        $params = explode(',', $paramsString);
        $params = array_map('trim', $params);
        
        error_log("Parâmetros após processamento: " . print_r($params, true));
        
        return $params;
    }
} 