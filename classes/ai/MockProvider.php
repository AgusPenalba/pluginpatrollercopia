<?php
namespace mod_pluginpatroller\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Proveedor simulado de IA para modo demo y fallback
 * Genera análisis realistas pero ficticios para testing
 */
class MockProvider {
    
    /**
     * Simula análisis de IA para un estudiante
     */
    public function analyzeStudent($student_data, $repository_data, $commits_data) {
        // Generar análisis simulado basado en datos reales del estudiante
        $analysis = $this->generateMockAnalysis($student_data, $repository_data, $commits_data);
        
        return [
            'student' => [
                'name' => $student_data['firstname'] . ' ' . $student_data['lastname'],
                'username' => $student_data['username'] ?? '',
                'github_username' => $student_data['github_username'] ?? ''
            ],
            'repository' => [
                'name' => $repository_data['name'],
                'last_commit' => $commits_data[0]['message'] ?? 'Update frontend styling and layout',
                'total_commits' => count($commits_data) ?: 4,
                'files_changed' => $commits_data[0]['files_changed'] ?? 4,
                'lines_added' => $commits_data[0]['lines_added'] ?? 132,
                'lines_deleted' => $commits_data[0]['lines_deleted'] ?? 34
            ],
            'analysis' => $analysis
        ];
    }
    
    /**
     * Genera análisis simulado inteligente basado en datos reales del estudiante
     */
    private function generateMockAnalysis($student_data, $repository_data, $commits_data) {
        $student_name = $student_data['firstname'] ?? 'Estudiante';
        $repo_name = $repository_data['name'] ?? 'repositorio';
        
        // Analizar datos reales de commits para generar análisis inteligente
        $commit_analysis = $this->analyzeCommitPatterns($commits_data);
        
        // Generar análisis basado en patrones reales
        $analysis = $this->generateAnalysisFromPatterns($commit_analysis, $student_name);
        
        // Agregar metadatos del análisis
        $analysis['ai_provider'] = 'Análisis Simulado (Basado en commits reales)';
        $analysis['analysis_date'] = date('Y-m-d H:i:s');
        
        return $analysis;
    }
    
    /**
     * Analiza patrones reales en los commits del estudiante
     */
    private function analyzeCommitPatterns($commits_data) {
        if (empty($commits_data)) {
            return [
                'total_commits' => 0,
                'frequency_score' => 1, // Muy bajo
                'message_quality' => 1,
                'consistency' => 1,
                'progress_trend' => 'sin_datos'
            ];
        }
        
        $total_commits = count($commits_data);
        $total_files = 0;
        $total_lines_added = 0;
        $total_lines_deleted = 0;
        $message_scores = [];
        
        // Analizar cada commit
        foreach ($commits_data as $commit) {
            $total_files += $commit['files_changed'] ?? 0;
            $total_lines_added += $commit['lines_added'] ?? 0;
            $total_lines_deleted += $commit['lines_deleted'] ?? 0;
            
            // Evaluar calidad del mensaje
            $message_scores[] = $this->evaluateCommitMessage($commit['message'] ?? '');
        }
        
        // Calcular métricas
        $avg_files_per_commit = $total_commits > 0 ? $total_files / $total_commits : 0;
        $avg_lines_per_commit = $total_commits > 0 ? ($total_lines_added + $total_lines_deleted) / $total_commits : 0;
        $avg_message_quality = !empty($message_scores) ? array_sum($message_scores) / count($message_scores) : 1;
        
        // Evaluar frecuencia (basado en número total de commits)
        $frequency_score = min(5, max(1, $total_commits / 2)); // 1-5 escala
        
        // Evaluar consistencia (basado en variación de tamaño de commits)
        $consistency_score = $this->calculateConsistencyScore($commits_data);
        
        return [
            'total_commits' => $total_commits,
            'avg_files_per_commit' => $avg_files_per_commit,
            'avg_lines_per_commit' => $avg_lines_per_commit,
            'frequency_score' => $frequency_score,
            'message_quality' => $avg_message_quality,
            'consistency' => $consistency_score,
            'total_lines_added' => $total_lines_added,
            'total_lines_deleted' => $total_lines_deleted,
            'progress_trend' => $this->determineProgressTrend($commits_data)
        ];
    }
    
    /**
     * Evalúa la calidad de un mensaje de commit (1-5)
     */
    private function evaluateCommitMessage($message) {
        $message = trim(strtolower($message));
        $score = 1;
        
        // Mensajes muy cortos o genéricos
        if (strlen($message) < 10) return 1;
        if (in_array($message, ['update', 'fix', 'change', 'commit', 'test', '.'])) return 1;
        
        // Mensajes descriptivos obtienen más puntos
        if (strlen($message) > 20) $score += 1;
        if (strlen($message) > 40) $score += 1;
        
        // Palabras clave que indican buenas prácticas
        $good_keywords = ['add', 'implement', 'fix', 'update', 'improve', 'refactor', 'optimize'];
        foreach ($good_keywords as $keyword) {
            if (strpos($message, $keyword) !== false) {
                $score += 0.5;
                break;
            }
        }
        
        // Estructura más específica
        if (preg_match('/\b(feature|bug|issue|task)\b/', $message)) $score += 0.5;
        
        return min(5, $score);
    }
    
    /**
     * Calcula score de consistencia basado en variación de commits
     */
    private function calculateConsistencyScore($commits_data) {
        if (count($commits_data) < 2) return 3;
        
        $sizes = [];
        foreach ($commits_data as $commit) {
            $size = ($commit['lines_added'] ?? 0) + ($commit['lines_deleted'] ?? 0);
            $sizes[] = $size;
        }
        
        // Calcular varianza
        $mean = array_sum($sizes) / count($sizes);
        $variance = 0;
        foreach ($sizes as $size) {
            $variance += pow($size - $mean, 2);
        }
        $variance = $variance / count($sizes);
        
        // Convertir varianza a score 1-5 (menor varianza = más consistente)
        if ($variance < 100) return 5;
        if ($variance < 500) return 4;
        if ($variance < 1000) return 3;
        if ($variance < 2000) return 2;
        return 1;
    }
    
    /**
     * Determina tendencia de progreso
     */
    private function determineProgressTrend($commits_data) {
        if (count($commits_data) < 3) return 'inicial';
        
        // Analizar últimos 3 commits vs primeros 3
        $recent = array_slice($commits_data, 0, 3);
        $older = array_slice($commits_data, -3, 3);
        
        $recent_activity = 0;
        $older_activity = 0;
        
        foreach ($recent as $commit) {
            $recent_activity += ($commit['lines_added'] ?? 0) + ($commit['lines_deleted'] ?? 0);
        }
        
        foreach ($older as $commit) {
            $older_activity += ($commit['lines_added'] ?? 0) + ($commit['lines_deleted'] ?? 0);
        }
        
        if ($recent_activity > $older_activity * 1.5) return 'creciente';
        if ($recent_activity < $older_activity * 0.5) return 'decreciente';
        return 'estable';
    }
    
    /**
     * Genera análisis inteligente basado en patrones reales
     */
    private function generateAnalysisFromPatterns($patterns, $student_name) {
        // Generar descripción basada en análisis real
        $descripcion = $this->generateDescriptionFromCommits($patterns, $student_name);
        
        // Generar sugerencias constructivas
        $sugerencias = $this->generateSuggestions($patterns);
        
        return [
            'descripcion' => $descripcion,
            'sugerencias' => $sugerencias,
            'total_commits' => $patterns['total_commits'],
            'total_lines_added' => $patterns['total_lines_added'],
            'total_lines_deleted' => $patterns['total_lines_deleted']
        ];
    }
    
    /**
     * Genera descripción basada en commits reales
     */
    private function generateDescriptionFromCommits($patterns, $student_name) {
        $descripcion_parts = [];
        
        if ($patterns['total_commits'] === 0) {
            return "$student_name aún no ha realizado commits en el repositorio. Es importante comenzar el desarrollo y establecer una rutina de commits regulares para mostrar el progreso del proyecto.";
        }
        
        // Descripción de actividad
        if ($patterns['total_commits'] === 1) {
            $descripcion_parts[] = "$student_name ha realizado 1 commit";
        } else {
            $descripcion_parts[] = "$student_name ha realizado {$patterns['total_commits']} commits";
        }
        
        // Descripción de volumen de trabajo
        if ($patterns['total_lines_added'] > 0) {
            $descripcion_parts[] = "agregando {$patterns['total_lines_added']} líneas de código";
            if ($patterns['total_lines_deleted'] > 0) {
                $descripcion_parts[] = "y eliminando {$patterns['total_lines_deleted']} líneas";
            }
        }
        
        // Descripción de calidad de mensajes
        if ($patterns['message_quality'] >= 4) {
            $descripcion_parts[] = "Los mensajes de commit son descriptivos y claros, lo cual facilita el seguimiento del progreso.";
        } elseif ($patterns['message_quality'] >= 3) {
            $descripcion_parts[] = "Los mensajes de commit son adecuados, aunque podrían ser más descriptivos.";
        } else {
            $descripcion_parts[] = "Los mensajes de commit tienden a ser muy breves o genéricos.";
        }
        
        // Descripción de consistencia
        if ($patterns['consistency'] >= 4) {
            $descripcion_parts[] = "El desarrollo muestra consistencia en el tamaño y frecuencia de los cambios.";
        } elseif ($patterns['consistency'] <= 2) {
            $descripcion_parts[] = "El tamaño de los commits varía considerablemente, lo que podría indicar trabajos en ráfagas.";
        }
        
        // Descripción de tendencia
        if ($patterns['progress_trend'] === 'creciente') {
            $descripcion_parts[] = "Se observa una tendencia positiva con mayor actividad en commits recientes.";
        } elseif ($patterns['progress_trend'] === 'decreciente') {
            $descripcion_parts[] = "La actividad reciente ha disminuido comparada con commits anteriores.";
        }
        
        return implode(' ', $descripcion_parts);
    }
    
    /**
     * Genera sugerencias constructivas
     */
    private function generateSuggestions($patterns) {
        $sugerencias = [];
        
        if ($patterns['total_commits'] === 0) {
            return [
                "Comienza realizando tu primer commit con la estructura básica del proyecto",
                "Establece una rutina de commits regulares (al menos 2-3 por semana)",
                "Usa mensajes descriptivos que expliquen qué cambios realizaste"
            ];
        }
        
        // Sugerencias basadas en frecuencia
        if ($patterns['frequency_score'] <= 2 && $patterns['total_commits'] < 5) {
            $sugerencias[] = "Considera realizar commits con mayor frecuencia para mostrar tu progreso de forma más continua";
        }
        
        // Sugerencias basadas en calidad de mensajes
        if ($patterns['message_quality'] <= 2) {
            $sugerencias[] = "Mejora la descripción en los mensajes de commit explicando qué funcionalidad agregaste o qué problema resolviste";
        } elseif ($patterns['message_quality'] <= 3) {
            $sugerencias[] = "Tus mensajes están bien, pero podrías ser más específico sobre los cambios realizados";
        }
        
        // Sugerencias basadas en consistencia
        if ($patterns['consistency'] <= 2) {
            $sugerencias[] = "Intenta mantener un tamaño más consistente en tus commits, evitando cambios muy grandes o muy pequeños";
        }
        
        // Sugerencias basadas en volumen
        if ($patterns['avg_lines_per_commit'] < 10) {
            $sugerencias[] = "Considera agrupar cambios relacionados en un mismo commit para que sean más significativos";
        } elseif ($patterns['avg_lines_per_commit'] > 100) {
            $sugerencias[] = "Divide los cambios grandes en commits más pequeños para facilitar el seguimiento";
        }
        
        // Sugerencias basadas en tendencia
        if ($patterns['progress_trend'] === 'decreciente') {
            $sugerencias[] = "Mantén un ritmo constante de desarrollo para mostrar progreso continuo";
        }
        
        // Sugerencias generales positivas
        if ($patterns['message_quality'] >= 4 && $patterns['frequency_score'] >= 3) {
            $sugerencias[] = "Excelente trabajo con la documentación y frecuencia. Continúa con estas buenas prácticas";
        }
        
        // Si no hay sugerencias específicas, dar consejos generales
        if (empty($sugerencias)) {
            $sugerencias[] = "Continúa con el buen trabajo y mantén la consistencia en tu desarrollo";
            $sugerencias[] = "Considera agregar comentarios en el código para explicar decisiones importantes";
        }
        
        return array_slice($sugerencias, 0, 3); // Máximo 3 sugerencias
    }
}