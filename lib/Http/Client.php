<?php
namespace Zero\Lib\Http;

/**
 * HTTP Client using cURL
 * Handles HTTP requests with support for various authentication methods
 */
class Client {

    private $timeout = 5000; // milliseconds
    private $headers = [];
    private $options = [];
    private $verifySSL = true;

    /**
     * Set request timeout in milliseconds
     */
    public function setTimeout(int $ms): self {
        $this->timeout = $ms;
        return $this;
    }

    /**
     * Set custom headers
     */
    public function setHeaders(array $headers): self {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    /**
     * Set a single header
     */
    public function setHeader(string $key, string $value): self {
        $this->headers[$key] = $value;
        return $this;
    }

    /**
     * Disable SSL verification (use cautiously)
     */
    public function disableSSLVerification(): self {
        $this->verifySSL = false;
        return $this;
    }

    /**
     * Set custom cURL options
     */
    public function setOption(int $option, $value): self {
        $this->options[$option] = $value;
        return $this;
    }

    /**
     * Configure authentication
     */
    public function setAuth(string $type, array $credentials): self {
        switch (strtoupper($type)) {
            case 'API_KEY':
                // API key can be in header or query param
                if (isset($credentials['header_name'])) {
                    $this->setHeader($credentials['header_name'], $credentials['key']);
                }
                break;
            case 'BEARER':
                $this->setHeader('Authorization', 'Bearer ' . $credentials['token']);
                break;

            case 'BASIC':
                $this->setOption(CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                $this->setOption(CURLOPT_USERPWD, $credentials['username'] . ':' . $credentials['password']);
                break;

            case 'OAUTH2':
                // OAuth2 typically uses Bearer token
                if (isset($credentials['access_token'])) {
                    $this->setHeader('Authorization', 'Bearer ' . $credentials['access_token']);
                }
                break;
        }

        return $this;
    }

    /**
     * Perform GET request
     */
    public function get(string $url, array $params = []): Response {
        if (!empty($params)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }
        return $this->request('GET', $url);
    }

    /**
     * Perform POST request
     */
    public function post(string $url, $data = null, bool $json = true): Response {
        return $this->request('POST', $url, $data, $json);
    }

    /**
     * Perform PUT request
     */
    public function put(string $url, $data = null, bool $json = true): Response {
        return $this->request('PUT', $url, $data, $json);
    }

    /**
     * Perform DELETE request
     */
    public function delete(string $url, $data = null, bool $json = true): Response {
        return $this->request('DELETE', $url, $data, $json);
    }

    /**
     * Perform PATCH request
     */
    public function patch(string $url, $data = null, bool $json = true): Response {
        return $this->request('PATCH', $url, $data, $json);
    }

    /**
     * Core request method
     */
    private function request(string $method, string $url, $data = null, bool $json = true): Response {
        $ch = curl_init();

        // Base options
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, min(2000, $this->timeout));

        // Set HTTP method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        // Handle request body
        if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            if ($json) {
                $body = is_string($data) ? $data : json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                $this->setHeader('Content-Type', 'application/json');
            } else {
                // Form data
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
                $this->setHeader('Content-Type', 'application/x-www-form-urlencoded');
            }
        }

        // Set headers
        if (!empty($this->headers)) {
            $headerArray = [];
            foreach ($this->headers as $key => $value) {
                $headerArray[] = "$key: $value";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);
        }

        // Apply custom options (these can override defaults)
        foreach ($this->options as $option => $value) {
            curl_setopt($ch, $option, $value);
        }

        // Track timing
        $startTime = microtime(true);

        // Execute request
        $response = curl_exec($ch);

        $responseTime = (microtime(true) - $startTime) * 1000; // Convert to ms

        // Get response info
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        curl_close($ch);

        // Handle cURL errors
        if ($errno !== 0) {
            return new Response(
                statusCode: 0,
                headers: [],
                body: null,
                responseTime: $responseTime,
                error: $error,
                success: false
            );
        }

        // Parse response
        $headerString = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $headers = $this->parseHeaders($headerString);

        return new Response(
            statusCode: $httpCode,
            headers: $headers,
            body: $body,
            responseTime: $responseTime,
            error: null,
            success: $httpCode >= 200 && $httpCode < 300
        );
    }

    /**
     * Parse HTTP headers from response
     */
    private function parseHeaders(string $headerString): array {
        $headers = [];
        $lines = explode("\r\n", $headerString);

        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            }
        }

        return $headers;
    }

    /**
     * Reset client to default state
     */
    public function reset(): self {
        $this->headers = [];
        $this->options = [];
        $this->timeout = 5000;
        $this->verifySSL = true;
        return $this;
    }
}
