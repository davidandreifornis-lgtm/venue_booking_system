<?php

/**
 * Simple e-mail sender. Uses PHP mail() by default.
 * When email_smtp_host is set, a small built-in SMTP client is used (no external dependencies):
 *   - port 465  -> implicit TLS (ssl://)
 *   - port 587 / any server that offers it -> STARTTLS, then AUTH LOGIN
 * Never throws - failures are returned so the caller can record them.
 *
 * Sending happens while the user's request is being served, so every network step has a short timeout and, once SMTP
 * has failed during a request, later e-mails in the same request are skipped instead of waiting again.
 */
class MailService
{
    private const CONNECT_TIMEOUT = 5;
    private const IO_TIMEOUT = 8;

    /** Set after the first SMTP failure in this request. */
    private static ?string $smtpDown = null;

    public static function isEnabled(): bool
    {
        require_once __DIR__ . '/Settings.php';
        return Settings::get('email_enabled') === '1';
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public static function send(string $to, string $subject, string $bodyHtml, ?string $bodyText = null): array
    {
        if (!self::isEnabled()) {
            return ['ok' => false, 'error' => 'Email disabled in settings'];
        }

        require_once __DIR__ . '/Settings.php';
        // Header values must never contain line breaks (header injection).
        $clean = static fn(string $v): string => trim(str_replace(["\r", "\n"], ' ', $v));
        $to = $clean($to);
        $subject = $clean($subject);
        $from = $clean((string)(Settings::get('email_from') ?: 'noreply@localhost'));
        $host = trim((string)Settings::get('email_smtp_host'));
        $text = $bodyText ?? strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Recipient is not a valid e-mail address'];
        }

        if ($host === '') {
            return self::sendViaMail($to, $from, $subject, $bodyHtml, $text);
        }
        if (self::$smtpDown !== null) {
            return ['ok' => false, 'error' => 'Skipped - SMTP already failed in this request (' . self::$smtpDown . ')'];
        }
        $res = self::sendViaSmtp($to, $from, $subject, $bodyHtml, $text);
        if (!$res['ok']) {
            self::$smtpDown = (string)$res['error'];
        }
        return $res;
    }

    private static function sendViaMail(string $to, string $from, string $subject, string $html, string $text): array
    {
        $boundary = 'b_' . bin2hex(random_bytes(8));
        $headers = [
            'From: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: VenueBookingSystem',
        ];
        $message = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\n{$text}\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n"
            . "--{$boundary}--";

        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, implode("\r\n", $headers));
        return $ok
            ? ['ok' => true, 'error' => null]
            : ['ok' => false, 'error' => 'PHP mail() returned false'];
    }

    private static function sendViaSmtp(string $to, string $from, string $subject, string $html, string $text): array
    {
        require_once __DIR__ . '/Settings.php';
        $host = trim((string)Settings::get('email_smtp_host'));
        $port = (int)(Settings::get('email_smtp_port') ?: 587);
        $user = (string)Settings::get('email_smtp_user');
        $pass = (string)Settings::get('email_smtp_pass');

        $errno = 0;
        $errstr = '';
        $target = ($port === 465 ? 'ssl://' : '') . $host;
        $fp = @fsockopen($target, $port, $errno, $errstr, self::CONNECT_TIMEOUT);
        if (!$fp) {
            return ['ok' => false, 'error' => "SMTP connect failed: {$errstr} ({$errno})"];
        }
        stream_set_timeout($fp, self::IO_TIMEOUT);

        // Reads one (possibly multi-line) reply and returns [code, fullText].
        $read = static function () use ($fp): array {
            $data = '';
            $code = 0;
            while (($line = fgets($fp, 1024)) !== false) {
                $data .= $line;
                $code = (int)substr($line, 0, 3);
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return [$code, trim($data)];
        };
        // Sends a command and checks the reply code.
        $cmd = static function (string $line, array $okCodes) use ($fp, $read): array {
            fwrite($fp, $line . "\r\n");
            [$code, $text] = $read();
            return [in_array($code, $okCodes, true), $text];
        };
        $fail = static function (string $msg) use ($fp): array {
            @fwrite($fp, "QUIT\r\n");
            @fclose($fp);
            return ['ok' => false, 'error' => $msg];
        };

        try {
            [$code, $banner] = $read();
            if ($code !== 220) {
                return $fail('SMTP greeting rejected: ' . $banner);
            }
            [$ok, $ehlo] = $cmd('EHLO venuebooking.local', [250]);
            if (!$ok) {
                return $fail('SMTP EHLO rejected: ' . $ehlo);
            }

            // Upgrade to TLS when the server offers it (required by most providers on port 587).
            if ($port !== 465 && stripos($ehlo, 'STARTTLS') !== false) {
                [$ok, $t] = $cmd('STARTTLS', [220]);
                if (!$ok) {
                    return $fail('SMTP STARTTLS rejected: ' . $t);
                }
                $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (!@stream_socket_enable_crypto($fp, true, $method)) {
                    return $fail('SMTP TLS handshake failed (check the certificate / port).');
                }
                [$ok, $ehlo] = $cmd('EHLO venuebooking.local', [250]);
                if (!$ok) {
                    return $fail('SMTP EHLO after TLS rejected: ' . $ehlo);
                }
            }

            if ($user !== '' && $pass !== '') {
                [$ok, $t] = $cmd('AUTH LOGIN', [334]);
                if (!$ok) {
                    return $fail('SMTP AUTH not accepted: ' . $t);
                }
                [$ok, $t] = $cmd(base64_encode($user), [334]);
                if (!$ok) {
                    return $fail('SMTP AUTH user rejected: ' . $t);
                }
                [$ok, $t] = $cmd(base64_encode($pass), [235]);
                if (!$ok) {
                    return $fail('SMTP AUTH failed: ' . $t);
                }
            }

            [$ok, $t] = $cmd('MAIL FROM:<' . $from . '>', [250]);
            if (!$ok) {
                return $fail('SMTP sender rejected: ' . $t);
            }
            [$ok, $t] = $cmd('RCPT TO:<' . $to . '>', [250, 251]);
            if (!$ok) {
                return $fail('SMTP recipient rejected: ' . $t);
            }
            [$ok, $t] = $cmd('DATA', [354]);
            if (!$ok) {
                return $fail('SMTP DATA rejected: ' . $t);
            }

            $boundary = 'b_' . bin2hex(random_bytes(8));
            $data = "From: {$from}\r\nTo: {$to}\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
                . 'Date: ' . date('r') . "\r\n"
                . "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n"
                . "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$text}\r\n"
                . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n"
                . "--{$boundary}--";
            // Normalise line endings and "dot-stuff" lines that begin with a dot (SMTP would end the message there).
            $data = preg_replace("/\r\n|\r|\n/", "\r\n", $data);
            $data = preg_replace('/^\./m', '..', $data);
            fwrite($fp, $data . "\r\n.\r\n");
            [$code, $resp] = $read();
            @fwrite($fp, "QUIT\r\n");
            @fclose($fp);

            if ($code !== 250) {
                return ['ok' => false, 'error' => 'SMTP message rejected: ' . $resp];
            }
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            @fclose($fp);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
