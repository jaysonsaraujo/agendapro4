<?php
/**
 * PhoneNumberUtility.php
 * 
 * Utilitário para manipulação de números de telefone
 * Inclui funções para formatação e padronização de números
 */

class PhoneNumberUtility {
    /**
     * Formata um número de telefone adicionando DDI 55 automaticamente se não estiver presente
     *
     * @param string $phoneNumber Número de telefone para formatar
     * @return string Número formatado com DDI 55
     */
    public static function formatPhoneNumber($phoneNumber) {
        // Remover qualquer caractere não numérico
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Se o número estiver vazio após a limpeza, retornar vazio
        if (empty($phoneNumber)) {
            return '';
        }
        
        // Se o número não começar com 55 (DDI do Brasil), adicionar
        if (!preg_match('/^55/', $phoneNumber)) {
            $phoneNumber = '55' . $phoneNumber;
        }
        
        // Retornar número formatado
        return $phoneNumber;
    }
    
    /**
     * Remove o DDI 55 do número para exibição mais amigável
     *
     * @param string $phoneNumber Número de telefone completo
     * @return string Número sem o DDI
     */
    public static function displayFormat($phoneNumber) {
        // Remover qualquer caractere não numérico
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Se o número começar com 55, removê-lo
        if (preg_match('/^55(.+)$/', $phoneNumber, $matches)) {
            return $matches[1];
        }
        
        return $phoneNumber;
    }
    
    /**
     * Extrai o número de telefone do JID do WhatsApp
     *
     * @param string $remoteJid JID do WhatsApp (formato: número@s.whatsapp.net)
     * @return string Número de telefone formatado com DDI 55
     */
    public static function extractFromJid($remoteJid) {
        // Remove a parte @s.whatsapp.net ou @g.us
        $phoneNumber = substr($remoteJid, 0, strpos($remoteJid, '@'));
        
        // Formatar o número garantindo que tenha o DDI 55
        return self::formatPhoneNumber($phoneNumber);
    }
    
    /**
     * Verifica se um número de telefone é válido
     *
     * @param string $phoneNumber Número a ser validado
     * @return bool True se for válido, false caso contrário
     */
    public static function isValid($phoneNumber) {
        // Remover qualquer caractere não numérico
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Verificar se o número tem pelo menos 10 dígitos (considerando um número brasileiro)
        if (strlen($phoneNumber) < 10) {
            return false;
        }
        
        return true;
    }
} 