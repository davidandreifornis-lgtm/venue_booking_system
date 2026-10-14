<?php

/**
 * Thrown instead of exit() when Response::$throw is true (automated tests only).
 * Production never sets that flag, so behaviour there is unchanged.
 */
class ApiResponse extends RuntimeException
{
    public int $status;
    /** @var mixed */
    public $payload;

    public function __construct(int $status, $payload)
    {
        $this->status = $status;
        $this->payload = $payload;
        $msg = is_array($payload) ? (string)($payload['message'] ?? '') : '';
        parent::__construct($msg, $status);
    }
}

class Response
{
    /** Tests set this to true so json()/error() throw ApiResponse instead of exiting. */
    public static bool $throw = false;

    public static function json($data, int $status = 200): void
    {
        if (self::$throw) {
            throw new ApiResponse($status, $data);
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::json(array_merge(['message' => $message], $extra), $status);
    }

    public static function noContent(): void
    {
        if (self::$throw) {
            throw new ApiResponse(204, null);
        }
        http_response_code(204);
        exit;
    }
}
