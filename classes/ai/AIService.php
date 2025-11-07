<?php
namespace mod_pluginpatroller\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Servicio principal de IA que decide automáticamente qué proveedor usar
 * - Si hay API key de Gemini configurada: usa GeminiProvider (IA real)
 * - Si no hay API key: usa MockProvider (análisis simulado)
 */
class AIService {
    
    private $provider;
    
    public function __construct() {
        $this->initializeProvider();
    }
    
    /**
     * Analiza los commits de un estudiante usando IA
     */
    public function analyzeStudent($student_data, $repository_data, $commits_data) {
        try {
            return $this->provider->analyzeStudent($student_data, $repository_data, $commits_data);
        } catch (\Exception $e) {
            error_log("PLUGIN AI ERROR: " . $e->getMessage());
            
            // Si falla, usar provider de fallback
            if (!($this->provider instanceof MockProvider)) {
                $this->provider = new MockProvider();
                return $this->provider->analyzeStudent($student_data, $repository_data, $commits_data);
            }
            
            throw $e;
        }
    }
    
    /**
     * Verifica si está usando IA real o simulada
     */
    public function isUsingRealAI(): bool {
        return ($this->provider instanceof GeminiProvider);
    }
    
    /**
     * Obtiene información del proveedor actual
     */
    public function getProviderInfo(): array {
        if ($this->provider instanceof GeminiProvider) {
            return [
                'type' => 'real',
                'name' => 'Google Gemini',
                'status' => 'IA real conectada'
            ];
        } else {
            return [
                'type' => 'demo',
                'name' => 'Análisis simulado',
                'status' => 'Modo demo - Configure API key para IA real'
            ];
        }
    }
    
    /**
     * Inicializa el proveedor apropiado según configuración
     */
    private function initializeProvider(): void {
        $gemini_api_key = get_config('mod_pluginpatroller', 'gemini_api_key');
        
        if (!empty($gemini_api_key) && trim($gemini_api_key) !== '') {
            // Usar IA real
            $this->provider = new GeminiProvider($gemini_api_key);
        } else {
            // Usar análisis simulado
            $this->provider = new MockProvider();
        }
    }
}