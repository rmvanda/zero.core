<?php

namespace Zero\Core\Attribute;

use \Attribute;
use \Zero\Core\Console;
use \Zero\Core\Request;
use \Zero\Entity\Event;

#[Attribute]
class AuditLog {

    private string $eventType;
    private bool $includeParams;
    private bool $requireAuth;

    /**
     * AuditLog constructor
     *
     * @param string $eventType The event type/category to log (e.g., 'admin_action', 'user_data_access')
     * @param bool $includeParams Whether to include request parameters in the log (default: false)
     * @param bool $requireAuth Whether to only log if user is authenticated (default: false)
     */
    public function __construct(
        string $eventType,
        bool $includeParams = false,
        bool $requireAuth = false
    ) {
        $this->eventType = $eventType;
        $this->includeParams = $includeParams;
        $this->requireAuth = $requireAuth;
    }

    /**
     * Log the endpoint access to the event table
     *
     * @return bool Always returns true (logging should not block execution)
     */
    public function handler(): bool {
        @session_start();

        // Skip logging if requireAuth is true and user is not authenticated
        if ($this->requireAuth && (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']))) {
            Console::debug("AuditLog skipped: requireAuth=true but user not authenticated");
            return true;
        }

        // Build endpoint identifier
        $endpoint = Request::$module . '/' . Request::$endpoint;

        // Build detail string
        $detail = "Endpoint: {$endpoint}";

        // Add user info if available
        if (isset($_SESSION['user_id'])) {
            $detail .= " | User ID: {$_SESSION['user_id']}";
        }

        // Add username if available
        if (isset($_SESSION['username'])) {
            $detail .= " | Username: {$_SESSION['username']}";
        }

        // Add request method
        $detail .= " | Method: " . Request::$method;

        // Add request parameters if requested
        if ($this->includeParams) {
            $params = $this->sanitizeParams();
            if (!empty($params)) {
                $detail .= " | Params: " . json_encode($params);
            }
        }

        // Add user agent
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = substr($_SERVER['HTTP_USER_AGENT'], 0, 100); // Truncate for brevity
            $detail .= " | UA: {$userAgent}";
        }

        try {
            // Create Event entity (automatically adds datetime and IP)
            new Event([
                "event" => $this->eventType,
                "detail" => $detail
            ]);

            Console::debug("AuditLog: Logged '{$this->eventType}' event for {$endpoint}");
        } catch (\Exception $e) {
            // Log error but don't block execution
            Console::error("AuditLog failed: " . $e->getMessage());
        }

        return true;
    }

    /**
     * Sanitize request parameters to remove sensitive data
     *
     * @return array Sanitized parameters
     */
    private function sanitizeParams(): array {
        $params = array_merge($_GET, $_POST);

        // Remove sensitive fields
        $sensitiveKeys = [
            'password',
            'passwd',
            'pwd',
            'pass',
            'secret',
            'token',
            'api_key',
            'apikey',
            'auth',
            'authorization',
            'csrf',
            'csrf_token'
        ];

        foreach ($sensitiveKeys as $key) {
            if (isset($params[$key])) {
                $params[$key] = '[REDACTED]';
            }
        }

        // Limit parameter value length to prevent huge logs
        foreach ($params as $key => $value) {
            if (is_string($value) && strlen($value) > 200) {
                $params[$key] = substr($value, 0, 200) . '... [truncated]';
            }
        }

        return $params;
    }
}
