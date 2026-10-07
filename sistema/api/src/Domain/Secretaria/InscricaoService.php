<?php

declare(strict_types=1);

namespace App\Domain\Secretaria;

use App\Domain\Auth\AuthUser;
use App\Domain\Auth\PasswordHasher;
use App\Domain\Auth\PasswordPolicy;
use App\Domain\Auth\Perfil;
use App\Domain\Usuario\UsuarioRepository;
use App\Integrations\Mail\Mailer;
use App\Integrations\Storage\LocalStorage;
use App\Domain\Trilha\ArquivoUrl;
use App\Support\ApiException;
use App\Support\Audit;
use App\Support\Connection;
use App\Support\Crud\Cpf;
use App\Support\MailTemplate;
use App\Support\RateLimiter;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Inscrição pública, aprovação pela secretaria e rematrícula
 * (antes InscricaoController + UsuarioCursoController.Rematricular).
 *
 * Diferenças intencionais em relação ao legado:
 *  - a instituição vem do HOST, nunca do corpo da requisição;
 *  - e-mail já cadastrado não é reaproveitado anonimamente (o legado matriculava qualquer
 *    conta existente e gerava cobrança para ela): a pessoa entra no sistema e se inscreve logada;
 *  - o Edit legado funcionava SEM login e gravava o USUARIO inteiro vindo do cliente;
 *    aqui aprovação/reprovação exige admin e só mexe no status;
 *  - reprovação CANCELA (situação 4) só a cobrança de matrícula, em vez de apagar
 *    todas as contas em aberto do usuário;
 *  - vencimento EAD = hoje + 3 dias (o legado chamava AddDays(3) e descartava o resultado);
 *  - rematrícula não gera cobrança em duplicidade.
 * A emissão de boleto/cartão da matrícula fica para a Fase 5 (gateways).
 */
final class InscricaoService
{
    public const PENDENTE = 1;
    public const APROVADA = 2;
    public const REPROVADA = 3;

    private const CR_A_RECEBER = 1;
    private const CR_CANCELADO = 4;
    private const CAT_MENSALIDADE = 1;
    private const CAT_MATRICULA = 2;
    private const CAT_REMATRICULA = 3;

    /** Documentos aceitos (coluna de INSCRICAO => rótulo). */
    public const DOCUMENTOS = [
        'URL_RG' => 'RG',
        'URL_CPF' => 'CPF',
        'URL_COMP_ESCOLARIDADE' => 'Comprovante de escolaridade',
        'URL_COMP_RESIDENCIA' => 'Comprovante de residência',
        'URL_CERTIDAO_CASAMENTO' => 'Certidão de casamento',
        'URL_CARTA_PASTORAL' => 'Carta pastoral',
        'URL_CONTRATO' => 'Contrato assinado',
    ];

    /** Questionário opcional do legado: camelCase => [coluna, tipo]. */
    private const QUESTIONARIO = [
        'igrejaNome' => ['IGREJA', 'usuario'], 'igrejaPastor' => ['PASTOR', 'usuario'],
        'igrejaMembro' => ['IGREJA_MEMBRO', 'bool'], 'igrejaFreqRegular' => ['IGREJA_FREQ_REGULAR', 'bool'],
        'igrejaTempo' => ['IGREJA_TEMPO', 'str'], 'igrejaTelefone' => ['IGREJA_TELEFONE', 'str'],
        'igrejaPastorNome' => ['IGREJA_PASTOR_NOME', 'str'], 'igrejaPastorEmail' => ['IGREJA_PASTOR_EMAIL', 'str'],
        'igrejaLiderNome' => ['IGREJA_LIDER_NOME', 'str'], 'igrejaLiderEmail' => ['IGREJA_LIDER_EMAIL', 'str'],
        'igrejaAtividadesEnvolvidas' => ['IGREJA_ATIVIDADES_ENVOLVIDAS', 'str'], 'igrejaHabilidades' => ['IGREJA_HABILIDADES', 'str'],
        'igrejaFePalavraInspirada' => ['IGREJA_FE_PALAVRA_INSPIRADA', 'bool'], 'igrejaFeTrindade' => ['IGREJA_FE_TRINDADE', 'bool'],
        'igrejaFeJesus' => ['IGREJA_FE_JESUS', 'bool'], 'igrejaDesviou' => ['IGREJA_DESVIOU', 'bool'],
        'dataConversao' => ['DATA_CONVERSAO', 'date'], 'dataBatismo' => ['DATA_BATISMO', 'date'],
        'igrejaBatismo' => ['IGREJA_BATISMO', 'str'], 'justificativaSalvacao' => ['JUSTIFICATIVA_SALVACAO', 'str'],
        'escolhaSeminario' => ['ESCOLHA_SEMINARIO', 'str'],
        'doencasLimitacoes' => ['DOENCAS_LIMITACOES', 'str'], 'transtornoDoenca' => ['TRANSTORNO_DOENCA', 'bool'],
        'remedioControlado' => ['REMEDIO_CONTROLADO', 'str'], 'alergiaMedicamento' => ['ALERGIA_MEDICAMENTO', 'str'],
        'saudeObservacao' => ['SAUDE_OBSERVACAO', 'str'],
        'emergenciaNome' => ['EMERGENCIA_NOME', 'str'], 'emergenciaParentesco' => ['EMERGENCIA_PARENTESCO', 'str'],
        'emergenciaTelefone' => ['EMERGENCIA_TELEFONE', 'str'], 'emergenciaCelular' => ['EMERGENCIA_CELULAR', 'str'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly UsuarioRepository $usuarios,
        private readonly PasswordHasher $hasher,
        private readonly Mailer $mailer,
        private readonly LocalStorage $storage,
        private readonly RateLimiter $limiter,
        private readonly Audit $audit,
        private readonly string $tokenSecret,
        private readonly string $appUrl,
        private readonly bool $clearLegacyPlaintext,
        private readonly LoggerInterface $logger,
    ) {
    }

    /* =========================== PÚBLICO =========================== */

    /** Cursos com matrícula aberta na instituição do host. */
    public function cursosAbertos(int $instituicaoId): array
    {
        $rows = $this->db->run(
            'SELECT c.ID, c.NOME, c.SUBTITULO, c.DESCRICAO, c.FOTO, c.VALOR, c.VALOR_MATRICULA, c.DATA_MATRICULA,
                    c.CARGA_HORARIA, c.TEMPO_CURSO, c.LIMITE_ALUNOS_TURMA, tc.VALOR AS TIPO,
                    (SELECT COUNT(*) FROM USUARIO_CURSO uc WHERE uc.CURSO_ID = c.ID) AS INSCRITOS,
                    (SELECT COUNT(*) FROM CURSO_DEPENDENCIA cd WHERE cd.CURSO_ID = c.ID) AS REQUISITOS
               FROM CURSO c LEFT JOIN LISTA_TIPO_CURSO tc ON tc.ID = c.LISTA_TIPO_CURSO_ID
              WHERE c.INSTITUICAO_ID = ? AND c.ATIVO = 1 AND c.MATRICULA_ABERTA = 1
                AND (c.DATA_MATRICULA IS NULL OR c.DATA_MATRICULA >= CURDATE())
              ORDER BY c.NOME',
            [$instituicaoId]
        )->fetchAll();
        return array_map(static fn ($c) => [
            'id' => (int) $c['ID'],
            'nome' => $c['NOME'],
            'subtitulo' => $c['SUBTITULO'],
            'descricao' => $c['DESCRICAO'],
            'foto' => $c['FOTO'],
            'tipo' => $c['TIPO'],
            'mensalidade' => (float) $c['VALOR'],
            // decimal vem como string "0.00" (verdadeira em PHP): comparar como número
            'valorMatricula' => (float) $c['VALOR_MATRICULA'] > 0 ? (float) $c['VALOR_MATRICULA'] : (float) $c['VALOR'],
            'inscricoesAte' => $c['DATA_MATRICULA'],
            'cargaHoraria' => $c['CARGA_HORARIA'] !== null ? (int) $c['CARGA_HORARIA'] : null,
            'duracaoMeses' => $c['TEMPO_CURSO'] !== null ? (int) $c['TEMPO_CURSO'] : null,
            'vagasRestantes' => $c['LIMITE_ALUNOS_TURMA'] ? max(0, (int) $c['LIMITE_ALUNOS_TURMA'] - (int) $c['INSCRITOS']) : null,
            'exigePreRequisito' => (int) $c['REQUISITOS'] > 0,
        ], $rows);
    }

    /** Inscrição de pessoa NOVA (sem cadastro). */
    public function inscrever(int $instituicaoId, array $in, string $ip): array
    {
        $this->limiter->hit("inscricao:{$ip}", 5, 3600);
        if (!empty($in['website'])) { // honeypot: campo invisível que só robôs preenchem
            throw new ApiException('Não foi possível concluir a inscrição.', 400, 'bot');
        }

        $dados = $this->validarPessoa($in);
        $curso = $this->cursoParaInscricao($instituicaoId, (int) ($in['cursoId'] ?? 0));
        if ((int) $curso['REQUISITOS'] > 0) {
            throw new ApiException('Este curso exige a conclusão de um curso pré-requisito. Entre no sistema com seu usuário para se inscrever.', 422, 'prerequisito');
        }
        if (empty($in['deAcordo'])) {
            throw ApiException::validation(['deAcordo' => 'É preciso aceitar os termos para se inscrever.']);
        }
        $forma = (int) ($in['formaPagamento'] ?? 1);
        if (!in_array($forma, [1, 2], true)) {
            throw ApiException::validation(['formaPagamento' => 'Forma de pagamento inválida.']);
        }

        $existe = $this->db->run('SELECT ID FROM USUARIO WHERE INSTITUICAO_ID = ? AND (EMAIL = ? OR (CPF IS NOT NULL AND CPF = ?)) LIMIT 1',
            [$instituicaoId, $dados['EMAIL'], $dados['CPF']])->fetchColumn();
        if ($existe) {
            throw new ApiException('Já existe um cadastro com este e-mail ou CPF. Entre no sistema (ou use "Esqueci minha senha") para se inscrever em um novo curso.', 409, 'ja_cadastrado');
        }

        $questionario = $this->questionario((array) ($in['questionario'] ?? []));

        $this->db->beginTransaction();
        try {
            $usuarioId = $this->criarAluno($instituicaoId, $curso, $dados, $questionario['usuario']);
            $senha = (string) $in['senha'];
            $this->usuarios->updatePassword($usuarioId, $senha, $this->hasher->hash($senha), $this->clearLegacyPlaintext);
            $inscricaoId = $this->criarInscricao($instituicaoId, $usuarioId, $forma, $questionario['inscricao']);
            $this->matricular($instituicaoId, $usuarioId, $curso, $inscricaoId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $matricula = (string) $this->db->run('SELECT MATRICULA FROM USUARIO WHERE ID = ?', [$usuarioId])->fetchColumn();
        $this->emailInscricao($instituicaoId, $dados['EMAIL'], $dados['NOME'], $matricula, $curso['NOME']);

        return [
            'inscricaoId' => $inscricaoId,
            'matricula' => $matricula,
            // permite enviar os documentos logo após a inscrição, sem login (válido por 24h)
            'tokenDocumentos' => $this->tokenDocumentos($inscricaoId),
            'documentos' => self::DOCUMENTOS,
        ];
    }

    /** Aluno já cadastrado (logado) se inscreve em outro curso. */
    public function inscreverLogado(AuthUser $user, int $cursoId): array
    {
        if (!$user->isAluno()) {
            throw ApiException::forbidden('Somente alunos se inscrevem em cursos.');
        }
        $curso = $this->cursoParaInscricao($user->instituicaoId, $cursoId);
        if ($this->db->run('SELECT 1 FROM USUARIO_CURSO WHERE USUARIO_ID = ? AND CURSO_ID = ?', [$user->id, $cursoId])->fetchColumn()) {
            throw new ApiException('Você já está inscrito neste curso.', 409, 'conflict');
        }
        $this->checarPreRequisitos($user->id, $user->instituicaoId, $cursoId);

        $this->db->beginTransaction();
        try {
            $inscricaoId = (int) $this->db->run('SELECT ID FROM INSCRICAO WHERE USUARIO_ID = ? AND INSTITUICAO_ID = ? ORDER BY ID LIMIT 1',
                [$user->id, $user->instituicaoId])->fetchColumn();
            if (!$inscricaoId) {
                $inscricaoId = $this->criarInscricao($user->instituicaoId, $user->id, 1, []);
            }
            $this->matricular($user->instituicaoId, $user->id, $curso, $inscricaoId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->audit->log($user, 'inscrever', 'CURSO', $cursoId);
        return ['inscricaoId' => $inscricaoId, 'curso' => $curso['NOME']];
    }

    /* ======================== DOCUMENTOS ========================= */

    public function tokenDocumentos(int $inscricaoId, int $ttl = 86400): string
    {
        $exp = time() + $ttl;
        return "{$inscricaoId}.{$exp}." . hash_hmac('sha256', "doc:{$inscricaoId}:{$exp}", $this->tokenSecret);
    }

    /** Valida o token público e devolve [inscricaoId, instituicaoId]. */
    public function validarTokenDocumentos(string $token): array
    {
        $p = explode('.', $token);
        if (count($p) !== 3 || (int) $p[1] < time()
            || !hash_equals(hash_hmac('sha256', "doc:{$p[0]}:{$p[1]}", $this->tokenSecret), $p[2])) {
            throw ApiException::unauthorized('Link de envio de documentos expirado. Entre no sistema para enviar.');
        }
        $inst = $this->db->run('SELECT INSTITUICAO_ID FROM INSCRICAO WHERE ID = ?', [(int) $p[0]])->fetchColumn();
        if (!$inst) {
            throw ApiException::notFound();
        }
        return [(int) $p[0], (int) $inst];
    }

    public function salvarDocumento(int $inscricaoId, int $instituicaoId, string $campo, UploadedFileInterface $file): void
    {
        $col = $this->colunaDocumento($campo);
        $atual = $this->db->run("SELECT {$col} FROM INSCRICAO WHERE ID = ? AND INSTITUICAO_ID = ?", [$inscricaoId, $instituicaoId])->fetch();
        if (!$atual) {
            throw ApiException::notFound();
        }
        $rel = $this->storage->save($file, $instituicaoId, 'inscricao', ['pdf', 'jpg', 'jpeg', 'png']);
        $this->db->run("UPDATE INSCRICAO SET {$col} = ? WHERE ID = ?", [ArquivoUrl::PREFIX . $rel, $inscricaoId]);
        if (str_starts_with((string) $atual[$col], ArquivoUrl::PREFIX)) {
            $this->storage->delete(substr($atual[$col], strlen(ArquivoUrl::PREFIX)));
        }
    }

    /** Caminho local do documento (ou URL absoluta do legado). */
    public function documento(AuthUser $user, int $inscricaoId, string $campo): array
    {
        $col = $this->colunaDocumento($campo);
        $row = $this->db->run("SELECT USUARIO_ID, {$col} AS URL FROM INSCRICAO WHERE ID = ? AND INSTITUICAO_ID = ?",
            [$inscricaoId, $user->instituicaoId])->fetch();
        if (!$row || !$user->canAccessUser((int) $row['USUARIO_ID']) || $user->perfil() === Perfil::Professor) {
            throw ApiException::notFound();
        }
        $url = (string) $row['URL'];
        if ($url === '') {
            throw ApiException::notFound('Documento não enviado.');
        }
        return str_starts_with($url, ArquivoUrl::PREFIX)
            ? ['path' => $this->storage->path(substr($url, strlen(ArquivoUrl::PREFIX))), 'nome' => strtolower(self::DOCUMENTOS[$col])]
            : ['url' => $url];
    }

    /** Inscrição do próprio aluno (para enviar documentos depois). */
    public function minhaInscricao(AuthUser $user): ?array
    {
        $id = $this->db->run('SELECT ID FROM INSCRICAO WHERE USUARIO_ID = ? AND INSTITUICAO_ID = ? ORDER BY ID DESC LIMIT 1',
            [$user->id, $user->instituicaoId])->fetchColumn();
        return $id ? $this->detalhe($user, (int) $id, true) : null;
    }

    /* ====================== SECRETARIA (admin) ===================== */

    public function listar(AuthUser $user, array $q): array
    {
        $where = ['i.INSTITUICAO_ID = ?'];
        $params = [$user->instituicaoId];
        if (!empty($q['status'])) {
            $where[] = 'i.STATUS_APROVACAO = ?';
            $params[] = (int) $q['status'];
        }
        if (!empty($q['cursoId'])) {
            $where[] = 'EXISTS (SELECT 1 FROM USUARIO_CURSO x WHERE x.INSCRICAO_ID = i.ID AND x.CURSO_ID = ?)';
            $params[] = (int) $q['cursoId'];
        }
        if (($busca = trim((string) ($q['q'] ?? ''))) !== '') {
            $where[] = '(u.NOME LIKE ? OR u.EMAIL LIKE ? OR u.CPF LIKE ? OR u.MATRICULA LIKE ?)';
            $like = '%' . addcslashes($busca, '%_\\') . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $w = implode(' AND ', $where);
        $page = max(1, (int) ($q['page'] ?? 1));
        $total = (int) $this->db->run("SELECT COUNT(*) FROM INSCRICAO i JOIN USUARIO u ON u.ID = i.USUARIO_ID WHERE {$w}", $params)->fetchColumn();
        $docs = implode(' + ', array_map(static fn ($c) => "(i.{$c} IS NOT NULL AND i.{$c} <> '')", array_keys(self::DOCUMENTOS)));
        $rows = $this->db->run(
            "SELECT i.ID, i.STATUS_APROVACAO, i.DATA_INSCRICAO, i.FORMA_PAGAMENTO, u.ID AS USUARIO_ID, u.NOME, u.EMAIL, u.CPF,
                    u.MATRICULA, u.INATIVO, ({$docs}) AS DOCUMENTOS,
                    (SELECT GROUP_CONCAT(c.NOME SEPARATOR ', ') FROM USUARIO_CURSO x JOIN CURSO c ON c.ID = x.CURSO_ID WHERE x.INSCRICAO_ID = i.ID) AS CURSOS
               FROM INSCRICAO i JOIN USUARIO u ON u.ID = i.USUARIO_ID
              WHERE {$w}
              ORDER BY (i.STATUS_APROVACAO = 1) DESC, COALESCE(i.DATA_INSCRICAO, u.DATA_CADASTRO) DESC
              LIMIT 25 OFFSET " . (($page - 1) * 25),
            $params
        )->fetchAll();
        return [array_map(static fn ($r) => [
            'id' => (int) $r['ID'],
            'status' => (int) $r['STATUS_APROVACAO'],
            'data' => $r['DATA_INSCRICAO'],
            'formaPagamento' => (int) $r['FORMA_PAGAMENTO'] === 2 ? 'Cartão' : 'Boleto',
            'usuarioId' => (int) $r['USUARIO_ID'],
            'nome' => $r['NOME'],
            'email' => $r['EMAIL'],
            'cpf' => $r['CPF'],
            'matricula' => $r['MATRICULA'],
            'inativo' => (bool) $r['INATIVO'],
            'documentos' => (int) $r['DOCUMENTOS'],
            'cursos' => $r['CURSOS'],
        ], $rows), ['page' => $page, 'perPage' => 25, 'total' => $total]];
    }

    public function detalhe(AuthUser $user, int $id, bool $propria = false): array
    {
        $i = $this->db->run('SELECT * FROM INSCRICAO WHERE ID = ? AND INSTITUICAO_ID = ?', [$id, $user->instituicaoId])->fetch();
        if (!$i || (!$propria && !$user->isAdmin())) {
            throw ApiException::notFound();
        }
        $u = $this->usuarios->findById((int) $i['USUARIO_ID'], $user->instituicaoId) ?? [];
        $cursos = $this->db->run('SELECT c.ID, c.NOME FROM USUARIO_CURSO x JOIN CURSO c ON c.ID = x.CURSO_ID WHERE x.INSCRICAO_ID = ?', [$id])->fetchAll();

        $questionario = [];
        foreach (self::QUESTIONARIO as $k => [$col, $tipo]) {
            // booleanos são NOT NULL no legado (0 por padrão): só "sim" é informação real
            if ($tipo === 'bool' && !(int) ($i[$col] ?? 0)) {
                continue;
            }
            if ($tipo !== 'usuario' && array_key_exists($col, $i) && $i[$col] !== null && $i[$col] !== '') {
                $questionario[$k] = $tipo === 'bool' ? (bool) $i[$col] : $i[$col];
            }
        }
        return [
            'id' => (int) $i['ID'],
            'status' => (int) $i['STATUS_APROVACAO'],
            'justificativaReprovacao' => $i['JUSTIFICATIVA_REPROVACAO'],
            'data' => $i['DATA_INSCRICAO'],
            'formaPagamento' => (int) $i['FORMA_PAGAMENTO'],
            'aluno' => [
                'id' => (int) ($u['ID'] ?? 0), 'nome' => $u['NOME'] ?? null, 'email' => $u['EMAIL'] ?? null,
                'cpf' => $u['CPF'] ?? null, 'celular' => $u['CELULAR'] ?? null, 'telefone' => $u['TELEFONE'] ?? null,
                'dataNascimento' => $u['DATA_NASCIMENTO'] ?? null, 'matricula' => $u['MATRICULA'] ?? null,
                'endereco' => trim(implode(', ', array_filter([$u['RUA'] ?? null, $u['NUMERO'] ?? null, $u['BAIRRO'] ?? null,
                    $u['CIDADE'] ?? null, $u['UF'] ?? null, $u['CEP'] ?? null]))),
                'inativo' => (bool) ($u['INATIVO'] ?? false),
            ],
            'cursos' => array_map(static fn ($c) => ['id' => (int) $c['ID'], 'nome' => $c['NOME']], $cursos),
            'questionario' => $questionario,
            'documentos' => array_map(static fn ($col, $rotulo) => [
                'campo' => $col, 'rotulo' => $rotulo, 'enviado' => !empty($i[$col]),
            ], array_keys(self::DOCUMENTOS), self::DOCUMENTOS),
        ];
    }

    /** Aprova (2) ou reprova (3) uma inscrição pendente e avisa o candidato por e-mail. */
    public function decidir(AuthUser $user, int $id, int $status, ?string $justificativa): array
    {
        if (!in_array($status, [self::APROVADA, self::REPROVADA], true)) {
            throw ApiException::validation(['status' => 'Use 2 (aprovar) ou 3 (reprovar).']);
        }
        $justificativa = $justificativa !== null ? trim($justificativa) : null;
        if ($status === self::REPROVADA && !$justificativa) {
            throw ApiException::validation(['justificativa' => 'Informe o motivo da reprovação.']);
        }
        $i = $this->db->run('SELECT ID, USUARIO_ID, STATUS_APROVACAO FROM INSCRICAO WHERE ID = ? AND INSTITUICAO_ID = ?',
            [$id, $user->instituicaoId])->fetch();
        if (!$i) {
            throw ApiException::notFound();
        }
        if ((int) $i['STATUS_APROVACAO'] !== self::PENDENTE) {
            throw new ApiException('Esta inscrição já foi analisada.', 409, 'conflict');
        }

        $this->db->beginTransaction();
        try {
            $this->db->run('UPDATE INSCRICAO SET STATUS_APROVACAO = ?, JUSTIFICATIVA_REPROVACAO = ? WHERE ID = ?',
                [$status, $status === self::REPROVADA ? mb_substr($justificativa, 0, 2000) : null, $id]);
            if ($status === self::REPROVADA) {
                $this->db->run(
                    'UPDATE CONTAS_RECEBER SET LISTA_SITUACAO_CR_ID = ?, DATA_EDICAO = NOW()
                      WHERE USUARIO_ID = ? AND INSTITUICAO_ID = ? AND LISTA_SITUACAO_CR_ID = ? AND LISTA_CATEGORIA_CR_ID = ?',
                    [self::CR_CANCELADO, (int) $i['USUARIO_ID'], $user->instituicaoId, self::CR_A_RECEBER, self::CAT_MATRICULA]
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $u = $this->usuarios->findById((int) $i['USUARIO_ID'], $user->instituicaoId);
        if ($u) {
            $status === self::APROVADA
                ? $this->email($user->instituicaoId, $u['EMAIL'], 'INSCRIÇÃO APROVADA',
                    '<p>Prezado(a) <b>' . MailTemplate::e($u['NOME']) . '</b>,</p><p>Parabéns! Sua inscrição foi <b>aprovada</b>. '
                    . 'Sua matrícula será ativada após a confirmação do pagamento da taxa de matrícula.</p>')
                : $this->email($user->instituicaoId, $u['EMAIL'], 'INSCRIÇÃO NÃO APROVADA',
                    '<p>Prezado(a) <b>' . MailTemplate::e($u['NOME']) . '</b>,</p><p>Infelizmente sua inscrição não foi aprovada.</p>'
                    . '<p><b>Motivo:</b> ' . nl2br(MailTemplate::e((string) $justificativa)) . '</p>'
                    . '<p>Em caso de dúvidas, entre em contato com a secretaria.</p>');
        }
        $this->audit->log($user, $status === self::APROVADA ? 'aprovar' : 'reprovar', 'INSCRICAO', $id);
        return ['status' => $status];
    }

    /* ========================= REMATRÍCULA ======================== */

    /** Cursos do aluno com rematrícula aberta. */
    public function rematriculasDisponiveis(AuthUser $user): array
    {
        $rows = $this->db->run(
            'SELECT c.ID, c.NOME, c.DATA_REMATRICULA, c.VALOR, c.VALOR_REMATRICULA, u.DESCONTO,
                    (SELECT COUNT(*) FROM CONTAS_RECEBER cr WHERE cr.USUARIO_ID = u.ID AND cr.INSTITUICAO_ID = c.INSTITUICAO_ID
                       AND cr.DATA_VENCIMENTO = c.DATA_REMATRICULA AND cr.LISTA_CATEGORIA_CR_ID IN (1, 3)
                       AND cr.LISTA_SITUACAO_CR_ID <> 4) AS JA_FEITA
               FROM USUARIO_CURSO uc JOIN CURSO c ON c.ID = uc.CURSO_ID JOIN USUARIO u ON u.ID = uc.USUARIO_ID
              WHERE uc.USUARIO_ID = ? AND uc.INSTITUICAO_ID = ? AND c.REMATRICULA_ABERTA = 1
                AND c.DATA_REMATRICULA IS NOT NULL AND c.DATA_REMATRICULA >= CURDATE()',
            [$user->id, $user->instituicaoId]
        )->fetchAll();
        return array_map(static fn ($r) => [
            'cursoId' => (int) $r['ID'],
            'curso' => $r['NOME'],
            'prazo' => $r['DATA_REMATRICULA'],
            'valor' => max(0, ((float) $r['VALOR_REMATRICULA'] > 0 ? (float) $r['VALOR_REMATRICULA'] : (float) $r['VALOR']) - (float) $r['DESCONTO']),
            'realizada' => (int) $r['JA_FEITA'] > 0,
        ], $rows);
    }

    public function rematricular(AuthUser $user, int $cursoId): array
    {
        $disp = array_values(array_filter($this->rematriculasDisponiveis($user), static fn ($r) => $r['cursoId'] === $cursoId));
        if (!$disp) {
            throw new ApiException('Rematrícula indisponível para este curso (período encerrado ou não aberto).', 422, 'fechada');
        }
        $r = $disp[0];
        if ($r['realizada']) {
            throw new ApiException('Você já fez a rematrícula neste período.', 409, 'conflict');
        }
        $curso = $this->db->run('SELECT VALOR_REMATRICULA FROM CURSO WHERE ID = ?', [$cursoId])->fetch();

        if ($r['valor'] > 0) {
            $this->db->run(
                'INSERT INTO CONTAS_RECEBER (INSTITUICAO_ID, USUARIO_ID, DATA_VENCIMENTO, LISTA_SITUACAO_CR_ID, LISTA_CATEGORIA_CR_ID, VALOR, DATA_CADASTRO)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [$user->instituicaoId, $user->id, $r['prazo'], self::CR_A_RECEBER,
                    (float) $curso['VALOR_REMATRICULA'] > 0 ? self::CAT_REMATRICULA : self::CAT_MENSALIDADE, $r['valor']]
            );
            $msg = 'Rematrícula realizada. Uma cobrança foi gerada; sua matrícula será reativada após o pagamento.';
        } else {
            $this->db->run('UPDATE USUARIO SET INATIVO = 0, DATA_UPDATE = NOW() WHERE ID = ?', [$user->id]);
            $msg = 'Rematrícula realizada. Como você possui bolsa integral, sua matrícula já está ativa.';
        }
        $u = $this->usuarios->findById($user->id, $user->instituicaoId);
        $this->email($user->instituicaoId, $u['EMAIL'], 'REMATRÍCULA',
            '<p>Prezado(a) <b>' . MailTemplate::e($u['NOME']) . '</b>,</p><p>Sua rematrícula em <b>'
            . MailTemplate::e($r['curso']) . '</b> foi realizada. Matrícula: <b>' . MailTemplate::e((string) $u['MATRICULA']) . '</b>.</p>'
            . ($r['valor'] > 0 ? '<p>Acesse o sistema para acompanhar a cobrança.</p>' : ''));
        $this->audit->log($user, 'rematricular', 'CURSO', $cursoId, null, ['valor' => $r['valor']]);
        return ['message' => $msg, 'valor' => $r['valor']];
    }

    /**
     * Rotina do legado AtualizaInatividadeUsuarios: inativa alunos cujo tempo de curso
     * (CURSO.TEMPO_CURSO meses desde a matrícula) acabou. Retorna quantos foram inativados.
     */
    public function rotinaInatividade(?int $instituicaoId = null): int
    {
        $sql = 'UPDATE USUARIO u SET u.INATIVO = 1, u.DATA_UPDATE = NOW()
                 WHERE u.INATIVO = 0 AND u.LISTA_PERFIL_ID = 3
                   AND EXISTS (SELECT 1 FROM USUARIO_CURSO uc JOIN CURSO c ON c.ID = uc.CURSO_ID
                                WHERE uc.USUARIO_ID = u.ID AND c.TEMPO_CURSO > 0
                                  AND DATE_ADD(uc.DATA_CADASTRO, INTERVAL c.TEMPO_CURSO MONTH) < NOW())
                   AND NOT EXISTS (SELECT 1 FROM USUARIO_CURSO uc JOIN CURSO c ON c.ID = uc.CURSO_ID
                                WHERE uc.USUARIO_ID = u.ID AND (COALESCE(c.TEMPO_CURSO, 0) = 0
                                  OR DATE_ADD(uc.DATA_CADASTRO, INTERVAL c.TEMPO_CURSO MONTH) >= NOW()))';
        $params = [];
        if ($instituicaoId !== null) {
            $sql .= ' AND u.INSTITUICAO_ID = ?';
            $params[] = $instituicaoId;
        }
        return $this->db->run($sql, $params)->rowCount();
    }

    /* =========================== INTERNOS ========================= */

    private function cursoParaInscricao(int $instituicaoId, int $cursoId): array
    {
        $c = $this->db->run(
            'SELECT c.*, tc.VALOR AS TIPO, (SELECT COUNT(*) FROM CURSO_DEPENDENCIA cd WHERE cd.CURSO_ID = c.ID) AS REQUISITOS,
                    (SELECT COUNT(*) FROM USUARIO_CURSO uc WHERE uc.CURSO_ID = c.ID) AS INSCRITOS
               FROM CURSO c LEFT JOIN LISTA_TIPO_CURSO tc ON tc.ID = c.LISTA_TIPO_CURSO_ID
              WHERE c.ID = ? AND c.INSTITUICAO_ID = ? AND c.ATIVO = 1',
            [$cursoId, $instituicaoId]
        )->fetch();
        if (!$c || !(bool) $c['MATRICULA_ABERTA']) {
            throw ApiException::validation(['cursoId' => 'Curso indisponível para inscrição.']);
        }
        if ($c['DATA_MATRICULA'] && substr($c['DATA_MATRICULA'], 0, 10) < date('Y-m-d')) {
            throw new ApiException('O período de matrícula deste curso se encerrou. Entre em contato com a secretaria.', 422, 'encerrado');
        }
        if ($c['LIMITE_ALUNOS_TURMA'] && (int) $c['INSCRITOS'] >= (int) $c['LIMITE_ALUNOS_TURMA']) {
            throw new ApiException('Este curso atingiu o número de vagas. Entre em contato com a secretaria.', 422, 'sem_vagas');
        }
        return $c;
    }

    /** Pré-requisito: estar inscrito no curso exigido e sem disciplina cursando/reprovada nele. */
    private function checarPreRequisitos(int $usuarioId, int $instituicaoId, int $cursoId): void
    {
        $reqs = $this->db->run(
            'SELECT cd.CURSO_REQUISITO_ID, c.NOME FROM CURSO_DEPENDENCIA cd JOIN CURSO c ON c.ID = cd.CURSO_REQUISITO_ID
              WHERE cd.CURSO_ID = ? AND cd.INSTITUICAO_ID = ?',
            [$cursoId, $instituicaoId]
        )->fetchAll();
        foreach ($reqs as $r) {
            $req = (int) $r['CURSO_REQUISITO_ID'];
            if (!$this->db->run('SELECT 1 FROM USUARIO_CURSO WHERE USUARIO_ID = ? AND CURSO_ID = ?', [$usuarioId, $req])->fetchColumn()) {
                throw new ApiException("É preciso estar inscrito no curso pré-requisito ({$r['NOME']}).", 422, 'prerequisito');
            }
            $pend = $this->db->run(
                'SELECT 1 FROM NOTA n JOIN DISCIPLINA d ON d.ID = n.DISCIPLINA_ID
                  WHERE d.CURSO_ID = ? AND n.USUARIO_ID = ? AND n.LISTA_STATUS_NOTA_ID IN (1, 3) LIMIT 1',
                [$req, $usuarioId]
            )->fetchColumn();
            if ($pend) {
                throw new ApiException("Você ainda tem disciplinas pendentes no curso pré-requisito ({$r['NOME']}).", 422, 'prerequisito');
            }
        }
    }

    /** @return array<string,mixed> colunas de USUARIO */
    private function validarPessoa(array $in): array
    {
        $e = [];
        $nome = trim((string) ($in['nome'] ?? ''));
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        $cpf = preg_replace('/\D/', '', (string) ($in['cpf'] ?? ''));
        $celular = trim((string) ($in['celular'] ?? ''));
        if (mb_strlen($nome) < 5 || !str_contains($nome, ' ')) {
            $e['nome'] = 'Informe o nome completo.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $e['email'] = 'E-mail inválido.';
        }
        if (!Cpf::valido((string) $cpf)) {
            $e['cpf'] = 'CPF inválido.';
        }
        if (strlen(preg_replace('/\D/', '', $celular)) < 10) {
            $e['celular'] = 'Informe um celular com DDD.';
        }
        $nasc = null;
        if (!empty($in['dataNascimento'])) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $in['dataNascimento']);
            if (!$d || $d > new \DateTimeImmutable('-5 years') || $d < new \DateTimeImmutable('-120 years')) {
                $e['dataNascimento'] = 'Data de nascimento inválida.';
            } else {
                $nasc = $d->format('Y-m-d');
            }
        }
        try {
            PasswordPolicy::assert((string) ($in['senha'] ?? ''), 'senha');
        } catch (ApiException $ex) {
            $e += $ex->fields;
        }
        if ($e) {
            throw ApiException::validation($e);
        }
        $str = static fn ($k, $max) => isset($in[$k]) && trim((string) $in[$k]) !== '' ? mb_substr(trim((string) $in[$k]), 0, $max) : null;
        return [
            'NOME' => mb_substr($nome, 0, 200),
            'EMAIL' => $email,
            'CPF' => Cpf::formatar((string) $cpf),
            'CELULAR' => mb_substr($celular, 0, 30),
            'TELEFONE' => $str('telefone', 30),
            'DATA_NASCIMENTO' => $nasc,
            'SEXO' => in_array($in['sexo'] ?? null, ['M', 'F'], true) ? $in['sexo'] : null,
            'RG' => $str('rg', 30),
            'CEP' => $str('cep', 10),
            'RUA' => $str('rua', 200),
            'NUMERO' => isset($in['numero']) && is_numeric($in['numero']) ? (int) $in['numero'] : null,
            'BAIRRO' => $str('bairro', 100),
            'CIDADE' => $str('cidade', 100),
            'UF' => ($uf = $str('uf', 2)) ? strtoupper($uf) : null,
            'NACIONALIDADE' => $str('nacionalidade', 100),
            'NATURALIDADE' => $str('naturalidade', 100),
        ];
    }

    /** Separa o questionário em colunas de INSCRICAO e de USUARIO (só chaves conhecidas). */
    private function questionario(array $q): array
    {
        $ins = [];
        $usu = [];
        foreach (self::QUESTIONARIO as $k => [$col, $tipo]) {
            if (!array_key_exists($k, $q) || $q[$k] === null || $q[$k] === '') {
                continue;
            }
            $v = $q[$k];
            switch ($tipo) {
                case 'bool':
                    $ins[$col] = filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                    break;
                case 'date':
                    $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
                    if ($d) {
                        $ins[$col] = $d->format('Y-m-d');
                    }
                    break;
                case 'usuario':
                    $usu[$col] = mb_substr(trim((string) $v), 0, 200);
                    break;
                default:
                    $ins[$col] = mb_substr(trim((string) $v), 0, 2000);
            }
        }
        return ['inscricao' => $ins, 'usuario' => $usu];
    }

    private function criarAluno(int $instituicaoId, array $curso, array $dados, array $extras): int
    {
        $estadoCivil = (int) ($this->db->run('SELECT MIN(ID) FROM LISTA_ESTADO_CIVIL')->fetchColumn() ?: 1);
        $row = $dados + $extras + [
            'INSTITUICAO_ID' => $instituicaoId,
            'LISTA_PERFIL_ID' => Perfil::Aluno->value,
            'LISTA_TURMA_ID' => $curso['TIPO_TURMA'] ?: null,
            'LISTA_UNIDADE_ID' => (int) $curso['LISTA_UNIDADE_ID'],
            'LISTA_ESTADO_CIVIL_ID' => $estadoCivil,
            'MATRICULA' => $this->novaMatricula($instituicaoId),
            'INATIVO' => 1, // ativa após aprovação/pagamento, como no legado
            'DESCONTO' => 0,
            'DIA_VENCIMENTO' => 20,
            'ONLINE' => 0,
            'MSG_NO_VIEW' => 0,
            'MASTER' => 0,
        ];
        $cols = array_keys($row);
        $this->db->run(
            'INSERT INTO USUARIO (' . implode(', ', $cols) . ', DATA_CADASTRO) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', NOW())',
            array_values($row)
        );
        return $this->db->lastInsertId();
    }

    /** Formato do legado: AAAA + "2" + semestre (01/02) + sequencial de 4 dígitos, garantindo unicidade. */
    private function novaMatricula(int $instituicaoId): string
    {
        $prefixo = sprintf('%04d2%02d', (int) date('Y'), (int) date('n') <= 6 ? 1 : 2);
        $n = (int) $this->db->run('SELECT COUNT(*) FROM USUARIO WHERE INSTITUICAO_ID = ? AND MATRICULA LIKE ?',
            [$instituicaoId, $prefixo . '%'])->fetchColumn();
        do {
            $mat = $prefixo . sprintf('%04d', ++$n);
        } while ($this->db->run('SELECT 1 FROM USUARIO WHERE INSTITUICAO_ID = ? AND MATRICULA = ?', [$instituicaoId, $mat])->fetchColumn());
        return $mat;
    }

    private function criarInscricao(int $instituicaoId, int $usuarioId, int $forma, array $extras): int
    {
        $row = $extras + [
            'INSTITUICAO_ID' => $instituicaoId, 'USUARIO_ID' => $usuarioId, 'STATUS_APROVACAO' => self::PENDENTE,
            'FORMA_PAGAMENTO' => $forma, 'DE_ACORDO' => 1,
            'IGREJA_MEMBRO' => 0, 'IGREJA_FREQ_REGULAR' => 0, 'IGREJA_DESVIOU' => 0, 'IGREJA_FE_PALAVRA_INSPIRADA' => 0,
            'IGREJA_FE_TRINDADE' => 0, 'IGREJA_FE_JESUS' => 0, 'TRANSTORNO_DOENCA' => 0,
        ];
        $cols = array_keys($row);
        $this->db->run(
            'INSERT INTO INSCRICAO (' . implode(', ', $cols) . ', DATA_INSCRICAO) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', NOW())',
            array_values($row)
        );
        return $this->db->lastInsertId();
    }

    /** USUARIO_CURSO + cobrança da matrícula (emissão do boleto/cartão: Fase 5). */
    private function matricular(int $instituicaoId, int $usuarioId, array $curso, int $inscricaoId): void
    {
        $this->db->run('INSERT INTO USUARIO_CURSO (INSTITUICAO_ID, USUARIO_ID, CURSO_ID, INSCRICAO_ID, DATA_CADASTRO) VALUES (?, ?, ?, ?, NOW())',
            [$instituicaoId, $usuarioId, (int) $curso['ID'], $inscricaoId]);

        if (strtoupper((string) $curso['TIPO']) === 'EAD') {
            $venc = new \DateTimeImmutable('+3 days');
        } elseif ($curso['DATA_MATRICULA']) {
            $venc = new \DateTimeImmutable(substr($curso['DATA_MATRICULA'], 0, 10));
        } else {
            $venc = new \DateTimeImmutable(date('Y-m-20'));
            if ($venc < new \DateTimeImmutable('today')) {
                $venc = $venc->modify('+1 month');
            }
        }
        $valor = (float) $curso['VALOR_MATRICULA'] > 0 ? (float) $curso['VALOR_MATRICULA'] : (float) $curso['VALOR'];
        if ($valor > 0) {
            $this->db->run(
                'INSERT INTO CONTAS_RECEBER (INSTITUICAO_ID, USUARIO_ID, DATA_VENCIMENTO, LISTA_SITUACAO_CR_ID, LISTA_CATEGORIA_CR_ID, VALOR, DATA_CADASTRO)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [$instituicaoId, $usuarioId, $venc->format('Y-m-d'), self::CR_A_RECEBER, self::CAT_MATRICULA, $valor]
            );
        }
    }

    private function colunaDocumento(string $campo): string
    {
        $col = strtoupper($campo);
        if (!isset(self::DOCUMENTOS[$col])) {
            throw ApiException::validation(['campo' => 'Documento desconhecido.']);
        }
        return $col;
    }

    private function emailInscricao(int $instituicaoId, string $email, string $nome, string $matricula, string $curso): void
    {
        $this->email($instituicaoId, $email, 'INSCRIÇÃO REALIZADA',
            '<p>Prezado(a) <b>' . MailTemplate::e($nome) . '</b>,</p>'
            . '<p>Recebemos sua inscrição no curso <b>' . MailTemplate::e($curso) . '</b>. Seu número de matrícula é <b>'
            . MailTemplate::e($matricula) . '</b>.</p>'
            . '<p>A secretaria vai analisar sua inscrição. Você pode acompanhar e enviar documentos acessando '
            . '<a href="' . MailTemplate::e($this->appUrl) . '">' . MailTemplate::e($this->appUrl) . '</a> com o e-mail e a senha cadastrados.</p>');
    }

    /** E-mail é consequência, não parte da operação: falha no envio é registrada e não desfaz nada. */
    private function email(int $instituicaoId, string $to, string $assunto, string $html): void
    {
        try {
            $inst = $this->db->run('SELECT FANTASIA, EMAIL, TELEFONE FROM INSTITUICAO WHERE ID = ?', [$instituicaoId])->fetch() ?: [];
            $this->mailer->send($to, '[' . ($inst['FANTASIA'] ?? 'Studying Online') . "] {$assunto}",
                MailTemplate::render($assunto, $html, $inst['FANTASIA'] ?? 'Studying Online', $inst['EMAIL'] ?? null, $inst['TELEFONE'] ?? null));
        } catch (\Throwable $e) {
            $this->logger->error('Falha ao enviar e-mail: ' . $e->getMessage(), ['assunto' => $assunto]);
        }
    }
}
