<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\Exception\AwsException;

/**
 * Ticket repository backed by DynamoDB.
 *
 * DynamoDB is the single source of truth. All updates (status, assignee,
 * notes) write back to the same table the Lambda populated.
 *
 * Expected item shape (may have extras the Lambda added):
 *   ticket_id       (S) — partition key
 *   created_at      (S) — ISO timestamp
 *   status          (S) — OPEN | IN_PROGRESS | RESOLVED | CLOSED
 *   question        (S)
 *   ai_attempt      (S)
 *   top_similarity  (N)
 *   user_email      (S)  [optional]
 *   assignee        (S)  [added by this app]
 *   resolution_notes(S)  [added by this app]
 *   updated_at      (S)  [added by this app]
 *   resolved_at     (S)  [added by this app]
 */
class TicketRepo {

    private static ?DynamoDbClient $client = null;
    private static ?Marshaler $marshaler = null;
    private static string $table = 'upou-helpdesk-tickets';

    public static function init(): void {
        if (self::$client === null) {
            $cfg = require __DIR__ . '/config.php';
            self::$client = new DynamoDbClient([
                'version' => 'latest',
                'region'  => $cfg['aws']['region'],
            ]);
            self::$marshaler = new Marshaler();
            self::$table = $cfg['aws']['tickets_table'];
        }
    }

    /** List all tickets, optionally filtered by status and/or assignee. */
    public static function listAll(?string $status = null, ?string $assignee = null): array {
        self::init();

        $params = ['TableName' => self::$table];
        $filters = [];
        $values  = [];
        $names   = [];

        if ($status !== null && $status !== '') {
            $filters[] = '#st = :st';
            $names['#st'] = 'status';
            $values[':st'] = ['S' => $status];
        }
        if ($assignee !== null && $assignee !== '') {
            if ($assignee === '__unassigned__') {
                $filters[] = 'attribute_not_exists(assignee) OR assignee = :empty';
                $values[':empty'] = ['S' => ''];
            } else {
                $filters[] = 'assignee = :as';
                $values[':as'] = ['S' => $assignee];
            }
        }

        if ($filters) {
            $params['FilterExpression'] = implode(' AND ', $filters);
            if ($values) $params['ExpressionAttributeValues'] = $values;
            if ($names)  $params['ExpressionAttributeNames'] = $names;
        }

        try {
            $tickets = [];
            $continuationKey = null;
            do {
                if ($continuationKey) {
                    $params['ExclusiveStartKey'] = $continuationKey;
                }
                $result = self::$client->scan($params);
                foreach ($result['Items'] as $item) {
                    $tickets[] = self::unmarshal($item);
                }
                $continuationKey = $result['LastEvaluatedKey'] ?? null;
            } while ($continuationKey);

            // Sort newest first by created_at
            usort($tickets, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
            return $tickets;
        } catch (AwsException $e) {
            error_log('DynamoDB scan failed: ' . $e->getAwsErrorMessage());
            return [];
        }
    }

    public static function get(string $ticketId): ?array {
        self::init();
        try {
            $result = self::$client->getItem([
                'TableName' => self::$table,
                'Key' => ['ticket_id' => ['S' => $ticketId]],
            ]);
            if (empty($result['Item'])) return null;
            return self::unmarshal($result['Item']);
        } catch (AwsException $e) {
            error_log('DynamoDB get failed: ' . $e->getAwsErrorMessage());
            return null;
        }
    }

    /**
     * Update fields on a ticket. Only allows the safe whitelist.
     */
    public static function update(string $ticketId, array $fields): bool {
        self::init();

        $allowed = ['status', 'assignee', 'resolution_notes'];
        $updates = [];
        $values  = [];
        $names   = [];
        $i = 0;

        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $ph = ':v' . $i;
            $np = '#f' . $i;
            $names[$np]  = $k;
            $values[$ph] = ['S' => (string) $v];
            $updates[]   = "$np = $ph";
            $i++;
        }
        if (!$updates) return false;

        // Always bump updated_at
        $names['#upd'] = 'updated_at';
        $values[':upd'] = ['S' => gmdate('Y-m-d\TH:i:s\Z')];
        $updates[] = '#upd = :upd';

        // If transitioning to RESOLVED, also set resolved_at
        if (($fields['status'] ?? null) === 'RESOLVED') {
            $names['#res'] = 'resolved_at';
            $values[':res'] = ['S' => gmdate('Y-m-d\TH:i:s\Z')];
            $updates[] = '#res = :res';
        }

        try {
            self::$client->updateItem([
                'TableName'                 => self::$table,
                'Key'                       => ['ticket_id' => ['S' => $ticketId]],
                'UpdateExpression'          => 'SET ' . implode(', ', $updates),
                'ExpressionAttributeNames'  => $names,
                'ExpressionAttributeValues' => $values,
            ]);
            return true;
        } catch (AwsException $e) {
            error_log('DynamoDB update failed: ' . $e->getAwsErrorMessage());
            return false;
        }
    }

    public static function delete(string $ticketId): bool {
        self::init();
        try {
            self::$client->deleteItem([
                'TableName' => self::$table,
                'Key'       => ['ticket_id' => ['S' => $ticketId]],
            ]);
            return true;
        } catch (AwsException $e) {
            error_log('DynamoDB delete failed: ' . $e->getAwsErrorMessage());
            return false;
        }
    }

    /** Return counts grouped by status. */
    public static function counts(): array {
        $all = self::listAll();
        $counts = [
            'TOTAL'       => count($all),
            'OPEN'        => 0,
            'IN_PROGRESS' => 0,
            'RESOLVED'    => 0,
            'CLOSED'      => 0,
            'UNASSIGNED'  => 0,
        ];
        foreach ($all as $t) {
            $s = $t['status'] ?? 'OPEN';
            if (isset($counts[$s])) $counts[$s]++;
            if (empty($t['assignee'])) $counts['UNASSIGNED']++;
        }
        return $counts;
    }

    /** Convert a DynamoDB item to a plain PHP array. */
    private static function unmarshal(array $item): array {
        $out = [];
        foreach ($item as $k => $v) {
            if (isset($v['S'])) $out[$k] = $v['S'];
            elseif (isset($v['N'])) $out[$k] = (float) $v['N'];
            elseif (isset($v['BOOL'])) $out[$k] = (bool) $v['BOOL'];
            elseif (isset($v['NULL'])) $out[$k] = null;
            else $out[$k] = null;
        }
        return $out;
    }
}
