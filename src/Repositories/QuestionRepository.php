<?php

namespace Repositories;

use DateTimeImmutable;
use PDO;
use Support\QuestionTags;

class QuestionRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(array $data, array $tags): int
    {
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        $stmt = $this->pdo->prepare(
            'INSERT INTO question_bank (type, prompt, options_json, answer_json, explanation, difficulty, source, created_at, updated_at)
             VALUES (:type, :prompt, :options_json, :answer_json, :explanation, :difficulty, :source, :created_at, :updated_at)'
        );

        $stmt->execute([
            ':type' => $data['type'],
            ':prompt' => $data['prompt'],
            ':options_json' => $data['options_json'],
            ':answer_json' => $data['answer_json'],
            ':explanation' => $data['explanation'],
            ':difficulty' => $data['difficulty'],
            ':source' => $data['source'] ?? 'manual',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->syncTags($id, $tags);

        return $id;
    }

    public function update(int $id, array $data, array $tags): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE question_bank
             SET type = :type,
                 prompt = :prompt,
                 options_json = :options_json,
                 answer_json = :answer_json,
                 explanation = :explanation,
                 difficulty = :difficulty,
                 updated_at = :updated_at
             WHERE id = :id'
        );

        $stmt->execute([
            ':type' => $data['type'],
            ':prompt' => $data['prompt'],
            ':options_json' => $data['options_json'],
            ':answer_json' => $data['answer_json'],
            ':explanation' => $data['explanation'],
            ':difficulty' => $data['difficulty'],
            ':updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            ':id' => $id,
        ]);

        $this->syncTags($id, $tags);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM question_bank WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM question_bank WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (! $row) {
            return null;
        }

        $question = $this->hydrateQuestion($row);
        $question['tags'] = $this->getTagsForQuestions([$question['id']])[$question['id']] ?? [];
        return $question;
    }

    public function list(int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM question_bank
             ORDER BY id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (! $rows) {
            return [];
        }

        $questions = array_map(fn ($row) => $this->hydrateQuestion($row), $rows);
        $tags = $this->getTagsForQuestions(array_column($questions, 'id'));
        foreach ($questions as &$question) {
            $question['tags'] = $tags[$question['id']] ?? [];
        }
        unset($question);

        return $questions;
    }

    public function count(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) AS total FROM question_bank');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['total'] ?? 0);
    }

    public function findByTags(array $tags, int $limit = 10, ?string $type = null): array
    {
        $tags = QuestionTags::normalizeList($tags);
        $params = [];
        $sql = 'SELECT q.* FROM question_bank q';

        if ($tags !== []) {
            $placeholders = [];
            foreach ($tags as $index => $tag) {
                $key = ':tag_' . $index;
                $placeholders[] = $key;
                $params[$key] = $tag;
            }
            $sql .= ' INNER JOIN question_tags t ON q.id = t.question_id';
            $sql .= ' WHERE t.tag IN (' . implode(', ', $placeholders) . ')';
        } else {
            $sql .= ' WHERE 1=1';
        }

        if ($type !== null && $type !== '' && $type !== 'all') {
            $sql .= ' AND q.type = :type';
            $params[':type'] = $type;
        }

        $sql .= ' GROUP BY q.id ORDER BY RANDOM() LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (! $rows) {
            return [];
        }

        $questions = array_map(fn ($row) => $this->hydrateQuestion($row), $rows);
        $tags = $this->getTagsForQuestions(array_column($questions, 'id'));
        foreach ($questions as &$question) {
            $question['tags'] = $tags[$question['id']] ?? [];
        }
        unset($question);

        return $questions;
    }

    private function hydrateQuestion(array $row): array
    {
        $options = null;
        if (isset($row['options_json']) && $row['options_json'] !== null && $row['options_json'] !== '') {
            $decoded = json_decode((string) $row['options_json'], true);
            $options = is_array($decoded) ? $decoded : null;
        }

        $answer = null;
        if (isset($row['answer_json'])) {
            $decoded = json_decode((string) $row['answer_json'], true);
            $answer = $decoded !== null ? $decoded : $row['answer_json'];
        }

        return [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'prompt' => (string) $row['prompt'],
            'options' => $options,
            'answer' => $answer,
            'explanation' => $row['explanation'] ?? null,
            'difficulty' => isset($row['difficulty']) ? (int) $row['difficulty'] : null,
            'source' => $row['source'] ?? 'manual',
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function syncTags(int $questionId, array $tags): void
    {
        $normalized = QuestionTags::normalizeList($tags);
        $delete = $this->pdo->prepare('DELETE FROM question_tags WHERE question_id = :question_id');
        $delete->execute([':question_id' => $questionId]);

        if ($normalized === []) {
            return;
        }

        $insert = $this->pdo->prepare('INSERT INTO question_tags (question_id, tag) VALUES (:question_id, :tag)');
        foreach ($normalized as $tag) {
            $insert->execute([
                ':question_id' => $questionId,
                ':tag' => $tag,
            ]);
        }
    }

    private function getTagsForQuestions(array $questionIds): array
    {
        if ($questionIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($questionIds as $index => $id) {
            $key = ':id_' . $index;
            $placeholders[] = $key;
            $params[$key] = $id;
        }

        $stmt = $this->pdo->prepare(
            'SELECT question_id, tag
             FROM question_tags
             WHERE question_id IN (' . implode(', ', $placeholders) . ')
             ORDER BY tag'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) {
            $questionId = (int) $row['question_id'];
            $map[$questionId] ??= [];
            $map[$questionId][] = (string) $row['tag'];
        }

        return $map;
    }
}
