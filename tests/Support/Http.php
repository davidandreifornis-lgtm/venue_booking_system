<?php
declare(strict_types=1);

/** Minimal cookie-aware JSON client for the PHP built-in server. */
final class Http
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'vbsjar');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** @return array{status:int,json:mixed,raw:string} */
    public function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $url = $this->base . '/backend/api/index.php?' . http_build_query(['r' => $path] + $query);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'json' => json_decode($raw, true), 'raw' => $raw];
    }

    public function login(string $email, string $password): array
    {
        return $this->request('POST', '/auth/login', ['email' => $email, 'password' => $password]);
    }
}
