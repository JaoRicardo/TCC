<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * "Texto curto" e "Texto longo" viram um tipo só, "texto" (caixa grande, até
 * 2000 caracteres): quem monta o formulário não precisa mais escolher o tamanho.
 */
class MergeTextQuestionTypes extends BaseMigration
{
    public function up(): void
    {
        $this->execute("UPDATE questions SET type = 'texto' WHERE type IN ('texto_curto', 'texto_longo')");
    }

    public function down(): void
    {
        // Não há como saber qual era qual; todas voltam como texto longo (o mesmo tamanho de agora).
        $this->execute("UPDATE questions SET type = 'texto_longo' WHERE type = 'texto'");
    }
}
