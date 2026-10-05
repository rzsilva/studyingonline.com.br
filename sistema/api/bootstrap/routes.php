<?php

declare(strict_types=1);

use App\Domain\Academico\Resources;
use App\Domain\Auth\Perfil;
use App\Http\Controllers\AcademicoController as A;
use App\Http\Controllers\ArquivoController;
use App\Http\Controllers\AuthController;
use App\Domain\Comunidade\Resources as ComunidadeResources;
use App\Http\Controllers\ComunidadeController as C;
use App\Http\Controllers\CrudController;
use App\Http\Controllers\InstituicaoController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\PainelController as P;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\App;
use Slim\Routing\RouteCollectorProxy as Group;

return static function (App $app): void {
    $app->get('/health', fn ($req, Response $res) => Json::ok($res, ['status' => 'ok']));

    // Públicas
    $app->get('/instituicoes/by-host', [InstituicaoController::class, 'byHost']);
    $app->group('/auth', function (Group $g) {
        $g->post('/login', [AuthController::class, 'login']);
        $g->post('/refresh', [AuthController::class, 'refresh']);
        $g->post('/logout', [AuthController::class, 'logout']);
        $g->post('/esqueci-senha', [AuthController::class, 'forgot']);
        $g->post('/redefinir-senha', [AuthController::class, 'resetPassword']);
    });

    // Autenticadas. Permissões por perfil: RoleMiddleware na rota ou checagem no controller/service.
    $app->group('', function (Group $g) {
        $g->get('/me', [MeController::class, 'show']);
        $g->put('/me/senha', [MeController::class, 'changePassword']);

        // Fase 2 — acadêmico (CRUD com isolamento por instituição; ver Domain/Academico/Resources)
        CrudController::routes($g, '/cursos', Resources::cursos());
        CrudController::routes($g, '/disciplinas', Resources::disciplinas());
        CrudController::routes($g, '/modulos', Resources::modulos());
        CrudController::routes($g, '/videos', Resources::videos());
        CrudController::routes($g, '/aulas', Resources::aulas());
        CrudController::routes($g, '/arquivos', Resources::arquivos());
        CrudController::routes($g, '/provas', Resources::provas());
        CrudController::routes($g, '/questoes', Resources::questoes());
        CrudController::routes($g, '/estagios', Resources::estagios());
        CrudController::routes($g, '/agendamentos', Resources::agendamentos());

        $g->post('/arquivos/{id:[0-9]+}/upload', [ArquivoController::class, 'upload'])
            ->add(RoleMiddleware::only(Perfil::Administrador));
        $g->get('/arquivos/{id:[0-9]+}/download', [ArquivoController::class, 'download']);
        $g->post('/provas/{id:[0-9]+}/copiar', [A::class, 'copiarProva']);

        $g->get('/listas/modulos', [A::class, 'modulos']);
        $g->get('/listas/{nome}', [A::class, 'lista']);

        $g->get('/notas/modulos/{id:[0-9]+}', [A::class, 'notasModulo']);
        $g->put('/notas/modulos/{id:[0-9]+}', [A::class, 'salvarNotasModulo']);
        $g->get('/notas/alunos/{id:[0-9]+}', [A::class, 'boletim']);

        $g->get('/cursos/{id:[0-9]+}/alunos', [A::class, 'alunosCurso']);
        $g->get('/cursos/{id:[0-9]+}/alunos-disponiveis', [A::class, 'alunosDisponiveis']);
        $g->post('/cursos/{id:[0-9]+}/alunos', [A::class, 'matricular']);
        $g->delete('/cursos/{id:[0-9]+}/alunos/{usuarioId:[0-9]+}', [A::class, 'desmatricular']);
        $g->post('/cursos/{id:[0-9]+}/alunos/{usuarioId:[0-9]+}/trocar', [A::class, 'trocarTurma']);

        // Fase 3 — comunidade
        CrudController::routes($g, '/avisos', ComunidadeResources::avisos());
        $g->post('/avisos/{id:[0-9]+}/imagem', [C::class, 'avisoImagem'])->add(RoleMiddleware::only(Perfil::Administrador));
        $g->get('/avisos/{id:[0-9]+}/imagem', [C::class, 'avisoImagemDownload']);
        CrudController::routes($g, '/anotacoes', ComunidadeResources::anotacoes());

        $g->get('/forum/topicos', [C::class, 'topicos']);
        $g->post('/forum/topicos', [C::class, 'criarTopico']);
        $g->get('/forum/topicos/{id:[0-9]+}', [C::class, 'topico']);
        $g->put('/forum/topicos/{id:[0-9]+}', [C::class, 'editarTopico']);
        $g->delete('/forum/topicos/{id:[0-9]+}', [C::class, 'excluirTopico']);
        $g->post('/forum/topicos/{id:[0-9]+}/respostas', [C::class, 'responder']);
        $g->put('/forum/respostas/{id:[0-9]+}', [C::class, 'editarResposta']);
        $g->delete('/forum/respostas/{id:[0-9]+}', [C::class, 'excluirResposta']);

        $g->get('/chat/contatos', [C::class, 'contatos']);
        $g->get('/chat/nao-lidas', [C::class, 'naoLidas']);
        $g->get('/chat/{id:[0-9]+}', [C::class, 'mensagens']);
        $g->post('/chat/{id:[0-9]+}', [C::class, 'enviar']);

        $g->get('/painel-presencial/cursos', [C::class, 'presencialCursos']);
        $g->get('/painel-presencial/cursos/{id:[0-9]+}', [C::class, 'presencialCurso']);
        $g->get('/me/financeiro', [C::class, 'meuFinanceiro']);

        // Painel EAD do aluno (trilha sequencial)
        $g->get('/painel/cursos', [P::class, 'cursos']);
        $g->get('/painel/cursos/{id:[0-9]+}', [P::class, 'trilha']);
        $g->post('/painel/videos/{id:[0-9]+}/progresso', [P::class, 'progresso']);
        $g->post('/painel/provas/{id:[0-9]+}/respostas', [P::class, 'responder']);
    })->add(AuthMiddleware::class);
};
