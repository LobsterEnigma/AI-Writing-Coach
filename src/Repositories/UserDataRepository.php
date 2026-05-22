<?php

namespace Repositories;

use DateTimeImmutable;
use PDO;

class UserDataRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function saveDocument(?int $userId, string $sessionKey, string $filename, string $content): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_documents (user_id, session_id, filename, content, created_at) VALUES (:user_id, :session_id, :filename, :content, :created_at)');
        $stmt->execute([
            ':user_id' => $userId,
            ':session_id' => $sessionKey,
            ':filename' => $filename,
            ':content' => $content,
            ':created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    public function getDocuments(?int $userId, string $sessionKey, int $limit = 5): array
    {
        if ($userId !== null) {
            $stmt = $this->pdo->prepare('SELECT filename, content, created_at FROM user_documents WHERE user_id = :user_id OR session_id = :session_id ORDER BY created_at DESC LIMIT :limit');
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':session_id', $sessionKey);
        } else {
            $stmt = $this->pdo->prepare('SELECT filename, content, created_at FROM user_documents WHERE session_id = :session_id ORDER BY created_at DESC LIMIT :limit');
            $stmt->bindValue(':session_id', $sessionKey);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        return $rows ?: [];
    }

    public function saveChatMessage(?int $userId, string $sessionKey, string $role, string $message): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO chat_messages (user_id, session_id, role, message, created_at) VALUES (:user_id, :session_id, :role, :message, :created_at)');
        $stmt->execute([
            ':user_id' => $userId,
            ':session_id' => $sessionKey,
            ':role' => $role,
            ':message' => $message,
            ':created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    public function getRecentChat(?int $userId, string $sessionKey, int $limit = 10): array
    {
        if ($userId !== null) {
            $stmt = $this->pdo->prepare('SELECT role, message FROM chat_messages WHERE user_id = :user_id OR session_id = :session_id ORDER BY id DESC LIMIT :limit');
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':session_id', $sessionKey);
        } else {
            $stmt = $this->pdo->prepare('SELECT role, message FROM chat_messages WHERE session_id = :session_id ORDER BY id DESC LIMIT :limit');
            $stmt->bindValue(':session_id', $sessionKey);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        return array_reverse($rows ?: []);
    }
}
