<?php

namespace Services;

use Exception;
use PDO;
use Repositories\PromptRepository;
use Support\QuestionTags;

class QuestionBankGenerator
{
    private AiClient $client;
    private PromptRepository $prompts;

    private const TYPES = [
        'multiple_choice',
        'fill_blank',
        'correction',
        'rewrite',
    ];

    public function __construct(PDO $pdo)
    {
        $this->client = new AiClient($pdo);
        $this->prompts = new PromptRepository($pdo);
    }

    public function generate(array $tags, string $questionType, int $count, ?int $difficulty = null): array
    {
        $tags = QuestionTags::normalizeList($tags);
        $count = max(1, min($count, 30));
        $difficulty = $difficulty === null ? 2 : max(1, min($difficulty, 5));

        $payload = [
            'tags' => $tags,
            'question_type' => $questionType,
            'count' => $count,
            'difficulty' => $difficulty,
            'language' => 'en',
        ];

        $result = $this->callPrompt('question_bank_generate', $payload);
        $questions = $result['questions'] ?? [];
        if (! is_array($questions)) {
            return [];
        }

        $normalized = [];
        foreach ($questions as $question) {
            if (! is_array($question)) {
                continue;
            }
            $item = $this->normalizeQuestion($question, $questionType, $tags, $difficulty);
            if ($item !== null) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    private function callPrompt(string $key, array $payload): array
    {
        $prompt = $this->prompts->getByKey($key);
        if (! $prompt) {
            throw new Exception('Prompt not configured for ' . $key);
        }

        $messages = [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        ];

        return $this->client->chat($messages);
    }

    private function normalizeQuestion(array $question, string $questionType, array $tags, int $difficulty): ?array
    {
        $type = $this->normalizeType((string) ($question['type'] ?? ''));
        if ($type === null) {
            $type = $questionType === 'mixed' ? 'multiple_choice' : $this->normalizeType($questionType);
        }
        if ($type === null) {
            return null;
        }

        $prompt = trim((string) ($question['prompt'] ?? ''));
        if ($prompt === '') {
            return null;
        }

        $options = null;
        if ($type === 'multiple_choice') {
            $rawOptions = $question['options'] ?? [];
            if (is_string($rawOptions)) {
                $rawOptions = preg_split('/[\r\n\|]+/u', $rawOptions) ?: [];
            }
            if (is_array($rawOptions)) {
                $options = array_values(array_filter(array_map('trim', $rawOptions), fn ($value) => $value !== ''));
            }
            if ($options === null || count($options) < 3) {
                return null;
            }
        }

        $answer = $question['answer'] ?? null;
        if ($answer === null || $answer === '') {
            return null;
        }

        $questionTags = QuestionTags::normalizeList($question['tags'] ?? $tags);
        if ($questionTags === []) {
            $questionTags = $tags;
        }

        $normalizedDifficulty = isset($question['difficulty']) ? (int) $question['difficulty'] : $difficulty;
        if ($normalizedDifficulty < 1 || $normalizedDifficulty > 5) {
            $normalizedDifficulty = $difficulty;
        }

        return [
            'type' => $type,
            'prompt' => $prompt,
            'options' => $options,
            'answer' => $answer,
            'explanation' => trim((string) ($question['explanation'] ?? '')),
            'difficulty' => $normalizedDifficulty,
            'tags' => $questionTags,
        ];
    }

    private function normalizeType(string $type): ?string
    {
        $type = trim($type);
        if ($type === '') {
            return null;
        }

        if (! in_array($type, self::TYPES, true)) {
            return null;
        }

        return $type;
    }
}
