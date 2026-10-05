<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthUser;
use App\Domain\Auth\Perfil;
use App\Support\ApiException;
use App\Support\Audit;
use App\Support\Crud\CrudRepository;
use App\Support\Crud\Resource;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/** Rotas REST padrão (index/show/store/update/destroy) para um Resource. */
final class CrudController
{
    public function __construct(private readonly CrudRepository $repo, private readonly Audit $audit)
    {
    }

    public static function routes(RouteCollectorProxy $g, string $path, Resource $r): void
    {
        $g->get($path, fn (Request $q, Response $s) => self::make($g)->index($r, $q, $s));
        $g->get("{$path}/{id:[0-9]+}", fn (Request $q, Response $s, array $a) => self::make($g)->show($r, $q, $s, (int) $a['id']));
        $g->post($path, fn (Request $q, Response $s) => self::make($g)->store($r, $q, $s));
        $g->put("{$path}/{id:[0-9]+}", fn (Request $q, Response $s, array $a) => self::make($g)->update($r, $q, $s, (int) $a['id']));
        $g->delete("{$path}/{id:[0-9]+}", fn (Request $q, Response $s, array $a) => self::make($g)->destroy($r, $q, $s, (int) $a['id']));
    }

    private static function make(RouteCollectorProxy $g): self
    {
        return $g->getContainer()->get(self::class);
    }

    public function index(Resource $r, Request $request, Response $response): Response
    {
        $user = $this->authorize($request, $r->readRoles, $r, false);
        [$rows, $meta] = $this->repo->list($r, $user, $request->getQueryParams());
        return Json::ok($response, $rows, $meta);
    }

    public function show(Resource $r, Request $request, Response $response, int $id): Response
    {
        $user = $this->authorize($request, $r->readRoles, $r, false);
        return Json::ok($response, $this->repo->find($r, $user, $id));
    }

    public function store(Resource $r, Request $request, Response $response): Response
    {
        $user = $this->authorize($request, $r->writeRoles, $r, true);
        $row = $this->repo->create($r, $user, (array) $request->getParsedBody());
        $this->audit->log($user, 'criar', $r->table, (int) $row['id'], $request);
        return Json::ok($response, $row, [], 201);
    }

    public function update(Resource $r, Request $request, Response $response, int $id): Response
    {
        $user = $this->authorize($request, $r->writeRoles, $r, true);
        $row = $this->repo->update($r, $user, $id, (array) $request->getParsedBody());
        $this->audit->log($user, 'alterar', $r->table, $id, $request);
        return Json::ok($response, $row);
    }

    public function destroy(Resource $r, Request $request, Response $response, int $id): Response
    {
        $user = $this->authorize($request, $r->writeRoles, $r, true);
        $this->repo->delete($r, $user, $id);
        $this->audit->log($user, 'excluir', $r->table, $id, $request);
        return Json::noContent($response);
    }

    /**
     * @param Perfil[] $roles
     * Aluno também passa quando o recurso tem ownerColumn (leitura) e ownerCanWrite (escrita);
     * o repositório então restringe aos registros dele.
     */
    private function authorize(Request $request, array $roles, Resource $r, bool $write): AuthUser
    {
        $user = $request->getAttribute('user');
        if (!$user instanceof AuthUser) {
            throw ApiException::unauthorized();
        }
        $alunoDono = $r->ownedBy($user) && (!$write || $r->ownerCanWrite);
        if (!$user->is(...$roles) && !$alunoDono) {
            throw ApiException::forbidden();
        }
        return $user;
    }
}
