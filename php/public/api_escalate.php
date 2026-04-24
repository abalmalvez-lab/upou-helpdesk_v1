<?php
/**
 * api_escalate.php — POST /api_escalate.php
 *
 * Called when the user clicks "Yes" to confirm they want to forward
 * their question to a human agent. Creates a DynamoDB ticket via Lambda.
 *
 * Expects JSON: { "question": "...", "ai_attempt": "...", "top_similarity": 0.0 }
 * Returns JSON: { "ticket_id": "...", "status": "OPEN", "message": "..." }
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
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

$question      = trim($data['question'] ?? '');
$ai_attempt    = trim($data['ai_attempt'] ?? '');
$top_similarity = floatval($data['top_similarity'] ?? 0);

if ($question === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Question is required']);
    exit;
}

try {
    $payload = [
        'action'         => 'escalate',
        'question'       => $question,
        'ai_attempt'     => $ai_attempt,
        'top_similarity' => $top_similarity,
        'user_email'     => $user['email'] ?? null,
    ];

    $result = AwsClient::invokeLambdaRaw($payload);

    // Update the chat_history row to record the ticket_id
    if (!empty($result['data']['ticket_id'])) {
        try {
            $pdo = Database::get();
            $stmt = $pdo->prepare(
                'UPDATE chat_history SET ticket_id = ? WHERE user_id = ? AND question = ? AND ticket_id IS NULL ORDER BY created_at DESC LIMIT 1'
            );
            $stmt->execute([
                $result['data']['ticket_id'],
                $user['id'],
                $question,
            ]);
        } catch (Throwable $e) {
            error_log('ticket_id update failed: ' . $e->getMessage());
        }
    }

    http_response_code($result['status'] ?? 200);
    echo json_encode($result['data']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'  => 'Escalation failed',
        'detail' => $e->getMessage(),
    ]);
}
