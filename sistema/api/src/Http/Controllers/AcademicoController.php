<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Academico\MatriculaService;
use App\Domain\Academico\NotaService;
use App\Domain\Auth\AuthUser;
use App\Domain\Auth\Perfil;
use App\Support\ApiException;
use App\Support\Audit;
use App\Support\Connection;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Endpoints acadêmicos que não são CRUD simples: notas, turma, listas e cópia de prova. */
final class AcademicoController
{
    /** listas auxiliares: nome => [SQL, por instituição?, só equipe?] */
    private const LISTAS = [
        'tipos-curso'  => ['SELECT ID, VALOR AS NOME FROM LISTA_TIPO_CURSO WHERE INSTITUICAO_ID = ? ORDER BY VALOR', true, false],
        'unidades'     => ['SELECT ID, VALOR AS NOME FROM LISTA_UNIDADE WHERE INSTITUICAO_ID = ? ORDER BY VALOR', true, false],
        'professores'  => ['SELECT ID, PROFESSOR AS NOME FROM LISTA_PROFESSOR WHERE INSTITUICAO_ID = ? ORDER BY PROFESSOR', true, true],
        'disciplinas'  => ['SELECT ID, VALOR AS NOME FROM LISTA_DISCIPLINA WHERE INSTITUICAO_ID = ? ORDER BY VALOR', true, true],
        // disciplinas para o fórum (todos os perfis participam)
        'disciplinas-forum' => ['SELECT ID, VALOR AS NOME FROM LISTA_DISCIPLINA WHERE INSTITUICAO_ID = ? ORDER BY VALOR', true, false],
        'cursos'       => ['SELECT ID, NOME FROM CURSO WHERE INSTITUICAO_ID = ? ORDER BY ATIVO DESC, NOME', true, true],
        'alunos'       => ['SELECT ID, NOME FROM USUARIO WHERE INSTITUICAO_ID = ? AND LISTA_PERFIL_ID = 3 ORDER BY NOME', true, true],
        'turmas'       => ['SELECT ID, VALOR AS NOME FROM LISTA_TURMA ORDER BY ID', false, false],
        'status-nota'  => ['SELECT ID, VALOR AS NOME FROM LISTA_STATUS_NOTA ORDER BY ID', false, false],
        'situacoes-ag' => ['SELECT ID, VALOR AS NOME FROM LISTA_SITUACAO_AG ORDER BY ID', false, false],
        'categorias-ag' => ['SELECT ID, VALOR AS NOME FROM LISTA_CATEGORIA_AG ORDER BY VALOR', false, false],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly NotaService $notas,
        private readonly MatriculaService $matriculas,
        private readonly Audit $audit,
    ) {
    }

    /** GET /listas/{nome} */
    public function lista(Request $request, Response $response, array $args): Response
    {
        /** @var AuthUser $user */
        $user = $request->getAttribute('user');
        $def = self::LISTAS[$args['nome']] ?? null;
        if ($def === null) {
            throw ApiException::notFound('Lista não encontrada.');
        }
        [$sql, $tenant, $staff] = $def;
        if ($staff && $user->isAluno()) {
            throw ApiException::forbidden();
        }
        $rows = $this->db->run($sql, $tenant ? [$user->instituicaoId] : [])->fetchAll();
        return Json::ok($response, array_map(static fn ($r) => ['id' => (int) $r['ID'], 'nome' => $r['NOME']], $rows));
    }

    /** GET /listas/modulos?cursoId= */
    public function modulos(Request $request, Response $response): Response
    {
        $user = $this->staff($request);
        $cursoId = (int) ($request->getQueryParams()['cursoId'] ?? 0);
        $rows = $this->db->run(
            'SELECT d.ID, ld.VALOR AS NOME FROM DISCIPLINA d LEFT JOIN LISTA_DISCIPLINA ld ON ld.ID = d.LISTA_DISCIPLINA_ID
              WHERE d.CURSO_ID = ? AND d.INSTITUICAO_ID = ? ORDER BY COALESCE(d.ORDEM, 2147483647), d.DATA_INICIO, d.ID',
            [$cursoId, $user->instituicaoId]
        )->fetchAll();
        return Json::ok($response, array_map(static fn ($r) => ['id' => (int) $r['ID'], 'nome' => $r['NOME']], $rows));
    }

    /** GET /notas/modulos/{id} */
    public function notasModulo(Request $request, Response $response, array $args): Response
    {
        return Json::ok($response, $this->notas->porModulo($this->staff($request), (int) $args['id']));
    }

    /** PUT /notas/modulos/{id} {notas: [...]} */
    public function salvarNotasModulo(Request $request, Response $response, array $args): Response
    {
        $itens = ((array) $request->getParsedBody())['notas'] ?? null;
        if (!is_array($itens)) {
            throw ApiException::validation(['notas' => 'Lista de notas obrigatória.']);
        }
        $n = $this->notas->salvarModulo($this->staff($request), (int) $args['id'], $itens);
        return Json::ok($response, ['salvas' => $n]);
    }

    /** GET /notas/alunos/{id}?cursoId= */
    public function boletim(Request $request, Response $response, array $args): Response
    {
        $cursoId = (int) ($request->getQueryParams()['cursoId'] ?? 0);
        return Json::ok($response, $this->notas->boletim($request->getAttribute('user'), (int) $args['id'], $cursoId));
    }

    /** GET /cursos/{id}/alunos */
    public function alunosCurso(Request $request, Response $response, array $args): Response
    {
        return Json::ok($response, $this->matriculas->alunos($this->staff($request), (int) $args['id']));
    }

    /** GET /cursos/{id}/alunos-disponiveis?q= */
    public function alunosDisponiveis(Request $request, Response $response, array $args): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        return Json::ok($response, $this->matriculas->disponiveis($this->admin($request), (int) $args['id'], $q));
    }

    /** POST /cursos/{id}/alunos {usuarioIds: []} */
    public function matricular(Request $request, Response $response, array $args): Response
    {
        $ids = ((array) $request->getParsedBody())['usuarioIds'] ?? [];
        $n = $this->matriculas->adicionar($this->admin($request), (int) $args['id'], is_array($ids) ? $ids : []);
        return Json::ok($response, ['adicionados' => $n], [], 201);
    }

    /** DELETE /cursos/{id}/alunos/{usuarioId} */
    public function desmatricular(Request $request, Response $response, array $args): Response
    {
        $this->matriculas->remover($this->admin($request), (int) $args['id'], (int) $args['usuarioId']);
        return Json::noContent($response);
    }

    /** POST /cursos/{id}/alunos/{usuarioId}/trocar {cursoDestinoId} */
    public function trocarTurma(Request $request, Response $response, array $args): Response
    {
        $destino = (int) (((array) $request->getParsedBody())['cursoDestinoId'] ?? 0);
        $this->matriculas->trocar($this->admin($request), (int) $args['id'], (int) $args['usuarioId'], $destino);
        return Json::noContent($response);
    }

    /** POST /provas/{id}/copiar {moduloIds: []} — copia prova e questões para outros módulos */
    public function copiarProva(Request $request, Response $response, array $args): Response
    {
        $user = $this->admin($request);
        $provaId = (int) $args['id'];
        $prova = $this->db->run('SELECT ID, VALOR_PROVA FROM PROVA WHERE ID = ? AND INSTITUICAO_ID = ?',
            [$provaId, $user->instituicaoId])->fetch();
        if (!$prova) {
            throw ApiException::notFound('Prova não encontrada.');
        }
        $destinos = array_values(array_unique(array_map('intval', (array) (((array) $request->getParsedBody())['moduloIds'] ?? []))));
        if (!$destinos) {
            throw ApiException::validation(['moduloIds' => 'Selecione ao menos um módulo.']);
        }
        $in = implode(',', array_fill(0, count($destinos), '?'));
        $validos = $this->db->run("SELECT ID FROM DISCIPLINA WHERE INSTITUICAO_ID = ? AND ID IN ({$in})",
            [$user->instituicaoId, ...$destinos])->fetchAll(\PDO::FETCH_COLUMN);
        if (count($validos) !== count($destinos)) {
            throw ApiException::validation(['moduloIds' => 'Módulo inválido na seleção.']);
        }

        $this->db->beginTransaction();
        try {
            foreach ($validos as $moduloId) {
                $this->db->run('INSERT INTO PROVA (INSTITUICAO_ID, DISCIPLINA_ID, ATIVA, VALOR_PROVA, DATA_CADASTRO) VALUES (?, ?, 1, ?, NOW())',
                    [$user->instituicaoId, (int) $moduloId, $prova['VALOR_PROVA']]);
                $novaId = $this->db->lastInsertId();
                $this->db->run(
                    'INSERT INTO QUESTAO_PROVA (INSTITUICAO_ID, PROVA_ID, QUESTAO, OPCAO_1, OPCAO_2, OPCAO_3, OPCAO_4, OPCAO_5, CORRETA, VALOR, DATA_CADASTRO)
                     SELECT INSTITUICAO_ID, ?, QUESTAO, OPCAO_1, OPCAO_2, OPCAO_3, OPCAO_4, OPCAO_5, CORRETA, VALOR, NOW()
                       FROM QUESTAO_PROVA WHERE PROVA_ID = ? AND INSTITUICAO_ID = ?',
                    [$novaId, $provaId, $user->instituicaoId]
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->audit->log($user, 'copiar_prova', 'PROVA', $provaId, $request, ['modulos' => $validos]);
        return Json::ok($response, ['copias' => count($validos)], [], 201);
    }

    private function staff(Request $request): AuthUser
    {
        $user = $request->getAttribute('user');
        if (!$user->is(Perfil::Administrador, Perfil::Professor)) {
            throw ApiException::forbidden();
        }
        return $user;
    }

    private function admin(Request $request): AuthUser
    {
        $user = $request->getAttribute('user');
        if (!$user->isAdmin()) {
            throw ApiException::forbidden();
        }
        return $user;
    }
}
