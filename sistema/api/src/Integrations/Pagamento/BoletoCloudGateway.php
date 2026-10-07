<?php

declare(strict_types=1);

namespace App\Integrations\Pagamento;

final class BoletoCloudGateway implements Gateway
{
    private const SEGUNDA_VIA = 'https://app.boletocloud.com/boleto/2via/';

    public function __construct(private readonly HttpCliente $http, private readonly string $proxyUrl, private readonly string $apiToken)
    {
    }

    public function nome(): string
    {
        return 'BoletoCloud';
    }

    public function emitir(Titulo $t, ContaGateway $conta): Emissao
    {
        $p = $t->pagador;
        $instrucao = match ($t->categoriaId) {
            2 => 'Boleto referente a Matrícula',
            3 => 'Boleto referente a Rematrícula',
            default => 'Boleto referente a Mensalidade',
        } . ' - Aguardar 24h para realizar o pagamento';
        $r = $this->http->json('POST', rtrim($this->proxyUrl, '/') . '/Criar', [
            'Debug' => false,
            'BoletoApiToken' => $this->apiToken,
            'BoletoContaToken' => $conta->token,
            'BoletoEmissao' => date('Y-m-d'),
            'BoletoVencimento' => $t->vencimento,
            'BoletoNumDocumento' => $t->id,
            'BoletoTitulo' => 'DS',
            'BoletoValor' => $t->valor,
            'BoletoInstrucao1' => $instrucao,
            'BoletoInstrucao2' => (string) $conta->instrucoes,
            'PagadorNome' => $p['nome'], 'PagadorEmail' => $p['email'], 'PagadorCpf' => $p['cpf'],
            'PagadorEndCep' => $p['cep'] ?: '00000-000', 'PagadorEndUf' => $p['uf'], 'PagadorEndMunicipio' => $p['cidade'],
            'PagadorEndBairro' => $p['bairro'], 'PagadorEndLogradouro' => $p['rua'], 'PagadorEndNumero' => $p['numero'] ?: '0',
            'PagadorEndComplemento' => '',
        ]);
        if (empty($r['Status']) || empty($r['BoletoToken'])) {
            throw new GatewayException('BoletoCloud recusou o boleto: ' . ($r['Message'] ?? 'sem detalhes'));
        }
        return new Emissao(self::SEGUNDA_VIA . $r['BoletoToken'], tokenBoleto: $r['BoletoToken'], numDocumento: (string) $t->id);
    }

    /** O proxy só lista liquidações por data; a consulta por título usa os últimos 5 dias. */
    public function consultar(Titulo $t, ContaGateway $conta): Situacao
    {
        foreach ($this->liquidacoes($conta, 5) as $token => $data) {
            if ($token === $t->tokenBoleto) {
                return new Situacao(Situacao::PAGO, $data, "boletocloud:{$token}");
            }
        }
        return new Situacao(Situacao::PENDENTE);
    }

    /** @return array<string,string> token => data de pagamento (Y-m-d) */
    public function liquidacoes(ContaGateway $conta, int $dias): array
    {
        $out = [];
        for ($d = 1; $d <= $dias; $d++) {
            $lista = $this->http->json('POST', rtrim($this->proxyUrl, '/') . '/Retorno', [
                'Debug' => false, 'BoletoApiToken' => $this->apiToken, 'BoletoContaToken' => $conta->token,
                'Data' => date('Y-m-d', strtotime("-{$d} day")),
            ]);
            foreach ($lista as $item) {
                foreach ((array) ($item['ocorrencias'] ?? []) as $oc) {
                    if (($oc['situacao'] ?? '') === 'LIQUIDACAO' && !empty($item['token'])) {
                        $out[$item['token']] = substr((string) ($oc['info']['dataDePagamento'] ?? date('Y-m-d')), 0, 10);
                    }
                }
            }
        }
        return $out;
    }

    public function urlSegundaVia(Titulo $t): ?string
    {
        return $t->tokenBoleto ? self::SEGUNDA_VIA . $t->tokenBoleto : null;
    }
}
