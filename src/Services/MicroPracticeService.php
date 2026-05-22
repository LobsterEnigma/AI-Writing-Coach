<?php

namespace Services;

use Exception;
use PDO;
use Repositories\PromptRepository;

class MicroPracticeService
{
    private AiClient $client;
    private PromptRepository $prompts;

    public function __construct(PDO $pdo)
    {
        $this->client = new AiClient($pdo);
        $this->prompts = new PromptRepository($pdo);
    }

    public function generateFromAnalysis(string $essayText, array $analysisResult, int $limit = 4): array
    {
        $limit = max(1, min($limit, 8));

        $payload = $this->buildPayload($essayText, $analysisResult, $limit);
        $result = $this->callPrompt('targeted_micro_practice', $payload);

        $questions = $result['questions'] ?? ($result['items'] ?? []);
        if (! is_array($questions)) {
            return [];
        }

        return array_slice($this->normalizeQuestions($questions), 0, $limit);
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

    private function buildPayload(string $essayText, array $analysisResult, int $limit): array
    {
        $analysisSentences = $analysisResult['analysis']['sentences'] ?? [];
        $issuesBySentence = [];

        if (is_array($analysisSentences)) {
            foreach ($analysisSentences as $index => $sentence) {
                if (! is_array($sentence)) {
                    continue;
                }

                $issues = [];
                foreach (($sentence['issues'] ?? []) as $issue) {
                    if (! is_array($issue)) {
                        continue;
                    }

                    $issues[] = [
                        'type' => (string) ($issue['type'] ?? ''),
                        'description' => (string) ($issue['description'] ?? ''),
                        'error_excerpt' => (string) ($issue['error_excerpt'] ?? ''),
                        'corrected_form' => (string) ($issue['corrected_form'] ?? ''),
                        'practice_tip' => (string) ($issue['practice_tip'] ?? ''),
                    ];

                    if (count($issues) >= 2) {
                        break;
                    }
                }

                if ($issues === []) {
                    continue;
                }

                $issuesBySentence[] = [
                    'index' => (int) ($sentence['index'] ?? ($index + 1)),
                    'original' => (string) ($sentence['original'] ?? ''),
                    'improved_sentence' => (string) ($sentence['improved_sentence'] ?? ''),
                    'issues' => $issues,
                ];

                if (count($issuesBySentence) >= 12) {
                    break;
                }
            }
        }

        $weaknesses = [];
        $feedbackWeaknesses = $analysisResult['feedback']['weaknesses'] ?? [];
        if (is_array($feedbackWeaknesses)) {
            foreach ($feedbackWeaknesses as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $weaknesses[] = [
                    'issue' => (string) ($item['issue'] ?? ''),
                    'description' => (string) ($item['description'] ?? ''),
                    'sentence_reference' => (string) ($item['sentence_reference'] ?? ''),
                    'improvement_steps' => (string) ($item['improvement_steps'] ?? ''),
                ];

                if (count($weaknesses) >= 8) {
                    break;
                }
            }
        }

        return [
            'essay_excerpt' => $this->truncate($essayText, 1600),
            'issues_by_sentence' => $issuesBySentence,
            'weaknesses' => $weaknesses,
            'question_count' => $limit,
            'language' => 'en',
        ];
    }

    private function normalizeQuestions(array $questions): array
    {
        $allowedTypes = ['multiple_choice', 'fill_blank', 'correction', 'rewrite'];
        $items = [];

        foreach ($questions as $question) {
            if (! is_array($question)) {
                continue;
            }

            $prompt = trim((string) ($question['prompt'] ?? ''));
            if ($prompt === '') {
                continue;
            }

            $type = trim((string) ($question['type'] ?? 'correction'));
            if (! in_array($type, $allowedTypes, true)) {
                $type = 'correction';
            }

            $options = null;
            if ($type === 'multiple_choice' && isset($question['options']) && is_array($question['options'])) {
                $filtered = [];
                foreach ($question['options'] as $option) {
                    $text = trim((string) $option);
                    if ($text !== '') {
                        $filtered[] = $text;
                    }
                    if (count($filtered) >= 6) {
                        break;
                    }
                }
                if ($filtered !== []) {
                    $options = $filtered;
                }
            }

            $answer = $question['answer'] ?? null;
            if (is_string($answer)) {
                $answer = trim($answer);
            } elseif (is_array($answer)) {
                $answer = array_values(array_map(
                    static fn ($value) => is_scalar($value) ? (string) $value : '',
                    $answer
                ));
            } elseif (! is_int($answer) && ! is_float($answer) && ! is_bool($answer) && $answer !== null) {
                $answer = (string) json_encode($answer, JSON_UNESCAPED_UNICODE);
            }

            $items[] = [
                'type' => $type,
                'prompt' => $prompt,
                'options' => $options,
                'answer' => $answer,
                'explanation' => trim((string) ($question['explanation'] ?? '')),
                'focus' => trim((string) ($question['focus'] ?? '')),
                'difficulty' => isset($question['difficulty']) ? (int) $question['difficulty'] : null,
            ];
        }

        return $items;
    }

    private function truncate(string $text, int $maxLength): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($trimmed, 0, $maxLength, 'UTF-8');
        }

        return substr($trimmed, 0, $maxLength);
    }
}

