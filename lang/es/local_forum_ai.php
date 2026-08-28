<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin strings are defined here.
 *
 * @package     local_forum_ai
 * @category    string
 * @copyright   2025 Piero Llanos <piero@datacurso.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action_failed'] = 'No se pudo procesar la acción.';
$string['actions'] = 'Acciones';
$string['ai_response'] = 'Respuesta IA';
$string['ai_response_approved'] = 'Respuesta IA Aprobada';
$string['ai_response_expired'] = 'Respuesta IA (vencida)';
$string['ai_response_proposed'] = 'Respuesta IA propuesta';
$string['ai_response_rejected'] = 'Respuesta IA Rechazada';
$string['ai_review_button'] = 'Revisar con IA';
$string['aiproposed'] = 'Respuesta AI propuesta';
$string['allowedroles'] = 'Roles permitidos para respuestas de IA';
$string['allowedroles_help'] = 'Selecciona qué roles de usuario pueden recibir respuestas de la IA. Si no se selecciona ninguno, la IA no responderá a ningún usuario.';
$string['alreadysubmitted'] = 'Esta solicitud ya fue aprobada, rechazada o no existe.';
$string['approve'] = 'Aprobar';
$string['autogradegrader'] = 'Usuario calificador para aprobaciones automáticas';
$string['autogradegrader_help'] = 'Selecciona al usuario que se registrará como calificador cuando la retroalimentación de IA se apruebe automáticamente. Solo se listan usuarios con permiso para calificar en este curso.';
$string['backtocourse'] = 'Regresar al curso';
$string['backtodiscussion'] = 'Volver al debate';
$string['backup:includeai'] = 'Incluir datos del foro de IA en las copias de seguridad';
$string['cancel'] = 'Cancelar';
$string['col_message'] = 'Mensaje';
$string['course'] = 'Curso';
$string['coursename'] = 'Curso';
$string['created'] = 'Creado';
$string['datacurso_custom'] = 'Datacurso Foro IA';
$string['default_reply_message'] = 'Responde con tono empático y motivador';
$string['default_replyinlocked'] = 'Responder en debates bloqueados';
$string['default_replyinlocked_desc'] = 'Si se habilita, la IA generará y publicará respuestas en debates bloqueados. Cada foro puede sobreescribir este valor en su propia configuración.';
$string['defaultenableai'] = 'Habilitar IA';
$string['defaultenableai_desc'] = 'Controla la disponibilidad global de IA en los foros. Si se deshabilita, la IA se desactiva en todos los foros existentes y no se puede activar individualmente hasta que se vuelva a habilitar.';
$string['delayminutes'] = 'Tiempo de espera (minutos)';
$string['delayminutes_help'] = 'Cantidad de minutos que se debe esperar después de que el estudiante publique antes de ejecutar la revisión con IA.';
$string['discussion'] = 'Debate';
$string['discussion_label'] = 'Debate: {$a}';
$string['discussioninfo'] = 'Información del debate';
$string['discussionmsg'] = 'Mensaje hecho por IA';
$string['discussionname'] = 'Asunto';
$string['enabled'] = 'Habilitar IA';
$string['enablediainitconversation'] = 'Habilitar respuesta IA al tema de discusión';
$string['enablediainitconversation_help'] = 'Al habilitar esta opción, la IA podrá responder al mensaje inicial que inicia la discusión. También se recomienda seleccionar el rol de Profesor en el campo siguiente.';
$string['enableforumai'] = 'Habilitar Foro IA';
$string['enableforumai_desc'] = 'Si se deshabilita, la sección "Datacurso Foro IA" se oculta en la configuración de la actividad foro y se pausa el procesamiento automático.';
$string['error_airequest'] = 'No se pudo cargar la respuesta de IA. Inténtelo de nuevo más tarde o contacte a su administrador.';
$string['error_discussionlocked'] = 'Este debate está bloqueado, por lo que la respuesta de la IA no se puede publicar. Desbloquea el debate e inténtalo de nuevo.';
$string['error_forumclosed'] = 'La fecha límite de este foro ya pasó, por lo que la respuesta de la IA no se puede publicar.';
$string['error_invalidgrade'] = 'The AI grade could not be resolved to a valid forum grade.';
$string['error_privatereply'] = 'La publicación a la que se responde es una respuesta privada, por lo que no se puede publicar una respuesta de la IA.';
$string['error_responsenotpending'] = 'Esta respuesta ya fue aprobada o rechazada y ya no se puede editar.';
$string['error_usernotincourse'] = 'El usuario seleccionado no está matriculado en este curso.';
$string['error_usernotingroup'] = 'No puede solicitar una revisión con IA para un usuario fuera de sus grupos.';
$string['evaluatingwithai'] = 'Evaluando con IA...';
$string['eventaireviewrequested'] = 'Revisión con IA solicitada';
$string['eventresponseapproved'] = 'Respuesta de IA aprobada';
$string['eventresponserejected'] = 'Respuesta de IA rechazada';
$string['forum'] = 'Foro';
$string['forum_ai:approveresponses'] = 'Aprobar o rechazar respuestas generadas por IA en los foros';
$string['forum_ai:useaireview'] = 'Utilice la función de revisión de IA para calificar el foro';
$string['forumname'] = 'Foro';
$string['grade'] = 'Calificación';
$string['gradesappliedsuccessfully'] = 'Calificaciones aplicadas exitosamente por IA';
$string['historyresponses'] = 'Historial de respuestas Foro IA';
$string['invalidaction'] = 'La acción indicada no es válida.';
$string['level'] = 'Nivel: {$a}';
$string['managedby'] = 'Gestionado por';
$string['messageprovider:ai_approval_request'] = 'Solicitud de aprobación de IA';
$string['modal_title'] = 'Detalles del historial de debate';
$string['modal_title_pending'] = 'Detalles del debate';
$string['no'] = 'No';
$string['no_posts'] = 'No se encontraron posts en este debate.';
$string['nohistory'] = 'No hay historial de respuestas IA aprobadas, rechazadas o vencidas.';
$string['noresponses'] = 'No hay respuestas pendientes de aprobación.';
$string['notification_course_label'] = 'Curso';
$string['notification_fullmessage'] = 'Hola {$a->firstname},

Se ha generado una respuesta con IA para la discusión "{$a->discussion}" en el foro "{$a->forum}" (Curso: {$a->course}).

Vista previa: {$a->preview}...

Para revisar el mensaje completo y decidir si aprobarlo o rechazarlo, por favor visita:
{$a->reviewurl}';
$string['notification_greeting'] = 'Hola {$a->firstname},';
$string['notification_intro'] = 'Se ha generado una respuesta automática para el debate "{$a->discussion}" en el foro "{$a->forum}" del curso "{$a->course}".';
$string['notification_preview'] = 'Vista previa:';
$string['notification_review_button'] = 'Revisar respuesta';
$string['notification_smallmessage'] = 'Nueva respuesta AI pendiente en "{$a->discussion}"';
$string['notification_subject'] = 'Aprobación requerida: Respuesta AI';
$string['originalmessage'] = 'Mensaje original';
$string['pendingresponses'] = 'Respuestas Foro IA Pendientes';
$string['pluginname'] = 'Foro IA';
$string['preview'] = 'Mensaje IA';
$string['privacy:metadata:datacurso_ai'] = 'El contenido de las publicaciones del foro se envía al servicio externo de IA de Datacurso para generar respuestas y evaluaciones.';
$string['privacy:metadata:datacurso_ai:author_name'] = 'Nombre completo del autor de la publicación incluido en la solicitud a la IA.';
$string['privacy:metadata:datacurso_ai:course_activity'] = 'Nombres del curso, el foro y el debate que contextualizan la solicitud a la IA.';
$string['privacy:metadata:datacurso_ai:post_content'] = 'Texto de las publicaciones del foro enviadas al servicio de IA.';
$string['privacy:metadata:datacurso_ai:thread_history'] = 'Publicaciones anteriores del debate enviadas como contexto de la conversación.';
$string['privacy:metadata:datacurso_ai:userid'] = 'El ID del usuario en cuyo nombre se realiza la solicitud a la IA.';
$string['privacy:metadata:local_forum_ai_config'] = 'Almacena las configuraciones de IA por foro.';
$string['privacy:metadata:local_forum_ai_config:allowedroles'] = 'Lista separada por comas de los ID de rol a los que la IA puede responder.';
$string['privacy:metadata:local_forum_ai_config:delayminutes'] = 'Cantidad de minutos de espera antes de ejecutar la revisión diferida con IA.';
$string['privacy:metadata:local_forum_ai_config:enabled'] = 'Indica si la IA está habilitada para este foro.';
$string['privacy:metadata:local_forum_ai_config:enablediainitconversation'] = 'Indica si la IA responde al mensaje inicial del debate.';
$string['privacy:metadata:local_forum_ai_config:forumid'] = 'El ID del foro al que pertenece esta configuración.';
$string['privacy:metadata:local_forum_ai_config:graderid'] = 'ID del usuario registrado como calificador en las aprobaciones automáticas.';
$string['privacy:metadata:local_forum_ai_config:questionturns'] = 'Cantidad máxima de respuestas de IA con pregunta guía permitidas por hilo de réplicas.';
$string['privacy:metadata:local_forum_ai_config:reply_message'] = 'Plantilla de respuesta generada por la IA.';
$string['privacy:metadata:local_forum_ai_config:replyinlocked'] = 'Indica si la IA puede responder en debates bloqueados en este foro.';
$string['privacy:metadata:local_forum_ai_config:require_approval'] = 'Indica si las respuestas de IA requieren aprobación antes de publicarse.';
$string['privacy:metadata:local_forum_ai_config:timecreated'] = 'Fecha de creación de la configuración.';
$string['privacy:metadata:local_forum_ai_config:timemodified'] = 'Fecha de última modificación de la configuración.';
$string['privacy:metadata:local_forum_ai_config:usedelay'] = 'Indica si la revisión con IA se ejecuta tras un tiempo de espera configurable.';
$string['privacy:metadata:local_forum_ai_pending'] = 'Datos almacenados por el plugin Foro IA.';
$string['privacy:metadata:local_forum_ai_pending:action_userid'] = 'ID del usuario que aprobó o rechazó la respuesta.';
$string['privacy:metadata:local_forum_ai_pending:approval_token'] = 'Token de aprobación vinculado a la publicación.';
$string['privacy:metadata:local_forum_ai_pending:approved_at'] = 'Fecha en la que fue aprobada la respuesta.';
$string['privacy:metadata:local_forum_ai_pending:creator_userid'] = 'ID del usuario que creó la publicación.';
$string['privacy:metadata:local_forum_ai_pending:discussionid'] = 'ID de la discusión relacionada.';
$string['privacy:metadata:local_forum_ai_pending:forumid'] = 'ID del foro en el que se generó la respuesta.';
$string['privacy:metadata:local_forum_ai_pending:grade'] = 'Calificación propuesta por la IA para la publicación evaluada.';
$string['privacy:metadata:local_forum_ai_pending:message'] = 'Mensaje generado por la inteligencia artificial.';
$string['privacy:metadata:local_forum_ai_pending:parentpostid'] = 'ID de la publicación del foro a la que responde la respuesta de la IA.';
$string['privacy:metadata:local_forum_ai_pending:postid'] = 'ID de la publicación del foro creada a partir de esta respuesta de la IA.';
$string['privacy:metadata:local_forum_ai_pending:status'] = 'Estado de la publicación (aprobada, pendiente o rechazada).';
$string['privacy:metadata:local_forum_ai_pending:subject'] = 'Asunto o tema del mensaje.';
$string['privacy:metadata:local_forum_ai_pending:timecreated'] = 'Fecha en que se creó el registro.';
$string['privacy:metadata:local_forum_ai_pending:timemodified'] = 'Fecha en que se actualizó el registro.';
$string['privacy:metadata:local_forum_ai_queue'] = 'Cola de solicitudes diferidas de procesamiento con IA.';
$string['privacy:metadata:local_forum_ai_queue:payload'] = 'Carga JSON con el módulo del curso y la publicación o el debate a procesar.';
$string['privacy:metadata:local_forum_ai_queue:processed'] = 'Indica si la solicitud en cola ya fue procesada.';
$string['privacy:metadata:local_forum_ai_queue:timecreated'] = 'Fecha en que se creó la solicitud en cola.';
$string['privacy:metadata:local_forum_ai_queue:timetoprocess'] = 'Fecha en la que debe procesarse la solicitud en cola.';
$string['privacy:metadata:local_forum_ai_queue:type'] = 'Tipo de solicitud en cola (publicación o debate).';
$string['questionturns'] = 'Respuestas de IA con pregunta guía (por hilo)';
$string['questionturns_help'] = 'Elige cuántas respuestas de IA en el mismo hilo de réplicas deben incluir feedback y una pregunta guía. Al alcanzar este límite, la IA continuará respondiendo solo con feedback. Usa 0 para desactivar preguntas guía.';
$string['reject'] = 'Rechazar';
$string['reply_message'] = 'Dale indicaciones a la IA';
$string['replyinlocked'] = 'Responder en debates bloqueados';
$string['replyinlocked_help'] = 'Elige si la IA debe generar y publicar respuestas cuando el debate está bloqueado. Si se establece en No, la IA omite los debates bloqueados y las respuestas pendientes no se pueden aprobar mientras el debate siga bloqueado.';
$string['replylevel'] = 'Respuesta nivel {$a}';
$string['require_approval'] = 'Revisar respuesta IA';
$string['response_approved'] = 'Respuesta de la IA aprobada y publicada correctamente.';
$string['response_rejected'] = 'Respuesta de la IA rechazada.';
$string['response_update_failed'] = 'No se pudo actualizar la respuesta.';
$string['response_updated'] = 'Respuesta actualizada correctamente.';
$string['reviewtitle'] = 'Revisar respuesta IA';
$string['save'] = 'Guardar';
$string['saveapprove'] = 'Guardar y aprobar';
$string['settings'] = 'Configuración para: ';
$string['status'] = 'Estado';
$string['statusapproved'] = 'Aprobado';
$string['statusexpired'] = 'Vencido';
$string['statuspending'] = 'Pendiente';
$string['statusrejected'] = 'Rechazado';
$string['task_process_ai_queue'] = 'Procesar cola diferida de Foro IA';
$string['task_process_single_forum_discussion'] = 'Procesar un único foro de discusión para IA';
$string['usedelay'] = 'Usar revisión diferida';
$string['usedelay_help'] = 'Si está activado, la revisión con IA se ejecutará después de un tiempo de espera configurable en lugar de ejecutarse inmediatamente.';
$string['username'] = 'Creador';
$string['viewdetails'] = 'Detalles';
$string['yes'] = 'Si';
