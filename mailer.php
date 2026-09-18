<?php
/**
 * Minimal, dependency-free SMTP mailer for sending status-change
 * notifications through a real email account (Gmail, Outlook/Office365,
 * or any other SMTP-with-STARTTLS provider).
 *
 * Uses only PHP's built-in streams extension - no Composer, no
 * PHPMailer. This is intentional, matching the rest of the project: it
 * needs to work on a stock shared-hosting PHP install.
 *
 * Note: this does one live SMTP connection per notification batch,
 * which typically takes 1-3 seconds (TLS handshake + auth). That's fine
 * at the scale of a small request portal; it's not built for bulk mail.
 */

require_once __DIR__ . '/config.php';

class MailException extends Exception {}

function mail_encode_header(string $value): string {
    // RFC 2047 encode header values that contain non-ASCII characters
    // (e.g. an accented name). Leaves plain ASCII untouched.
    if (preg_match('/[^\x20-\x7E]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
    return $value;
}

function smtp_read_response($socket): array {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        // The last line of a (possibly multi-line) SMTP response has a
        // space after the 3-digit code; continuation lines have a dash.
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    $code = (int) substr($response, 0, 3);
    return [$code, $response];
}

function smtp_command($socket, string $command, array $expectedCodes): string {
    fwrite($socket, $command . "\r\n");
    [$code, $response] = smtp_read_response($socket);
    if (!in_array($code, $expectedCodes, true)) {
        throw new MailException('SMTP server rejected a command (expected ' . implode('/', $expectedCodes) . ", got $code): " . trim($response));
    }
    return $response;
}

function smtp_connect_and_auth() {
    if (!function_exists('stream_socket_client')) {
        throw new MailException('The PHP streams extension is not available on this server, which email sending requires.');
    }

    // Port 465 uses implicit TLS (encrypted from the first byte).
    // Port 587 (and others) use STARTTLS (plain connection, then
    // upgraded). Some networks block one port but allow the other, so
    // supporting both gives a fallback if the default doesn't work.
    $useImplicitTls = ((int) SMTP_PORT === 465);
    $transport = $useImplicitTls ? 'ssl://' : 'tcp://';

    $socket = @stream_socket_client(
        $transport . SMTP_HOST . ':' . SMTP_PORT,
        $errno,
        $errstr,
        10
    );

    if (!$socket) {
        throw new MailException("Could not connect to $errstr ($errno) at " . SMTP_HOST . ':' . SMTP_PORT
            . '. If this keeps happening, your network may be blocking outbound SMTP - see the README troubleshooting note.');
    }

    stream_set_timeout($socket, 15);

    smtp_read_response($socket); // initial 220 greeting

    $helloName = $_SERVER['SERVER_NAME'] ?? 'localhost';
    smtp_command($socket, "EHLO $helloName", [250]);

    if (!$useImplicitTls) {
        smtp_command($socket, 'STARTTLS', [220]);

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new MailException('Could not establish a TLS connection to the SMTP server.');
        }

        // Must re-introduce ourselves after STARTTLS
        smtp_command($socket, "EHLO $helloName", [250]);
    }

    smtp_command($socket, 'AUTH LOGIN', [334]);
    smtp_command($socket, base64_encode(SMTP_USERNAME), [334]);
    smtp_command($socket, base64_encode(SMTP_PASSWORD), [235]);

    return $socket;
}

function smtp_send_one($socket, string $toEmail, string $toName, string $subject, string $bodyText): void {
    smtp_command($socket, 'MAIL FROM:<' . SMTP_FROM_EMAIL . '>', [250]);
    smtp_command($socket, "RCPT TO:<$toEmail>", [250, 251]);
    smtp_command($socket, 'DATA', [354]);

    $fromHeader = mail_encode_header(SMTP_FROM_NAME) . ' <' . SMTP_FROM_EMAIL . '>';
    $toHeader = ($toName !== '' ? mail_encode_header($toName) . ' ' : '') . "<$toEmail>";

    $headerLines = [
        'From: ' . $fromHeader,
        'To: ' . $toHeader,
        'Subject: ' . mail_encode_header($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Date: ' . date('r'),
    ];

    // Normalize line endings, then escape any line that starts with a
    // lone "." (SMTP data-termination rule) by doubling it.
    $normalizedBody = preg_replace('/\r\n|\r|\n/', "\r\n", $bodyText);
    $escapedBody = preg_replace('/^\./m', '..', $normalizedBody);

    $message = implode("\r\n", $headerLines) . "\r\n\r\n" . $escapedBody . "\r\n.";

    smtp_command($socket, $message, [250]);
}

function smtp_close($socket): void {
    @fwrite($socket, "QUIT\r\n");
    @fclose($socket);
}

/**
 * Sends the same subject/body to a list of recipients over one SMTP
 * connection. Each recipient only sees their own email (no CC/BCC
 * leakage between requester and staff).
 *
 * $recipients: array of ['email' => ..., 'name' => ... (optional)]
 *
 * Returns ['sent' => [emails...], 'failed' => [email => reason, ...]].
 * Throws MailException only if the connection/login itself fails
 * (in which case nothing was sent to anyone).
 */
function send_notification_emails(array $recipients, string $subject, string $body): array {
    if (!SMTP_ENABLED) {
        throw new MailException('Email notifications are disabled (SMTP_ENABLED is false in config.php).');
    }

    if (empty($recipients)) {
        return ['sent' => [], 'failed' => []];
    }

    $socket = smtp_connect_and_auth();
    $results = ['sent' => [], 'failed' => []];

    foreach ($recipients as $recipient) {
        $email = $recipient['email'];
        $name = $recipient['name'] ?? '';
        try {
            smtp_send_one($socket, $email, $name, $subject, $body);
            $results['sent'][] = $email;
        } catch (MailException $e) {
            $results['failed'][$email] = $e->getMessage();
        }
    }

    smtp_close($socket);

    return $results;
}
