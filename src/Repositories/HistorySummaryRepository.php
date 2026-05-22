<?php

namespace Repositories;

use DateTimeImmutable;
use PDO;

class HistorySummaryRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByHash(int $userId, string $hash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, summary_json, created_at
             FROM history_summaries
             WHERE user_id = :user_id AND history_hash = :history_hash
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':history_hash' => $hash,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function latestForUser(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, summary_json, created_at, history_hash, history_ids
             FROM history_summaries
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function deleteForUser(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM history_summaries WHERE user_id = :user_id');
        $stmt->execute([
            ':user_id' => $userId,
        ]);
    }

    public function create(int $userId, string $hash, array $historyIds, array $summary): int
    {
        $this->deleteForUser($userId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO history_summaries (user_id, history_hash, history_ids, summary_json, created_at)
             VALUES (:user_id, :history_hash, :history_ids, :summary_json, :created_at)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':history_hash' => $hash,
            ':history_ids' => json_encode($historyIds, JSON_UNESCAPED_UNICODE),
            ':summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE),
            ':created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
