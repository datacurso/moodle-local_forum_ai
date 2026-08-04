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

$string['action_failed'] = 'A ação não pôde ser processada.';
$string['actions'] = 'Ações';
$string['ai_response'] = 'Resposta de IA';
$string['ai_response_approved'] = 'Resposta de IA aprovada';
$string['ai_response_proposed'] = 'Resposta de IA proposta';
$string['ai_response_rejected'] = 'Resposta de IA rejeitada';
$string['ai_review_button'] = 'Revisar com IA';
$string['aiproposed'] = 'Resposta de IA proposta';
$string['allowedroles'] = 'Funções permitidas para respostas da IA';
$string['allowedroles_help'] = 'Selecione quais funções de usuário a IA pode responder. Se nenhuma for selecionada, a IA não responderá a nenhum usuário.';
$string['alreadysubmitted'] = 'Esta solicitação já foi aprovada, rejeitada ou não existe.';
$string['approve'] = 'Aprovar';
$string['autogradegrader'] = 'Usuário avaliador para aprovações automáticas';
$string['autogradegrader_help'] = 'Selecione o usuário que será registrado como avaliador quando o feedback da IA for aprovado automaticamente. Apenas usuários com permissão para avaliar neste curso são listados.';
$string['backtocourse'] = 'Voltar ao curso';
$string['backtodiscussion'] = 'Voltar à discussão';
$string['backup:includeai'] = 'Incluir dados do fórum de IA nos backups';
$string['cancel'] = 'Cancelar';
$string['col_message'] = 'Mensagem';
$string['course'] = 'Curso';
$string['coursename'] = 'Curso';
$string['created'] = 'Criado';
$string['datacurso_custom'] = 'Fórum de IA Datacurso';
$string['default_reply_message'] = 'Responda com um tom empático e motivador';
$string['delayminutes'] = 'Tempo de espera (minutos)';
$string['delayminutes_help'] = 'Quantidade de minutos que deve ser aguardada após o aluno enviar antes de executar a revisão com IA.';
$string['discussion'] = 'Discussão';
$string['discussion_label'] = 'Discussão: {$a}';
$string['discussioninfo'] = 'Informações da discussão';
$string['discussionmsg'] = 'Mensagem gerada por IA';
$string['discussionname'] = 'Assunto';
$string['defaultenableai'] = 'Habilitar IA';
$string['defaultenableai_desc'] = 'Controla a disponibilidade global da IA nos fóruns. Se desabilitada, a IA é desativada em todos os fóruns existentes e não pode ser ativada por fórum até ser reativada globalmente.';
$string['enableforumai'] = 'Habilitar Fórum IA';
$string['enableforumai_desc'] = 'Se desabilitado, a seção "Datacurso Fórum IA" é ocultada nas configurações da atividade fórum e o processamento automático é pausado.';
$string['enabled'] = 'Ativar IA';
$string['enablediainitconversation'] = 'Ativar resposta de IA ao tópico de discussão';
$string['enablediainitconversation_help'] = 'Ativar esta opção permitirá que a IA responda à mensagem inicial que inicia a discussão. Também é recomendável selecionar o papel de Professor no campo a seguir.';
$string['error_airequest'] = 'Erro ao comunicar-se com o serviço de IA: {$a}';
$string['evaluatingwithai'] = 'Avaliando com IA...';
$string['forum'] = 'Fórum';
$string['forum_ai:approveresponses'] = 'Aprovar ou rejeitar respostas do fórum geradas por IA';
$string['forum_ai:useaireview'] = 'Utilize a função de revisão por IA para avaliar o fórum';
$string['forumname'] = 'Fórum';
$string['grade'] = 'Classificação';
$string['gradesappliedsuccessfully'] = 'Notas aplicadas com sucesso por IA';
$string['historyresponses'] = 'Histórico de respostas do Fórum de IA';
$string['invalidaction'] = 'A ação especificada é inválida.';
$string['level'] = 'Nível: {$a}';
$string['messageprovider:ai_approval_request'] = 'Solicitação de aprovação de IA';
$string['modal_title'] = 'Detalhes do histórico da discussão';
$string['modal_title_pending'] = 'Detalhes da discussão';
$string['no'] = 'Não';
$string['no_posts'] = 'Nenhuma postagem encontrada nesta discussão.';
$string['nohistory'] = 'Nenhum histórico de respostas de IA aprovadas ou rejeitadas.';
$string['noresponses'] = 'Nenhuma resposta pendente de aprovação.';
$string['notification_course_label'] = 'Curso';
$string['notification_fullmessage'] = 'Olá {$a->firstname},

Uma resposta gerada por IA foi criada para a discussão "{$a->discussion}" no fórum "{$a->forum}" (Curso: {$a->course}).

Pré-visualização: {$a->preview}...

Para revisar a mensagem completa e decidir se deve aprová-la ou rejeitá-la, por favor visite:
{$a->reviewurl}

Ações rápidas:
- Aprovar: {$a->approveurl}
- Rejeitar: {$a->rejecturl}';
$string['notification_greeting'] = 'Olá {$a->firstname},';
$string['notification_intro'] = 'Uma resposta automática foi gerada para a discussão "{$a->discussion}" no fórum "{$a->forum}" do curso "{$a->course}".';
$string['notification_preview'] = 'Pré-visualização:';
$string['notification_review_button'] = 'Revisar resposta';
$string['notification_smallmessage'] = 'Nova resposta de IA pendente em "{$a->discussion}"';
$string['notification_subject'] = 'Aprovação necessária: Resposta de IA';
$string['originalmessage'] = 'Mensagem original';
$string['pendingresponses'] = 'Respostas pendentes do Fórum de IA';
$string['pluginname'] = 'Fórum de IA';
$string['preview'] = 'Mensagem de IA';
$string['privacy:metadata:local_forum_ai_config'] = 'Armazena configurações de IA por fórum.';
$string['privacy:metadata:local_forum_ai_config:enabled'] = 'Indica se a IA está habilitada para este fórum.';
$string['privacy:metadata:local_forum_ai_config:forumid'] = 'ID do fórum ao qual esta configuração pertence.';
$string['privacy:metadata:local_forum_ai_config:reply_message'] = 'Modelo de resposta gerado pela IA.';
$string['privacy:metadata:local_forum_ai_config:require_approval'] = 'Indica se as respostas de IA requerem aprovação antes de serem publicadas.';
$string['privacy:metadata:local_forum_ai_config:timecreated'] = 'Data de criação da configuração.';
$string['privacy:metadata:local_forum_ai_config:timemodified'] = 'Data da última modificação da configuração.';
$string['privacy:metadata:local_forum_ai_pending'] = 'Dados armazenados pelo plugin Fórum de IA.';
$string['privacy:metadata:local_forum_ai_pending:approval_token'] = 'Token de aprovação vinculado à publicação.';
$string['privacy:metadata:local_forum_ai_pending:approved_at'] = 'Data em que a resposta foi aprovada.';
$string['privacy:metadata:local_forum_ai_pending:creator_userid'] = 'ID do usuário que criou a publicação.';
$string['privacy:metadata:local_forum_ai_pending:discussionid'] = 'ID da discussão relacionada.';
$string['privacy:metadata:local_forum_ai_pending:forumid'] = 'ID do fórum onde a resposta foi gerada.';
$string['privacy:metadata:local_forum_ai_pending:message'] = 'Mensagem gerada pela inteligência artificial.';
$string['privacy:metadata:local_forum_ai_pending:status'] = 'Status da publicação (aprovada, pendente ou rejeitada).';
$string['privacy:metadata:local_forum_ai_pending:subject'] = 'Assunto ou tema da mensagem.';
$string['privacy:metadata:local_forum_ai_pending:timecreated'] = 'Data em que o registro foi criado.';
$string['privacy:metadata:local_forum_ai_pending:timemodified'] = 'Data em que o registro foi atualizado.';
$string['reject'] = 'Rejeitar';
$string['reply_message'] = 'Dê instruções à IA';
$string['replylevel'] = 'Nível de resposta {$a}';
$string['require_approval'] = 'Revisar resposta de IA';
$string['response_approved'] = 'Resposta de IA aprovada e publicada com sucesso.';
$string['response_rejected'] = 'Resposta de IA rejeitada.';
$string['response_update_failed'] = 'Não foi possível atualizar a resposta.';
$string['response_updated'] = 'Resposta atualizada com sucesso.';
$string['reviewtitle'] = 'Revisar resposta de IA';
$string['save'] = 'Salvar';
$string['saveapprove'] = 'Salvar e aprovar';
$string['settings'] = 'Configurações para: ';
$string['status'] = 'Status';
$string['statusapproved'] = 'Aprovado';
$string['statuspending'] = 'Pendente';
$string['statusrejected'] = 'Rejeitado';
$string['task_process_ai_queue'] = 'Processar fila adiada do Forum AI';
$string['task_process_single_forum_discussion'] = 'Processar um único fórum de discussão para IA';
$string['usedelay'] = 'Usar revisão atrasada';
$string['usedelay_help'] = 'Se ativado, a revisão com IA será executada após um tempo de espera configurável, em vez de ser executada imediatamente.';
$string['username'] = 'Criador';
$string['viewdetails'] = 'Detalhes';
$string['yes'] = 'Sim';
