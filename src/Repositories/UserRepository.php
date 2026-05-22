<?php

namespace Repositories;

use DateTimeImmutable;
use PDO;
use Support\UserStatus;

class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    public function create(string $name, string $email, string $passwordHash): int
    {
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, account_status, status_updated_at, created_at, updated_at) VALUES (:name, :email, :password_hash, :account_status, :status_updated_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':account_status' => UserStatus::NORMAL,
            ':status_updated_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :password, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':password' => $passwordHash,
            ':updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            ':id' => $id,
        ]);
    }

    public function updateLastLogin(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = :last_login, updated_at = :updated_at WHERE id = :id');
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        $stmt->execute([
            ':last_login' => $now,
            ':updated_at' => $now,
            ':id' => $id,
        ]);
    }

    public function listForAdmin(int $limit = 300): array
    {
        $limit = max(1, min($limit, 1000));
        $stmt = $this->pdo->prepare(
            'SELECT id, name, email, account_status, status_updated_at, last_login_at, created_at, updated_at
             FROM users
             ORDER BY id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    }

    public function updateStatus(int $id, string $status): bool
    {
        $normalized = UserStatus::normalize($status);
        if (! in_array($normalized, UserStatus::all(), true)) {
            return false;
        }

        $current = $this->findById($id);
        if (! $current) {
            return false;
        }
        if (UserStatus::normalize($current['account_status'] ?? UserStatus::NORMAL) === $normalized) {
            return true;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE users
             SET account_status = :account_status,
                 status_updated_at = :status_updated_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        $stmt->execute([
            ':account_status' => $normalized,
            ':status_updated_at' => $now,
            ':updated_at' => $now,
            ':id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }
}
