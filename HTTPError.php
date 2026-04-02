<?php

namespace Zero\Core;

/**
 * HTTP error exception for clean control flow.
 *
 * Throw from anywhere in the module/response layer;
 * Application::run() catches it and renders via the existing Response instance,
 * preserving framePath and all other resolved paths.
 *
 * The legacy Error class remains available for direct instantiation.
 *
 * Usage:
 *   throw new HTTPError(404);
 *   throw new HTTPError(403);
 */
class HTTPError extends \Exception
{
    public static array $messages = [
        400 => "Bad Request",
        401 => "Unauthorized",
        402 => "Payment Required",
        403 => "Forbidden",
        404 => "Not Found",
        405 => "Method Not Allowed",
        406 => "Not Acceptable",
        407 => "Proxy Authentication Required",
        408 => "Request Timeout",
        409 => "Conflict",
        410 => "Gone",
        411 => "Length Required",
        412 => "Precondition Failed",
        413 => "Payload Too Large",
        414 => "Request-URI Too Long",
        415 => "Unsupported Media Type",
        416 => "Request Range Not Satisfiable",
        417 => "Expectation Failed",
        418 => "I'm a teapot",
        419 => "Page Expired",
        420 => "Enhance Your Calm",
        421 => "Misdirected Request",
        422 => "Unprocessable Entity",
        423 => "Locked",
        424 => "Failed Dependency",
        425 => "Too Early",
        426 => "Upgrade Required",
        428 => "Precondition Required",
        429 => "Too Many Requests",
        431 => "Request Header Fields Too Large",
        444 => "No Response",
        450 => "Blocked by Windows Parental Controls",
        451 => "Unavailable For Legal Reasons",
        495 => "SSL Certificate Error",
        496 => "SSL Certificate Required",
        497 => "HTTP Request Sent to HTTPS Port",
        498 => "Token expired/invalid",
        499 => "Client Closed Request",
        500 => "Internal Server Error",
        501 => "Not Implemented",
        502 => "Bad Gateway",
        503 => "Service Unavailable",
        504 => "Gateway Timeout",
        506 => "Variant Also Negotiates",
        507 => "Insufficient Storage",
        508 => "Loop Detected",
        509 => "Bandwidth Limit Exceeded",
        510 => "Not Extended",
        511 => "Network Authentication Required",
        521 => "Web Server Is Down",
        522 => "Connection Timed Out",
        523 => "Origin Is Unreachable",
        525 => "SSL Handshake Failed",
        530 => "Site Frozen",
        599 => "Network Connect Timeout Error",
    ];

    /** Optional detail string (e.g. what param was missing, which method was rejected) */
    public ?string $detail;

    public function __construct(int $code, ?string $detail = null)
    {
        $this->detail = $detail;
        parent::__construct(self::$messages[$code] ?? "Unspecified", $code);
    }
}
