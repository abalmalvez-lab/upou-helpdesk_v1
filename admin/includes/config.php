<?php
/**
 * Loads configuration from environment variables set by Apache (SetEnv).
 */

if (!function_exists('admin_env')) {
    function admin_env(string $key, ?string $default = null): ?string {
        $v = getenv($key);
        if ($v === false || $v === '') {
            return $default;
        }
        return $v;
    }
}

return [
    'aws' => [
        'region'         => admin_env('AWS_REGION', 'us-east-1'),
        'tickets_table'  => admin_env('DDB_TICKETS_TABLE', 'upou-helpdesk-tickets'),
    ],
    'db' => [
        'host'     => admin_env('DB_HOST', '127.0.0.1'),
        'port'     => (int) admin_env('DB_PORT', '3306'),
        'database' => admin_env('DB_NAME', 'upou_admin'),
        'user'     => admin_env('DB_USER', 'upou_admin_app'),
        'password' => admin_env('DB_PASS', ''),
    ],
    'app' => [
        'name'  => 'UPOU HelpDesk Admin',
        'debug' => admin_env('APP_DEBUG', 'false') === 'true',
    ],
];
