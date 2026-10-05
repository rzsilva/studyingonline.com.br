<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\AuthUser;
use App\Domain\Instituicao\InstituicaoRepository;
use App\Domain\Usuario\UsuarioRepository;
use App\Support\ApiException;
use App\Support\Json;
use App\Support\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MeController
{
    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly InstituicaoRepository $instituicoes,
        private readonly AuthService $auth,
    ) {
    }

    /**
     * GET /me — substitui HomeController.MontaObjetoTela: dados do usuário,
     * instituição/tema e flags que controlam o menu.
     */
    public function show(Request $request, Response $response): Response
    {
        /** @var AuthUser $user */
        $user = $request->getAttribute('user');
        $u = $this->usuarios->findById($user->id, $user->instituicaoId);
        $inst = $this->instituicoes->findById($user->instituicaoId);
        if ($u === null || $inst === null) {
            throw ApiException::unauthorized();
        }

        $data = [
            'id'        => (int) $u['ID'],
            'nome'      => $u['NOME'],
            'email'     => $u['EMAIL'],
            'matricula' => $u['MATRICULA'],
            'foto'      => $u['URL'],
            'perfilId'  => (int) $u['LISTA_PERFIL_ID'],
            'perfil'    => $u['PERFIL'],
            'turmaId'   => $u['LISTA_TURMA_ID'] !== null ? (int) $u['LISTA_TURMA_ID'] : null,
            'unidadeId' => (int) $u['LISTA_UNIDADE_ID'],
            'master'    => (bool) $u['MASTER'],
            'inativo'   => (bool) $u['INATIVO'],
            'mensagensNaoLidas' => (int) $u['MSG_NO_VIEW'],
            'pendenciaFinanceira' => $user->isAluno()
                && $this->usuarios->hasPendenciaFinanceira($user->id, $user->instituicaoId),
            'instituicao' => [
                'id'          => (int) $inst['ID'],
                'nome'        => $inst['FANTASIA'],
                'titulo'      => $inst['TITULO'] ?: $inst['FANTASIA'],
                'logo'        => $inst['LOGO'],
                'corPrimaria' => InstituicaoController::safeColor($inst['COR_PRIMARIA']),
                'celular'     => $inst['CELULAR'],
            ],
        ];
        if ($user->isAdmin()) {
            $data['instituicao']['vencimento'] = $inst['DATA_VENCIMENTO'];
            $data['instituicao']['cobrarBoletos'] = (bool) $inst['COBRAR_BOLETOS'];
        }
        return Json::ok($response, $data);
    }

    /** PUT /me/senha {senhaAtual, novaSenha} */
    public function changePassword(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        Validator::make($body)->required('senhaAtual', 'novaSenha')->validate();
        $this->auth->changePassword($request->getAttribute('user'), (string) $body['senhaAtual'], (string) $body['novaSenha']);
        return Json::ok($response, ['message' => 'Senha alterada. Entre novamente nos outros dispositivos.']);
    }
}
