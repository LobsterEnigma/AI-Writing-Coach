<?php

namespace Services;

use Exception;
use PDO;
use Repositories\AnalysisHistoryRepository;
use Repositories\HistorySummaryRepository;
use Repositories\PromptRepository;
use Repositories\QuestionRepository;
use Support\QuestionTags;

class HistorySummaryService
{
    private AiClient $client;
    private PromptRepository $prompts;
    private AnalysisHistoryRepository $historyRepository;
    private HistorySummaryRepository $summaryRepository;
    private QuestionRepository $questionRepository;

    public function __construct(PDO $pdo)
    {
        $this->client = new AiClient($pdo);
        $this->prompts = new PromptRepository($pdo);
        $this->historyRepository = new AnalysisHistoryRepository($pdo);
        $this->summaryRepository = new HistorySummaryRepository($pdo);
        $this->questionRepository = new QuestionRepository($pdo);
    }

    public function buildSummary(int $userId, int $limit, int $practiceLimit = 8, ?string $questionType = null, ?array $existingSummary = null): array
    {
        $limit = max(1, min($limit, 20));
        $practiceLimit = max(1, min($practiceLimit, 30));

        $histories = $this->historyRepository->listRecentDetailed($userId, $limit);
        if ($histories === []) {
            return [
                'history_count' => 0,
                'summary' => null,
                'issue_stats' => [],
                'recommended_questions' => [],
                'weakness_tags' => [],
                'cached' => false,
            ];
        }

        $historyIds = array_map(fn ($row) => (int) $row['id'], $histories);
        $hash = hash('sha256', implode(',', $historyIds));
        $cached = $this->summaryRepository->findByHash($userId, $hash);

        $issueStats = $this->collectIssueStats($histories);
        $summary = null;
        $wasCached = false;

        if (is_array($existingSummary)) {
            $summary = $existingSummary;
            $wasCached = true;
        }

        if (! $summary && $cached) {
            $decoded = json_decode((string) ($cached['summary_json'] ?? ''), true);
            if (is_array($decoded)) {
                $summary = $decoded;
                $wasCached = true;
            }
        }

        if (! $summary) {
            $payload = [
                'recent_histories' => $this->buildSummaryInput($histories),
                'language_level' => null,
            ];

            $summary = $this->callPrompt('history_summary', $payload);
            $this->summaryRepository->create($userId, $hash, $historyIds, $summary);
        }

        $tags = QuestionTags::normalizeList($summary['weakness_tags'] ?? []);
        if ($tags === []) {
            $tags = $this->fallbackTagsFromIssues($issueStats);
        }

        $questions = $this->questionRepository->findByTags($tags, $practiceLimit, $questionType);

        return [
            'history_count' => count($histories),
            'summary' => $summary,
            'issue_stats' => $issueStats,
            'recommended_questions' => $questions,
            'weakness_tags' => $tags,
            'cached' => $wasCached,
        ];
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

    private function buildSummaryInput(array $histories): array
    {
        $result = [];

        foreach ($histories as $row) {
            $decoded = json_decode((string) ($row['result_json'] ?? ''), true);
            if (! is_array($decoded)) {
                continue;
            }

            $feedback = [
                'strengths' => $decoded['feedback']['strengths'] ?? [],
                'weaknesses' => $decoded['feedback']['weaknesses'] ?? [],
                'recommendations' => $decoded['feedback']['recommendations'] ?? [],
            ];

            $issueTypes = [];
            $examples = [];
            $sentences = $decoded['analysis']['sentences'] ?? [];
            foreach ($sentences as $sentence) {
                foreach ($sentence['issues'] ?? [] as $issue) {
                    $type = (string) ($issue['type'] ?? '');
                    if ($type !== '') {
                        $issueTypes[$type] = ($issueTypes[$type] ?? 0) + 1;
                    }

                    if (count($examples) < 2) {
                        $error = (string) ($issue['error_excerpt'] ?? '');
                        $correction = (string) ($issue['corrected_form'] ?? '');
                        if ($error !== '' || $correction !== '') {
                            $examples[] = [
                                'type' => $type,
                                'error' => $error,
                                'correction' => $correction,
                            ];
                        }
                    }
                }
            }

            $result[] = [
                'id' => (int) $row['id'],
                'created_at' => $row['created_at'] ?? null,
                'feedback' => $feedback,
                'issue_types' => $issueTypes,
                'examples' => $examples,
            ];
        }

        return $result;
    }

    private function collectIssueStats(array $histories): array
    {
        $counts = [];

        foreach ($histories as $row) {
            $decoded = json_decode((string) ($row['result_json'] ?? ''), true);
            if (! is_array($decoded)) {
                continue;
            }
            $sentences = $decoded['analysis']['sentences'] ?? [];
            foreach ($sentences as $sentence) {
                foreach ($sentence['issues'] ?? [] as $issue) {
                    $type = (string) ($issue['type'] ?? '');
                    if ($type === '') {
                        continue;
                    }
                    $counts[$type] = ($counts[$type] ?? 0) + 1;
                }
            }
        }

        arsort($counts);
        $stats = [];
        foreach ($counts as $type => $count) {
            $stats[] = [
                'type' => $type,
                'count' => $count,
            ];
        }

        return $stats;
    }

    private function fallbackTagsFromIssues(array $issueStats): array
    {
        $map = [
            'grammar' => 'verb_tense',
            'spelling' => 'spelling',
            'word_choice' => 'word_choice',
            'structure' => 'sentence_structure',
            'tone' => 'cohesion',
            'punctuation' => 'punctuation',
        ];

        $tags = [];
        foreach ($issueStats as $stat) {
            $type = (string) ($stat['type'] ?? '');
            $tag = $map[$type] ?? null;
            if ($tag && ! in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
            if (count($tags) >= 4) {
                break;
            }
        }

        return $tags;
    }
}
