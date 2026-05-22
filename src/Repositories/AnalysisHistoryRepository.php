<?php

namespace Repositories;

use DateTimeImmutable;
use PDO;

class AnalysisHistoryRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findLatestByHash(int $userId, string $hash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, result_json, created_at, duration_ms
             FROM analysis_histories
             WHERE user_id = :user_id AND input_hash = :input_hash
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':input_hash' => $hash,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(int $userId, string $hash, string $text, array $result, ?int $durationMs = null): int
    {
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        $stmt = $this->pdo->prepare(
            'INSERT INTO analysis_histories (user_id, input_hash, input_text, result_json, duration_ms, created_at)
             VALUES (:user_id, :input_hash, :input_text, :result_json, :duration_ms, :created_at)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':input_hash' => $hash,
            ':input_text' => $text,
            ':result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
            ':duration_ms' => $durationMs,
            ':created_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function list(int $userId, int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id,
                    created_at,
                    duration_ms,
                    substr(input_text, 1, 180) AS excerpt
             FROM analysis_histories
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    }

    public function listRecentDetailed(int $userId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, created_at, result_json
             FROM analysis_histories
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    }

    public function findById(int $userId, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, input_text, result_json, created_at, duration_ms
             FROM analysis_histories
             WHERE user_id = :user_id AND id = :id
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':id' => $id,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function saveMicroPractice(int $userId, int $historyId, array $items): bool
    {
        $row = $this->findById($userId, $historyId);
        if (! $row) {
            return false;
        }

        $result = json_decode((string) ($row['result_json'] ?? ''), true);
        if (! is_array($result)) {
            $result = [];
        }

        $result['micro_practice'] = [
            'items' => array_values($items),
            'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        $stmt = $this->pdo->prepare(
            'UPDATE analysis_histories
             SET result_json = :result_json
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            ':result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
            ':id' => $historyId,
            ':user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM analysis_histories WHERE user_id = :user_id AND id = :id');
        $stmt->execute([
            ':user_id' => $userId,
            ':id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }
}
