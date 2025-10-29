# Future Attribute Ideas

Ideas for additional PHP attributes to eliminate boilerplate and enforce common patterns in Zero Framework.

## Security & Access Control

### `RequireHTTPS`
Enforce HTTPS connections, redirect or error on HTTP requests.
```php
#[RequireHTTPS]
public function checkout($args) {
    // Sensitive payment processing
}
```

### `CSRF`
Validate CSRF token for state-changing operations.
```php
#[CSRF]
#[AllowedMethods('POST')]
public function deleteAccount($args) {
    // Protected against CSRF attacks
}
```

### `RateLimit($requests, $window)`
Throttle requests to prevent abuse (e.g., 100 requests per minute).
```php
#[RateLimit(100, 60)]
public function search($args) {
    // Limited to 100 requests per 60 seconds
}
```

### `IPWhitelist($ips)` / `IPBlacklist($ips)`
IP-based access control.
```php
#[IPWhitelist(['10.0.0.0/8', '192.168.1.100'])]
public function adminPanel($args) {
    // Only accessible from specific IPs
}
```

## Request Validation

### `ValidateContentType($types)`
Ensure Content-Type header matches expected format.
```php
#[ValidateContentType(['application/json'])]
#[AllowedMethods('POST')]
public function apiEndpoint($args) {
    // Ensures proper JSON requests
}
```

### `ValidateParamTypes($rules)`
Type validation beyond existence checking.
```php
#[ValidateParamTypes([
    'email' => 'email',
    'age' => 'int',
    'url' => 'url',
    'amount' => 'float'
])]
public function register($args) {
    // Validates parameter types automatically
}
```

### `MaxRequestSize($bytes)`
Reject oversized requests to prevent memory exhaustion.
```php
#[MaxRequestSize(1048576)] // 1MB limit
public function uploadThumbnail($args) {
    // Prevents huge uploads
}
```

### `SanitizeParams($params)`
Auto-sanitize inputs before method execution.
```php
#[SanitizeParams(['text', 'description'])]
public function createPost($args) {
    // Parameters are pre-sanitized
}
```

## Response Control

### `OutputFormat($format)`
Force output format regardless of Accept header.
```php
#[OutputFormat('json')]
public function apiV1($args) {
    // Always returns JSON
}
```

### `CacheControl($maxAge, $public)`
Set Cache-Control headers automatically.
```php
#[CacheControl(3600, true)]
public function getPublicData($args) {
    // Cache-Control: public, max-age=3600
}
```

### `CORS($origins, $methods)`
Set CORS headers for API endpoints.
```php
#[CORS(['https://example.com'], ['GET', 'POST'])]
public function apiEndpoint($args) {
    // Access-Control-Allow-Origin headers set
}
```

### `Compress($threshold)`
Auto-compress responses over threshold size.
```php
#[Compress(1024)]
public function getLargeDataset($args) {
    // Gzip compression if response > 1KB
}
```

## Utility

### `Paginate($defaultLimit, $maxLimit)`
Auto-handle limit/offset from GET params.
```php
#[Paginate(50, 100)]
public function listItems($args) {
    // $this->limit and $this->offset automatically set
}
```

### `Deprecated($message, $sunset)`
Mark endpoint as deprecated, log warnings.
```php
#[Deprecated('Use /api/v2/users instead', '2026-01-01')]
public function oldEndpoint($args) {
    // Logs deprecation warning, adds header
}
```

### `LogRequest($level)`
Auto-log all requests to this endpoint.
```php
#[LogRequest('info')]
public function sensitiveOperation($args) {
    // Request logged automatically
}
```

### `Timeout($seconds)`
Set max_execution_time for the request.
```php
#[Timeout(300)]
public function longRunningReport($args) {
    // 5 minute execution limit
}
```

## Priority Implementation Recommendations

1. **`RateLimit`** - Prevents abuse of public endpoints like Share
2. **`ValidateContentType`** - Ensures API endpoints receive proper format
3. **`CORS`** - Essential for browser-based API access
4. **`RequireHTTPS`** - Security best practice for production
5. **`ValidateParamTypes`** - More sophisticated validation than RequiredParams
