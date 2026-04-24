<?php
/**
 * api_ticket_status.php — GET /api_ticket_status.php?ticket_id=xxx
 *                         GET /api_ticket_status.php  (returns all user's tickets)
 *
 * Fetches ticket status from DynamoDB via Lambda.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode([
            'error' => 'PHP fatal: ' . $err['message'],
            'file'  => basename($err['file'] ?? ''),
            'line'  => $err['line'] ?? 0,
        ]);
    }
});

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/aws_client.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$user = Auth::user();

$ticket_id = trim($_GET['ticket_id'] ?? '');

try {
    $payload = [
        'action' => 'ticket_status',
    ];

    if ($ticket_id !== '') {
        $payload['ticket_id'] = $ticket_id;
    } else {
        $payload['user_email'] = $user['email'] ?? '';
    }

    $result = AwsClient::invokeLambdaRaw($payload);
    http_response_code($result['status'] ?? 200);
    echo json_encode($result['data']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'  => 'Ticket status fetch failed',
        'detail' => $e->getMessage(),
    ]);
}
