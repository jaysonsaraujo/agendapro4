<?php
/**
 * DateHelper.php
 * 
 * Classe utilitária para manipulação, validação e formatação de datas.
 * Centraliza todas as operações com datas, garantindo consistência e uso correto do timezone.
 */

class DateHelper {
    /**
     * Inicializa o timezone padrão
     */
    public static function init() {
        date_default_timezone_set('America/Sao_Paulo');
    }
    
    /**
     * Retorna o timezone correto para uso em todo o sistema
     * 
     * @return string Nome do timezone
     */
    public static function getTimezone() {
        return 'America/Sao_Paulo';
    }
    
    /**
     * Retorna a data e hora atual no formato especificado
     * 
     * @param string $format Formato da data (padrão Y-m-d H:i:s)
     * @return string Data/hora formatada
     */
    public static function now($format = 'Y-m-d H:i:s') {
        self::init();
        return date($format);
    }
    
    /**
     * Valida se uma data é válida e está no formato especificado
     * 
     * @param string $date Data a ser validada
     * @param string $format Formato esperado (padrão d/m/Y)
     * @return bool True se a data for válida
     */
    public static function isValidDate($date, $format = 'd/m/Y') {
        if (empty($date)) return false;
        
        $d = \DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
    
    /**
     * Converte uma data de um formato para outro
     * 
     * @param string $date Data a ser convertida
     * @param string $fromFormat Formato de origem (padrão d/m/Y)
     * @param string $toFormat Formato de destino (padrão Y-m-d)
     * @return string|false Data convertida ou false se inválida
     */
    public static function convertFormat($date, $fromFormat = 'd/m/Y', $toFormat = 'Y-m-d') {
        if (!self::isValidDate($date, $fromFormat)) return false;
        
        $dateObj = \DateTime::createFromFormat($fromFormat, $date);
        return $dateObj->format($toFormat);
    }
    
    /**
     * Obtém o dia da semana de uma data
     * 
     * @param string $date Data no formato d/m/Y ou DateTime
     * @param bool $asNumber Se true, retorna o número do dia (0=Dom, 1=Seg, etc)
     * @return string|int Nome do dia da semana ou número
     */
    public static function getDayOfWeek($date, $asNumber = false) {
        self::init();
        
        if (is_string($date)) {
            // Verificar se está no formato brasileiro
            if (strpos($date, '/') !== false) {
                list($day, $month, $year) = explode('/', $date);
                $timestamp = mktime(0, 0, 0, $month, $day, $year);
            } else {
                $timestamp = strtotime($date);
            }
        } elseif ($date instanceof \DateTime) {
            $timestamp = $date->getTimestamp();
        } else {
            return false;
        }
        
        if ($asNumber) {
            return (int)date('w', $timestamp);
        }
        
        $daysOfWeek = [
            'Domingo', 'Segunda-feira', 'Terça-feira', 
            'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'
        ];
        
        return $daysOfWeek[(int)date('w', $timestamp)];
    }
    
    /**
     * Processa uma expressão de data em linguagem natural
     * 
     * @param string $expression Expressão (hoje, amanhã, próxima segunda, etc)
     * @return array Informações sobre a data processada
     */
    public static function processDateExpression($expression) {
        self::init();
        
        $expression = mb_strtolower(trim($expression));
        $today = new \DateTime();
        $today->setTime(0, 0, 0);
        
        error_log("DateHelper: Processando expressão de data: '$expression'");
        
        $weekdays = [
            'domingo' => 0, 'dom' => 0, 
            'segunda' => 1, 'seg' => 1, 'segunda-feira' => 1, 
            'terça' => 2, 'terca' => 2, 'ter' => 2, 'terça-feira' => 2, 'terca-feira' => 2,
            'quarta' => 3, 'qua' => 3, 'quarta-feira' => 3, 
            'quinta' => 4, 'qui' => 4, 'quinta-feira' => 4, 
            'sexta' => 5, 'sex' => 5, 'sexta-feira' => 5,
            'sábado' => 6, 'sabado' => 6, 'sab' => 6
        ];
        
        $months = [
            'janeiro' => 1, 'jan' => 1,
            'fevereiro' => 2, 'fev' => 2,
            'março' => 3, 'marco' => 3, 'mar' => 3,
            'abril' => 4, 'abr' => 4,
            'maio' => 5, 'mai' => 5,
            'junho' => 6, 'jun' => 6,
            'julho' => 7, 'jul' => 7,
            'agosto' => 8, 'ago' => 8,
            'setembro' => 9, 'set' => 9,
            'outubro' => 10, 'out' => 10,
            'novembro' => 11, 'nov' => 11,
            'dezembro' => 12, 'dez' => 12
        ];
        
        // Expressões comuns
        if ($expression == 'hoje') {
            error_log("DateHelper: Expressão reconhecida como 'hoje'");
            $date = clone $today;
        } elseif ($expression == 'amanhã' || $expression == 'amanha') {
            error_log("DateHelper: Expressão reconhecida como 'amanhã'");
            $date = clone $today;
            $date->modify('+1 day');
            error_log("DateHelper: Data amanhã calculada como: " . $date->format('d/m/Y (l)'));
        } elseif ($expression == 'depois de amanhã' || $expression == 'depois de amanha') {
            error_log("DateHelper: Expressão reconhecida como 'depois de amanhã'");
            $date = clone $today;
            $date->modify('+2 days');
        } 
        // Formato "dia DD de MÊS de AAAA" (ex: "dia 01 de abril de 2025")
        elseif (preg_match('/dia\s+(\d{1,2})\s+de\s+([a-zç]+)(?:\s+de\s+(\d{4}))?/', $expression, $matches)) {
            $day = (int)$matches[1];
            $monthName = $matches[2];
            $year = isset($matches[3]) ? (int)$matches[3] : (int)$today->format('Y');
            
            error_log("DateHelper: Expressão reconhecida como 'dia DD de MES de AAAA'");
            error_log("DateHelper: Dia: $day, Mês: $monthName, Ano: $year");
            
            // Verificar se o nome do mês é válido
            if (!isset($months[$monthName])) {
                error_log("DateHelper: Nome de mês não reconhecido: $monthName");
                return ['success' => false, 'message' => "Nome de mês não reconhecido: $monthName"];
            }
            
            $month = $months[$monthName];
            
            // Validar data
            if (!checkdate($month, $day, $year)) {
                error_log("DateHelper: Data inválida: $day/$month/$year");
                return ['success' => false, 'message' => "Data inválida: $day/$month/$year"];
            }
            
            $date = new \DateTime();
            $date->setDate($year, $month, $day);
            $date->setTime(0, 0, 0);

            // Verificar se há uma menção de dia da semana no início e fazer a verificação de consistência
            if (preg_match('/^(segunda|segunda-feira|seg|terça|terca|terça-feira|terca-feira|ter|quarta|quarta-feira|qua|quinta|quinta-feira|qui|sexta|sexta-feira|sex|sábado|sabado|sab|domingo|dom)(?:[,-]|\s+)/i', $expression, $weekdayMatches)) {
                $weekdayMention = mb_strtolower(trim($weekdayMatches[1]));
                
                // Mapear o dia da semana mencionado para seu número correspondente
                $weekdayMap = [
                    'domingo' => 0, 'dom' => 0,
                    'segunda' => 1, 'segunda-feira' => 1, 'seg' => 1,
                    'terça' => 2, 'terca' => 2, 'terça-feira' => 2, 'terca-feira' => 2, 'ter' => 2,
                    'quarta' => 3, 'quarta-feira' => 3, 'qua' => 3,
                    'quinta' => 4, 'quinta-feira' => 4, 'qui' => 4,
                    'sexta' => 5, 'sexta-feira' => 5, 'sex' => 5,
                    'sábado' => 6, 'sabado' => 6, 'sab' => 6
                ];
                
                $mentionedWeekday = isset($weekdayMap[$weekdayMention]) ? $weekdayMap[$weekdayMention] : null;
                // Obter o dia da semana real da data
                $actualWeekday = (int)$date->format('w');
                
                if ($mentionedWeekday !== null && $mentionedWeekday !== $actualWeekday) {
                    // A data mencionada não corresponde ao dia da semana mencionado
                    $weekdayNames = [
                        0 => 'Domingo',
                        1 => 'Segunda-feira',
                        2 => 'Terça-feira',
                        3 => 'Quarta-feira',
                        4 => 'Quinta-feira',
                        5 => 'Sexta-feira',
                        6 => 'Sábado'
                    ];
                    
                    error_log("CONFLITO DETECTADO: {$weekdayNames[$mentionedWeekday]} ≠ {$weekdayNames[$actualWeekday]}");
                    
                    return [
                        'success' => false, 
                        'message' => "Inconsistência: $day/$month/$year não é {$weekdayNames[$mentionedWeekday]}, é {$weekdayNames[$actualWeekday]}",
                        'expected_weekday' => $mentionedWeekday,
                        'actual_weekday' => $actualWeekday,
                        'date' => $date->format('d/m/Y')
                    ];
                }
            }
        }
        // Processar expressões com dia da semana mencionado (ex: "segunda-feira, dia 01 de abril")
        elseif (preg_match('/^(segunda|segunda-feira|seg|terça|terca|terça-feira|terca-feira|ter|quarta|quarta-feira|qua|quinta|quinta-feira|qui|sexta|sexta-feira|sex|sábado|sabado|sab|domingo|dom)(?:[,-]|\s+)?\s*(?:dia\s+)?(\d{1,2})(?:\s+de\s+([a-zç]+))?(?:\s+de\s+(\d{4}))?/i', $expression, $matches)) {
            $weekdayMention = mb_strtolower(trim($matches[1]));
            $day = (int)$matches[2];
            $monthName = !empty($matches[3]) ? $matches[3] : null;
            $year = !empty($matches[4]) ? (int)$matches[4] : (int)$today->format('Y');
            
            error_log("Dia da semana mencionado: $weekdayMention");
            error_log("Dia: $day");
            error_log("Mês: $monthName");
            error_log("Ano: $year");
            
            // Se o mês for mencionado, usá-lo, senão usar o mês atual
            if ($monthName && isset($months[$monthName])) {
                $month = $months[$monthName];
            } else {
                $month = (int)$today->format('m');
                
                // Verificar se a informação de data pode conter um formato DD/MM/YYYY
                if (preg_match('/(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?/', $expression, $dateMatches)) {
                    // Se encontrou uma data no formato DD/MM, usar esse mês
                    $month = (int)$dateMatches[2];
                    // Se um ano foi especificado, usá-lo
                    if (!empty($dateMatches[3])) {
                        $year = (int)$dateMatches[3];
                    }
                }
            }
            
            // Validar data
            if (!checkdate($month, $day, $year)) {
                return ['success' => false, 'message' => "Data inválida: $day/$month/$year"];
            }
            
            $date = new \DateTime();
            $date->setDate($year, $month, $day);
            $date->setTime(0, 0, 0);
            
            // Obter o dia da semana real da data
            $actualWeekday = (int)$date->format('w');
            
            // Identificar o dia da semana mencionado
            $weekdayMap = [
                'domingo' => 0, 'dom' => 0,
                'segunda' => 1, 'segunda-feira' => 1, 'seg' => 1,
                'terça' => 2, 'terca' => 2, 'terça-feira' => 2, 'terca-feira' => 2, 'ter' => 2,
                'quarta' => 3, 'quarta-feira' => 3, 'qua' => 3,
                'quinta' => 4, 'quinta-feira' => 4, 'qui' => 4,
                'sexta' => 5, 'sexta-feira' => 5, 'sex' => 5,
                'sábado' => 6, 'sabado' => 6, 'sab' => 6
            ];
            
            $mentionedWeekday = isset($weekdayMap[$weekdayMention]) ? $weekdayMap[$weekdayMention] : null;
            
            error_log("Dia da semana mencionado (número): " . ($mentionedWeekday !== null ? $mentionedWeekday : "não identificado"));
            error_log("Dia da semana real (número): $actualWeekday");
            
            if ($mentionedWeekday !== null && $mentionedWeekday !== $actualWeekday) {
                // A data mencionada não corresponde ao dia da semana mencionado
                $weekdayNames = [
                    0 => 'Domingo',
                    1 => 'Segunda-feira',
                    2 => 'Terça-feira',
                    3 => 'Quarta-feira',
                    4 => 'Quinta-feira',
                    5 => 'Sexta-feira',
                    6 => 'Sábado'
                ];
                
                error_log("CONFLITO DETECTADO: {$weekdayNames[$mentionedWeekday]} ≠ {$weekdayNames[$actualWeekday]}");
                
                return [
                    'success' => false, 
                    'message' => "Inconsistência: $day/$month/$year não é {$weekdayNames[$mentionedWeekday]}, é {$weekdayNames[$actualWeekday]}",
                    'expected_weekday' => $mentionedWeekday,
                    'actual_weekday' => $actualWeekday,
                    'date' => $date->format('d/m/Y')
                ];
            }
            
            // Se chegou até aqui, a data é válida e consistente
            return [
                'success' => true,
                'date' => $date->format('d/m/Y'),
                'iso_date' => $date->format('Y-m-d'),
                'day_of_week' => self::getDayOfWeek($date),
                'day_number' => $actualWeekday,
                'date_obj' => $date
            ];
        }
        // Dia da semana mencionado, seguido de uma data no formato DD/MM/YYYY (ex: "segunda-feira, 01/04/2025")
        elseif (preg_match('/^(segunda|segunda-feira|seg|terça|terca|terça-feira|terca-feira|ter|quarta|quarta-feira|qua|quinta|quinta-feira|qui|sexta|sexta-feira|sex|sábado|sabado|sab|domingo|dom)(?:[,-]|\s+)?\s*(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?/i', $expression, $matches)) {
            $weekdayMention = mb_strtolower(trim($matches[1]));
            $day = (int)$matches[2];
            $month = (int)$matches[3];
            $year = !empty($matches[4]) ? (int)$matches[4] : (int)$today->format('Y');
            
            error_log("Dia da semana mencionado: $weekdayMention");
            error_log("Dia: $day");
            error_log("Mês: $month");
            error_log("Ano: $year");
            
            // Validar data
            if (!checkdate($month, $day, $year)) {
                return ['success' => false, 'message' => "Data inválida: $day/$month/$year"];
            }
            
            $date = new \DateTime();
            $date->setDate($year, $month, $day);
            $date->setTime(0, 0, 0);
            
            // Obter o dia da semana real da data
            $actualWeekday = (int)$date->format('w');
            
            // Identificar o dia da semana mencionado
            $weekdayMap = [
                'domingo' => 0, 'dom' => 0,
                'segunda' => 1, 'segunda-feira' => 1, 'seg' => 1,
                'terça' => 2, 'terca' => 2, 'terça-feira' => 2, 'terca-feira' => 2, 'ter' => 2,
                'quarta' => 3, 'quarta-feira' => 3, 'qua' => 3,
                'quinta' => 4, 'quinta-feira' => 4, 'qui' => 4,
                'sexta' => 5, 'sexta-feira' => 5, 'sex' => 5,
                'sábado' => 6, 'sabado' => 6, 'sab' => 6
            ];
            
            $mentionedWeekday = isset($weekdayMap[$weekdayMention]) ? $weekdayMap[$weekdayMention] : null;
            
            error_log("Dia da semana mencionado (número): " . ($mentionedWeekday !== null ? $mentionedWeekday : "não identificado"));
            error_log("Dia da semana real (número): $actualWeekday");
            
            if ($mentionedWeekday !== null && $mentionedWeekday !== $actualWeekday) {
                // A data mencionada não corresponde ao dia da semana mencionado
                $weekdayNames = [
                    0 => 'Domingo',
                    1 => 'Segunda-feira',
                    2 => 'Terça-feira',
                    3 => 'Quarta-feira',
                    4 => 'Quinta-feira',
                    5 => 'Sexta-feira',
                    6 => 'Sábado'
                ];
                
                error_log("CONFLITO DETECTADO: {$weekdayNames[$mentionedWeekday]} ≠ {$weekdayNames[$actualWeekday]}");
                
                return [
                    'success' => false, 
                    'message' => "Inconsistência: $day/$month/$year não é {$weekdayNames[$mentionedWeekday]}, é {$weekdayNames[$actualWeekday]}",
                    'expected_weekday' => $mentionedWeekday,
                    'actual_weekday' => $actualWeekday,
                    'date' => $date->format('d/m/Y')
                ];
            }
            
            // Se chegou até aqui, a data é válida e consistente
            return [
                'success' => true,
                'date' => $date->format('d/m/Y'),
                'iso_date' => $date->format('Y-m-d'),
                'day_of_week' => self::getDayOfWeek($date),
                'day_number' => $actualWeekday,
                'date_obj' => $date
            ];
        }
        // "Próxima segunda", etc
        elseif (preg_match('/próxim[ao]\s+([a-z\-]+)/ui', $expression, $matches) || 
                 preg_match('/proxim[ao]\s+([a-z\-]+)/ui', $expression, $matches)) {
            
            $weekday = mb_strtolower(trim($matches[1]));
            
            if (isset($weekdays[$weekday])) {
                $date = clone $today;
                $currentDay = (int)$today->format('w');
                $targetDay = $weekdays[$weekday];
                
                // Calcular dias a adicionar
                $daysToAdd = $targetDay - $currentDay;
                if ($daysToAdd <= 0) {
                    $daysToAdd += 7; // Vai para a próxima semana
                }
                
                $date->modify("+$daysToAdd days");
            } else {
                return ['success' => false, 'message' => 'Dia da semana não reconhecido'];
            }
        } 
        // Apenas nome do dia ("segunda", etc)
        elseif (isset($weekdays[$expression])) {
            $date = clone $today;
            $currentDay = (int)$today->format('w');
            $targetDay = $weekdays[$expression];
            
            // Calcular dias a adicionar
            $daysToAdd = $targetDay - $currentDay;
            if ($daysToAdd <= 0) {
                $daysToAdd += 7; // Vai para a próxima semana
            }
            
            $date->modify("+$daysToAdd days");
        } 
        // Data no formato DD/MM ou DD/MM/YYYY
        elseif (preg_match('/(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?/', $expression, $matches)) {
            $day = (int)$matches[1];
            $month = (int)$matches[2];
            $year = isset($matches[3]) ? (int)$matches[3] : (int)$today->format('Y');
            
            // Validar data
            if (!checkdate($month, $day, $year)) {
                return ['success' => false, 'message' => 'Data inválida'];
            }
            
            $date = new \DateTime();
            $date->setDate($year, $month, $day);
            $date->setTime(0, 0, 0);
            
            // Se a data já passou este ano, e não foi especificado o ano, considera o próximo ano
            if (!isset($matches[3]) && $date < $today) {
                $date->modify('+1 year');
            }
            
            // Verificar se há uma menção de dia da semana no início e fazer a verificação de consistência
            if (preg_match('/^(segunda|segunda-feira|seg|terça|terca|terça-feira|terca-feira|ter|quarta|quarta-feira|qua|quinta|quinta-feira|qui|sexta|sexta-feira|sex|sábado|sabado|sab|domingo|dom)(?:[,-]|\s+)/i', $expression, $weekdayMatches)) {
                $weekdayMention = mb_strtolower(trim($weekdayMatches[1]));
                
                // Mapear o dia da semana mencionado para seu número correspondente
                $weekdayMap = [
                    'domingo' => 0, 'dom' => 0,
                    'segunda' => 1, 'segunda-feira' => 1, 'seg' => 1,
                    'terça' => 2, 'terca' => 2, 'terça-feira' => 2, 'terca-feira' => 2, 'ter' => 2,
                    'quarta' => 3, 'quarta-feira' => 3, 'qua' => 3,
                    'quinta' => 4, 'quinta-feira' => 4, 'qui' => 4,
                    'sexta' => 5, 'sexta-feira' => 5, 'sex' => 5,
                    'sábado' => 6, 'sabado' => 6, 'sab' => 6
                ];
                
                $mentionedWeekday = isset($weekdayMap[$weekdayMention]) ? $weekdayMap[$weekdayMention] : null;
                // Obter o dia da semana real da data
                $actualWeekday = (int)$date->format('w');
                
                if ($mentionedWeekday !== null && $mentionedWeekday !== $actualWeekday) {
                    // A data mencionada não corresponde ao dia da semana mencionado
                    $weekdayNames = [
                        0 => 'Domingo',
                        1 => 'Segunda-feira',
                        2 => 'Terça-feira',
                        3 => 'Quarta-feira',
                        4 => 'Quinta-feira',
                        5 => 'Sexta-feira',
                        6 => 'Sábado'
                    ];
                    
                    error_log("CONFLITO DETECTADO: {$weekdayNames[$mentionedWeekday]} ≠ {$weekdayNames[$actualWeekday]}");
                    
                    return [
                        'success' => false, 
                        'message' => "Inconsistência: $day/$month/$year não é {$weekdayNames[$mentionedWeekday]}, é {$weekdayNames[$actualWeekday]}",
                        'expected_weekday' => $mentionedWeekday,
                        'actual_weekday' => $actualWeekday,
                        'date' => $date->format('d/m/Y')
                    ];
                }
            }
        } else {
            // Tentar outros formatos
            $timestamp = strtotime($expression);
            if ($timestamp !== false) {
                $date = new \DateTime();
                $date->setTimestamp($timestamp);
                $date->setTime(0, 0, 0);
            } else {
                return ['success' => false, 'message' => 'Formato de data não reconhecido'];
            }
        }
        
        // Obter o dia da semana
        $dayOfWeek = self::getDayOfWeek($date);
        $dayNumber = (int)$date->format('w');
        
        error_log("DateHelper: Data processada com sucesso: " . $date->format('d/m/Y') . " ($dayOfWeek)");
        
        return [
            'success' => true,
            'date' => $date->format('d/m/Y'),
            'iso_date' => $date->format('Y-m-d'),
            'day_of_week' => $dayOfWeek,
            'day_number' => $dayNumber,
            'date_obj' => $date
        ];
    }
    
    /**
     * Verifica se uma data é uma segunda-feira
     * 
     * @param string $date Data no formato d/m/Y
     * @return bool True se for segunda-feira
     */
    public static function isMonday($date) {
        return self::getDayOfWeek($date, true) === 1;
    }
    
    /**
     * Retorna a próxima segunda-feira a partir de uma data
     * 
     * @param string $date Data base no formato d/m/Y (padrão: hoje)
     * @return string Próxima segunda-feira no formato d/m/Y
     */
    public static function getNextMonday($date = null) {
        self::init();
        
        if ($date === null) {
            $dateObj = new \DateTime();
        } elseif (is_string($date)) {
            if (strpos($date, '/') !== false) {
                list($day, $month, $year) = explode('/', $date);
                $dateObj = new \DateTime();
                $dateObj->setDate($year, $month, $day);
            } else {
                $dateObj = new \DateTime($date);
            }
        } else {
            $dateObj = clone $date;
        }
        
        $dayOfWeek = (int)$dateObj->format('w');
        
        // Se hoje é segunda, retorna a próxima
        if ($dayOfWeek === 1) {
            $dateObj->modify('+7 days');
        } else {
            // Calcula quantos dias faltam para a próxima segunda
            $daysToAdd = 1 - $dayOfWeek;
            if ($daysToAdd <= 0) {
                $daysToAdd += 7;
            }
            $dateObj->modify("+$daysToAdd days");
        }
        
        return $dateObj->format('d/m/Y');
    }
    
    /**
     * Verifica se uma data é no passado
     * 
     * @param string $date Data no formato d/m/Y
     * @return bool True se a data for no passado
     */
    public static function isPast($date) {
        self::init();
        
        if (is_string($date) && strpos($date, '/') !== false) {
            list($day, $month, $year) = explode('/', $date);
            $timestamp = mktime(0, 0, 0, $month, $day, $year);
        } elseif ($date instanceof \DateTime) {
            $timestamp = $date->getTimestamp();
        } else {
            $timestamp = strtotime($date);
        }
        
        return $timestamp < strtotime('today');
    }
    
    /**
     * Formata uma data para exibição amigável, incluindo o dia da semana
     * 
     * @param string $date Data no formato d/m/Y
     * @return string Data formatada com dia da semana
     */
    public static function formatFriendlyDate($date) {
        $dayOfWeek = self::getDayOfWeek($date);
        return "$dayOfWeek, $date";
    }
} 