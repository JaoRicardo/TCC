<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * O documento de identidade vira uma pergunta comum, do tipo "arquivo": a
 * organização decide em cada formulário o que pedir (documento, receita…),
 * e o arquivo enviado fica ligado à inscrição e à pergunta.
 *
 * - user_documents.question_id: a pergunta "arquivo" que o arquivo responde.
 *   A resposta (answers.answer) guarda o id do arquivo.
 * - Todo formulário que já tem perguntas ganha "Documento de identidade"
 *   (arquivo, obrigatória) na primeira posição da primeira seção. A
 *   organização pode editar ou excluir como qualquer pergunta.
 * - Os documentos já enviados com os formulários passam a ser a resposta dessa
 *   pergunta, para continuarem aparecendo na aba Respostas.
 * - Sai a permissão separada "documentos": quem vê as respostas vê os arquivos.
 */
class AddArquivoQuestions extends BaseMigration
{
    public function up(): void
    {
        $this->table('user_documents')
            ->addColumn('question_id', 'biginteger', ['null' => true, 'default' => null, 'signed' => false, 'after' => 'pre_registration_id'])
            ->addIndex(['question_id'])
            ->addForeignKey('question_id', 'questions', 'id', ['delete' => 'NO_ACTION', 'update' => 'NO_ACTION'])
            ->update();

        $formularios = $this->fetchAll(
            'SELECT DISTINCT category_id, subscription_type, event_form_id FROM questions WHERE deleted IS NULL'
        );
        foreach ($formularios as $f) {
            if (!in_array($f['subscription_type'], ['Campista', 'Servo', 'Participante'], true)) continue;

            $doFormulario = $f['event_form_id'] !== null
                ? 'q.event_form_id = ' . (int)$f['event_form_id']
                : 'q.event_form_id IS NULL AND q.category_id = ' . (int)$f['category_id']
                    . " AND q.subscription_type = '{$f['subscription_type']}'";

            $primeira = $this->fetchRow(
                "SELECT q.section_id, MIN(q.`order`) AS ordem FROM questions q JOIN sections s ON s.id = q.section_id
                 WHERE {$doFormulario} AND q.deleted IS NULL
                 GROUP BY q.section_id, s.`order`, s.id ORDER BY s.`order`, s.id LIMIT 1"
            );
            if (!$primeira) continue;
            $secao = (int)$primeira['section_id'];
            $ordem = (int)$primeira['ordem'];

            $this->execute(
                "UPDATE questions q SET q.`order` = q.`order` + 1 WHERE {$doFormulario} AND q.section_id = {$secao}"
            );
            $this->execute(
                'INSERT INTO questions (category_id, event_form_id, subscription_type, text, `order`, type, is_required,
                    accept_generic_answer, section_id, created, modified)
                 VALUES (' . ($f['category_id'] === null ? 'NULL' : (int)$f['category_id']) . ', '
                    . ($f['event_form_id'] === null ? 'NULL' : (int)$f['event_form_id'])
                    . ", '{$f['subscription_type']}', 'Documento de identidade', {$ordem}, 'arquivo', 1, 0, {$secao}, NOW(), NOW())"
            );
            $perguntaId = (int)$this->fetchRow('SELECT LAST_INSERT_ID() AS id')['id'];

            $daAtividade = $f['event_form_id'] !== null
                ? 'a.camping_id IS NULL AND a.event_form_id = ' . (int)$f['event_form_id']
                : 'a.camping_id IS NOT NULL AND a.category_id = ' . (int)$f['category_id']
                    . " AND pr.subscription_type = '{$f['subscription_type']}'";
            $this->execute(
                "UPDATE user_documents d
                 JOIN pre_registrations pr ON pr.id = d.pre_registration_id
                 JOIN activities a ON a.id = pr.activity_id
                 SET d.question_id = {$perguntaId}
                 WHERE d.question_id IS NULL AND {$daAtividade}"
            );
            $this->execute(
                "INSERT INTO answers (pre_registration_id, question_id, answer, created, modified)
                 SELECT d.pre_registration_id, {$perguntaId}, d.id, d.created, d.created
                 FROM user_documents d WHERE d.question_id = {$perguntaId} AND d.deleted IS NULL"
            );
        }

        $this->execute("DELETE FROM user_permissions WHERE permission = 'documentos'");
    }

    public function down(): void
    {
        $this->execute(
            "DELETE a FROM answers a JOIN questions q ON q.id = a.question_id WHERE q.type = 'arquivo'"
        );
        $this->execute('UPDATE user_documents SET question_id = NULL');
        $this->execute(
            "DELETE FROM questions WHERE type = 'arquivo' AND text = 'Documento de identidade'"
        );
        $this->table('user_documents')
            ->dropForeignKey('question_id')
            ->removeIndex(['question_id'])
            ->removeColumn('question_id')
            ->update();
    }
}
