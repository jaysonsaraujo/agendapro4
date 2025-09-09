<?php
// Configurações do Supabase
// Adicione as configurações do seu Supabase aqui
$SUPABASE_URL = 'SUA_URL_DO_SUPABASE';
$SUPABASE_KEY = 'SUA_CHAVE_ANON_KEY';

// Configurações de timezone
date_default_timezone_set('America/Sao_Paulo');

// Carregar o gerenciador de configurações
require_once __DIR__ . '/ConfigManager.php';

try {
    // Inicializar o gerenciador de configurações
    $configManager = ConfigManager::getInstance($SUPABASE_URL, $SUPABASE_KEY);
    
    // Carregar configurações da Evolution API
    $evolutionConfig = $configManager->getEvolutionConfig();
    $EVOLUTION_API_BASE_URL = $evolutionConfig['base_url'];
    $EVOLUTION_API_KEY = $evolutionConfig['api_key'];
    
    // Carregar configurações da OpenAI
    $openaiConfig = $configManager->getOpenAIConfig();
    $OPENAI_API_KEY = $openaiConfig['api_key'];
    
    // Exportar as configurações como variáveis globais
    $GLOBALS['EVOLUTION_API_BASE_URL'] = $EVOLUTION_API_BASE_URL;
    $GLOBALS['EVOLUTION_API_KEY'] = $EVOLUTION_API_KEY;
    $GLOBALS['OPENAI_API_KEY'] = $OPENAI_API_KEY;
    
    // Exportar o gerenciador de configurações para uso em outros arquivos
    $GLOBALS['configManager'] = $configManager;
    
} catch (Exception $e) {
    error_log("Erro ao carregar configurações: " . $e->getMessage());
    die("Erro ao carregar configurações necessárias para o webhook");
} 