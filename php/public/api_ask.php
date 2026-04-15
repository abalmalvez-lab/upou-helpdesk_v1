<?php
/**
 * api_ask.php — POST /api_ask.php
 *
 * Receives a JSON body { "question": "..." }, invokes the Lambda,
 * and returns the JSON response. Per-user history is also written to
 * MySQL (best-effort).
 *
 * Hardened against PHP fatal errors: a shutdown handler returns any
 * fatal as a JSON 500 instead of the default HTML error page (which
 * would cause "Unexpected end of JSON input" in the browser).
 */

// Force JSON-shaped errors even on PHP fatals
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

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
$question = trim($data['question'] ?? '');

if ($question === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Question is required']);
    exit;
}

try {
    $result = AwsClient::invokeAi($question, $user['email'] ?? null);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Lambda invoke failed',
        'detail' => $e->getMessage(),
    ]);
    exit;
}

// Optional: write per-user chat history to MySQL (best-effort, ignore failures)
try {
    $pdo = Database::get();
    $stmt = $pdo->prepare(
        'INSERT INTO chat_history
            (user_id, question, answer, source_label, top_similarity, ticket_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        $user['id'],
        $question,
        $result['data']['answer'] ?? '',
        $result['data']['source_label'] ?? null,
        $result['data']['top_similarity'] ?? null,
        $result['data']['ticket_id'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('history insert failed: ' . $e->getMessage());
}

http_response_code($result['status'] ?? 200);
echo json_encode($result['data']);
