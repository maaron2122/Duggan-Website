<?php

$config = require __DIR__ . '/contact-config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_with_status('error');
}

$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$company = trim((string) ($_POST['company'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));
$honeypot = trim((string) ($_POST['website'] ?? ''));

if ($honeypot !== '') {
    redirect_with_status('success');
}

if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_with_status('error');
}

if (($config['password'] ?? '') === 'REPLACE_WITH_MAILBOX_PASSWORD') {
    redirect_with_status('error');
}

$safeName = clean_header_value($name);
$safeEmail = clean_header_value($email);
$safeCompany = str_replace(["\r", "\n"], [' ', ' '], $company);
$safeMessage = str_replace(["\r\n", "\r"], "\n", $message);

$subject = 'Nightshade Technologies Inquiry';
$plainBody = implode("\r\n", [
    'New contact form submission from the Nightshade Technologies website.',
    '',
    'Name: ' . $safeName,
    'Email: ' . $safeEmail,
    'Company: ' . ($safeCompany !== '' ? $safeCompany : 'Not provided'),
    '',
    'Message:',
    $safeMessage,
]);

$htmlBody = '<html><body style="font-family:Arial,sans-serif;line-height:1.6;color:#111;">'
    . '<h2>Nightshade Technologies Inquiry</h2>'
    . '<p><strong>Name:</strong> ' . htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8') . '</p>'
    . '<p><strong>Email:</strong> ' . htmlspecialchars($safeEmail, ENT_QUOTES, 'UTF-8') . '</p>'
    . '<p><strong>Company:</strong> ' . htmlspecialchars($safeCompany !== '' ? $safeCompany : 'Not provided', ENT_QUOTES, 'UTF-8') . '</p>'
    . '<p><strong>Message:</strong></p>'
    . '<p>' . nl2br(htmlspecialchars($safeMessage, ENT_QUOTES, 'UTF-8')) . '</p>'
    . '</body></html>';

try {
    smtp_send_mail($config, $subject, $plainBody, $htmlBody, $safeEmail, $safeName);
    redirect_with_status('success');
} catch (Throwable $exception) {
    redirect_with_status('error');
}

function redirect_with_status(string $status): void
{
    header('Location: index.html?contact=' . rawurlencode($status) . '#contact');
    exit;
}

function clean_header_value(string $value): string
{
    return trim(str_replace(["\r", "\n"], [' ', ' '], $value));
}

function smtp_send_mail(array $config, string $subject, string $plainBody, string $htmlBody, string $replyToEmail, string $replyToName): void
{
    $transport = open_smtp_transport($config['host'], (int) $config['port'], $config['encryption']);

    smtp_expect($transport, [220]);
    smtp_command($transport, 'EHLO nightshadetech.network', [250]);

    if (($config['encryption'] ?? '') === 'tls') {
        smtp_command($transport, 'STARTTLS', [220]);

        if (!stream_socket_enable_crypto($transport, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Failed to enable TLS.');
        }

        smtp_command($transport, 'EHLO nightshadetech.network', [250]);
    }

    smtp_command($transport, 'AUTH LOGIN', [334]);
    smtp_command($transport, base64_encode($config['username']), [334]);
    smtp_command($transport, base64_encode($config['password']), [235]);

    smtp_command($transport, 'MAIL FROM:<' . $config['from_email'] . '>', [250]);
    smtp_command($transport, 'RCPT TO:<' . $config['to_email'] . '>', [250, 251]);
    smtp_command($transport, 'DATA', [354]);

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . format_address($config['from_name'], $config['from_email']),
        'Reply-To: ' . format_address($replyToName, $replyToEmail),
        'MIME-Version: 1.0',
        'Subject: ' . encode_mime_header($subject),
    ];

    $boundary = 'b' . bin2hex(random_bytes(12));
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $message = implode("\r\n", $headers) . "\r\n\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . normalize_smtp_lines($plainBody) . "\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . normalize_smtp_lines($htmlBody) . "\r\n"
        . '--' . $boundary . "--\r\n.";

    fwrite($transport, normalize_smtp_lines($message) . "\r\n");
    smtp_expect($transport, [250]);
    smtp_command($transport, 'QUIT', [221]);

    fclose($transport);
}

function open_smtp_transport(string $host, int $port, string $encryption)
{
    $target = $encryption === 'ssl' ? 'ssl://' . $host : $host;
    $transport = stream_socket_client($target . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);

    if (!$transport) {
        throw new RuntimeException('SMTP connection failed: ' . $errstr);
    }

    stream_set_timeout($transport, 20);

    return $transport;
}

function smtp_command($transport, string $command, array $expectedCodes): string
{
    fwrite($transport, $command . "\r\n");

    return smtp_expect($transport, $expectedCodes);
}

function smtp_expect($transport, array $expectedCodes): string
{
    $response = '';

    while (($line = fgets($transport, 515)) !== false) {
        $response .= $line;

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException('Empty SMTP response.');
    }

    $code = (int) substr($response, 0, 3);

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Unexpected SMTP response: ' . $response);
    }

    return $response;
}

function format_address(string $name, string $email): string
{
    return encode_mime_header($name) . ' <' . $email . '>';
}

function encode_mime_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function normalize_smtp_lines(string $value): string
{
    $normalized = preg_replace("/\r\n|\r|\n/", "\r\n", $value);

    return preg_replace('/^\./m', '..', (string) $normalized);
}
