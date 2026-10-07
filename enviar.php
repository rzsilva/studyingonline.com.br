<?php
declare(strict_types=1);

const DESTINO = 'comercial@adaline.com.br';
const REMETENTE = 'no-reply@studyingonline.com.br';

$querJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

function responder(bool $ok, string $mensagem, int $status = 200): never
{
    global $querJson;
    if ($querJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $mensagem], JSON_UNESCAPED_UNICODE);
    } else {
        header('Location: index.html' . ($ok ? '?enviado=1' : '') . '#contato', true, 303);
    }
    exit;
}

function campo(string $nome, int $max): string
{
    $valor = trim((string) ($_POST[$nome] ?? ''));
    return mb_substr($valor, 0, $max);
}

function semQuebra(string $valor): string
{
    return trim(str_replace(["\r", "\n", "\0"], ' ', $valor));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.html#contato', true, 303);
    exit;
}

// Honeypot: robôs preenchem o campo oculto
if (campo('site', 100) !== '') {
    responder(true, 'Mensagem enviada! Em breve entraremos em contato.');
}

$nome = semQuebra(campo('nome', 100));
$email = semQuebra(campo('email', 150));
$telefone = semQuebra(campo('telefone', 30));
$mensagem = campo('mensagem', 3000);

if ($nome === '' || $mensagem === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    responder(false, 'Preencha nome, um e-mail válido e a mensagem.', 422);
}

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$corpo = '<h2>Contato pelo site Studying Online</h2>'
    . '<p><b>Nome:</b> ' . $e($nome) . '</p>'
    . '<p><b>E-mail:</b> ' . $e($email) . '</p>'
    . '<p><b>Telefone:</b> ' . $e($telefone ?: '-') . '</p>'
    . '<p><b>Mensagem:</b><br>' . nl2br($e($mensagem)) . '</p>';

$assunto = '=?UTF-8?B?' . base64_encode('Contato pelo site: ' . $nome) . '?=';
$cabecalhos = [
    'MIME-Version' => '1.0',
    'Content-Type' => 'text/html; charset=UTF-8',
    'From' => 'Studying Online <' . REMETENTE . '>',
    'Reply-To' => $email,
];

$enviado = @mail(DESTINO, $assunto, $corpo, $cabecalhos, '-f' . REMETENTE);

if (!$enviado) {
    responder(false, 'Não foi possível enviar agora. Tente novamente ou escreva para ' . DESTINO . '.', 500);
}

responder(true, 'Mensagem enviada! Em breve entraremos em contato.');
