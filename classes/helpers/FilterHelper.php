<?php
namespace mod_pluginpatroller\helpers;

defined('MOODLE_INTERNAL') || die();

use mod_pluginpatroller\model\UserModel;

/**
 * Helper class para manejar filtros reutilizables en las diferentes vistas
 * Proporciona filtros unificados para Sede, Curso y Repositorio
 */
class FilterHelper {
    
    /**
     * Aplica filtros a cualquier array de datos basado en criterios
     * 
     * @param array $data Datos a filtrar
     * @param array $filters Filtros a aplicar ['sede' => 'YA', 'curso' => 'A', 'repo_id' => 5]
     * @return array Datos filtrados
     */
    public static function applyFilters(array $data, array $filters): array {
        if (empty($filters)) {
            return $data;
        }
        
        return array_filter($data, function($item) use ($filters) {
            foreach ($filters as $field => $value) {
                if (empty($value) || $value === 'all' || $value === '') {
                    continue; // Skip empty filters
                }
                
                $itemValue = self::getFieldValue($item, $field);
                if ($itemValue !== $value) {
                    return false; // Item doesn't match this filter
                }
            }
            return true; // Item matches all filters
        });
    }
    
    /**
     * Filtra estudiantes por sede y curso
     * 
     * @param array $students Lista de estudiantes
     * @param string|null $sede Sede a filtrar (null = todos)
     * @param string|null $curso Curso a filtrar (null = todos)  
     * @return array Estudiantes filtrados
     */
    public static function filterStudents(array $students, ?string $sede = null, ?string $curso = null): array {
        return self::applyFilters($students, array_filter([
            'sede' => $sede,
            'curso' => $curso
        ]));
    }
    
    /**
     * Filtra repositorios por sede y curso
     * 
     * @param array $repositories Lista de repositorios
     * @param string|null $sede Sede a filtrar
     * @param string|null $curso Curso a filtrar
     * @return array Repositorios filtrados
     */
    public static function filterRepositories(array $repositories, ?string $sede = null, ?string $curso = null): array {
        return self::applyFilters($repositories, array_filter([
            'sede' => $sede,
            'curso' => $curso
        ]));
    }
    
    /**
     * Filtra por grupo (sede-curso combinado)
     * 
     * @param array $data Datos a filtrar
     * @param string|null $group_key Formato "SEDE-CURSO" ej: "YA-A"
     * @return array Datos filtrados
     */
    public static function filterByGroup(array $data, ?string $group_key = null): array {
        if (empty($group_key)) {
            return $data;
        }
        
        [$sede, $curso] = self::splitGroupKey($group_key);
        return self::applyFilters($data, [
            'sede' => $sede,
            'curso' => $curso
        ]);
    }
    
    /**
     * Genera opciones de filtro para un campo específico basado en los datos
     * 
     * @param array $data Datos base
     * @param string $field Campo a usar para las opciones
     * @param bool $include_all Si incluir opción "Todos"
     * @return array Opciones ['value' => 'label']
     */
    public static function generateFilterOptions(array $data, string $field, bool $include_all = true): array {
        $options = [];
        
        if ($include_all) {
            $options[''] = 'Todos';
        }
        
        $values = array_unique(array_map(function($item) use ($field) {
            return self::getFieldValue($item, $field);
        }, $data));
        
        foreach (array_filter($values) as $value) {
            $options[$value] = $value;
        }
        
        return $options;
    }
    
    /**
     * Obtiene SOLO los datos necesarios para renderizar filtros (sin HTML)
     * CORRECTO según patrón MVC: Helper proporciona datos, Template genera HTML
     * 
     * @param int $courseId ID del curso
     * @param array $config Configuración de filtros
     * @param array|null $customRepositories Repositorios específicos a usar (opcional)
     * @return array Solo datos para el template
     */
    public static function getFiltersData(int $courseId, array $config = [], ?array $customRepositories = null): array {
        $defaultConfig = [
            'show_name' => false,
            'show_sede' => true,
            'show_curso' => true, 
            'show_repo' => false,
            'show_group' => false
        ];
        
        $config = array_merge($defaultConfig, $config);
        $filters = [];
        
        // Filtro de Nombre (campo de texto con búsqueda en tiempo real)
        if ($config['show_name']) {
            $filters[] = [
                'id' => 'filterName',
                'label' => 'Buscar por Nombre',
                'type' => 'text',
                'placeholder' => 'Escriba para buscar...',
                'is_text' => true
            ];
        }
        
        // Filtro de Sede
        if ($config['show_sede']) {
            $filters[] = [
                'id' => 'filterSede',
                'label' => 'Filtrar por Sede',
                'options' => self::formatOptionsForTemplate(self::getSedeOptions()),
                'type' => 'select',
                'is_select' => true
            ];
        }
        
        // Filtro de Curso 
        if ($config['show_curso']) {
            $filters[] = [
                'id' => 'filterCurso',
                'label' => 'Filtrar por Curso',
                'options' => self::formatOptionsForTemplate(self::getCursoOptions($courseId)),
                'type' => 'select',
                'is_select' => true
            ];
        }
        
        // Filtro de Repositorio
        if ($config['show_repo']) {
            //  Usar repositorios personalizados si se proporcionan, sino usar todos del curso
            $repositoryOptions = $customRepositories !== null 
                ? self::formatCustomRepositoryOptions($customRepositories)
                : self::getRepositoryOptions($courseId);
            
            $formattedOptions = self::formatOptionsForTemplate($repositoryOptions);
                
            $filters[] = [
                'id' => 'filterRepo',
                'label' => 'Filtrar por Repositorio',
                'options' => $formattedOptions,
                'type' => 'select',
                'is_select' => true
            ];
        }
        
        $result = [
            'filters' => $filters,
            'script_functions' => self::getFilterScriptFunctions(),
            'table_id' => 'unregisteredTable' // Por defecto, puede sobreescribirse
        ];
        
        return $result;
    }
    
    /**
     * DEPRECATED: Método que viola MVC - genera HTML en Helper
     * Renderiza filtros completos para una vista específica
     * 
     * @param int $courseId ID del curso
     * @param array $config Configuración de filtros ['show_sede' => true, 'show_curso' => true, 'show_repo' => false]
     * @param string $tableId ID de la tabla a filtrar
     * @return array ['html' => string, 'script' => string, 'options' => array]
     */
    public static function renderCompleteFilters(int $courseId, array $config = [], string $tableId = 'dataTable'): array {
        global $OUTPUT;
        
        $defaultConfig = [
            'show_sede' => true,
            'show_curso' => true, 
            'show_repo' => false,
            'show_group' => false,
            'show_name' => true,
            'multi_select' => false
        ];
        
        $config = array_merge($defaultConfig, $config);
        $templateData = [
            'table_id' => $tableId,
            'filters' => []
        ];
        
        // Filtro de Sede
        if ($config['show_sede']) {
            $templateData['filters'][] = [
                'id' => 'filterSede',
                'label' => 'Filtrar por Sede',
                'options' => self::getSedeOptions(),
                'onchange' => "filterTable('{$tableId}')"
            ];
        }
        
        // Filtro de Curso 
        if ($config['show_curso']) {
            $templateData['filters'][] = [
                'id' => 'filterCurso',
                'label' => 'Filtrar por Curso',
                'options' => self::getCursoOptions($courseId),
                'onchange' => "filterTable('{$tableId}')"
            ];
        }
        
        // Filtro de Repositorio
        if ($config['show_repo']) {
            $templateData['filters'][] = [
                'id' => 'filterRepo',
                'label' => 'Filtrar por Repositorio',
                'options' => self::getRepositoryOptions($courseId),
                'onchange' => "filterTable('{$tableId}')"
            ];
        }
        
        // Filtro de Grupo (Sede-Curso combinado)
        if ($config['show_group']) {
            $templateData['filters'][] = [
                'id' => 'filterGroup',
                'label' => 'Filtrar por Grupo',
                'options' => self::getGroupOptions($courseId),
                'onchange' => "filterTable('{$tableId}')"
            ];
        }
        
        $html = $OUTPUT->render_from_template('mod_pluginpatroller/filters_advanced', $templateData);
        $script = self::generateAdvancedFilterScript($tableId, array_keys($config));
        
        return [
            'html' => $html,
            'script' => $script,
            'options' => $templateData['filters']
        ];
    }
    
    // ==================== MÉTODOS HELPER PRIVADOS ====================
    
    /**
     * Convierte opciones de array asociativo a formato Mustache
     * 
     * @param array $options Array asociativo ['value' => 'label']
     * @return array Array para Mustache [['value' => 'x', 'label' => 'y', 'selected' => false]]
     */
    private static function formatOptionsForTemplate(array $options): array {
        $formatted = [];
        $isFirst = true;
        
        foreach ($options as $value => $label) {
            $formatted[] = [
                'value' => $value,
                'label' => $label, // Usar 'label' como es el estándar
                'selected' => $isFirst && ($value === '' || $value === 'All') // Primera opción seleccionada por defecto
            ];
            $isFirst = false;
        }
        
        return $formatted;
    }
    
    /**
     * Extrae el valor de un campo de un item (array u objeto)
     */
    private static function getFieldValue($item, string $field) {
        if (is_array($item)) {
            return $item[$field] ?? null;
        } elseif (is_object($item)) {
            return $item->$field ?? null;
        }
        return null;
    }
    
    /**
     * Separa una clave de grupo en sede y curso
     */
    private static function splitGroupKey(string $group_key): array {
        $parts = explode('-', $group_key, 2);
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
    
    /**
     * Genera script JavaScript avanzado para filtrar tablas
     */
    private static function generateAdvancedFilterScript(string $tableId, array $filterTypes): string {
        return '
        <script>
        function filterTable(tableId = "' . $tableId . '") {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            const filters = {
                sede: document.getElementById("filterSede")?.value?.toUpperCase() || "",
                curso: document.getElementById("filterCurso")?.value?.toUpperCase() || "", 
                repo: document.getElementById("filterRepo")?.value || "",
                group: document.getElementById("filterGroup")?.value?.toUpperCase() || ""
            };
            
            const rows = table.getElementsByTagName("tr");
            
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                const cells = row.getElementsByTagName("td");
                let showRow = true;
                
                // Aplicar cada filtro
                if (filters.sede && cells[0]) {
                    const sedeValue = (cells[0].textContent || cells[0].innerText).toUpperCase();
                    if (sedeValue !== filters.sede) showRow = false;
                }
                
                if (filters.curso && cells[1]) {
                    const cursoValue = (cells[1].textContent || cells[1].innerText).toUpperCase();
                    if (cursoValue !== filters.curso) showRow = false;
                }
                
                if (filters.repo && cells[2]) {
                    const repoValue = cells[2].textContent || cells[2].innerText;
                    if (repoValue !== filters.repo && filters.repo !== "All") showRow = false;
                }
                
                row.style.display = showRow ? "" : "none";
            }
            
            // Actualizar contador si existe
            updateFilterCount(tableId);
        }
        
        function updateFilterCount(tableId) {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            const rows = table.getElementsByTagName("tr");
            let visibleCount = 0;
            
            for (let i = 1; i < rows.length; i++) {
                if (rows[i].style.display !== "none") {
                    visibleCount++;
                }
            }
            
            const counter = document.getElementById("filterCounter");
            if (counter) {
                counter.textContent = `Mostrando ${visibleCount} de ${rows.length - 1} registros`;
            }
        }
        
        function clearAllFilters(tableId = "' . $tableId . '") {
            document.querySelectorAll("select[id^=\'filter\']").forEach(select => {
                select.selectedIndex = 0;
            });
            filterTable(tableId);
        }
        </script>';
    }
    
    /**
     * Obtiene las funciones JavaScript necesarias para los filtros (sin HTML)
     * Para uso en templates que generan su propio HTML
     */
    public static function getFilterScriptFunctions(): string {
        return '
function filterTable(tableId) {
    const table = document.getElementById(tableId);
    if (!table) {
        return;
    }
    
    const nameFilter = document.getElementById("filterName");
    const sedeFilter = document.getElementById("filterSede");
    const cursoFilter = document.getElementById("filterCurso");
    const repoFilter = document.getElementById("filterRepo");
    const repoFilter = document.getElementById("filterRepo");
    
    console.log("Elementos de filtro encontrados:", {
        name: nameFilter ? "✓" : "✗",
        sede: sedeFilter ? "✓" : "✗", 
        curso: cursoFilter ? "✓" : "✗",
        repo: repoFilter ? "✓" : "✗"
    });
    
    const filters = {
        name: nameFilter ? nameFilter.value.toLowerCase().trim() : "",
        sede: sedeFilter ? sedeFilter.value : "",
        curso: cursoFilter ? cursoFilter.value : "",
        repo: repoFilter ? repoFilter.value : ""
    };
    
    const rows = table.getElementsByTagName("tr");
    let visibleCount = 0;
    
    // Empezar desde la fila 1 (saltar header)
    for (let i = 1; i < rows.length; i++) {
        const row = rows[i];
        const cells = row.getElementsByTagName("td");
        let showRow = true;
        
        // Lógica diferente según la tabla
        if (tableId === "dataTable") {
            // Tabla de contributors: [Repositorio, Usuario GitHub, Nombre Completo, Último Commit, Commits, Líneas +, Líneas -, Modificadas, Calificación]
            
            // Filtro por nombre - busca en las columnas 1 (Usuario GitHub) y 2 (Nombre Completo)
            if (filters.name) {
                let nameFound = false;
                if (cells[1]) { // Usuario GitHub
                    const userText = (cells[1].textContent || cells[1].innerText).toLowerCase();
                    if (userText.includes(filters.name)) {
                        nameFound = true;
                    }
                }
                if (!nameFound && cells[2]) { // Nombre Completo
                    const nameText = (cells[2].textContent || cells[2].innerText).toLowerCase();
                    if (nameText.includes(filters.name)) {
                        nameFound = true;
                    }
                }
                if (!nameFound) showRow = false;
            }
            
            // Filtro por repositorio (columna 0)
            if (filters.repo && filters.repo !== "" && filters.repo !== "All" && cells[0]) {
                const repoText = (cells[0].textContent || cells[0].innerText).trim();
                if (repoText !== filters.repo) {
                    showRow = false;
                }
            }
            
        } else if (tableId === "classmatesTable") {
            // Tabla de compañeros: [Sede y Curso, Nombre, GitHub, Repositorio, Acciones]
            
            // Filtro por nombre - busca en la columna 1 (Nombre)
            if (filters.name && cells[1]) {
                const nameText = (cells[1].textContent || cells[1].innerText).toLowerCase();
                if (!nameText.includes(filters.name)) {
                    showRow = false;
                }
            }
            
            // Filtro por repositorio - busca en la columna 3 (Repositorio)
            if (filters.repo && filters.repo !== "" && cells[3]) {
                const repoText = (cells[3].textContent || cells[3].innerText).trim();
                if (!repoText.includes(filters.repo)) {
                    showRow = false;
                }
            }
            
        } else if (tableId === "dataTable") {
            // Tabla de seguimiento: [Repositorio, Usuario GitHub, Nombre Completo, Último Commit, Commits, Líneas +, Líneas -, Modificadas, Calificación]
            
            // Filtro por nombre - busca en la columna 2 (Nombre Completo)
            if (filters.name && cells[2]) {
                const nameText = (cells[2].textContent || cells[2].innerText).toLowerCase();
                if (!nameText.includes(filters.name)) {
                    showRow = false;
                }
            }
            
            // Filtro por repositorio - busca en la columna 0 (Repositorio)
            if (filters.repo && filters.repo !== "" && cells[0]) {
                const repoText = (cells[0].textContent || cells[0].innerText).trim();
                if (!repoText.includes(filters.repo)) {
                    showRow = false;
                }
            }
            
        } else {
            // Tabla de usuarios sin registrar: [Sede, Curso, Usuario, Nombre, Email]
            
            // Filtro por nombre - busca en todas las columnas
            if (filters.name) {
                let nameFound = false;
                for (let j = 0; j < cells.length; j++) {
                    const cellText = (cells[j].textContent || cells[j].innerText).toLowerCase();
                    if (cellText.includes(filters.name)) {
                        nameFound = true;
                        break;
                    }
                }
                if (!nameFound) showRow = false;
            }
            
            // Filtro por sede (columna 0)
            if (filters.sede && filters.sede !== "" && cells[0]) {
                const sedeText = (cells[0].textContent || cells[0].innerText).trim();
                if (!sedeText.includes(filters.sede)) {
                    showRow = false;
                }
            }
            
            // Filtro por curso (columna 1) 
            if (filters.curso && filters.curso !== "" && cells[1]) {
                const cursoText = (cells[1].textContent || cells[1].innerText).trim();
                if (!cursoText.includes(filters.curso)) {
                    showRow = false;
                }
            }
        }
        
        row.style.display = showRow ? "" : "none";
        if (showRow) visibleCount++;
    }
    
    updateFilterCount(tableId, visibleCount);
}

function updateFilterCount(tableId, count = null) {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    let visibleCount = count;
    if (visibleCount === null) {
        const rows = table.getElementsByTagName("tr");
        visibleCount = 0;
        for (let i = 1; i < rows.length; i++) {
            if (rows[i].style.display !== "none") {
                visibleCount++;
            }
        }
    }
    
    const counter = document.getElementById("filterCounter");
    if (counter) {
        const totalRows = table.getElementsByTagName("tr").length - 1;
        counter.textContent = `Mostrando ${visibleCount} de ${totalRows} registros`;
    }
}

function clearAllFilters(tableId) {
    document.querySelectorAll("input[id^=filterName]").forEach(input => {
        input.value = "";
    });
    document.querySelectorAll("select[id^=filter]").forEach(select => {
        select.selectedIndex = 0;
    });
    
    // Detectar tabla si no se proporciona tableId
    if (!tableId) {
        const classmatesTable = document.getElementById("classmatesTable");
        const unregisteredTable = document.getElementById("unregisteredTable");
        const dataTable = document.getElementById("dataTable");
        
        if (classmatesTable) {
            tableId = "classmatesTable";
        } else if (unregisteredTable) {
            tableId = "unregisteredTable";
        } else if (dataTable) {
            tableId = "dataTable";
        }
    }
    
    if (tableId) {
        filterTable(tableId);
    }
}

function setupRealtimeSearch(tableId) {
    const nameFilter = document.getElementById("filterName");
    if (nameFilter) {
        console.log("✅ Configurando búsqueda en tiempo real para campo nombre");
        nameFilter.addEventListener("input", function() {
            console.log("📝 Búsqueda en tiempo real:", this.value);
            filterTable(tableId);
        });
    }
}

document.addEventListener("DOMContentLoaded", function() {
    console.log("🚀 Inicializando sistema de filtros...");
    
    // Detectar qué tabla está presente
    const classmatesTable = document.getElementById("classmatesTable");
    const unregisteredTable = document.getElementById("unregisteredTable");
    
    let currentTableId = null;
    if (classmatesTable) {
        currentTableId = "classmatesTable";
        console.log("📋 Tabla de compañeros detectada");
    } else if (unregisteredTable) {
        currentTableId = "unregisteredTable";
        console.log("📋 Tabla de usuarios sin registrar detectada");
    }
    
    if (currentTableId) {
        // Verificar elementos de filtro
        const nameFilter = document.getElementById("filterName");
        const sedeFilter = document.getElementById("filterSede");
        const cursoFilter = document.getElementById("filterCurso");
        
        console.log("Elementos encontrados:", {
            name: nameFilter ? "✓" : "✗",
            sede: sedeFilter ? "✓" : "✗",
            curso: cursoFilter ? "✓" : "✗"
        });
        
        if (sedeFilter) {
            console.log("Opciones de sede:", Array.from(sedeFilter.options).map(opt => opt.value + ": " + opt.text));
        }
        
        if (cursoFilter) {
            console.log("Opciones de curso:", Array.from(cursoFilter.options).map(opt => opt.value + ": " + opt.text));
        }
        
        setupRealtimeSearch(currentTableId);
        updateFilterCount(currentTableId);
    } else {
        console.log("⚠️ No se encontró ninguna tabla compatible");
    }
});';
    }
    
    // ==================== MÉTODOS DE OPCIONES ====================
    
    /**
     * Obtiene las opciones de sede disponibles
     */
    public static function getSedeOptions(): array {
        return [
            '' => 'Todas las Sedes',
            'YA' => 'YA',
            'BE' => 'BE'
        ];
    }
    
    /**
     * Obtiene las opciones de curso para un curso dado
     */
    public static function getCursoOptions(int $courseId): array {
        $cursos = UserModel::get_all_cursos_by_course_id($courseId);
        $options = ['' => 'Todos los Cursos'];
        foreach ($cursos as $curso => $label) {
            $options[$curso] = $label;
        }
        return $options;
    }
    
    /**
     * Obtiene opciones de repositorio para un curso
     */
    public static function getRepositoryOptions(int $courseId, bool $includeAll = true): array {
        global $DB;
        
        $repositories = $DB->get_records('repositorios_data_patroller', 
            ['id_materia' => $courseId], 
            'sede ASC, curso ASC, num_grupo ASC'
        );
        
        $options = [];
        if ($includeAll) {
            $options[''] = 'Todos los Repositorios';
        }
        
        foreach ($repositories as $repo) {
            $options[$repo->id] = "{$repo->sede}-{$repo->curso}-{$repo->num_grupo}: {$repo->nombre_repo}";
        }
        
        return $options;
    }
    
    /**
     * Obtiene opciones de grupo (sede-curso combinado)
     */
    public static function getGroupOptions(int $courseId): array {
        global $DB;
        
        $groups = $DB->get_records_sql("
            SELECT DISTINCT CONCAT(sede, '-', curso) as group_key, sede, curso
            FROM {repositorios_data_patroller}
            WHERE id_materia = ?
            ORDER BY sede ASC, curso ASC
        ", [$courseId]);
        
        $options = ['' => 'Todos los Grupos'];
        foreach ($groups as $group) {
            $options[$group->group_key] = $group->group_key;
        }
        
        return $options;
    }
    
    /**
     *  Formatea repositorios personalizados para el filtro
     * @param array $customRepositories Array [repo_name => repo_name] 
     * @return array Array con formato ['All' => 'Todos...', 'repo1' => 'repo1', ...]
     */
    private static function formatCustomRepositoryOptions(array $customRepositories): array {
        // Siempre incluir la opción "Todos"
        $options = ['All' => 'Todos los Repositorios'];
        
        // Agregar cada repositorio (tanto sin asignar como específicos)
        foreach ($customRepositories as $repo_name) {
            $options[$repo_name] = $repo_name;  // value = repo_name, label = repo_name
        }
        
        return $options;
    }
}