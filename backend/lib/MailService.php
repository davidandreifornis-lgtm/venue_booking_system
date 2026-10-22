<?php

/**
 * Simple e-mail sender. Uses PHP mail() by default.
 * When email_smtp_host is set, falls back to a basic socket SMTP (no external deps).
 * Never throws — failures are recorded and returned.
 */
class MailService
{
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
        $from = Settings::get('email_from') ?: 'noreply@localhost';
        $host = trim((string)Settings::get('email_smtp_host'));
        $text = $bodyText ?? strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml));

        if ($host === '') {
            return self::sendViaMail($to, $from, $subject, $bodyHtml, $text);
        }

        return self::sendViaSmtp($to, $from, $subject, $bodyHtml, $text);
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
        $host = Settings::get('email_smtp_host');
        $port = (int)(Settings::get('email_smtp_port') ?: 587);
        $user = Settings::get('email_smtp_user');
        $pass = Settings::get('email_smtp_pass');

        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, 15);
        if (!$fp) {
            return ['ok' => false, 'error' => "SMTP connect failed: {$errstr} ({$errno})"];
        }

        $read = function () use ($fp) {
            $data = '';
            while ($line = fgets($fp, 512)) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $data;
        };
        $write = function (string $cmd) use ($fp) {
            fwrite($fp, $cmd . "\r\n");
        };

        try {
            $read(); // banner
            $write('EHLO venuebooking.local');
            $read();
            if ($user !== '' && $pass !== '') {
                $write('AUTH LOGIN');
                $read();
                $write(base64_encode($user));
                $read();
                $write(base64_encode($pass));
                $auth = $read();
                if (strpos($auth, '235') === false) {
                    fclose($fp);
                    return ['ok' => false, 'error' => 'SMTP AUTH failed: ' . trim($auth)];
                }
            }
            $write('MAIL FROM:<' . $from . '>');
            $read();
            $write('RCPT TO:<' . $to . '>');
            $read();
            $write('DATA');
            $read();

            $boundary = 'b_' . bin2hex(random_bytes(8));
            $data = "From: {$from}\r\nTo: {$to}\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
                . "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n"
                . "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$text}\r\n"
                . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n"
                . "--{$boundary}--\r\n.";
            $write($data);
            $resp = $read();
            $write('QUIT');
            fclose($fp);

            if (strpos($resp, '250') === false) {
                return ['ok' => false, 'error' => 'SMTP DATA rejected: ' . trim($resp)];
            }
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            @fclose($fp);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
