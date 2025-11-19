<?php
defined('MOODLE_INTERNAL') || die();

// Plugin general
$string['pluginname'] = 'GitPatroller';
$string['modulename'] = 'GitPatroller';
$string['modulenameplural'] = 'pluginpatroller';
$string['pluginpatroller:addinstance'] = 'Agregar una nueva instancia del plugin';
$string['pluginpatroller:view'] = 'Ver GitPatroller';
$string['pluginpatroller'] = 'pluginpatroller';
$string['pluginadministration'] = 'administración de pluginpatroller';

// Configuration
$string['patrollerheader'] = 'Configuración Patroller';
$string['name'] = 'Nombre';
$string['max_users_per_group'] = 'Máximo de usuarios por grupo (sugerido)';
$string['tokenpatroller'] = 'Token Github';
$string['tokenpatroller_desc'] = 'Token Github del Bot';
$string['ownerpatroller'] = 'Owner Github';
$string['ownerpatroller_desc'] = 'Nombre de usuario Github del Bot';
$string['filterbysede'] = 'Sede';
$string['filterbycurso'] = 'Curso';
$string['filterbyrepo'] = 'Repositorio';

// Statistics
$string['statistics'] = 'Estadísticas';
$string['statisticsheader'] = 'Estadísticas - Plantilla para Admin';
$string['globalstatistics'] = 'Estadística global';
$string['groupstatistics'] = 'Estadísticas por grupo';
$string['selectgroup'] = 'Seleccionar Grupo:';
$string['commits'] = 'Commits';
$string['lines'] = 'Líneas';
$string['commitspergroup'] = 'Commits por grupo';
$string['commitsperstudent'] = 'Commits por alumno';
$string['totallines'] = 'Total líneas';
$string['metricglobal'] = 'Métrica global';
$string['metricgroup'] = 'Métrica grupo';
$string['contributionscomparison'] = 'Comparativa de contribuciones por grupo y alumnos';

// Main Panel
$string['mainpanel'] = 'Panel Principal';
$string['dashboard'] = 'Panel de Control';
$string['overview'] = 'Vista General';
$string['totalstudents'] = 'Total de Estudiantes';
$string['totalgroups'] = 'Total de Grupos';
$string['activerepos'] = 'Repositorios Activos';
$string['lastactivity'] = 'Última Actividad';

// Student View
$string['studentview'] = 'Vista de Estudiante';
$string['myrepo'] = 'Mi Repositorio';
$string['mygroup'] = 'Mi Grupo';
$string['mystats'] = 'Mis Estadísticas';
$string['groupmates'] = 'Compañeros de Equipo';
$string['repolink'] = 'Enlace del Repositorio';
$string['githubusername'] = 'Usuario de GitHub';
$string['selectrepo'] = 'Seleccionar Repositorio';
$string['savechanges'] = 'Guardar Cambios';

// Group Management
$string['groupmanagement'] = 'Gestión de Grupos';
$string['creategroup'] = 'Crear Grupo';
$string['editgroup'] = 'Editar Grupo';
$string['deletegroup'] = 'Eliminar Grupo';
$string['groupname'] = 'Nombre del Grupo';
$string['groupmembers'] = 'Miembros del Grupo';
$string['addmember'] = 'Agregar Miembro';
$string['removemember'] = 'Eliminar Miembro';

// Access Management
$string['accessmanagement'] = 'Gestión de Accesos';
$string['gestionaccesos'] = 'Gestión de Accesos';
$string['invitations'] = 'Invitaciones';
$string['sendinvitation'] = 'Enviar Invitación';
$string['pendinginvitations'] = 'Invitaciones Pendientes';
$string['acceptedinvitations'] = 'Invitaciones Aceptadas';
$string['unregisteredusers'] = 'Usuarios Sin Registrar';

// Contributors Insights
$string['contributorsinsights'] = 'Análisis de Contribuciones';
$string['topcontributors'] = 'Principales Contribuyentes';
$string['recentactivity'] = 'Actividad Reciente';
$string['codefrequency'] = 'Frecuencia de Código';
$string['weeklycommits'] = 'Commits Semanales';

// Repository Management
$string['repositorymanagement'] = 'Gestión de Repositorios';
$string['githubreposadmin'] = 'Administración de repositorios GitHub';
$string['pluginnotconfigured'] = 'Plugin GitPatroller no configurado. Configure token y organización GitHub en: Administración del sitio → Plugins → Módulos de actividad → PatrollerPRF';
$string['createrepo'] = 'Crear Repositorio';
$string['editrepo'] = 'Editar Repositorio';
$string['deleterepo'] = 'Eliminar Repositorio';
$string['reponame'] = 'Nombre del Repositorio';
$string['repodescription'] = 'Descripción del Repositorio';
$string['repoisactive'] = 'El Repositorio está Activo';

// Filters
$string['filters'] = 'Filtros';
$string['advancedfilters'] = 'Filtros Avanzados';
$string['applyfilters'] = 'Aplicar Filtros';
$string['clearfilters'] = 'Limpiar Filtros';
$string['filterresults'] = 'Filtrar Resultados';

// Common actions
$string['save'] = 'Guardar';
$string['cancel'] = 'Cancelar';
$string['edit'] = 'Editar';
$string['delete'] = 'Eliminar';
$string['view'] = 'Ver';
$string['back'] = 'Atrás';
$string['next'] = 'Siguiente';
$string['previous'] = 'Anterior';
$string['loading'] = 'Cargando...';
$string['error'] = 'Error';
$string['success'] = 'Éxito';
$string['warning'] = 'Advertencia';
$string['info'] = 'Información';

// Messages
$string['nostudentstodisplay'] = 'No hay estudiantes para mostrar';
$string['norepositories'] = 'No se encontraron repositorios';
$string['nodata'] = 'No hay datos disponibles';
$string['errorrenderingstats'] = 'Error al renderizar estadísticas';
$string['dataloadedsuccessfully'] = 'Datos cargados exitosamente';

// Group labels
$string['group'] = 'Grupo';
$string['students'] = 'alumnos';
$string['group_header_subtitle'] = 'Información detallada de tu grupo académico';

// Error messages
$string['problemoccurred'] = 'Ha ocurrido un problema';
$string['errordetails'] = 'Detalles del error:';
$string['whatcanyoudo'] = '¿Qué puedes hacer?';
$string['refreshpage'] = 'Refresca la página y vuelve a intentar';
$string['checkpermissions'] = 'Verifica que tienes los permisos necesarios';
$string['contactadmin'] = 'Contacta al administrador si persiste';
$string['technicalinfo'] = 'Información técnica:';
$string['course'] = 'Curso:';
$string['user'] = 'Usuario:';
$string['timestamp'] = 'Timestamp:';
$string['goback'] = 'Volver';
$string['reloadpage'] = 'Recargar Página';

// Alert messages
$string['success'] = '¡Éxito!';
$string['error'] = 'Error:';
$string['information'] = 'Información:';
$string['warning'] = 'Advertencia:';
$string['close'] = 'Cerrar';

// Filters
$string['filters'] = 'Filtros';
$string['clear'] = 'Limpiar';

// Access Management
$string['accessmanagement'] = 'Gestión de Accesos GitHub';
$string['manageinvitationsrepos'] = 'Administra invitaciones y asignaciones de repositorios';
$string['githubprocessing'] = 'Procesamiento de Invitaciones GitHub';
$string['invitationsregisters'] = 'Invitaciones y Registros de GitHub';
$string['selectrepository'] = 'Seleccionar Repositorio:';
$string['sendinvitations'] = 'Enviar Invitaciones';
$string['savechanges'] = 'Guardar Cambios';
$string['user'] = 'Usuario';
$string['sede'] = 'Sede';
$string['repository'] = 'Repositorio';
$string['invitationstatus'] = 'Estado Invitación';
$string['actions'] = 'Acciones';
$string['ready'] = 'Listo';
$string['pending'] = 'Pendiente';
$string['resetstatus'] = 'Resetear Estados';
$string['nostudents'] = 'Sin estudiantes:';
$string['nostudentsregistered'] = 'No hay estudiantes registrados en este curso.';
$string['invitationsnote'] = 'Solo se enviarán invitaciones a estudiantes con repositorio asignado y usuario GitHub configurado.';
$string['resetinvitations'] = 'Resetear Estados de Invitación';
$string['resetinvitationsdesc'] = 'Cambia todos los estados de invitación a "Sin procesar" para volver a probar el envío de invitaciones.';
$string['resetconfirm'] = '¿Estás seguro de resetear todos los estados de invitación?';
$string['select'] = 'Seleccionar...';
$string['githubplaceholder'] = 'usuario_github';
$string['note'] = 'Nota';
$string['allrepositories'] = 'Todos los Repositorios';
$string['invitationsreset'] = 'Estados de invitación reseteados exitosamente.';
$string['changessaved'] = 'Cambios guardados exitosamente.';

// Etiquetas de estado de invitación
$string['status_unprocessed'] = 'Sin procesar';
$string['status_missing_username'] = 'Sin usuario';
$string['status_username_not_found'] = 'Usuario no encontrado';
$string['status_sent'] = 'Invitación enviada';
$string['status_error_sending'] = 'Error en envío';
$string['status_accepted'] = 'Aceptado';
$string['status_pending'] = 'Pendiente';
$string['status_sent_legacy'] = 'Enviado (legacy)';
$string['status_error'] = 'Error';
$string['status_unknown'] = 'Estado desconocido';

// Main panel
$string['courseinfo'] = 'Información del Curso';
$string['subject'] = 'Materia';
$string['year'] = 'Año';
$string['semester'] = 'Cuatrimestre';
$string['totalpending'] = 'Total Pendientes';
$string['maxstudentsperrepo'] = 'Max. Alumnos / Repo';
$string['pendingstudentsbyseatcourse'] = 'Alumnos pendientes por Sede y Curso';
$string['pending'] = 'pendientes';
$string['nopending'] = 'No hay pendientes';
$string['createrepositories'] = 'Crear Repositorios';
$string['repos'] = 'repos';

// Etiquetas adicionales para el panel principal
$string['seatcourse'] = 'Sede \ Curso';
$string['createdrepos'] = 'Repositorios Creados';
$string['groupnumber'] = 'Nro Grupo';
$string['members'] = 'Miembros';
$string['members_title'] = 'Cantidad de miembros en el grupo';
$string['active'] = 'Activos';
$string['active_title'] = 'Alumnos que ya aceptaron la invitación';
$string['pending_title'] = 'Alumnos invitados pero aún no aceptaron';
$string['notfound'] = 'No encontrados';
$string['notfound_title'] = 'Alumnos cuyo usuario de GitHub no fue encontrado';
$string['missingusername'] = 'Sin completar';
$string['missingusername_title'] = 'Alumnos sin usuario de GitHub configurado';
$string['unprocessed_title'] = 'Alumnos sin procesar';
$string['error_title'] = 'Alumnos procesados con error';

// No repositories section
$string['noreposfound'] = 'No hay repositorios creados aún';
$string['noreposfounddesc'] = 'Cuando crees repositorios, aparecerán aquí con sus estadísticas detalladas.';

// Opciones de creación de repositorios
$string['repo_to_add_singular'] = '1 repositorio a agregar';
$string['repo_to_add_plural'] = '{$a} repositorios a agregar';

// Usuarios sin registrar
$string['unregisteredusers'] = 'Usuarios Sin Registrar';
$string['enrolledstudentspending'] = 'Estudiantes matriculados pendientes de registro en GitPatroller';
$string['searchfilters'] = 'Filtros de Búsqueda';
$string['clearfilters'] = 'Limpiar Filtros';
$string['studentsunregistered'] = 'estudiantes sin registrar';
$string['virtualclassroomuser'] = 'Usuario Aula Virtual';
$string['fullname'] = 'Nombre Completo';
$string['email'] = 'Correo Electrónico';
$string['unassigned'] = 'Sin asignar';
$string['unregisterednote'] = 'Estos estudiantes están matriculados pero aún no han accedido al sistema GitPatroller para registrar su información de GitHub.';
$string['excellent'] = '¡Excelente!';
$string['allstudentsregistered'] = 'Todos los estudiantes matriculados ya se han registrado en el sistema GitPatroller. No hay usuarios pendientes de registro.';

// Filter labels
$string['searchbyname'] = 'Buscar por Nombre';
$string['filterbysede'] = 'Filtrar por Sede';
$string['filterbycourse'] = 'Filtrar por Curso';
$string['filterbyrepository'] = 'Filtrar por Repositorio';
$string['searchplaceholder'] = 'Escriba para buscar...';
$string['allcourses'] = 'Todos los Cursos';
$string['allrepositories'] = 'Todos los Repositorios';
$string['allgroups'] = 'Todos los Grupos';
$string['allsedes'] = 'Todas las Sedes';
$string['all'] = 'Todos';
$string['showingxofy'] = 'Mostrando {$a->visible} de {$a->total} registros';

// Contributors insights
$string['trackingandgrading'] = 'Seguimiento y Calificación';
$string['contributionsmonitoring'] = 'Monitoreo de contribuciones y evaluación de estudiantes';
$string['searchfilters'] = 'Filtros de Búsqueda';
$string['clearfilters'] = 'Limpiar Filtros';
$string['updatecommitdata'] = 'Actualizar Datos de Commits';
$string['contributionstracking'] = 'Seguimiento de Contribuciones y Calificaciones';
$string['repository'] = 'Repositorio';
$string['githubuser'] = 'Usuario GitHub';
$string['fullname'] = 'Nombre Completo';
$string['lastcommit'] = 'Último Commit';
$string['commits'] = 'Commits';
$string['linesadded'] = 'Líneas agregadas';

// Teacher repos
$string['githubuser'] = 'Usuario GitHub';
$string['githubusername'] = 'Nombre de usuario GitHub:';
$string['save'] = 'Guardar';
$string['userconfiguredcorrectly'] = 'Usuario configurado correctamente';
$string['configuregithubuser'] = 'Configura tu usuario de GitHub para poder asignar repositorios';
$string['myassignedrepos'] = 'Mis Repositorios Asignados';
$string['synchronizestatus'] = 'Sincronizar Estados';
$string['invitationstatus'] = 'Estado Invitación';
$string['actions'] = 'Acciones';
$string['availablerepos'] = 'Repositorios Disponibles para Asignar';
$string['assignselectedrepos'] = 'Asignar Repositorios Seleccionados';
$string['reposassignedtoothers'] = 'Repositorios Asignados a Otros Profesores';
$string['noreposavailable'] = 'No hay repositorios disponibles en este curso';
$string['attention'] = 'Atención:';
$string['changeusernamewarning'] = 'Si cambias tu usuario de GitHub, serás removido como colaborador de todos tus repositorios asignados';
$string['noassignedrepos'] = 'No tienes repositorios asignados aún.';
$string['canassignfrom'] = 'Puedes asignar repositorios desde la sección de "Repositorios Disponibles" abajo.';
$string['configurefirst'] = 'Debes configurar tu nombre de usuario de GitHub antes de poder asignar repositorios.';
$string['assignlimit'] = 'Límite: Puedes asignar hasta 10 repositorios por operación.';
$string['selectrepos'] = 'Selecciona los repositorios que deseas asignar.';


$string['linesdeleted'] = 'Líneas eliminadas';
$string['linesmodified'] = 'Líneas modificadas';
$string['grade'] = 'Calificación';
$string['nograde'] = 'Sin nota';
$string['savegrades'] = 'Guardar Calificaciones';
$string['nostudentswithrepos'] = 'No hay estudiantes con repositorios asignados';
$string['assignreposfirst'] = 'Primero asigna repositorios a los estudiantes en la sección de';
$string['accessmanagement'] = 'Gestión de Accesos';
$string['unassigned'] = 'Sin asignar';
$string['never'] = 'Nunca';
$string['notconfigured'] = 'Sin configurar';

// Additional teacher repos strings
$string['confirmunassign'] = '¿Está seguro de desasignar este repositorio?\nEsto eliminará su acceso en GitHub.';
$string['unassign'] = 'Desasignar';
$string['unassignrepo'] = 'Desasignar repositorio';
$string['noactions'] = 'Sin acciones';
$string['note'] = 'Nota:';
$string['invitationinfo'] = 'Después de asignar un repositorio, recibirás una invitación en GitHub que debes aceptar para tener acceso.';
$string['usesyncbutton'] = 'Usa el botón "Sincronizar Estados" para actualizar el estado de tus invitaciones.';
$string['selected'] = 'seleccionados';
$string['mustconfiguregithub'] = 'Debes configurar tu nombre de usuario de GitHub antes de poder asignar repositorios.';
$string['selectdeselectall'] = 'Seleccionar/Deseleccionar todo';
$string['configureuserfirst'] = 'Configura tu usuario de GitHub primero';

// Tab names
$string['tabrepositories'] = 'Repositorios';
$string['tabparticipants'] = 'Participantes';
$string['tabmygroup'] = 'Mi Grupo';
$string['tabaccessmanagement'] = 'Gestión de accesos';
$string['tabtrackinggrading'] = 'Seguimiento y calificación';
$string['tabstatistics'] = 'Estadísticas';
$string['tabunregistered'] = 'Sin registrar';
$string['tabteacherrepos'] = 'Repositorios Profesor';
$string['tabai'] = 'IA Insights';

// Error messages
$string['unknowntab'] = 'Pestaña desconocida.';
$string['erroroccurred'] = 'Ha ocurrido un error';
$string['limit'] = 'Límite:';
$string['upto10repos'] = 'Puedes asignar hasta 10 repositorios por operación.';
$string['selectrepostoassign'] = 'Selecciona los repositorios que deseas asignar.';
$string['selectatleastone'] = 'Selecciona al menos un repositorio';
$string['state'] = 'Estado';

// Modal and additional strings
$string['confirmationchangemodal'] = 'Confirmación de Cambio de Usuario GitHub';
$string['confirmationchange'] = 'Confirmación de Cambio';
$string['currentuser'] = 'Usuario actual:';
$string['newuser'] = 'Nuevo usuario:';
$string['changeconsequences'] = 'Consecuencias del cambio:';
$string['willberemoved'] = 'Serás removido como colaborador de todos tus repositorios asignados en GitHub';
$string['willloseaccess'] = 'Perderás el acceso inmediato a';
$string['repositories'] = 'repositorio(s)';
$string['mustreassign'] = 'Deberás reasignar manualmente los repositorios que necesites con el nuevo usuario';
$string['invitationscanceled'] = 'Las invitaciones anteriores serán canceladas';
$string['cannotrecover'] = 'Si cambias el username por error, no podrás recuperar automáticamente tus asignaciones anteriores.';
$string['confirmchange'] = 'Confirmar Cambio';
$string['cancel'] = 'Cancelar';

// Student view strings
$string['classmates'] = 'Compañeros de Curso';
$string['academicgroupinfo'] = 'Ver y gestionar información de tu grupo académico';
$string['youracademiccontext'] = 'Tu contexto académico:';
$string['classmateslist'] = 'Lista de compañeros';
$string['campusandcourse'] = 'Sede y Curso';
$string['repositorygroup'] = 'Repositorio/Grupo';
$string['yourgithubusername'] = 'Tu usuario GitHub';
$string['lockedstate'] = 'Estado bloqueado';
$string['noreposavailable'] = 'Sin repositorios disponibles';
$string['save'] = 'Guardar';
$string['noteditable'] = 'No editable';
$string['noclassmatesregistered'] = 'No hay compañeros registrados';
$string['noclassmatesexplanation'] = 'No se encontraron otros estudiantes registrados en tu sede y curso. Puede que aún no se hayan registrado en el sistema o que seas el único estudiante inscrito.';
$string['noclassmatesfound'] = 'No se encontraron otros estudiantes registrados en tu sede y curso. Puede que aún no se hayan registrado en el sistema o que seas el único estudiante inscrito.';

// Group view
$string['success'] = '¡Éxito!';
$string['error'] = 'Error:';
$string['close'] = 'Cerrar';
$string['group'] = 'Grupo';
$string['groupinformation'] = 'Información del Grupo';
$string['campusandcourse'] = 'Sede y Curso';
$string['totalmembers'] = 'Total de Miembros';
$string['students'] = 'estudiantes';
$string['name'] = 'Nombre';
$string['you'] = '(Tú)';
$string['statistics'] = 'Estadísticas';
$string['metric'] = 'Métrica';

// Missing string definitions
$string['linesdeleted'] = 'Líneas eliminadas';
$string['linesmodified'] = 'Líneas modificadas';
$string['grade'] = 'Calificación';
$string['nograde'] = 'Sin nota';
$string['selected'] = 'seleccionados';
$string['mustconfiguregithub'] = 'Debes configurar tu nombre de usuario de GitHub antes de poder asignar repositorios.';
$string['configureuserfirst'] = 'Configure su usuario GitHub primero';

// Repository management
$string['repositoriescreatedsuccessfully'] = 'Repositorios creados exitosamente';

// Sync button tooltips
$string['detectchangesinvitations'] = 'Detecta cambios en invitaciones';

// Sync messages for JavaScript
$string['syncing'] = 'Sincronizando...';
$string['syncsuccess'] = '✅ Estados sincronizados correctamente!';
$string['errorserver'] = 'Error en la respuesta del servidor';
$string['errorsync'] = 'Error al sincronizar';
$string['synccompleted'] = 'Sincronización completada!';

// Invitation states
$string['unverified'] = 'Sin verificar';
$string['incomplete'] = 'Incompleto';

// Action buttons
$string['change'] = 'Cambiar';
$string['changerepository'] = 'Cambiar repositorio';
$string['changerepositoryfor'] = 'Cambiar Repositorio para';
$string['send'] = 'Enviar';
$string['sendinvitation'] = 'Enviar invitación';
$string['sendinvitationcollaboration'] = 'Enviar invitación de colaboración';
$string['cancelinvitation'] = 'Cancelar invitación';
$string['cancelinvitationpending'] = 'Cancelar invitación pendiente';

// Confirmation messages
$string['confirmcancelinvitation'] = '¿Está seguro de cancelar la invitación para {$a}?';
$string['confirmsendinvitation'] = '¿Enviar invitación de colaboración a {$a}?';

// Change repository form
$string['student'] = 'Estudiante';
$string['githubuser'] = 'Usuario GitHub';
$string['currentrepository'] = 'Repositorio actual';
$string['newrepository'] = 'Nuevo Repositorio';
$string['selectrepository'] = 'Seleccione un repositorio';
$string['confirmchange'] = 'Confirmar Cambio';

// Invitation status states
$string['collaboratoractive'] = 'Colaborador Activo';
$string['invitationpending'] = 'Invitación Pendiente';
$string['invitationexpired'] = 'Invitación Expirada';
$string['noinvitation'] = 'Sin Invitación';

?>
