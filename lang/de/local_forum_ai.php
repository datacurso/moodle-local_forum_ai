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

$string['action_failed'] = 'Aktion konnte nicht verarbeitet werden.';
$string['actions'] = 'Aktionen';
$string['ai_response'] = 'KI-Antwort';
$string['ai_response_approved'] = 'KI-Antwort genehmigt';
$string['ai_response_proposed'] = 'Vorgeschlagene KI-Antwort';
$string['ai_response_rejected'] = 'KI-Antwort abgelehnt';
$string['ai_review_button'] = 'Mit KI überprüfen';
$string['aiproposed'] = 'Vorgeschlagene KI-Antwort';
$string['allowedroles'] = 'Zulässige Rollen für KI-Antworten';
$string['allowedroles_help'] = 'Wählen Sie aus, auf welche Benutzerrollen die KI antworten darf. Wenn keine ausgewählt sind, antwortet die KI auf keine Benutzer.';
$string['alreadysubmitted'] = 'Diese Anfrage wurde bereits genehmigt, abgelehnt oder existiert nicht.';
$string['approve'] = 'Genehmigen';
$string['autogradegrader'] = 'Bewertender Benutzer für automatische Freigaben';
$string['autogradegrader_help'] = 'Wähle den Benutzer aus, der als Bewerter registriert wird, wenn KI-Feedback automatisch freigegeben wird. Es werden nur Benutzer angezeigt, die die Berechtigung zur Bewertung in diesem Kurs haben.';
$string['backtocourse'] = 'Zurück zum Kurs';
$string['backtodiscussion'] = 'Zurück zur Diskussion';
$string['backup:includeai'] = 'KI-Forendaten in Sicherungen einschließen';
$string['cancel'] = 'Abbrechen';
$string['col_message'] = 'Nachricht';
$string['course'] = 'Kurs';
$string['coursename'] = 'Kurs';
$string['created'] = 'Erstellt';
$string['datacurso_custom'] = 'Datacurso Forum KI';
$string['default_reply_message'] = 'Antworte mit einem empathischen und motivierenden Ton';
$string['delayminutes'] = 'Wartezeit (Minuten)';
$string['delayminutes_help'] = 'Anzahl der Minuten, die nach dem Absenden durch den Teilnehmer gewartet werden soll, bevor die KI-Überprüfung ausgeführt wird.';
$string['discussion'] = 'Diskussion';
$string['discussion_label'] = 'Diskussion: {$a}';
$string['discussioninfo'] = 'Diskussionsinformationen';
$string['discussionmsg'] = 'KI-generierte Nachricht';
$string['discussionname'] = 'Thema';
$string['defaultenableai'] = 'KI aktivieren';
$string['defaultenableai_desc'] = 'Steuert die globale Verfügbarkeit von KI für Foren. Wenn deaktiviert, wird KI in allen bestehenden Foren ausgeschaltet und kann pro Forum erst wieder aktiviert werden, wenn die globale Option erneut eingeschaltet wird.';
$string['enableforumai'] = 'Forum-KI aktivieren';
$string['enableforumai_desc'] = 'Wenn deaktiviert, wird der Abschnitt "Datacurso Forum KI" in den Foren-Einstellungen ausgeblendet und die automatische Verarbeitung pausiert.';
$string['enabled'] = 'KI aktivieren';
$string['enablediainitconversation'] = 'KI-Antworten auf das Diskussionsthema aktivieren';
$string['enablediainitconversation_help'] = 'Wenn diese Option aktiviert ist, kann die KI auf die erste Nachricht antworten, die die Diskussion startet. Es wird außerdem empfohlen, im folgenden Feld die Rolle „Lehrer“ auszuwählen.';
$string['error_airequest'] = 'Fehler bei der Kommunikation mit dem KI-Dienst: {$a}';
$string['evaluatingwithai'] = 'Auswertung mit KI...';
$string['forum'] = 'Forum';
$string['forum_ai:approveresponses'] = 'KI-generierte Forenantworten genehmigen oder ablehnen';
$string['forum_ai:useaireview'] = 'Verwenden Sie die KI-Überprüfungsfunktion, um das Forum zu bewerten';
$string['forumname'] = 'Forum';
$string['grade'] = 'Bewertung';
$string['gradesappliedsuccessfully'] = 'Noten erfolgreich durch KI angewendet';
$string['historyresponses'] = 'KI-Forum Antwortverlauf';
$string['invalidaction'] = 'Die angegebene Aktion ist ungültig.';
$string['level'] = 'Stufe: {$a}';
$string['messageprovider:ai_approval_request'] = 'KI-Genehmigungsanfrage';
$string['modal_title'] = 'Details zum Diskussionsverlauf';
$string['modal_title_pending'] = 'Diskussionsdetails';
$string['no'] = 'Nein';
$string['no_posts'] = 'Keine Beiträge in dieser Diskussion gefunden.';
$string['nohistory'] = 'Kein Verlauf genehmigter oder abgelehnter KI-Antworten.';
$string['noresponses'] = 'Keine Antworten zur Genehmigung ausstehend.';
$string['notification_course_label'] = 'Kurs';
$string['notification_fullmessage'] = 'Hallo {$a->firstname},

Für die Diskussion "{$a->discussion}" im Forum "{$a->forum}" (Kurs: {$a->course}) wurde eine KI-generierte Antwort erstellt.

Vorschau: {$a->preview}...

Um die vollständige Nachricht zu überprüfen und zu entscheiden, ob sie genehmigt oder abgelehnt werden soll, besuche bitte:
{$a->reviewurl}

Schnellaktionen:
- Genehmigen: {$a->approveurl}
- Ablehnen: {$a->rejecturl}';
$string['notification_greeting'] = 'Hallo {$a->firstname},';
$string['notification_intro'] = 'Eine automatische Antwort wurde für die Diskussion "{$a->discussion}" im Forum "{$a->forum}" des Kurses "{$a->course}" generiert.';
$string['notification_preview'] = 'Vorschau:';
$string['notification_review_button'] = 'Antwort überprüfen';
$string['notification_smallmessage'] = 'Neue KI-Antwort ausstehend in "{$a->discussion}"';
$string['notification_subject'] = 'Genehmigung erforderlich: KI-Antwort';
$string['originalmessage'] = 'Ursprüngliche Nachricht';
$string['pendingresponses'] = 'Ausstehende KI-Forum-Antworten';
$string['pluginname'] = 'Forum KI';
$string['preview'] = 'KI-Nachricht';
$string['privacy:metadata:local_forum_ai_config'] = 'Speichert KI-Konfigurationen pro Forum.';
$string['privacy:metadata:local_forum_ai_config:enabled'] = 'Gibt an, ob KI für dieses Forum aktiviert ist.';
$string['privacy:metadata:local_forum_ai_config:forumid'] = 'Die ID des Forums, zu dem diese Konfiguration gehört.';
$string['privacy:metadata:local_forum_ai_config:reply_message'] = 'Antwortvorlage, die von der KI generiert wurde.';
$string['privacy:metadata:local_forum_ai_config:require_approval'] = 'Gibt an, ob KI-Antworten vor der Veröffentlichung genehmigt werden müssen.';
$string['privacy:metadata:local_forum_ai_config:timecreated'] = 'Erstellungsdatum der Konfiguration.';
$string['privacy:metadata:local_forum_ai_config:timemodified'] = 'Datum der letzten Änderung der Konfiguration.';
$string['privacy:metadata:local_forum_ai_pending'] = 'Von der Forum-KI gespeicherte Daten.';
$string['privacy:metadata:local_forum_ai_pending:approval_token'] = 'Genehmigungstoken für die Veröffentlichung.';
$string['privacy:metadata:local_forum_ai_pending:approved_at'] = 'Datum, an dem die Antwort genehmigt wurde.';
$string['privacy:metadata:local_forum_ai_pending:creator_userid'] = 'ID des Benutzers, der den Beitrag erstellt hat.';
$string['privacy:metadata:local_forum_ai_pending:discussionid'] = 'ID der zugehörigen Diskussion.';
$string['privacy:metadata:local_forum_ai_pending:forumid'] = 'ID des Forums, in dem die Antwort generiert wurde.';
$string['privacy:metadata:local_forum_ai_pending:message'] = 'Von der KI generierte Nachricht.';
$string['privacy:metadata:local_forum_ai_pending:status'] = 'Status des Beitrags (genehmigt, ausstehend oder abgelehnt).';
$string['privacy:metadata:local_forum_ai_pending:subject'] = 'Betreff oder Thema der Nachricht.';
$string['privacy:metadata:local_forum_ai_pending:timecreated'] = 'Datum, an dem der Datensatz erstellt wurde.';
$string['privacy:metadata:local_forum_ai_pending:timemodified'] = 'Datum, an dem der Datensatz aktualisiert wurde.';
$string['reject'] = 'Ablehnen';
$string['reply_message'] = 'Anweisungen an die KI geben';
$string['replylevel'] = 'Antwortstufe {$a}';
$string['require_approval'] = 'KI-Antwort überprüfen';
$string['response_approved'] = 'KI-Antwort erfolgreich genehmigt und veröffentlicht.';
$string['response_rejected'] = 'KI-Antwort abgelehnt.';
$string['response_update_failed'] = 'Antwort konnte nicht aktualisiert werden.';
$string['response_updated'] = 'Antwort erfolgreich aktualisiert.';
$string['reviewtitle'] = 'KI-Antwort überprüfen';
$string['save'] = 'Speichern';
$string['saveapprove'] = 'Speichern und genehmigen';
$string['settings'] = 'Einstellungen für: ';
$string['status'] = 'Status';
$string['statusapproved'] = 'Genehmigt';
$string['statuspending'] = 'Ausstehend';
$string['statusrejected'] = 'Abgelehnt';
$string['task_process_ai_queue'] = 'Verzögerte Warteschlange von Forum AI verarbeiten';
$string['task_process_single_forum_discussion'] = 'Ein einzelnes Diskussionsforum für KI verarbeiten';
$string['usedelay'] = 'Verzögerte Überprüfung verwenden';
$string['usedelay_help'] = 'Wenn aktiviert, wird die KI-Überprüfung nach einer konfigurierbaren Wartezeit ausgeführt, anstatt sofort zu starten.';
$string['username'] = 'Ersteller';
$string['viewdetails'] = 'Details';
$string['yes'] = 'Ja';
