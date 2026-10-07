<?php

declare(strict_types=1);

namespace App\Domain\Secretaria;

use App\Domain\Auth\AuthUser;
use App\Support\ApiException;
use App\Support\Crud\Field as F;
use App\Support\Crud\Resource;

final class Resources
{
    /**
     * Usuários da instituição (alunos, professores, administradores).
     * Senha NUNCA passa por aqui: o admin envia um convite (link de definição de senha).
     * MASTER não é editável pela interface.
     */
    public static function usuarios(): Resource
    {
        $r = new Resource('USUARIO', 'Usuário');
        $r->fields(
            F::string('NOME', 200)->required(),
            F::email('EMAIL')->required(),
            F::ref('LISTA_PERFIL_ID', 'LISTA_PERFIL', false)->required(),
            F::ref('LISTA_TURMA_ID', 'LISTA_TURMA', false),
            F::ref('LISTA_UNIDADE_ID', 'LISTA_UNIDADE')->required(),
            F::ref('LISTA_ESTADO_CIVIL_ID', 'LISTA_ESTADO_CIVIL', false)->required(),
            F::string('MATRICULA', 50),
            F::cpf('CPF'),
            F::string('RG', 30),
            F::date('DATA_NASCIMENTO'),
            F::enum('SEXO', ['M', 'F']),
            F::string('TELEFONE', 30),
            F::string('CELULAR', 30),
            F::string('CEP', 10),
            F::string('RUA', 200),
            F::int('NUMERO'),
            F::string('BAIRRO', 100),
            F::string('CIDADE', 100),
            F::string('UF', 2),
            F::string('NACIONALIDADE', 100),
            F::string('NATURALIDADE', 100),
            F::string('FILIACAO', 300),
            F::email('EMAIL_RESPONSAVEL'),
            F::string('IGREJA', 200),
            F::string('PASTOR', 200),
            F::decimal('DESCONTO')->default('0'),
            F::int('DIA_VENCIMENTO')->default(10),
            F::bool('INATIVO'),
        );
        $r->joins = ['LEFT JOIN LISTA_PERFIL p ON p.ID = t.LISTA_PERFIL_ID', 'LEFT JOIN LISTA_TURMA tu ON tu.ID = t.LISTA_TURMA_ID'];
        $r->extraSelect = ['p.VALOR AS PERFIL', 'tu.VALOR AS TURMA', 't.ONLINE',
            '(t.SENHA_HASH IS NOT NULL OR (t.SENHA IS NOT NULL AND t.SENHA <> \'\')) AS ACESSO_DEFINIDO'];
        $r->filters = ['perfilId' => 't.LISTA_PERFIL_ID', 'inativo' => 't.INATIVO', 'turmaId' => 't.LISTA_TURMA_ID'];
        $r->search = ['t.NOME', 't.EMAIL', 't.MATRICULA', 't.CPF'];
        $r->orderBy = 't.NOME';
        $r->readRoles = [\App\Domain\Auth\Perfil::Administrador];
        $r->unique = ['EMAIL'];
        $r->createDefaults = ['ONLINE' => 0, 'MSG_NO_VIEW' => 0, 'MASTER' => 0];
        $r->beforeWrite = static function (array $data, AuthUser $user, ?int $id): array {
            // o administrador não se tranca para fora: não muda o próprio perfil nem se inativa
            if ($id === $user->id) {
                if (isset($data['LISTA_PERFIL_ID']) && (int) $data['LISTA_PERFIL_ID'] !== $user->perfilId) {
                    throw ApiException::validation(['listaPerfilId' => 'Você não pode alterar o próprio perfil.']);
                }
                if (!empty($data['INATIVO'])) {
                    throw ApiException::validation(['inativo' => 'Você não pode inativar a própria conta.']);
                }
            }
            if (isset($data['UF'])) {
                $data['UF'] = strtoupper($data['UF']);
            }
            return $data;
        };
        return $r;
    }

    /** Professores (tabela LISTA_PROFESSOR). A coluna SENHA do legado não é lida nem gravada. */
    public static function professores(): Resource
    {
        $r = new Resource('LISTA_PROFESSOR', 'Professor');
        $r->fields(
            F::string('PROFESSOR', 200)->required(),
            F::email('EMAIL'),
            F::string('TELEFONE', 30),
            F::cpf('CPF'),
            F::string('RG', 30),
            F::date('DATA_NASCIMENTO'),
            F::string('ESTADO_CIVIL', 50),
            F::string('NOME_MAE', 200),
            F::url('URL'),
            F::text('DESCRICAO'),
            F::string('BANCO', 100),
            F::string('AGENCIA', 20),
            F::string('CONTA', 30),
            F::text('OBSERVACAO'),
        );
        $r->search = ['t.PROFESSOR', 't.EMAIL'];
        $r->orderBy = 't.PROFESSOR';
        $r->readRoles = [\App\Domain\Auth\Perfil::Administrador];
        return $r;
    }
}
