<?php

namespace Repositories;

use DateTimeImmutable;
use PDO;

class AnalysisJobRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(int $userId, string $text, string $inputHash, bool $save, bool $force): int
    {
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        $stmt = $this->pdo->prepare(
            'INSERT INTO analysis_jobs (
                user_id, status, progress, message, input_text, input_hash, save_flag, force_flag, created_at, updated_at
            ) VALUES (
                :user_id, :status, :progress, :message, :input_text, :input_hash, :save_flag, :force_flag, :created_at, :updated_at
            )'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':status' => 'queued',
            ':progress' => 0,
            ':message' => 'Queued',
            ':input_text' => $text,
            ':input_hash' => $inputHash,
            ':save_flag' => $save ? 1 : 0,
            ':force_flag' => $force ? 1 : 0,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $userId, int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM analysis_jobs
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            ':id' => $jobId,
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function claim(int $userId, int $jobId, string $token, int $staleSeconds = 180): bool
    {
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        $staleBefore = (new DateTimeImmutable('-' . $staleSeconds . ' seconds'))->format(DATE_ATOM);
        $stmt = $this->pdo->prepare(
            'UPDATE analysis_jobs
             SET status = :status,
                 progress = CASE WHEN progress < 5 THEN 5 ELSE progress END,
                 message = :message,
                 worker_token = :worker_token,
                 started_at = COALESCE(started_at, :started_at),
                 updated_at = :updated_at
             WHERE id = :id
               AND user_id = :user_id
               AND (
                    status = "queued"
                    OR (status = "running" AND (updated_at IS NULL OR updated_at <= :stale_before))
               )'
        );
        $stmt->execute([
            ':status' => 'running',
            ':message' => 'Running analysis',
            ':worker_token' => $token,
            ':started_at' => $now,
            ':updated_at' => $now,
            ':id' => $jobId,
            ':user_id' => $userId,
            ':stale_before' => $staleBefore,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function touchRunning(int $userId, int $jobId, int $progress, string $message, string $token): void
    {
        $progress = max(0, min(99, $progress));
        $stmt = $this->pdo->prepare(
            'UPDATE analysis_jobs
             SET progress = :progress,
                 message = :message,
                 updated_at = :updated_at
             WHERE id = :id
               AND user_id = :user_id
               AND status = "running"
               AND worker_token = :worker_token'
        );
        $stmt->execute([
            ':progress' => $progress,
            ':message' => $message,
            ':updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            ':id' => $jobId,
            ':user_id' => $userId,
            ':worker_token' => $token,
        ]);
    }

    public function complete(int $userId, int $jobId, array $resultData, array $meta, string $token): void
    {
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        $stmt = $this->pdo->prepare(
            'UPDATE analysis_jobs
             SET status = :status,
                 progress = 100,
                 message = :message,
                 result_json = :result_json,
                 meta_json = :meta_json,
                 history_id = :history_id,
                 duration_ms = :duration_ms,
                 cached = :cached,
                 finished_at = :finished_at,
                 updated_at = :updated_at
             WHERE id = :id
               AND user_id = :user_id
               AND status = "running"
               AND worker_token = :worker_token'
        );
        $stmt->execute([
            ':status' => 'completed',
            ':message' => 'Analysis completed',
            ':result_json' => json_encode($resultData, JSON_UNESCAPED_UNICODE),
            ':meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            ':history_id' => isset($meta['history_id']) ? (int) $meta['history_id'] : null,
            ':duration_ms' => isset($meta['duration_ms']) ? (int) $meta['duration_ms'] : null,
            ':cached' => ! empty($meta['cached']) ? 1 : 0,
            ':finished_at' => $now,
            ':updated_at' => $now,
            ':id' => $jobId,
            ':user_id' => $userId,
            ':worker_token' => $token,
        ]);
    }

    public function fail(int $userId, int $jobId, string $error, string $token): void
    {
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        $stmt = $this->pdo->prepare(
            'UPDATE analysis_jobs
             SET status = :status,
                 progress = 100,
                 message = :message,
                 error_message = :error_message,
                 finished_at = :finished_at,
                 updated_at = :updated_at
             WHERE id = :id
               AND user_id = :user_id
               AND status = "running"
               AND worker_token = :worker_token'
        );
        $stmt->execute([
            ':status' => 'failed',
            ':message' => 'Analysis failed',
            ':error_message' => $error,
            ':finished_at' => $now,
            ':updated_at' => $now,
            ':id' => $jobId,
            ':user_id' => $userId,
            ':worker_token' => $token,
        ]);
    }
}

