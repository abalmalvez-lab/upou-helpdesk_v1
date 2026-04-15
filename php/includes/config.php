<?php
/**
 * Loads configuration from environment variables (set by Apache via SetEnv
 * or the systemd service file).
 *
 * The env() function is wrapped in function_exists() because this file
 * is included multiple times per request via require (once for db.php,
 * once for aws_client.php, etc.) and PHP would otherwise complain
 * "Cannot redeclare function env()".
 */

if (!function_exists('env')) {
    function env(string $key, ?string $default = null): ?string {
        $v = getenv($key);
        if ($v === false || $v === '') {
            return $default;
        }
        return $v;
    }
}

return [
    'aws' => [
        'region'           => env('AWS_REGION', 'us-east-1'),
        'lambda_function'  => env('LAMBDA_FUNCTION_NAME', 'ai-webapp-handler'),
        's3_bucket'        => env('S3_BUCKET'),
        's3_prefix'        => env('S3_PREFIX', 'logs/'),
    ],
    'db' => [
        'host'     => env('DB_HOST', '127.0.0.1'),
        'port'     => (int) env('DB_PORT', '3306'),
        'database' => env('DB_NAME', 'upou_helpdesk'),
        'user'     => env('DB_USER', 'upou_app'),
        'password' => env('DB_PASS', ''),
    ],
    'app' => [
        'name'      => 'UPOU AI HelpDesk',
        'base_url'  => env('APP_BASE_URL', ''),
        'debug'     => env('APP_DEBUG', 'false') === 'true',
    ],
];
