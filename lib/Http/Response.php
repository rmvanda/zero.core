<?php
namespace Zero\Lib\Http;

/**
 * HTTP Response object
 * Encapsulates response data from HTTP requests
 */
class Response {

    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly float $responseTime,
        public readonly ?string $error,
        public readonly bool $success
    ) {}

    /**
     * Parse body as JSON
     */
    public function json(): ?array {
        if (empty($this->body)) {
            return null;
        }

        $decoded = json_decode($this->body, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * Get response body as string
     */
    public function text(): ?string {
        return $this->body;
    }

    /**
     * Check if response is successful (2xx status)
     */
    public function isSuccess(): bool {
        return $this->success;
    }

    /**
     * Check if response is a client error (4xx)
     */
    public function isClientError(): bool {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * Check if response is a server error (5xx)
     */
    public function isServerError(): bool {
        return $this->statusCode >= 500 && $this->statusCode < 600;
    }

    /**
     * Get a specific header value
     */
    public function header(string $name): ?string {
        // Case-insensitive header lookup
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Convert to array for logging
     */
    public function toArray(): array {
        return [
            'status_code' => $this->statusCode,
            'headers' => $this->headers,
            'body' => $this->body,
            'response_time_ms' => $this->responseTime,
            'error' => $this->error,
            'success' => $this->success
        ];
    }
}
