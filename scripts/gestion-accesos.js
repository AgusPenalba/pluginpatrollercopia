/**
 * Gesti├│n de Accesos - JavaScript Module
 * Maneja la sincronizaci├│n AJAX de estados de invitaciones de GitHub
 * 
 * @author Plugin Patroller Team
 * @version 1.0
 */

(function(config) {
    'use strict';
    
    // ========================================
    // CONSTANTES Y CONFIGURACI├ôN
    // ========================================
    
    const SELECTORS = {
        SYNC_BTN: '#sync-btn',
        SYNC_ICON: '#sync-icon',
        SYNC_TEXT: '#sync-text',
        SYNC_PROGRESS: '#sync-progress',
        SYNC_STATUS: '#sync-status',
        // Filtros
        FILTER_NAME: '#filterName',
        FILTER_SEDE: '#filterSede',
        FILTER_CURSO: '#filterCurso',
        CLEAR_FILTERS: '#clearFilters',
        FILTER_COUNTER: '#filterCounter'
    };
    
    const CSS_CLASSES = {
        BTN_LIGHT: 'btn-light',
        BTN_SECONDARY: 'btn-secondary',
        SPINNER: 'fa-spinner',
        SPIN: 'fa-spin',
        SYNC_ICON: 'fa-sync-alt'
    };
    
    // Los mensajes se obtienen de la configuraci├│n (traducidos)
    const getMessages = (config) => ({
        SYNCING: config.strings?.syncing || 'Sincronizando...',
        SYNC_STATES: config.strings?.syncStates || 'Sincronizar Estados',
        SUCCESS: config.strings?.success || 'Γ£à Estados sincronizados correctamente!',
        ERROR_SERVER: config.strings?.errorServer || 'Error en la respuesta del servidor',
        ERROR_SYNC: config.strings?.errorSync || 'Error al sincronizar',
        COMPLETED: config.strings?.completed || 'Sincronizaci├│n completada!'
    });
    
    // ========================================
    // CLASE PRINCIPAL
    // ========================================
    
    class GestionAccesos {
        
        constructor(config) {
            this.config = config;
            this.messages = getMessages(config);
            this.elements = {};
            this.init();
        }
        
        /**
         * Inicializaci├│n del m├│dulo
         */
        init() {
            this.cacheElements();
            this.bindEvents();
            this.initializeFilters();
            console.log('Γ£à Gesti├│n de Accesos inicializado');
        }
        
        /**
         * Cache de elementos DOM para mejor rendimiento
         */
        cacheElements() {
            Object.entries(SELECTORS).forEach(([key, selector]) => {
                this.elements[key] = document.querySelector(selector);
            });
        }
        
        /**
         * Vinculaci├│n de eventos
         */
        bindEvents() {
            // Evento de sincronizaci├│n
            if (this.elements.SYNC_BTN) {
                this.elements.SYNC_BTN.addEventListener('click', () => {
                    this.syncInvitationStates();
                });
            }
            
            // Eventos de filtros
            if (this.elements.FILTER_NAME) {
                this.elements.FILTER_NAME.addEventListener('input', () => {
                    this.filterTable();
                });
            }
            
            if (this.elements.FILTER_SEDE) {
                this.elements.FILTER_SEDE.addEventListener('change', () => {
                    this.filterTable();
                });
            }
            
            if (this.elements.FILTER_CURSO) {
                this.elements.FILTER_CURSO.addEventListener('change', () => {
                    this.filterTable();
                });
            }
            
            if (this.elements.CLEAR_FILTERS) {
                this.elements.CLEAR_FILTERS.addEventListener('click', () => {
                    this.clearFilters();
                });
            }
            
            // Eventos de confirmaci├│n para formularios
            this.bindConfirmationEvents();
        }
        
        /**
         * Sincronizaci├│n AJAX de estados de invitaciones
         */
        async syncInvitationStates() {
            try {
                this.showSyncingState();
                
                const response = await this.performSync();
                const result = await this.parseResponse(response);
                
                this.handleSyncSuccess(result);
                
            } catch (error) {
                this.handleSyncError(error);
            }
        }
        
        /**
         * Mostrar estado de sincronizaci├│n en progreso
         */
        showSyncingState() {
            const { SYNC_BTN, SYNC_ICON, SYNC_TEXT, SYNC_PROGRESS } = this.elements;
            
            // Deshabilitar bot├│n
            SYNC_BTN.disabled = true;
            SYNC_BTN.classList.remove(CSS_CLASSES.BTN_LIGHT);
            SYNC_BTN.classList.add(CSS_CLASSES.BTN_SECONDARY);
            
            // Cambiar icono a spinner
            SYNC_ICON.classList.remove(CSS_CLASSES.SYNC_ICON);
            SYNC_ICON.classList.add(CSS_CLASSES.SPINNER, CSS_CLASSES.SPIN);
            
            // Cambiar texto
            SYNC_TEXT.textContent = this.messages.SYNCING;
            
            // Mostrar progreso
            SYNC_PROGRESS.style.display = 'block';
        }
        
        /**
         * Realizar petici├│n de sincronizaci├│n
         */
        async performSync() {
            const formData = new FormData();
            formData.append('sync_invitations', '1');
            formData.append('id', this.config.cmId);
            formData.append('tab', 'tab2');
            formData.append('sesskey', this.config.sesskey);
            
            return fetch(this.config.baseUrl, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
        }
        
        /**
         * Parsear respuesta del servidor
         */
        async parseResponse(response) {
            if (!response.ok) {
                throw new Error(this.messages.ERROR_SERVER);
            }
            
            const text = await response.text();
            
            try {
                return JSON.parse(text);
            } catch (e) {
                // Si no es JSON, probablemente es un redirect o p├ígina completa
                return { success: true, isRedirect: true };
            }
        }
        
        /**
         * Manejar respuesta exitosa
         */
        handleSyncSuccess(result) {
            const { SYNC_STATUS } = this.elements;
            
            if (result.success) {
                const message = result.message || this.messages.COMPLETED;
                SYNC_STATUS.innerHTML = `<i class="fas fa-check text-success"></i> ${message}`;
                
                if (result.students_data) {
                    // Actualizar datos sin recargar
                    this.updateTableData(result.students_data);
                    setTimeout(() => this.resetSyncButton(), 2000);
                } else {
                    // Recargar si no tenemos datos espec├¡ficos
                    setTimeout(() => window.location.reload(), 1500);
                }
            } else {
                const message = result.message || 'Error desconocido';
                SYNC_STATUS.innerHTML = `<i class="fas fa-exclamation-triangle text-warning"></i> ${message}`;
                setTimeout(() => this.resetSyncButton(), 3000);
            }
        }
        
        /**
         * Manejar errores de sincronizaci├│n
         */
        handleSyncError(error) {
            console.error('Error de sincronizaci├│n:', error);
            
            const { SYNC_STATUS } = this.elements;
            SYNC_STATUS.innerHTML = `<i class="fas fa-exclamation-triangle text-danger"></i> ${this.messages.ERROR_SYNC}`;
            
            setTimeout(() => this.resetSyncButton(), 3000);
        }
        
        /**
         * Resetear bot├│n a estado inicial
         */
        resetSyncButton() {
            const { SYNC_BTN, SYNC_ICON, SYNC_TEXT, SYNC_PROGRESS } = this.elements;
            
            // Habilitar bot├│n
            SYNC_BTN.disabled = false;
            SYNC_BTN.classList.remove(CSS_CLASSES.BTN_SECONDARY);
            SYNC_BTN.classList.add(CSS_CLASSES.BTN_LIGHT);
            
            // Restaurar icono
            SYNC_ICON.classList.remove(CSS_CLASSES.SPINNER, CSS_CLASSES.SPIN);
            SYNC_ICON.classList.add(CSS_CLASSES.SYNC_ICON);
            
            // Restaurar texto
            SYNC_TEXT.textContent = this.messages.SYNC_STATES;
            
            // Ocultar progreso
            SYNC_PROGRESS.style.display = 'none';
        }
        
        /**
         * Actualizar datos de la tabla (implementaci├│n futura)
         */
        updateTableData(studentsData) {
            console.log('Datos de estudiantes recibidos:', studentsData);
            
            // TODO: Implementar actualizaci├│n espec├¡fica de filas de tabla
            // Por ahora mostramos notificaci├│n y recargamos
            this.showNotification(this.messages.SUCCESS, 'success');
            
            // Implementaci├│n futura: actualizar filas espec├¡ficas sin recargar p├ígina
            // this.updateStudentRows(studentsData);
        }
        
        /**
         * Mostrar notificaci├│n temporal
         */
        showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            notification.style.cssText = `
                top: 20px;
                right: 20px;
                z-index: 9999;
                min-width: 300px;
            `;
            
            notification.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(notification);
            
            // Auto-remove despu├⌐s de 4 segundos
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 4000);
        }
        
        // ========================================
        // SISTEMA DE FILTROS
        // ========================================
        
        /**
         * Inicializar sistema de filtros
         */
        initializeFilters() {
            // Inicializar contador
            this.updateFilterCounter();
        }
        
        /**
         * Filtrar tabla por criterios m├║ltiples
         */
        filterTable() {
            const tableId = this.config.tableId || `access_table_${this.config.cmId}`;
            const table = document.getElementById(tableId);
            
            if (!table) {
                console.warn('ΓÜá∩╕Å Tabla no encontrada:', tableId);
                return;
            }
            
            const rows = table.getElementsByTagName('tr');
            let visibleCount = 0;
            
            // Obtener valores de filtros
            const nameFilter = this.elements.FILTER_NAME?.value.toLowerCase() || '';
            const sedeFilter = this.elements.FILTER_SEDE?.value || '';
            const cursoFilter = this.elements.FILTER_CURSO?.value || '';
            
            // Filtrar filas (saltando el header)
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                
                // Saltar filas de formularios expandidos
                if (row.classList.contains('bg-light')) {
                    continue;
                }
                
                // Obtener datos de la fila
                const sede = row.getAttribute('data-sede') || '';
                const curso = row.getAttribute('data-curso') || '';
                
                // Obtener texto del nombre (primera columna)
                const nameCell = row.getElementsByTagName('td')[0];
                const nameText = nameCell ? nameCell.textContent.toLowerCase() : '';
                
                // Aplicar filtros
                const nameMatch = !nameFilter || nameText.includes(nameFilter);
                const sedeMatch = !sedeFilter || sedeFilter === 'all' || sede === sedeFilter;
                const cursoMatch = !cursoFilter || cursoFilter === 'all' || curso === cursoFilter;
                
                // Mostrar/ocultar fila y su formulario asociado si existe
                const isVisible = nameMatch && sedeMatch && cursoMatch;
                row.style.display = isVisible ? '' : 'none';
                
                // Tambi├⌐n ocultar la fila de formulario si existe
                const nextRow = rows[i + 1];
                if (nextRow && nextRow.classList.contains('bg-light')) {
                    nextRow.style.display = isVisible ? '' : 'none';
                }
                
                if (isVisible) {
                    visibleCount++;
                }
            }
            
            this.updateFilterCounter(visibleCount);
        }
        
        /**
         * Limpiar todos los filtros
         */
        clearFilters() {
            if (this.elements.FILTER_NAME) this.elements.FILTER_NAME.value = '';
            if (this.elements.FILTER_SEDE) this.elements.FILTER_SEDE.value = 'all';
            if (this.elements.FILTER_CURSO) this.elements.FILTER_CURSO.value = 'all';
            
            this.filterTable();
        }
        
        /**
         * Actualizar contador de filtros
         */
        updateFilterCounter(visibleCount = null) {
            if (!this.elements.FILTER_COUNTER) return;
            
            if (visibleCount === null) {
                const tableId = this.config.tableId || `access_table_${this.config.cmId}`;
                const table = document.getElementById(tableId);
                const rows = table ? table.querySelectorAll('tbody tr:not(.bg-light)') : [];
                visibleCount = rows.length;
            }
            
            this.elements.FILTER_COUNTER.textContent = `${visibleCount} estudiante${visibleCount !== 1 ? 's' : ''}`;
        }
        
        // ========================================
        // CONFIRMACIONES Y VALIDACIONES
        // ========================================
        
        /**
         * Vincular eventos de confirmaci├│n a formularios
         */
        bindConfirmationEvents() {
            // Confirmaciones para env├¡o de invitaciones
            document.querySelectorAll('form button[title*="Enviar invitaci├│n"]').forEach(button => {
                button.addEventListener('click', (e) => {
                    const form = e.target.closest('form');
                    const studentName = form.querySelector('input[name="send_single_invitation"]')?.getAttribute('data-student-name') || 'este estudiante';
                    
                    if (!this.confirmSendInvitation(studentName)) {
                        e.preventDefault();
                    }
                });
            });
            
            // Confirmaciones para cancelaci├│n de invitaciones
            document.querySelectorAll('form button[title*="Cancelar invitaci├│n"]').forEach(button => {
                button.addEventListener('click', (e) => {
                    const form = e.target.closest('form');
                    const studentName = button.textContent.includes('{{full_name}}') ? 'este estudiante' : 'este estudiante';
                    
                    if (!this.confirmCancelInvitation(studentName)) {
                        e.preventDefault();
                    }
                });
            });
        }
        
        /**
         * Confirmar env├¡o de invitaci├│n
         */
        confirmSendInvitation(studentName) {
            return confirm(`┬┐Est├ís seguro de enviar una invitaci├│n a ${studentName}?`);
        }
        
        /**
         * Confirmar cancelaci├│n de invitaci├│n
         */
        confirmCancelInvitation(studentName) {
            return confirm(`┬┐Est├ís seguro de cancelar la invitaci├│n de ${studentName}?`);
        }
        
        // ========================================
        // ACTUALIZACI├ôN DIN├üMICA DE TABLA
        // ========================================
        
        /**
         * Actualizar fila espec├¡fica de estudiante
         */
        updateStudentRow(student) {
            const tableId = this.config.tableId || `access_table_${this.config.cmId}`;
            const table = document.getElementById(tableId);
            
            if (!table) {
                console.warn('ΓÜá∩╕Å Tabla no encontrada para actualizaci├│n');
                return null;
            }
            
            // Buscar fila existente
            const existingRow = table.querySelector(`tr[data-user-id="${student.user_id}"]`);
            const newRow = this.createStudentRow(student);
            
            if (existingRow) {
                existingRow.replaceWith(newRow);
            } else {
                table.querySelector('tbody').appendChild(newRow);
            }
            
            return newRow;
        }
        
        /**
         * Crear fila HTML para estudiante
         */
        createStudentRow(student) {
            const row = document.createElement('tr');
            row.setAttribute('data-sede', student.sede || '');
            row.setAttribute('data-curso', student.curso || '');
            row.setAttribute('data-repo', student.repository_name || '');
            row.setAttribute('data-user-id', student.user_id);
            
            // Determinar badge de estado
            const statusBadge = this.getStatusBadge(student);
            
            row.innerHTML = `
                <td>
                    <div class="d-flex align-items-center">
                        <div class="avatar-sm bg-primary rounded-circle d-flex align-items-center justify-content-center me-2">
                            <span class="text-white font-weight-bold">${student.initial || '?'}</span>
                        </div>
                        <div>
                            <div class="font-weight-bold">${student.full_name || 'Sin nombre'}</div>
                            <small class="text-muted">${student.email || ''}</small>
                        </div>
                    </div>
                </td>
                <td><span class="badge badge-secondary">${student.sede || ''}</span></td>
                <td><span class="badge badge-info">${student.curso || ''}</span></td>
                <td>
                    ${student.github_username ? 
                        `<div class="d-flex align-items-center">
                            <i class="fab fa-github text-muted mr-1"></i>
                            <strong>${student.github_username}</strong>
                        </div>` : 
                        '<span class="text-muted"><i class="fas fa-minus-circle"></i> Sin asignar</span>'
                    }
                </td>
                <td>
                    ${student.repository_name ? 
                        `<div class="d-flex align-items-center">
                            <i class="fas fa-code-branch text-muted mr-1"></i>
                            <strong>${student.repository_name}</strong>
                        </div>` : 
                        '<span class="text-muted"><i class="fas fa-minus-circle"></i> Sin asignar</span>'
                    }
                </td>
                <td class="align-top">${statusBadge}</td>
                <td class="align-top">
                    <div class="d-flex flex-column" style="gap: 3px;">
                        ${student.repository_name ? 
                            `<a href="?id=${this.config.cmId}&tab=tab2&change_repo_for=${student.user_id}" 
                               class="badge badge-secondary text-decoration-none" 
                               style="cursor: pointer; width: fit-content;">
                                <i class="fas fa-exchange-alt"></i> Cambiar Repo
                            </a>` : ''
                        }
                    </div>
                </td>
            `;
            
            return row;
        }
        
        /**
         * Obtener badge HTML para estado de invitaci├│n
         */
        getStatusBadge(student) {
            if (student.is_collaborator) {
                return '<span class="badge badge-success" style="width: fit-content;"><i class="fas fa-check-circle"></i> Colaborador</span>';
            }
            
            if (student.has_pending_invitation) {
                return '<span class="badge badge-warning" style="width: fit-content;"><i class="fas fa-clock"></i> Pendiente</span>';
            }
            
            if (student.can_invite) {
                return '<span class="badge badge-light" style="width: fit-content;"><i class="fas fa-envelope"></i> Listo para invitar</span>';
            }
            
            return '<span class="badge badge-secondary" style="width: fit-content;"><i class="fas fa-exclamation"></i> Incompleto</span>';
        }
    }
    
    // ========================================
    // INICIALIZACI├ôN
    // ========================================
    
    // Verificar que tenemos la configuraci├│n necesaria
    if (!config || !config.cmId || !config.sesskey || !config.baseUrl) {
        console.error('Configuraci├│n incompleta para Gesti├│n de Accesos:', config);
        return;
    }
    
    // ========================================
    // INSTANCIA GLOBAL Y FUNCIONES P├ÜBLICAS
    // ========================================
    
    let gestionAccesosInstance = null;
    
    // Inicializar cuando el DOM est├⌐ listo
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            gestionAccesosInstance = new GestionAccesos(config);
            // Exponer funciones p├║blicas para compatibilidad
            window.syncInvitationStates = () => gestionAccesosInstance.syncInvitationStates();
            window.filterTable = () => gestionAccesosInstance.filterTable();
            window.clearAllFilters = () => gestionAccesosInstance.clearFilters();
            window.clearFilters = () => gestionAccesosInstance.clearFilters();
            window.confirmSendInvitation = (name) => gestionAccesosInstance.confirmSendInvitation(name);
            window.confirmCancelInvitation = (name) => gestionAccesosInstance.confirmCancelInvitation(name);
            window.showNotification = (msg, type) => gestionAccesosInstance.showNotification(msg, type);
        });
    } else {
        gestionAccesosInstance = new GestionAccesos(config);
        // Exponer funciones p├║blicas para compatibilidad
        window.syncInvitationStates = () => gestionAccesosInstance.syncInvitationStates();
        window.filterTable = () => gestionAccesosInstance.filterTable();
        window.clearAllFilters = () => gestionAccesosInstance.clearFilters();
        window.clearFilters = () => gestionAccesosInstance.clearFilters();
        window.confirmSendInvitation = (name) => gestionAccesosInstance.confirmSendInvitation(name);
        window.confirmCancelInvitation = (name) => gestionAccesosInstance.confirmCancelInvitation(name);
        window.showNotification = (msg, type) => gestionAccesosInstance.showNotification(msg, type);
    }
    
})(window.GestionAccesosConfig || {});
