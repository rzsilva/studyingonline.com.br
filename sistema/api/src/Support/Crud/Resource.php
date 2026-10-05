<?php

declare(strict_types=1);

namespace App\Support\Crud;

use App\Domain\Auth\Perfil;

/** Descreve um recurso CRUD isolado por instituição. */
final class Resource
{
    /** @var Field[] */
    public array $fields = [];
    /** colunas devolvidas (além das graváveis); padrão: ID + graváveis */
    public array $extraSelect = [];
    /** joins somente-leitura para exibir nomes, ex.: "LEFT JOIN CURSO c ON c.ID = t.CURSO_ID" */
    public array $joins = [];
    /** filtros aceitos na query string: chave camelCase => expressão SQL */
    public array $filters = [];
    /** colunas usadas na busca textual ?q= */
    public array $search = [];
    public string $orderBy = 't.ID DESC';
    /** coluna com data de criação preenchida automaticamente */
    public ?string $createdAt = 'DATA_CADASTRO';
    /** @var Perfil[] perfis que podem ler */
    public array $readRoles = [Perfil::Administrador, Perfil::Professor];
    /** @var Perfil[] perfis que podem gravar */
    public array $writeRoles = [Perfil::Administrador];
    /** se definido, aluno lê/grava apenas registros onde esta coluna = seu ID */
    public ?string $ownerColumn = null;
    /** aluno pode criar/alterar/excluir os próprios registros (exige ownerColumn) */
    public bool $ownerCanWrite = false;
    /** restringe ao dono para TODOS os perfis (ex.: anotações pessoais), não só para o aluno */
    public bool $ownerForAll = false;
    /** colunas que o aluno nunca define (ficam com o default/valor atual) */
    public array $staffOnlyColumns = [];

    public function __construct(public readonly string $table, public readonly string $label)
    {
    }

    /** @param Field[] $fields */
    public function fields(Field ...$fields): self
    {
        $this->fields = $fields;
        return $this;
    }

    /** O registro é restrito ao dono para este usuário? */
    public function ownedBy(\App\Domain\Auth\AuthUser $user): bool
    {
        return $this->ownerColumn !== null && ($this->ownerForAll || $user->isAluno());
    }

    /** @return string[] colunas booleanas (para tipar a saída) */
    public function boolColumns(): array
    {
        return array_values(array_map(
            static fn (Field $f) => $f->column,
            array_filter($this->fields, static fn (Field $f) => $f->type === 'bool')
        ));
    }
}
