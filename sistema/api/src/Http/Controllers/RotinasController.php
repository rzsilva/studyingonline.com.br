<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Financeiro\CobrancaService;
use App\Domain\Secretaria\InscricaoService;
use App\Support\ApiException;
use App\Support\Connection;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Rotinas diárias para o agendador de tarefas da Locaweb (que só chama URLs):
 *   POST /rotinas/diaria   com o header  X-Rotinas-Token: <ROTINAS_TOKEN do .env>
 * Sincroniza baixas de pagamento e aplica a inatividade em TODAS as instituições ativas.
 * Também pode ser chamada pela linha de comando: php bin/rotinas.php
 */
final class RotinasController
{
    public function __construct(
        private readonly Connection $db,
        private readonly CobrancaService $cobranca,
        private readonly InscricaoService $inscricoes,
        private readonly LoggerInterface $logger,
        private readonly string $token,
    ) {
    }

    public function diaria(Request $request, Response $response): Response
    {
        if ($this->token === '' || !hash_equals($this->token, $request->getHeaderLine('X-Rotinas-Token'))) {
            throw ApiException::forbidden();
        }
        return Json::ok($response, $this->executar());
    }

    public function executar(): array
    {
        $res = ['instituicoes' => 0, 'pagos' => 0, 'erros' => 0, 'inativados' => 0];
        foreach ($this->db->run('SELECT ID FROM INSTITUICAO WHERE ATIVO = 1')->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $res['instituicoes']++;
            try {
                $s = $this->cobranca->sincronizar((int) $id);
                $res['pagos'] += $s['pagos'];
                $res['erros'] += $s['erros'];
                $res['inativados'] += $this->inscricoes->rotinaInatividade((int) $id);
            } catch (\Throwable $e) {
                $res['erros']++;
                $this->logger->error("Rotina diária falhou na instituição {$id}: " . $e->getMessage());
            }
        }
        $this->logger->info('Rotina diária executada', $res);
        return $res;
    }
}
