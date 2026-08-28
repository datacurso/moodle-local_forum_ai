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
$string['ai_response_expired'] = 'Réponse IA (expirée)';
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
$string['default_replyinlocked'] = 'Répondre dans les discussions verrouillées';
$string['default_replyinlocked_desc'] = 'Si activé, l’IA générera et publiera des réponses dans les discussions verrouillées. Chaque forum peut remplacer cette valeur dans ses propres paramètres.';
$string['defaultenableai'] = 'Activer l’IA';
$string['defaultenableai_desc'] = 'Contrôle la disponibilité globale de l’IA pour les forums. Si désactivée, l’IA est coupée pour tous les forums existants et ne peut pas être réactivée par forum tant qu’elle n’est pas réactivée globalement.';
$string['delayminutes'] = 'Délai d’attente (minutes)';
$string['delayminutes_help'] = 'Nombre de minutes à attendre après la publication de l’étudiant avant d’exécuter la révision par IA.';
$string['discussion'] = 'Discussion';
$string['discussion_label'] = 'Discussion : {$a}';
$string['discussioninfo'] = 'Informations sur la discussion';
$string['discussionmsg'] = 'Message généré par l’IA';
$string['discussionname'] = 'Sujet';
$string['enabled'] = 'Activer l’IA';
$string['enablediainitconversation'] = 'Activer la réponse de l’IA au sujet de discussion';
$string['enablediainitconversation_help'] = 'En activant cette option, l’IA pourra répondre au message initial qui lance la discussion. Il est également recommandé de sélectionner le rôle Enseignant dans le champ suivant.';
$string['enableforumai'] = 'Activer Forum IA';
$string['enableforumai_desc'] = 'Si désactivé, la section "Datacurso Forum IA" est masquée dans les paramètres de l’activité forum et le traitement automatique est mis en pause.';
$string['error_airequest'] = 'La réponse de l\'IA n\'a pas pu être chargée. Veuillez réessayer plus tard ou contacter votre administrateur.';
$string['error_discussionlocked'] = 'Cette discussion est verrouillée, la réponse de l’IA ne peut donc pas être publiée. Déverrouillez la discussion et réessayez.';
$string['error_forumclosed'] = 'La date limite de ce forum est dépassée, la réponse de l’IA ne peut donc pas être publiée.';
$string['error_invalidgrade'] = 'The AI grade could not be resolved to a valid forum grade.';
$string['error_privatereply'] = 'Le message auquel il est répondu est une réponse privée, une réponse de l’IA ne peut donc pas être publiée.';
$string['error_responsenotpending'] = 'Cette réponse a déjà été approuvée ou rejetée et ne peut plus être modifiée.';
$string['error_usernotincourse'] = 'L’utilisateur sélectionné n’est pas inscrit à ce cours.';
$string['error_usernotingroup'] = 'Vous ne pouvez pas demander une révision par l’IA pour un utilisateur en dehors de vos groupes.';
$string['evaluatingwithai'] = 'Évaluation avec l’IA...';
$string['eventaireviewrequested'] = 'Révision par l’IA demandée';
$string['eventresponseapproved'] = 'Réponse IA approuvée';
$string['eventresponserejected'] = 'Réponse IA rejetée';
$string['eventresponseupdated'] = 'Réponse IA modifiée';
$string['forum'] = 'Forum';
$string['forum_ai:approveresponses'] = 'Approuver ou rejeter les réponses générées par l’IA dans le forum';
$string['forum_ai:useaireview'] = 'Utilisez la fonction de révision par IA pour évaluer le forum';
$string['forumname'] = 'Forum';
$string['grade'] = 'Note';
$string['gradesappliedsuccessfully'] = 'Notes appliquées avec succès par l’IA';
$string['historyresponses'] = 'Historique des réponses Forum IA';
$string['invalidaction'] = 'L’action indiquée n’est pas valide.';
$string['level'] = 'Niveau : {$a}';
$string['managedby'] = 'Géré par';
$string['messageprovider:ai_approval_request'] = 'Demande d’approbation IA';
$string['modal_title'] = 'Détails de l’historique de la discussion';
$string['modal_title_pending'] = 'Détails de la discussion';
$string['no'] = 'Non';
$string['no_posts'] = 'Aucun message trouvé dans cette discussion.';
$string['nohistory'] = 'Aucun historique de réponses IA approuvées, rejetées ou expirées.';
$string['noresponses'] = 'Aucune réponse en attente d’approbation.';
$string['notification_course_label'] = 'Cours';
$string['notification_fullmessage'] = 'Bonjour {$a->firstname},

Une réponse générée par IA a été créée pour la discussion "{$a->discussion}" dans le forum "{$a->forum}" (Cours : {$a->course}).

Aperçu : {$a->preview}...

Pour consulter le message complet et décider de l’approuver ou de le rejeter, veuillez visiter :
{$a->reviewurl}';
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
$string['privacy:metadata:datacurso_ai'] = 'Le contenu des messages du forum est envoyé au service d’IA externe de Datacurso pour générer des réponses et des évaluations.';
$string['privacy:metadata:datacurso_ai:author_name'] = 'Nom complet de l’auteur du message inclus dans la requête à l’IA.';
$string['privacy:metadata:datacurso_ai:course_activity'] = 'Noms du cours, du forum et de la discussion qui contextualisent la requête à l’IA.';
$string['privacy:metadata:datacurso_ai:post_content'] = 'Texte des messages du forum envoyés au service d’IA.';
$string['privacy:metadata:datacurso_ai:thread_history'] = 'Messages précédents de la discussion envoyés comme contexte de conversation.';
$string['privacy:metadata:datacurso_ai:userid'] = 'ID de l’utilisateur au nom duquel la requête à l’IA est effectuée.';
$string['privacy:metadata:local_forum_ai_config'] = 'Stocke les configurations IA par forum.';
$string['privacy:metadata:local_forum_ai_config:allowedroles'] = 'Liste, séparée par des virgules, des ID de rôles auxquels l’IA peut répondre.';
$string['privacy:metadata:local_forum_ai_config:delayminutes'] = 'Nombre de minutes d’attente avant l’exécution de la révision différée par IA.';
$string['privacy:metadata:local_forum_ai_config:enabled'] = 'Indique si l’IA est activée pour ce forum.';
$string['privacy:metadata:local_forum_ai_config:enablediainitconversation'] = 'Indique si l’IA répond au message initial de la discussion.';
$string['privacy:metadata:local_forum_ai_config:forumid'] = 'ID du forum correspondant à cette configuration.';
$string['privacy:metadata:local_forum_ai_config:graderid'] = 'ID de l’utilisateur enregistré comme évaluateur pour les validations automatiques.';
$string['privacy:metadata:local_forum_ai_config:questionturns'] = 'Nombre maximal de réponses de l’IA avec question guide autorisées par fil de réponses.';
$string['privacy:metadata:local_forum_ai_config:reply_message'] = 'Modèle de réponse généré par l’IA.';
$string['privacy:metadata:local_forum_ai_config:replyinlocked'] = 'Indique si l’IA peut répondre dans les discussions verrouillées de ce forum.';
$string['privacy:metadata:local_forum_ai_config:require_approval'] = 'Indique si les réponses IA nécessitent une approbation avant publication.';
$string['privacy:metadata:local_forum_ai_config:timecreated'] = 'Date de création de la configuration.';
$string['privacy:metadata:local_forum_ai_config:timemodified'] = 'Date de dernière modification de la configuration.';
$string['privacy:metadata:local_forum_ai_config:usedelay'] = 'Indique si la révision par IA s’exécute après un délai configurable.';
$string['privacy:metadata:local_forum_ai_pending'] = 'Données stockées par le plugin Forum IA.';
$string['privacy:metadata:local_forum_ai_pending:action_userid'] = 'ID de l’utilisateur ayant approuvé ou rejeté la réponse.';
$string['privacy:metadata:local_forum_ai_pending:approval_token'] = 'Jeton d’approbation lié à la publication.';
$string['privacy:metadata:local_forum_ai_pending:approved_at'] = 'Date d’approbation de la réponse.';
$string['privacy:metadata:local_forum_ai_pending:creator_userid'] = 'ID de l’utilisateur ayant créé la publication.';
$string['privacy:metadata:local_forum_ai_pending:discussionid'] = 'ID de la discussion liée.';
$string['privacy:metadata:local_forum_ai_pending:forumid'] = 'ID du forum où la réponse a été générée.';
$string['privacy:metadata:local_forum_ai_pending:grade'] = 'Note proposée par l’IA pour le message évalué.';
$string['privacy:metadata:local_forum_ai_pending:message'] = 'Message généré par l’intelligence artificielle.';
$string['privacy:metadata:local_forum_ai_pending:parentpostid'] = 'ID du message du forum auquel la réponse de l’IA répond.';
$string['privacy:metadata:local_forum_ai_pending:postid'] = 'ID du message du forum publié à partir de cette réponse de l’IA.';
$string['privacy:metadata:local_forum_ai_pending:status'] = 'Statut de la publication (approuvée, en attente ou rejetée).';
$string['privacy:metadata:local_forum_ai_pending:subject'] = 'Sujet du message.';
$string['privacy:metadata:local_forum_ai_pending:timecreated'] = 'Date de création de l’enregistrement.';
$string['privacy:metadata:local_forum_ai_pending:timemodified'] = 'Date de mise à jour de l’enregistrement.';
$string['privacy:metadata:local_forum_ai_queue'] = 'File d’attente des demandes de traitement différé par IA.';
$string['privacy:metadata:local_forum_ai_queue:payload'] = 'Charge JSON contenant le module de cours et le message ou la discussion à traiter.';
$string['privacy:metadata:local_forum_ai_queue:processed'] = 'Indique si la demande en file d’attente a été traitée.';
$string['privacy:metadata:local_forum_ai_queue:timecreated'] = 'Date de création de la demande en file d’attente.';
$string['privacy:metadata:local_forum_ai_queue:timetoprocess'] = 'Date à laquelle la demande en file d’attente doit être traitée.';
$string['privacy:metadata:local_forum_ai_queue:type'] = 'Type de demande en file d’attente (message ou discussion).';
$string['questionturns'] = 'Réponses de l’IA avec question guide (par fil de réponses)';
$string['questionturns_help'] = 'Choisissez combien de réponses de l’IA dans le même fil de réponses doivent inclure un retour et une question guide. Une fois cette limite atteinte, l’IA continuera avec un retour uniquement. Utilisez 0 pour désactiver les questions guides.';
$string['reject'] = 'Rejeter';
$string['reply_message'] = 'Donner des instructions à l’IA';
$string['replyinlocked'] = 'Répondre dans les discussions verrouillées';
$string['replyinlocked_help'] = 'Choisissez si l’IA doit générer et publier des réponses lorsque la discussion est verrouillée. Si la valeur est Non, l’IA ignore les discussions verrouillées et les réponses en attente ne peuvent pas être approuvées tant que la discussion reste verrouillée.';
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
$string['statusexpired'] = 'Expiré';
$string['statuspending'] = 'En attente';
$string['statusrejected'] = 'Rejeté';
$string['task_process_ai_queue'] = 'Traiter la file d’attente différée de Forum AI';
$string['task_process_single_forum_discussion'] = 'Traiter un seul forum de discussion pour l\'IA';
$string['usedelay'] = 'Utiliser une révision différée';
$string['usedelay_help'] = 'Si activé, la révision par IA sera exécutée après un délai configurable au lieu d’être exécutée immédiatement.';
$string['username'] = 'Créateur';
$string['viewdetails'] = 'Détails';
$string['yes'] = 'Oui';
