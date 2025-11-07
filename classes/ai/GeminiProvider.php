<?php
namespace mod_pluginpatroller\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Proveedor de IA usando Google Gemini API (gratis para uso educativo)
 * 60 requests/minuto, 1500 requests/día
 */
class GeminiProvider {
    
    private $api_key;
    private $base_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent';
    
    public function __construct($api_key) {
        $this->api_key = $api_key;
    }
    
    /**
     * Analiza los datos del estudiante usando Google Gemini
     */
    public function analyzeStudent($student_data, $repository_data, $commits_data) {
        // Preparar el prompt educativo
        $prompt = $this->buildEducationalPrompt($student_data, $repository_data, $commits_data);
        
        // Llamar a la API de Gemini
        $response = $this->callGeminiAPI($prompt);
        
        // Procesar y estructurar la respuesta
        return $this->processGeminiResponse($response, $student_data, $repository_data, $commits_data);
    }
    
    /**
     * Construye un prompt educativo específico para análisis de commits
     */
    private function buildEducationalPrompt($student_data, $repository_data, $commits_data) {
        $prompt = "Eres un asistente educativo que analiza el progreso de programación de estudiantes universitarios.\n\n";
        
        $prompt .= "CONTEXTO EDUCATIVO:\n";
        $prompt .= "- Estudiante: {$student_data['firstname']} {$student_data['lastname']}\n";
        $prompt .= "- Repositorio: {$repository_data['name']}\n";
        $prompt .= "- Curso: Programación universitaria\n\n";
        
        if (!empty($commits_data)) {
            $latest_commit = $commits_data[0];
            $prompt .= "ÚLTIMO COMMIT ANALIZADO:\n";
            $prompt .= "- Mensaje: {$latest_commit['message']}\n";
            $prompt .= "- Archivos modificados: {$latest_commit['files_changed']}\n";
            $prompt .= "- Líneas agregadas: {$latest_commit['lines_added']}\n";
            $prompt .= "- Líneas eliminadas: {$latest_commit['lines_deleted']}\n\n";
            
            if (count($commits_data) > 1) {
                $prompt .= "HISTORIAL RECIENTE (" . count($commits_data) . " commits):\n";
                foreach (array_slice($commits_data, 1, 5) as $commit) {
                    $prompt .= "- {$commit['message']}\n";
                }
                $prompt .= "\n";
            }
        }
        
        $prompt .= "INSTRUCCIONES:\n";
        $prompt .= "Analiza el progreso del estudiante y proporciona:\n";
        $prompt .= "1. FORTALEZAS: 3-4 aspectos positivos específicos observados\n";
        $prompt .= "2. OPORTUNIDADES: 3-4 áreas de mejora constructivas\n";
        $prompt .= "3. CALIFICACIÓN: Puntaje del 1-10 (donde 10 es excelente)\n";
        $prompt .= "4. FRECUENCIA: Evaluación de consistencia (Regular/Irregular/Esporádica)\n";
        $prompt .= "5. COMENTARIOS: Retroalimentación específica y motivacional\n\n";
        
        $prompt .= "CRITERIOS DE EVALUACIÓN:\n";
        $prompt .= "- Calidad de mensajes de commit\n";
        $prompt .= "- Frecuencia y consistencia de commits\n";
        $prompt .= "- Progreso evidente en el código\n";
        $prompt .= "- Buenas prácticas de versionado\n\n";
        
        $prompt .= "FORMATO DE RESPUESTA:\n";
        $prompt .= "Responde EXACTAMENTE en este formato JSON:\n";
        $prompt .= "{\n";
        $prompt .= '  "calificacion": 8,';
        $prompt .= "\n";
        $prompt .= '  "frecuencia": "Regular",';
        $prompt .= "\n";
        $prompt .= '  "fortalezas": ["Fortaleza 1", "Fortaleza 2", "Fortaleza 3"],';
        $prompt .= "\n";
        $prompt .= '  "oportunidades": ["Oportunidad 1", "Oportunidad 2", "Oportunidad 3"],';
        $prompt .= "\n";
        $prompt .= '  "comentarios": "Comentarios específicos y motivacionales para el estudiante"';
        $prompt .= "\n";
        $prompt .= "}\n\n";
        
        $prompt .= "Sé específico, constructivo y motivacional en tu análisis.";
        
        return $prompt;
    }
    
    /**
     * Realiza la llamada HTTP a la API de Gemini
     */
    private function callGeminiAPI($prompt) {
        $url = $this->base_url . '?key=' . $this->api_key;
        
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'topP' => 0.8,
                'maxOutputTokens' => 1000
            ]
        ];
        
        $options = [
            'http' => [
                'header' => [
                    'Content-Type: application/json',
                    'User-Agent: Moodle-PluginPatroller/1.0'
                ],
                'method' => 'POST',
                'content' => json_encode($data),
                'timeout' => 30
            ]
        ];
        
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new \Exception('Error conectando con Google Gemini API');
        }
        
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Respuesta inválida de Google Gemini API');
        }
        
        if (isset($decoded['error'])) {
            throw new \Exception('Error API Gemini: ' . $decoded['error']['message']);
        }
        
        return $decoded;
    }
    
    /**
     * Procesa la respuesta de Gemini y la estructura para el frontend
     */
    private function processGeminiResponse($gemini_response, $student_data, $repository_data, $commits_data) {
        try {
            // Extraer el texto de la respuesta
            $text = $gemini_response['candidates'][0]['content']['parts'][0]['text'] ?? '';
            
            // Intentar parsear como JSON
            $ai_analysis = json_decode($text, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Si no es JSON válido, parsear manualmente
                $ai_analysis = $this->parseTextResponse($text);
            }
            
            // Estructurar respuesta final
            return [
                'student' => [
                    'name' => $student_data['firstname'] . ' ' . $student_data['lastname'],
                    'username' => $student_data['username'] ?? '',
                    'github_username' => $student_data['github_username'] ?? ''
                ],
                'repository' => [
                    'name' => $repository_data['name'],
                    'last_commit' => $commits_data[0]['message'] ?? 'Sin commits',
                    'total_commits' => count($commits_data),
                    'files_changed' => $commits_data[0]['files_changed'] ?? 0,
                    'lines_added' => $commits_data[0]['lines_added'] ?? 0,
                    'lines_deleted' => $commits_data[0]['lines_deleted'] ?? 0
                ],
                'analysis' => [
                    'calificacion' => $ai_analysis['calificacion'] ?? 7,
                    'frecuencia' => $ai_analysis['frecuencia'] ?? 'Regular',
                    'fortalezas' => $ai_analysis['fortalezas'] ?? ['Análisis en proceso'],
                    'oportunidades' => $ai_analysis['oportunidades'] ?? ['Continuar desarrollando'],
                    'comentarios' => $ai_analysis['comentarios'] ?? 'Análisis completado con IA.',
                    'ai_provider' => 'Google Gemini',
                    'analysis_date' => date('Y-m-d H:i:s')
                ]
            ];
            
        } catch (\Exception $e) {
            error_log("PLUGIN AI ERROR processing Gemini response: " . $e->getMessage());
            
            // Respuesta de fallback si hay error procesando
            return $this->buildFallbackResponse($student_data, $repository_data, $commits_data);
        }
    }
    
    /**
     * Parsea respuesta de texto cuando no es JSON válido
     */
    private function parseTextResponse($text) {
        // Implementación básica para extraer información del texto
        $analysis = [
            'calificacion' => 7,
            'frecuencia' => 'Regular',
            'fortalezas' => ['Análisis generado por IA'],
            'oportunidades' => ['Continuar desarrollando'],
            'comentarios' => $text // Usar el texto completo como comentarios
        ];
        
        // Intentar extraer calificación numérica
        if (preg_match('/(\d+)\/10/', $text, $matches)) {
            $analysis['calificacion'] = intval($matches[1]);
        }
        
        return $analysis;
    }
    
    /**
     * Construye respuesta de fallback si hay error
     */
    private function buildFallbackResponse($student_data, $repository_data, $commits_data) {
        return [
            'student' => [
                'name' => $student_data['firstname'] . ' ' . $student_data['lastname'],
                'username' => $student_data['username'] ?? '',
                'github_username' => $student_data['github_username'] ?? ''
            ],
            'repository' => [
                'name' => $repository_data['name'],
                'last_commit' => $commits_data[0]['message'] ?? 'Sin commits',
                'total_commits' => count($commits_data),
                'files_changed' => $commits_data[0]['files_changed'] ?? 0,
                'lines_added' => $commits_data[0]['lines_added'] ?? 0,
                'lines_deleted' => $commits_data[0]['lines_deleted'] ?? 0
            ],
            'analysis' => [
                'calificacion' => 7,
                'frecuencia' => 'Regular',
                'fortalezas' => ['Error en análisis - modo fallback'],
                'oportunidades' => ['Verificar configuración de IA'],
                'comentarios' => 'Hubo un error en el análisis con IA. Verifique la configuración.',
                'ai_provider' => 'Google Gemini (Fallback)',
                'analysis_date' => date('Y-m-d H:i:s')
            ]
        ];
    }
}