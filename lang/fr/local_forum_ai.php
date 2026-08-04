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

$string['action_failed'] = 'L’action n’a pas pu être traitée.';
$string['actions'] = 'Actions';
$string['ai_response'] = 'Réponse IA';
$string['ai_response_approved'] = 'Réponse IA approuvée';
$string['ai_response_proposed'] = 'Réponse IA proposée';
$string['ai_response_rejected'] = 'Réponse IA rejetée';
$string['ai_review_button'] = 'Réviser avec l\'IA';
$string['aiproposed'] = 'Réponse IA proposée';
$string['allowedroles'] = 'Rôles autorisés pour les réponses de l’IA';
$string['allowedroles_help'] = 'Sélectionnez les rôles d’utilisateurs auxquels l’IA est autorisée à répondre. Si aucun n’est sélectionné, l’IA ne répondra à aucun utilisateur.';
$string['alreadysubmitted'] = 'Cette demande a déjà été approuvée, rejetée ou n’existe pas.';
$string['approve'] = 'Approuver';
$string['autogradegrader'] = 'Utilisateur évaluateur pour les validations automatiques';
$string['autogradegrader_help'] = 'Sélectionne l’utilisateur qui sera enregistré comme évaluateur lorsque le retour de l’IA est approuvé automatiquement. Seuls les utilisateurs disposant des permissions d’évaluation dans ce cours sont listés.';
$string['backtocourse'] = 'Retour au cours';
$string['backtodiscussion'] = 'Retour à la discussion';
$string['backup:includeai'] = 'Inclure les données du forum IA dans les sauvegardes';
$string['cancel'] = 'Annuler';
$string['col_message'] = 'Message';
$string['course'] = 'Cours';
$string['coursename'] = 'Cours';
$string['created'] = 'Créé';
$string['datacurso_custom'] = 'Forum IA Datacurso';
$string['default_reply_message'] = 'Réponds avec un ton empathique et motivant';
$string['delayminutes'] = 'Délai d’attente (minutes)';
$string['delayminutes_help'] = 'Nombre de minutes à attendre après la publication de l’étudiant avant d’exécuter la révision par IA.';
$string['discussion'] = 'Discussion';
$string['discussion_label'] = 'Discussion : {$a}';
$string['discussioninfo'] = 'Informations sur la discussion';
$string['discussionmsg'] = 'Message généré par l’IA';
$string['discussionname'] = 'Sujet';
$string['defaultenableai'] = 'Activer l’IA';
$string['defaultenableai_desc'] = 'Contrôle la disponibilité globale de l’IA pour les forums. Si désactivée, l’IA est coupée pour tous les forums existants et ne peut pas être réactivée par forum tant qu’elle n’est pas réactivée globalement.';
$string['enableforumai'] = 'Activer Forum IA';
$string['enableforumai_desc'] = 'Si désactivé, la section "Datacurso Forum IA" est masquée dans les paramètres de l’activité forum et le traitement automatique est mis en pause.';
$string['enabled'] = 'Activer l’IA';
$string['enablediainitconversation'] = 'Activer la réponse de l’IA au sujet de discussion';
$string['enablediainitconversation_help'] = 'En activant cette option, l’IA pourra répondre au message initial qui lance la discussion. Il est également recommandé de sélectionner le rôle Enseignant dans le champ suivant.';
$string['error_airequest'] = 'Erreur de communication avec le service IA : {$a}';
$string['evaluatingwithai'] = 'Évaluation avec l’IA...';
$string['forum'] = 'Forum';
$string['forum_ai:approveresponses'] = 'Approuver ou rejeter les réponses générées par l’IA dans le forum';
$string['forum_ai:useaireview'] = 'Utilisez la fonction de révision par IA pour évaluer le forum';
$string['forumname'] = 'Forum';
$string['grade'] = 'Note';
$string['gradesappliedsuccessfully'] = 'Notes appliquées avec succès par l’IA';
$string['historyresponses'] = 'Historique des réponses Forum IA';
$string['invalidaction'] = 'L’action indiquée n’est pas valide.';
$string['level'] = 'Niveau : {$a}';
$string['messageprovider:ai_approval_request'] = 'Demande d’approbation IA';
$string['modal_title'] = 'Détails de l’historique de la discussion';
$string['modal_title_pending'] = 'Détails de la discussion';
$string['no'] = 'Non';
$string['no_posts'] = 'Aucun message trouvé dans cette discussion.';
$string['nohistory'] = 'Aucun historique de réponses IA approuvées ou rejetées.';
$string['noresponses'] = 'Aucune réponse en attente d’approbation.';
$string['notification_course_label'] = 'Cours';
$string['notification_fullmessage'] = 'Bonjour {$a->firstname},

Une réponse générée par IA a été créée pour la discussion "{$a->discussion}" dans le forum "{$a->forum}" (Cours : {$a->course}).

Aperçu : {$a->preview}...

Pour consulter le message complet et décider de l’approuver ou de le rejeter, veuillez visiter :
{$a->reviewurl}

Actions rapides :
- Approuver : {$a->approveurl}
- Rejeter : {$a->rejecturl}';
$string['notification_greeting'] = 'Bonjour {$a->firstname},';
$string['notification_intro'] = 'Une réponse automatique a été générée pour la discussion "{$a->discussion}" dans le forum "{$a->forum}" du cours "{$a->course}".';
$string['notification_preview'] = 'Aperçu :';
$string['notification_review_button'] = 'Examiner la réponse';
$string['notification_smallmessage'] = 'Nouvelle réponse IA en attente dans "{$a->discussion}"';
$string['notification_subject'] = 'Approbation requise : Réponse IA';
$string['originalmessage'] = 'Message original';
$string['pendingresponses'] = 'Réponses Forum IA en attente';
$string['pluginname'] = 'Forum IA';
$string['preview'] = 'Message IA';
$string['privacy:metadata:local_forum_ai_config'] = 'Stocke les configurations IA par forum.';
$string['privacy:metadata:local_forum_ai_config:enabled'] = 'Indique si l’IA est activée pour ce forum.';
$string['privacy:metadata:local_forum_ai_config:forumid'] = 'ID du forum correspondant à cette configuration.';
$string['privacy:metadata:local_forum_ai_config:reply_message'] = 'Modèle de réponse généré par l’IA.';
$string['privacy:metadata:local_forum_ai_config:require_approval'] = 'Indique si les réponses IA nécessitent une approbation avant publication.';
$string['privacy:metadata:local_forum_ai_config:timecreated'] = 'Date de création de la configuration.';
$string['privacy:metadata:local_forum_ai_config:timemodified'] = 'Date de dernière modification de la configuration.';
$string['privacy:metadata:local_forum_ai_pending'] = 'Données stockées par le plugin Forum IA.';
$string['privacy:metadata:local_forum_ai_pending:approval_token'] = 'Jeton d’approbation lié à la publication.';
$string['privacy:metadata:local_forum_ai_pending:approved_at'] = 'Date d’approbation de la réponse.';
$string['privacy:metadata:local_forum_ai_pending:creator_userid'] = 'ID de l’utilisateur ayant créé la publication.';
$string['privacy:metadata:local_forum_ai_pending:discussionid'] = 'ID de la discussion liée.';
$string['privacy:metadata:local_forum_ai_pending:forumid'] = 'ID du forum où la réponse a été générée.';
$string['privacy:metadata:local_forum_ai_pending:message'] = 'Message généré par l’intelligence artificielle.';
$string['privacy:metadata:local_forum_ai_pending:status'] = 'Statut de la publication (approuvée, en attente ou rejetée).';
$string['privacy:metadata:local_forum_ai_pending:subject'] = 'Sujet du message.';
$string['privacy:metadata:local_forum_ai_pending:timecreated'] = 'Date de création de l’enregistrement.';
$string['privacy:metadata:local_forum_ai_pending:timemodified'] = 'Date de mise à jour de l’enregistrement.';
$string['reject'] = 'Rejeter';
$string['reply_message'] = 'Donner des instructions à l’IA';
$string['replylevel'] = 'Niveau de réponse {$a}';
$string['require_approval'] = 'Examiner la réponse IA';
$string['response_approved'] = 'Réponse IA approuvée et publiée avec succès.';
$string['response_rejected'] = 'Réponse IA rejetée.';
$string['response_update_failed'] = 'La réponse n’a pas pu être mise à jour.';
$string['response_updated'] = 'Réponse mise à jour avec succès.';
$string['reviewtitle'] = 'Examiner la réponse IA';
$string['save'] = 'Enregistrer';
$string['saveapprove'] = 'Enregistrer et approuver';
$string['settings'] = 'Paramètres pour : ';
$string['status'] = 'Statut';
$string['statusapproved'] = 'Approuvé';
$string['statuspending'] = 'En attente';
$string['statusrejected'] = 'Rejeté';
$string['task_process_ai_queue'] = 'Traiter la file d’attente différée de Forum AI';
$string['task_process_single_forum_discussion'] = 'Traiter un seul forum de discussion pour l\'IA';
$string['usedelay'] = 'Utiliser une révision différée';
$string['usedelay_help'] = 'Si activé, la révision par IA sera exécutée après un délai configurable au lieu d’être exécutée immédiatement.';
$string['username'] = 'Créateur';
$string['viewdetails'] = 'Détails';
$string['yes'] = 'Oui';
