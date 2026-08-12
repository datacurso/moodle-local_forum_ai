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
 * @copyright   2025 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action_failed'] = 'Не удалось обработать действие.';
$string['actions'] = 'Действия';
$string['ai_response'] = 'Ответ ИИ';
$string['ai_response_approved'] = 'Ответ ИИ одобрен';
$string['ai_response_expired'] = 'Ответ ИИ (истёкший)';
$string['ai_response_proposed'] = 'Предложенный ответ ИИ';
$string['ai_response_rejected'] = 'Ответ ИИ отклонён';
$string['ai_review_button'] = 'Проверить с ИИ';
$string['aiproposed'] = 'Предложенный ответ ИИ';
$string['allowedroles'] = 'Разрешённые роли для ответов ИИ';
$string['allowedroles_help'] = 'Выберите роли пользователей, которым разрешено получать ответы от ИИ. Если ни одна не выбрана, ИИ не будет отвечать ни одному пользователю.';
$string['alreadysubmitted'] = 'Этот запрос уже был одобрен, отклонён или не существует.';
$string['approve'] = 'Одобрить';
$string['autogradegrader'] = 'Пользователь-оценщик для автоматических подтверждений';
$string['autogradegrader_help'] = 'Выберите пользователя, который будет зарегистрирован как оценщик, когда отзыв ИИ будет автоматически одобрен. Отображаются только пользователи, имеющие право оценивать в этом курсе.';
$string['backtocourse'] = 'Вернуться к курсу';
$string['backtodiscussion'] = 'Назад к обсуждению';
$string['backup:includeai'] = 'Включить данные форума ИИ в резервные копии';
$string['cancel'] = 'Отмена';
$string['col_message'] = 'Сообщение';
$string['course'] = 'Курс';
$string['coursename'] = 'Курс';
$string['created'] = 'Создано';
$string['datacurso_custom'] = 'Форум ИИ Datacurso';
$string['default_reply_message'] = 'Отвечайте с эмпатией и мотивацией';
$string['default_replyinlocked'] = 'Отвечать в заблокированных обсуждениях';
$string['default_replyinlocked_desc'] = 'Если включено, ИИ будет генерировать и публиковать ответы в заблокированных обсуждениях. Каждый форум может переопределить это значение в своих настройках.';
$string['defaultenableai'] = 'Включить ИИ';
$string['defaultenableai_desc'] = 'Управляет глобальной доступностью ИИ для форумов. Если отключено, ИИ выключается для всех существующих форумов и не может быть включён на уровне отдельного форума, пока глобальная настройка снова не будет включена.';
$string['delayminutes'] = 'Время ожидания (в минутах)';
$string['delayminutes_help'] = 'Количество минут, которое нужно подождать после отправки работы студентом перед запуском проверки с помощью ИИ.';
$string['discussion'] = 'Обсуждение';
$string['discussion_label'] = 'Обсуждение: {$a}';
$string['discussioninfo'] = 'Информация об обсуждении';
$string['discussionmsg'] = 'Сообщение, сгенерированное ИИ';
$string['discussionname'] = 'Тема';
$string['enabled'] = 'Включить ИИ';
$string['enablediainitconversation'] = 'Включить ИИ-ответ на тему обсуждения';
$string['enablediainitconversation_help'] = 'Если включить эту опцию, ИИ сможет отвечать на первое сообщение, которое начинает обсуждение. Также рекомендуется выбрать роль «Преподаватель» в следующем поле.';
$string['enableforumai'] = 'Включить Forum AI';
$string['enableforumai_desc'] = 'Если отключено, раздел "Datacurso Forum AI" скрывается в настройках активности форума, а автоматическая обработка приостанавливается.';
$string['error_airequest'] = 'Ошибка при связи со службой ИИ: {$a}';
$string['error_discussionlocked'] = 'Это обсуждение заблокировано, поэтому ответ ИИ не может быть опубликован. Разблокируйте обсуждение и попробуйте снова.';
$string['error_forumclosed'] = 'Крайний срок этого форума истёк, поэтому ответ ИИ не может быть опубликован.';
$string['error_invalidgrade'] = 'The AI grade could not be resolved to a valid forum grade.';
$string['error_privatereply'] = 'Сообщение, на которое даётся ответ, является личным ответом, поэтому ответ ИИ не может быть опубликован.';
$string['error_responsenotpending'] = 'Этот ответ уже был одобрен или отклонён и больше не может быть отредактирован.';
$string['error_usernotincourse'] = 'Выбранный пользователь не записан на этот курс.';
$string['error_usernotingroup'] = 'Вы не можете запросить проверку с помощью ИИ для пользователя вне ваших групп.';
$string['evaluatingwithai'] = 'Оценка с помощью ИИ...';
$string['eventaireviewrequested'] = 'Запрошена проверка с помощью ИИ';
$string['eventresponseapproved'] = 'Ответ ИИ одобрен';
$string['eventresponserejected'] = 'Ответ ИИ отклонён';
$string['forum'] = 'Форум';
$string['forum_ai:approveresponses'] = 'Одобрить или отклонить ответы форума, сгенерированные ИИ';
$string['forum_ai:useaireview'] = 'Используйте функцию проверки ИИ для оценки форума';
$string['forumname'] = 'Форум';
$string['grade'] = 'Оценка';
$string['gradesappliedsuccessfully'] = 'Оценки успешно применены ИИ';
$string['historyresponses'] = 'История ответов форума ИИ';
$string['invalidaction'] = 'Указанное действие недопустимо.';
$string['level'] = 'Уровень: {$a}';
$string['managedby'] = 'Обработано';
$string['messageprovider:ai_approval_request'] = 'Запрос на одобрение ИИ';
$string['modal_title'] = 'Подробности истории обсуждения';
$string['modal_title_pending'] = 'Подробности обсуждения';
$string['no'] = 'Нет';
$string['no_posts'] = 'В этом обсуждении сообщений не найдено.';
$string['nohistory'] = 'Нет истории одобренных, отклонённых или истёкших ответов ИИ.';
$string['noresponses'] = 'Нет ожидающих одобрения ответов.';
$string['notification_course_label'] = 'Курс';
$string['notification_fullmessage'] = 'Здравствуйте, {$a->firstname},

Для обсуждения "{$a->discussion}" на форуме "{$a->forum}" (Курс: {$a->course}) был сгенерирован ответ с использованием ИИ.

Предварительный просмотр: {$a->preview}...

Чтобы просмотреть полное сообщение и решить, одобрить его или отклонить, пожалуйста, перейдите по ссылке:
{$a->reviewurl}';
$string['notification_greeting'] = 'Здравствуйте, {$a->firstname},';
$string['notification_intro'] = 'Автоматический ответ был сгенерирован для обсуждения "{$a->discussion}" на форуме "{$a->forum}" курса "{$a->course}".';
$string['notification_preview'] = 'Предпросмотр:';
$string['notification_review_button'] = 'Проверить ответ';
$string['notification_smallmessage'] = 'Новый ответ ИИ ожидает в "{$a->discussion}"';
$string['notification_subject'] = 'Требуется одобрение: ответ ИИ';
$string['originalmessage'] = 'Оригинальное сообщение';
$string['pendingresponses'] = 'Ожидающие ответы форума ИИ';
$string['pluginname'] = 'Форум ИИ';
$string['preview'] = 'Сообщение ИИ';
$string['privacy:metadata:datacurso_ai'] = 'Содержимое сообщений форума отправляется во внешний ИИ-сервис Datacurso для генерации ответов и оценок.';
$string['privacy:metadata:datacurso_ai:author_name'] = 'Полное имя автора сообщения, включаемое в запрос к ИИ.';
$string['privacy:metadata:datacurso_ai:course_activity'] = 'Названия курса, форума и обсуждения, задающие контекст запроса к ИИ.';
$string['privacy:metadata:datacurso_ai:post_content'] = 'Текст сообщений форума, отправляемых в ИИ-сервис.';
$string['privacy:metadata:datacurso_ai:thread_history'] = 'Предыдущие сообщения обсуждения, отправляемые как контекст беседы.';
$string['privacy:metadata:datacurso_ai:userid'] = 'ID пользователя, от имени которого выполняется запрос к ИИ.';
$string['privacy:metadata:local_forum_ai_config'] = 'Хранит настройки ИИ по каждому форуму.';
$string['privacy:metadata:local_forum_ai_config:allowedroles'] = 'Список ID ролей через запятую, которым ИИ разрешено отвечать.';
$string['privacy:metadata:local_forum_ai_config:delayminutes'] = 'Количество минут ожидания перед запуском отложенной проверки ИИ.';
$string['privacy:metadata:local_forum_ai_config:enabled'] = 'Указывает, активирован ли ИИ для этого форума.';
$string['privacy:metadata:local_forum_ai_config:enablediainitconversation'] = 'Указывает, отвечает ли ИИ на первое сообщение обсуждения.';
$string['privacy:metadata:local_forum_ai_config:forumid'] = 'ID форума, к которому относится эта настройка.';
$string['privacy:metadata:local_forum_ai_config:graderid'] = 'ID пользователя, зарегистрированного как оценщик при автоматических одобрениях.';
$string['privacy:metadata:local_forum_ai_config:questionturns'] = 'Максимальное количество ответов ИИ с наводящим вопросом на одну ветку ответов.';
$string['privacy:metadata:local_forum_ai_config:reply_message'] = 'Шаблон ответа, сгенерированный ИИ.';
$string['privacy:metadata:local_forum_ai_config:replyinlocked'] = 'Указывает, может ли ИИ отвечать в заблокированных обсуждениях этого форума.';
$string['privacy:metadata:local_forum_ai_config:require_approval'] = 'Указывает, требуют ли ответы ИИ одобрения перед публикацией.';
$string['privacy:metadata:local_forum_ai_config:timecreated'] = 'Дата создания конфигурации.';
$string['privacy:metadata:local_forum_ai_config:timemodified'] = 'Дата последнего изменения конфигурации.';
$string['privacy:metadata:local_forum_ai_config:usedelay'] = 'Указывает, выполняется ли проверка ИИ после настраиваемой задержки.';
$string['privacy:metadata:local_forum_ai_pending'] = 'Данные, сохранённые плагином форума ИИ.';
$string['privacy:metadata:local_forum_ai_pending:action_userid'] = 'ID пользователя, который одобрил или отклонил ответ.';
$string['privacy:metadata:local_forum_ai_pending:approval_token'] = 'Токен одобрения, связанный с публикацией.';
$string['privacy:metadata:local_forum_ai_pending:approved_at'] = 'Дата, когда ответ был одобрен.';
$string['privacy:metadata:local_forum_ai_pending:creator_userid'] = 'ID пользователя, создавшего публикацию.';
$string['privacy:metadata:local_forum_ai_pending:discussionid'] = 'ID связанного обсуждения.';
$string['privacy:metadata:local_forum_ai_pending:forumid'] = 'ID форума, где был создан ответ.';
$string['privacy:metadata:local_forum_ai_pending:grade'] = 'Оценка, предложенная ИИ для оцениваемого сообщения.';
$string['privacy:metadata:local_forum_ai_pending:message'] = 'Сообщение, созданное искусственным интеллектом.';
$string['privacy:metadata:local_forum_ai_pending:parentpostid'] = 'ID сообщения форума, на которое отвечает ответ ИИ.';
$string['privacy:metadata:local_forum_ai_pending:postid'] = 'ID сообщения форума, опубликованного из этого ответа ИИ.';
$string['privacy:metadata:local_forum_ai_pending:status'] = 'Статус публикации (одобрена, ожидает, отклонена).';
$string['privacy:metadata:local_forum_ai_pending:subject'] = 'Тема сообщения.';
$string['privacy:metadata:local_forum_ai_pending:timecreated'] = 'Дата создания записи.';
$string['privacy:metadata:local_forum_ai_pending:timemodified'] = 'Дата обновления записи.';
$string['privacy:metadata:local_forum_ai_queue'] = 'Очередь отложенных запросов на обработку ИИ.';
$string['privacy:metadata:local_forum_ai_queue:payload'] = 'JSON-данные с модулем курса и сообщением или обсуждением для обработки.';
$string['privacy:metadata:local_forum_ai_queue:processed'] = 'Указывает, обработан ли запрос в очереди.';
$string['privacy:metadata:local_forum_ai_queue:timecreated'] = 'Дата создания запроса в очереди.';
$string['privacy:metadata:local_forum_ai_queue:timetoprocess'] = 'Дата, когда запрос в очереди должен быть обработан.';
$string['privacy:metadata:local_forum_ai_queue:type'] = 'Тип запроса в очереди (сообщение или обсуждение).';
$string['questionturns'] = 'Ответы ИИ с наводящим вопросом (на ветку ответов)';
$string['questionturns_help'] = 'Выберите, сколько ответов ИИ в одной ветке ответов должны включать обратную связь и наводящий вопрос. После достижения этого лимита ИИ продолжит отвечать только с обратной связью. Используйте 0, чтобы отключить наводящие вопросы.';
$string['reject'] = 'Отклонить';
$string['reply_message'] = 'Дайте указания ИИ';
$string['replyinlocked'] = 'Отвечать в заблокированных обсуждениях';
$string['replyinlocked_help'] = 'Выберите, должен ли ИИ генерировать и публиковать ответы, когда обсуждение заблокировано. Если выбрано «Нет», ИИ пропускает заблокированные обсуждения, а ожидающие ответы нельзя одобрить, пока обсуждение остаётся заблокированным.';
$string['replylevel'] = 'Уровень ответа {$a}';
$string['require_approval'] = 'Проверить ответ ИИ';
$string['response_approved'] = 'Ответ ИИ успешно одобрен и опубликован.';
$string['response_rejected'] = 'Ответ ИИ отклонён.';
$string['response_update_failed'] = 'Не удалось обновить ответ.';
$string['response_updated'] = 'Ответ успешно обновлён.';
$string['reviewtitle'] = 'Проверка ответа ИИ';
$string['save'] = 'Сохранить';
$string['saveapprove'] = 'Сохранить и одобрить';
$string['settings'] = 'Настройки для: ';
$string['status'] = 'Статус';
$string['statusapproved'] = 'Одобрено';
$string['statusexpired'] = 'Истёк';
$string['statuspending'] = 'Ожидает';
$string['statusrejected'] = 'Отклонено';
$string['task_process_ai_queue'] = 'Обработать отложенную очередь Forum AI';
$string['task_process_single_forum_discussion'] = 'Обработать один форум обсуждений для ИИ';
$string['usedelay'] = 'Использовать отложенную проверку';
$string['usedelay_help'] = 'Если включено, проверка с помощью ИИ будет выполнена после настраиваемой задержки, а не сразу.';
$string['username'] = 'Автор';
$string['viewdetails'] = 'Подробнее';
$string['yes'] = 'Да';
