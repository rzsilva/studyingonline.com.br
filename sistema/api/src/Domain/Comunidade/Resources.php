<?php

declare(strict_types=1);

namespace App\Domain\Comunidade;

use App\Domain\Auth\Perfil;
use App\Support\Crud\Field as F;
use App\Support\Crud\Resource;

final class Resources
{
    /** Avisos da instituição: todos leem (feed da página inicial); admin publica. */
    public static function avisos(): Resource
    {
        $r = new Resource('AVISO', 'Aviso');
        $r->fields(
            F::string('TITULO', 200)->required(),
            F::text('DESCRICAO'),
            F::string('TIPO', 50),
        );
        // URL = imagem; definida só pelo upload (POST /avisos/{id}/imagem)
        $r->extraSelect = ['t.URL'];
        $r->search = ['t.TITULO', 't.DESCRICAO'];
        $r->orderBy = 't.DATA_CADASTRO DESC, t.ID DESC';
        $r->readRoles = [Perfil::Administrador, Perfil::Professor, Perfil::Aluno];
        return $r;
    }

    /**
     * Anotações do aluno nos vídeos de um módulo (tabela VIDEO_ANOTACAO).
     * Cada usuário vê/edita só as suas (USUARIO_ID adicionado na migration 002).
     */
    public static function anotacoes(): Resource
    {
        $r = new Resource('VIDEO_ANOTACAO', 'Anotação');
        $r->fields(
            F::ref('DISCIPLINA_ID', 'DISCIPLINA')->required(),
            F::string('VIDEO', 200),
            F::string('POSICAO', 8),
            F::text('DESCRICAO')->required(),
        );
        $r->filters = ['moduloId' => 't.DISCIPLINA_ID'];
        $r->orderBy = 't.DATA_CADASTRO DESC';
        $r->readRoles = [];
        $r->writeRoles = [];
        $r->ownerColumn = 'USUARIO_ID';
        $r->ownerCanWrite = true;
        $r->ownerForAll = true;
        return $r;
    }
}
