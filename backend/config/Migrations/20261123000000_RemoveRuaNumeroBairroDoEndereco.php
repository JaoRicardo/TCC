<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * O endereço do cadastro fica só com cidade e CEP (decisão do usuário,
 * 2026-11-22): são os dados que dá para conferir, e a cidade é o que decide se
 * a pessoa concorre ao sorteio (Primavera do Leste ou Poxoréu). Rua, número e
 * bairro saem de todas as telas; se a organização precisar deles para um
 * acampamento, pergunta no formulário.
 */
class RemoveRuaNumeroBairroDoEndereco extends BaseMigration
{
    public function up(): void
    {
        $this->table('addresses')
            ->removeColumn('street')
            ->removeColumn('number')
            ->removeColumn('neighborhood')
            ->update();
    }

    public function down(): void
    {
        $this->table('addresses')
            ->addColumn('street', 'string', ['limit' => 255, 'null' => true, 'default' => null, 'after' => 'id'])
            ->addColumn('number', 'string', ['limit' => 50, 'null' => true, 'default' => null, 'after' => 'street'])
            ->addColumn('neighborhood', 'string', ['limit' => 255, 'null' => true, 'default' => null, 'after' => 'number'])
            ->update();
    }
}
