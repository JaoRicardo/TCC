<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class UsersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('users');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('MaritalStatuses', [
            'foreignKey' => 'marital_status_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->notEmptyString('cpf', 'CPF é obrigatório')
            ->add('cpf', 'validCpf', [
                'rule' => [$this, 'isValidCpf'],
                'message' => 'CPF inválido',
            ])
            ->add('cpf', 'unique', [
                'rule' => [$this, 'isUniqueCpf'],
                'message' => 'CPF já cadastrado',
            ]);

        $validator
            ->notEmptyString('name', 'Nome é obrigatório')
            ->maxLength('name', 255);

        $validator
            ->notEmptyString('password', 'Senha é obrigatória')
            ->minLength('password', 6, 'Senha deve ter no mínimo 6 caracteres');

        $validator
            ->notEmptyString('email', 'E-mail é obrigatório')
            ->email('email', false, 'E-mail inválido');

        $validator
            ->notEmptyString('phone', 'Telefone é obrigatório');

        $validator
            ->notEmptyDate('birthday', 'Data de nascimento é obrigatória')
            ->add('birthday', 'notFuture', [
                'rule' => function ($value) {
                    try {
                        $date = is_string($value) ? new \DateTime($value) : $value;
                        return $date <= new \DateTime('today');
                    } catch (\Exception $e) {
                        return false;
                    }
                },
                'message' => 'Data de nascimento não pode ser no futuro',
            ]);

        $validator
            ->notEmptyString('sex', 'Sexo é obrigatório')
            ->inList('sex', ['M', 'F'], 'Sexo deve ser M ou F');

        $validator
            ->requirePresence('marital_status_id', 'create', 'Estado civil é obrigatório')
            ->notEmptyString('marital_status_id', 'Estado civil é obrigatório')
            ->integer('marital_status_id', 'Estado civil inválido')
            ->greaterThan('marital_status_id', 0, 'Estado civil inválido');

        return $validator;
    }

    public function isValidCpf(mixed $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', (string)$cpf);

        if (strlen($cpf) !== 11 || preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int)$cpf[$i] * ($t + 1 - $i);
            }
            $rem = (10 * $sum) % 11;
            if ($rem >= 10) {
                $rem = 0;
            }
            if ($rem !== (int)$cpf[$t]) {
                return false;
            }
        }

        return true;
    }

    public function isUniqueCpf(mixed $cpf, array $context): bool
    {
        $cpf = preg_replace('/\D/', '', (string)$cpf);

        $query = $this->find()->where(['cpf' => $cpf, 'deleted IS' => null]);

        $id = $context['data']['id'] ?? null;
        if ($id) {
            $query->where(['id !=' => $id]);
        }

        return $query->count() === 0;
    }

    public function findByCpf(string $cpf): ?object
    {
        $cpf = preg_replace('/\D/', '', $cpf);

        return $this->find()
            ->where(['cpf' => $cpf, 'deleted IS' => null])
            ->first();
    }
}
