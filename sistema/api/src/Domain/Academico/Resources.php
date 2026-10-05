<?php

declare(strict_types=1);

namespace App\Domain\Academico;

use App\Domain\Auth\Perfil;
use App\Support\Crud\Field as F;
use App\Support\Crud\Resource;

/**
 * Recursos CRUD do módulo acadêmico (tabelas do legado, nomes preservados).
 * Regras de acesso seguem o menu legado: admin cadastra; professor consulta e lança notas.
 */
final class Resources
{
    public static function cursos(): Resource
    {
        $r = new Resource('CURSO', 'Curso');
        $r->fields(
            F::string('NOME', 200)->required(),
            F::string('SUBTITULO', 200),
            F::text('DESCRICAO'),
            F::ref('LISTA_TIPO_CURSO_ID', 'LISTA_TIPO_CURSO')->required(),
            F::ref('LISTA_UNIDADE_ID', 'LISTA_UNIDADE')->required(),
            F::ref('TIPO_TURMA', 'LISTA_TURMA', false),
            F::int('CARGA_HORARIA'),
            F::int('PERIODO'),
            F::decimal('MEDIA'),
            F::decimal('FALTAS_PERMITIDAS'),
            F::decimal('VALOR')->default('0'),
            F::decimal('VALOR_MATRICULA'),
            F::decimal('VALOR_REMATRICULA'),
            F::bool('MATRICULA_ABERTA'),
            F::date('DATA_MATRICULA'),
            F::bool('REMATRICULA_ABERTA'),
            F::date('DATA_REMATRICULA'),
            F::int('HORAS_ESTAGIO'),
            F::int('LIMITE_ALUNOS_TURMA'),
            F::int('PERIODICIDADE_COBRANCA')->default(1),
            F::int('TEMPO_CURSO'),
            F::url('FOTO'),
            F::bool('ATIVO')->default(1),
        );
        $r->joins = ['LEFT JOIN LISTA_TIPO_CURSO tc ON tc.ID = t.LISTA_TIPO_CURSO_ID'];
        $r->extraSelect = ['tc.VALOR AS TIPO_CURSO',
            '(SELECT COUNT(*) FROM USUARIO_CURSO uc WHERE uc.CURSO_ID = t.ID) AS TOTAL_ALUNOS'];
        $r->filters = ['ativo' => 't.ATIVO', 'tipoCursoId' => 't.LISTA_TIPO_CURSO_ID', 'unidadeId' => 't.LISTA_UNIDADE_ID'];
        $r->search = ['t.NOME', 't.SUBTITULO'];
        $r->orderBy = 't.ATIVO DESC, t.NOME';
        return $r;
    }

    /** Catálogo de disciplinas (LISTA_DISCIPLINA): o "nome" reutilizável entre cursos. */
    public static function disciplinas(): Resource
    {
        $r = new Resource('LISTA_DISCIPLINA', 'Disciplina');
        $r->fields(
            F::string('VALOR', 200)->required(),
            F::text('DESCRICAO'),
            F::text('LEITURA_OBRIGATORIA'),
            F::string('CARGA_HORARIA', 50),
        );
        $r->createdAt = null;
        $r->search = ['t.VALOR'];
        $r->orderBy = 't.VALOR';
        return $r;
    }

    /** Módulo = disciplina aplicada a um curso (tabela DISCIPLINA), com ordem da trilha. */
    public static function modulos(): Resource
    {
        $r = new Resource('DISCIPLINA', 'Módulo');
        $r->fields(
            F::ref('CURSO_ID', 'CURSO')->required(),
            F::ref('LISTA_DISCIPLINA_ID', 'LISTA_DISCIPLINA')->required(),
            F::ref('LISTA_PROFESSOR_ID', 'LISTA_PROFESSOR'),
            F::string('PERIODO', 50),
            F::date('DATA_INICIO'),
            F::int('ORDEM'),
            F::int('DURACAO_DIAS'),
        );
        $r->joins = [
            'LEFT JOIN LISTA_DISCIPLINA ld ON ld.ID = t.LISTA_DISCIPLINA_ID',
            'LEFT JOIN LISTA_PROFESSOR lp ON lp.ID = t.LISTA_PROFESSOR_ID',
            'LEFT JOIN CURSO c ON c.ID = t.CURSO_ID',
        ];
        $r->extraSelect = ['ld.VALOR AS DISCIPLINA', 'lp.PROFESSOR AS PROFESSOR', 'c.NOME AS CURSO',
            '(SELECT COUNT(*) FROM VIDEO v WHERE v.DISCIPLINA_ID = t.ID) AS TOTAL_VIDEOS'];
        $r->filters = ['cursoId' => 't.CURSO_ID'];
        $r->search = ['ld.VALOR', 'c.NOME'];
        $r->orderBy = 'c.NOME, COALESCE(t.ORDEM, 2147483647), t.DATA_INICIO, t.ID';
        return $r;
    }

    public static function videos(): Resource
    {
        $r = new Resource('VIDEO', 'Vídeo-aula');
        $r->fields(
            F::ref('DISCIPLINA_ID', 'DISCIPLINA')->required(),
            F::string('TITULO', 200)->required(),
            F::text('DESCRICAO'),
            F::url('URL')->required(),
            F::bool('YOUTUBE'),
            F::bool('VIMEO'),
            F::int('ORDEM'),
        );
        $r->joins = ['LEFT JOIN DISCIPLINA d ON d.ID = t.DISCIPLINA_ID',
            'LEFT JOIN LISTA_DISCIPLINA ld ON ld.ID = d.LISTA_DISCIPLINA_ID'];
        $r->extraSelect = ['ld.VALOR AS DISCIPLINA', 'd.CURSO_ID'];
        $r->filters = ['moduloId' => 't.DISCIPLINA_ID', 'cursoId' => 'd.CURSO_ID'];
        $r->search = ['t.TITULO'];
        $r->orderBy = 't.DISCIPLINA_ID, COALESCE(t.ORDEM, 2147483647), t.TITULO';
        return $r;
    }

    public static function aulas(): Resource
    {
        $r = new Resource('AULA', 'Aula');
        $r->fields(
            F::ref('DISCIPLINA_ID', 'DISCIPLINA')->required(),
            F::string('TITULO', 200)->required(),
            F::text('DESCRICAO'),
            F::url('URL'),
            F::date('DATA_AULA'),
            F::time('INICIO'),
            F::time('TERMINO'),
        );
        $r->joins = ['LEFT JOIN DISCIPLINA d ON d.ID = t.DISCIPLINA_ID',
            'LEFT JOIN LISTA_DISCIPLINA ld ON ld.ID = d.LISTA_DISCIPLINA_ID'];
        $r->extraSelect = ['ld.VALOR AS DISCIPLINA', 'd.CURSO_ID'];
        $r->filters = ['moduloId' => 't.DISCIPLINA_ID', 'cursoId' => 'd.CURSO_ID'];
        $r->search = ['t.TITULO'];
        $r->orderBy = 't.DATA_AULA DESC, t.INICIO';
        return $r;
    }

    /** Material de apoio, ligado ao catálogo de disciplinas (como no legado). Upload em ArquivoController. */
    public static function arquivos(): Resource
    {
        $r = new Resource('ARQUIVO', 'Material');
        $r->fields(
            F::ref('LISTA_DISCIPLINA_ID', 'LISTA_DISCIPLINA')->required(),
            F::string('TITULO', 200)->required(),
            F::text('DESCRICAO'),
            F::url('URL'),
        );
        $r->joins = ['LEFT JOIN LISTA_DISCIPLINA ld ON ld.ID = t.LISTA_DISCIPLINA_ID'];
        $r->extraSelect = ['ld.VALOR AS DISCIPLINA'];
        $r->filters = ['disciplinaId' => 't.LISTA_DISCIPLINA_ID'];
        $r->search = ['t.TITULO'];
        $r->orderBy = 'ld.VALOR, t.TITULO';
        return $r;
    }

    public static function provas(): Resource
    {
        $r = new Resource('PROVA', 'Prova');
        $r->fields(
            F::ref('DISCIPLINA_ID', 'DISCIPLINA')->required(),
            F::bool('ATIVA')->default(1),
            F::decimal('VALOR_PROVA'),
        );
        $r->joins = ['LEFT JOIN DISCIPLINA d ON d.ID = t.DISCIPLINA_ID',
            'LEFT JOIN LISTA_DISCIPLINA ld ON ld.ID = d.LISTA_DISCIPLINA_ID',
            'LEFT JOIN CURSO c ON c.ID = d.CURSO_ID'];
        $r->extraSelect = ['ld.VALOR AS DISCIPLINA', 'c.NOME AS CURSO', 'd.CURSO_ID',
            '(SELECT COUNT(*) FROM QUESTAO_PROVA q WHERE q.PROVA_ID = t.ID) AS TOTAL_QUESTOES',
            '(SELECT COALESCE(SUM(q.VALOR),0) FROM QUESTAO_PROVA q WHERE q.PROVA_ID = t.ID) AS VALOR_TOTAL'];
        $r->filters = ['moduloId' => 't.DISCIPLINA_ID', 'cursoId' => 'd.CURSO_ID'];
        $r->search = ['ld.VALOR', 'c.NOME'];
        $r->orderBy = 'c.NOME, ld.VALOR';
        return $r;
    }

    /** Questões com gabarito: apenas admin/professor (o aluno nunca recebe CORRETA). */
    public static function questoes(): Resource
    {
        $r = new Resource('QUESTAO_PROVA', 'Questão');
        $r->fields(
            F::ref('PROVA_ID', 'PROVA')->required(),
            F::text('QUESTAO')->required(),
            F::text('OPCAO_1')->required(),
            F::text('OPCAO_2')->required(),
            F::text('OPCAO_3'),
            F::text('OPCAO_4'),
            F::text('OPCAO_5'),
            F::enum('CORRETA', ['A', 'B', 'C', 'D', 'E'])->required(),
            F::decimal('VALOR')->required(),
        );
        $r->filters = ['provaId' => 't.PROVA_ID'];
        $r->orderBy = 't.ID';
        return $r;
    }

    public static function estagios(): Resource
    {
        $r = new Resource('ESTAGIO', 'Estágio');
        $r->fields(
            F::ref('CURSO_ID', 'CURSO')->required(),
            F::ref('USUARIO_ID', 'USUARIO')->required(),
            F::ref('LISTA_STATUS_NOTA_ID', 'LISTA_STATUS_NOTA', false)->default(1),
            F::decimal('HORAS'),
            F::int('FALTAS'),
        );
        $r->joins = ['LEFT JOIN USUARIO u ON u.ID = t.USUARIO_ID', 'LEFT JOIN CURSO c ON c.ID = t.CURSO_ID',
            'LEFT JOIN LISTA_STATUS_NOTA sn ON sn.ID = t.LISTA_STATUS_NOTA_ID'];
        $r->extraSelect = ['u.NOME AS ALUNO', 'c.NOME AS CURSO', 'c.HORAS_ESTAGIO', 'sn.VALOR AS STATUS'];
        $r->filters = ['cursoId' => 't.CURSO_ID', 'usuarioId' => 't.USUARIO_ID'];
        $r->search = ['u.NOME'];
        $r->orderBy = 'c.NOME, u.NOME';
        $r->readRoles = [Perfil::Administrador, Perfil::Professor];
        $r->ownerColumn = 'USUARIO_ID';
        return $r;
    }

    /** Atendimento: aluno agenda e vê só os próprios; admin/professor veem todos. */
    public static function agendamentos(): Resource
    {
        $r = new Resource('AGENDAMENTO', 'Agendamento');
        $r->fields(
            F::ref('USUARIO_ID', 'USUARIO')->required(),
            F::ref('LISTA_SITUACAO_AG_ID', 'LISTA_SITUACAO_AG', false)->default(1),
            F::ref('LISTA_CATEGORIA_AG_ID', 'LISTA_CATEGORIA_AG', false)->required(),
            F::date('DATA')->required(),
            F::time('HORA')->required(),
            F::string('OUTROS', 200),
            F::text('DESCRICAO'),
        );
        $r->createdAt = null;
        $r->joins = ['LEFT JOIN USUARIO u ON u.ID = t.USUARIO_ID',
            'LEFT JOIN LISTA_SITUACAO_AG s ON s.ID = t.LISTA_SITUACAO_AG_ID',
            'LEFT JOIN LISTA_CATEGORIA_AG ca ON ca.ID = t.LISTA_CATEGORIA_AG_ID'];
        $r->extraSelect = ['u.NOME AS USUARIO', 's.VALOR AS SITUACAO', 'ca.VALOR AS CATEGORIA'];
        $r->filters = ['situacaoId' => 't.LISTA_SITUACAO_AG_ID', 'data' => 't.DATA'];
        $r->search = ['u.NOME', 't.DESCRICAO'];
        $r->orderBy = 't.DATA DESC, t.HORA DESC';
        $r->writeRoles = [Perfil::Administrador, Perfil::Professor];
        $r->ownerColumn = 'USUARIO_ID';
        $r->ownerCanWrite = true;
        $r->staffOnlyColumns = ['LISTA_SITUACAO_AG_ID'];
        return $r;
    }
}
