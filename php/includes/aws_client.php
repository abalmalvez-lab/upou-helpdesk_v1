<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Aws\Lambda\LambdaClient;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class AwsClient {
    private static ?LambdaClient $lambda = null;
    private static ?S3Client $s3 = null;
    private static array $cfg;

    private static function cfg(): array {
        if (!isset(self::$cfg)) {
            self::$cfg = require __DIR__ . '/config.php';
        }
        return self::$cfg;
    }

    public static function lambda(): LambdaClient {
        if (self::$lambda === null) {
            $cfg = self::cfg()['aws'];
            self::$lambda = new LambdaClient([
                'version' => 'latest',
                'region'  => $cfg['region'],
            ]);
        }
        return self::$lambda;
    }

    public static function s3(): S3Client {
        if (self::$s3 === null) {
            $cfg = self::cfg()['aws'];
            self::$s3 = new S3Client([
                'version' => 'latest',
                'region'  => $cfg['region'],
            ]);
        }
        return self::$s3;
    }

    public static function invokeAi(string $question, ?string $userEmail = null): array {
        $payload = ['question' => $question];
        if ($userEmail !== null) {
            $payload['user_email'] = $userEmail;
        }
        return self::invokeLambdaRaw($payload);
    }

    /**
     * Generic Lambda invoke — accepts any payload dict, returns parsed response.
     * Used by api_ask.php (action=ask), api_escalate.php (action=escalate),
     * and api_ticket_status.php (action=ticket_status).
     */
    public static function invokeLambdaRaw(array $payload): array {
        $cfg = self::cfg()['aws'];
        try {
            $result = self::lambda()->invoke([
                'FunctionName'   => $cfg['lambda_function'],
                'InvocationType' => 'RequestResponse',
                'Payload'        => json_encode($payload),
            ]);
            $raw = (string) $result->get('Payload');
            $outer = json_decode($raw, true);
            if (is_array($outer) && isset($outer['body'])) {
                $body = json_decode($outer['body'], true);
                $status = $outer['statusCode'] ?? 200;
                return ['status' => $status, 'data' => $body];
            }
            return ['status' => 200, 'data' => $outer];
        } catch (AwsException $e) {
            return ['status' => 500, 'data' => ['error' => $e->getAwsErrorMessage() ?: $e->getMessage()]];
        } catch (Exception $e) {
            return ['status' => 500, 'data' => ['error' => $e->getMessage()]];
        }
    }
}
