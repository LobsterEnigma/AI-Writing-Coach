<?php

namespace Services;

use Exception;
use Support\DefaultPrompts;

class EssayAnalyzer
{
    private AiClient $client;

    public function __construct(array $config)
    {
        $this->client = new AiClient($config);
    }

    public function analyze(string $text, array $context = []): array
    {
        if (trim($text) === '') {
            throw new Exception('Essay text cannot be empty.');
        }

        $languageLevel = $context['level'] ?? null;
        $sentences = $this->splitIntoSentences($text);
        $sentenceCount = count($sentences);
        $textLength = $this->stringLength($text);

        // Most essays should use one bundled request to reduce total latency.
        // Pipeline is reserved for much longer input or when bundle fails.
        $preferPipeline = $sentenceCount > 24 || $textLength > 4200;
        if (! $preferPipeline) {
            try {
                $payload = [
                    'essay' => $text,
                    'context_documents' => $context['documents'] ?? [],
                    'language_level' => $languageLevel,
                ];

                $result = $this->callPrompt('analysis_bundle', $payload);
                return $this->normalizeResult($result, $sentences);
            } catch (Exception $e) {
                if (! $this->shouldFallbackToPipeline($e)) {
                    throw $e;
                }
            }
        }

        $pipeline = $this->analyzeWithPipeline($text, $sentences, $languageLevel);
        return $this->normalizeResult($pipeline, $sentences);
    }

    public function wordDetail(string $word): array
    {
        $payload = ['word' => $word];
        return $this->callPrompt('word_detail', $payload);
    }

    public function chatReply(string $message, array $documents, array $history): array
    {
        $prompt = DefaultPrompts::get('chat_assistant');
        if (! $prompt) {
            throw new Exception('Chat prompt template missing.');
        }

        $messages = [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => json_encode([
                'user_message' => $message,
                'documents' => $documents,
                'recent_history' => $history,
            ], JSON_UNESCAPED_UNICODE)],
        ];

        return $this->client->chat($messages);
    }

    private function callPrompt(string $key, array $payload): array
    {
        $prompt = DefaultPrompts::get($key);
        if (! $prompt) {
            throw new Exception('Prompt not configured for ' . $key);
        }

        $messages = [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        ];

        return $this->client->chat($messages);
    }

    private function analyzeWithPipeline(string $text, array $sentences, mixed $languageLevel): array
    {
        $sentenceItems = [];
        foreach ($sentences as $index => $sentence) {
            $sentenceItems[] = [
                'index' => $index + 1,
                'text' => $sentence,
            ];
        }

        if ($sentenceItems === []) {
            $sentenceItems[] = [
                'index' => 1,
                'text' => $text,
            ];
        }

        $analysisSentences = [];
        $lexicalTokens = [];

        $chunks = $this->chunkSentenceItems($sentenceItems);
        foreach ($chunks as $chunk) {
            $chunkResult = $this->callPrompt('analysis_sentences', [
                'sentences' => $chunk,
                'language_level' => $languageLevel,
            ]);

            foreach (($chunkResult['analysis']['sentences'] ?? []) as $row) {
                if (! isset($row['index'])) {
                    continue;
                }
                $analysisSentences[(int) $row['index']] = $row;
            }

            foreach (($chunkResult['lexical']['tokens'] ?? []) as $token) {
                if (! is_array($token)) {
                    continue;
                }
                $normalized = strtolower((string) ($token['normalized'] ?? $token['word'] ?? ''));
                if ($normalized === '') {
                    continue;
                }
                if (! isset($lexicalTokens[$normalized])) {
                    $lexicalTokens[$normalized] = $token;
                }
            }
        }

        ksort($analysisSentences);

        $rewriteSentences = $this->buildRewriteFromAnalysis($analysisSentences);
        $shouldCallRewritePrompt = false;
        if ($shouldCallRewritePrompt) {
            foreach ($chunks as $chunk) {
                try {
                    $chunkResult = $this->callPrompt('rewrite_sentences', [
                        'sentences' => $chunk,
                        'language_level' => $languageLevel,
                    ]);

                    foreach (($chunkResult['rewrite']['sentences'] ?? []) as $row) {
                        if (! isset($row['index'])) {
                            continue;
                        }
                        $rewriteSentences[(int) $row['index']] = $row;
                    }
                } catch (Exception) {
                    // Keep fallback rewrite generated from sentence improvements.
                    break;
                }
            }
        }

        ksort($rewriteSentences);

        try {
            $overall = $this->callPrompt('analysis_overall', [
                'essay' => $text,
                'language_level' => $languageLevel,
            ]);
        } catch (Exception) {
            $overall = [
                'keywords' => [],
                'feedback' => [],
            ];
        }

        return [
            'analysis' => [
                'sentences' => array_values($analysisSentences),
            ],
            'rewrite' => [
                'sentences' => array_values($rewriteSentences),
            ],
            'keywords' => $overall['keywords'] ?? [],
            'feedback' => $overall['feedback'] ?? [],
            'lexical' => [
                'tokens' => array_values($lexicalTokens),
            ],
        ];
    }

    private function shouldFallbackToPipeline(Exception $e): bool
    {
        $code = (int) $e->getCode();
        if (in_array($code, [429, 500, 502, 503, 504], true)) {
            return true;
        }

        $message = strtolower($e->getMessage());
        return str_contains($message, 'openai_error')
            || str_contains($message, 'bad_response_status_code')
            || str_contains($message, 'gateway')
            || str_contains($message, 'timed out')
            || str_contains($message, 'could not be decoded')
            || str_contains($message, 'empty response')
            || str_contains($message, 'not valid json');
    }

    private function splitIntoSentences(string $text): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $normalized = trim($normalized);
        if ($normalized === '') {
            return [];
        }

        $normalized = preg_replace("/[ \t]+/u", " ", $normalized) ?? $normalized;
        $paragraphs = preg_split("/\n+/u", $normalized) ?: [$normalized];

        $sentences = [];
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            $parts = preg_split('/(?<=[.!?。！？])\s+(?=[^\s])/u', $paragraph) ?: [$paragraph];
            foreach ($parts as $part) {
                $sentence = trim($part);
                if ($sentence === '') {
                    continue;
                }
                $sentences[] = $sentence;
            }
        }

        return $sentences;
    }

    private function chunkSentenceItems(array $sentenceItems): array
    {
        $total = count($sentenceItems);
        $maxSentences = $total > 60 ? 5 : ($total > 30 ? 6 : 8);
        $maxChars = $total > 60 ? 900 : ($total > 30 ? 1100 : 1400);

        $chunks = [];
        $current = [];
        $chars = 0;

        foreach ($sentenceItems as $item) {
            $text = (string) ($item['text'] ?? '');
            $len = $this->stringLength($text);

            $wouldExceed = $current !== []
                && (count($current) >= $maxSentences || ($chars + $len) > $maxChars);

            if ($wouldExceed) {
                $chunks[] = $current;
                $current = [];
                $chars = 0;
            }

            $current[] = $item;
            $chars += $len;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function buildRewriteFromAnalysis(array $analysisSentences): array
    {
        $rewrites = [];

        foreach ($analysisSentences as $key => $row) {
            if (! is_array($row)) {
                continue;
            }

            $index = (int) ($row['index'] ?? $key);
            if ($index <= 0) {
                continue;
            }

            $original = (string) ($row['original'] ?? '');
            $rewrite = (string) ($row['improved_sentence'] ?? $original);
            $rationale = 'Refined from detected issues to improve grammar and clarity.';

            $issues = $row['issues'] ?? [];
            if (is_array($issues) && $issues !== []) {
                $first = $issues[0] ?? null;
                if (is_array($first)) {
                    $hint = trim((string) ($first['description'] ?? $first['explanation'] ?? ''));
                    if ($hint !== '') {
                        $rationale = $hint;
                    }
                }
            }

            $rewrites[$index] = [
                'index' => $index,
                'original' => $original,
                'rewrite' => $rewrite,
                'rationale' => $rationale,
            ];
        }

        return $rewrites;
    }

    private function normalizeResult(array $result, array $sentences): array
    {
        $analysisRoot = $this->pickFirstArray(
            $result['analysis'] ?? null,
            $result['sentence_analysis'] ?? null,
            $result['analysis_result'] ?? null
        );
        $analysisSentences = $this->toSentenceRows(
            $analysisRoot['sentences'] ?? null,
            $analysisRoot['items'] ?? null,
            $result['sentences'] ?? null
        );
        if ($analysisSentences === []) {
            $analysisSentences = $this->buildMinimalAnalysisFromText($sentences);
        }

        $rewriteRoot = $this->pickFirstArray(
            $result['rewrite'] ?? null,
            $result['sentence_rewrite'] ?? null,
            $result['rewrites'] ?? null
        );
        $rewriteSentences = $this->toRewriteRows(
            $rewriteRoot['sentences'] ?? null,
            $rewriteRoot['items'] ?? null,
            $result['rewrite_sentences'] ?? null
        );
        if ($rewriteSentences === []) {
            $rewriteSentences = $this->buildRewriteFromAnalysis($analysisSentences);
        }

        $keywordsRoot = $this->pickFirstArray(
            $result['keywords'] ?? null,
            $result['keyword_analysis'] ?? null,
            $result['keyword'] ?? null
        );
        $keywords = $this->toKeywordRows(
            $keywordsRoot['keywords'] ?? null,
            $keywordsRoot['items'] ?? null,
            $result['keywords'] ?? null,
            $result['keyword_list'] ?? null
        );

        $feedback = $this->toFeedback(
            $result['feedback'] ?? null,
            $result['overall_feedback'] ?? null,
            $result['coach_feedback'] ?? null
        );

        $lexicalRoot = $this->pickFirstArray(
            $result['lexical'] ?? null,
            $result['original_text'] ?? null,
            $result['pos'] ?? null
        );
        $lexicalTokens = $this->toLexicalTokens(
            $lexicalRoot['tokens'] ?? null,
            $lexicalRoot['words'] ?? null
        );
        if ($lexicalTokens === []) {
            $lexicalTokens = $this->buildLexicalFromSentences($analysisSentences, $sentences);
        }

        if ($keywords === []) {
            $keywords = $this->buildKeywordFallback($lexicalTokens);
        }

        if (
            ($feedback['strengths'] ?? []) === []
            && ($feedback['weaknesses'] ?? []) === []
            && ($feedback['recommendations'] ?? []) === []
        ) {
            $feedback = $this->buildFeedbackFallback($analysisSentences);
        }

        return [
            'analysis' => [
                'sentences' => $analysisSentences,
            ],
            'rewrite' => [
                'sentences' => $rewriteSentences,
            ],
            'keywords' => [
                'keywords' => $keywords,
            ],
            'feedback' => $feedback,
            'lexical' => [
                'tokens' => $lexicalTokens,
            ],
        ];
    }

    private function pickFirstArray(mixed ...$values): array
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                return $value;
            }
        }
        return [];
    }

    private function toSentenceRows(mixed ...$values): array
    {
        $rows = [];
        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }
            foreach ($value as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $index = (int) ($item['index'] ?? 0);
                $original = trim((string) ($item['original'] ?? $item['sentence'] ?? $item['text'] ?? ''));
                if ($original === '') {
                    continue;
                }
                if ($index <= 0) {
                    $index = count($rows) + 1;
                }

                $issues = [];
                $rawIssues = $item['issues'] ?? $item['errors'] ?? [];
                if (is_array($rawIssues)) {
                    foreach ($rawIssues as $issue) {
                        if (! is_array($issue)) {
                            continue;
                        }
                        $issues[] = [
                            'type' => (string) ($issue['type'] ?? $issue['category'] ?? 'other'),
                            'description' => (string) ($issue['description'] ?? $issue['message'] ?? ''),
                            'error_excerpt' => (string) ($issue['error_excerpt'] ?? $issue['problem'] ?? $issue['wrong'] ?? ''),
                            'corrected_form' => (string) ($issue['corrected_form'] ?? $issue['fix'] ?? $issue['correction'] ?? ''),
                            'explanation' => (string) ($issue['explanation'] ?? ''),
                            'practice_tip' => (string) ($issue['practice_tip'] ?? $issue['tip'] ?? ''),
                        ];
                    }
                }

                $rows[] = [
                    'index' => $index,
                    'original' => $original,
                    'summary' => (string) ($item['summary'] ?? ''),
                    'issues' => $issues,
                    'improved_sentence' => (string) ($item['improved_sentence'] ?? $item['rewrite'] ?? $item['corrected_sentence'] ?? $original),
                ];
            }
            if ($rows !== []) {
                break;
            }
        }

        usort($rows, static fn (array $a, array $b): int => ((int) $a['index']) <=> ((int) $b['index']));
        return $rows;
    }

    private function toRewriteRows(mixed ...$values): array
    {
        $rows = [];
        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }
            foreach ($value as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $original = trim((string) ($item['original'] ?? $item['sentence'] ?? $item['text'] ?? ''));
                if ($original === '') {
                    continue;
                }
                $index = (int) ($item['index'] ?? 0);
                if ($index <= 0) {
                    $index = count($rows) + 1;
                }
                $rows[] = [
                    'index' => $index,
                    'original' => $original,
                    'rewrite' => (string) ($item['rewrite'] ?? $item['rewritten'] ?? $item['improved_sentence'] ?? $original),
                    'rationale' => (string) ($item['rationale'] ?? $item['reason'] ?? $item['explanation'] ?? ''),
                ];
            }
            if ($rows !== []) {
                break;
            }
        }

        usort($rows, static fn (array $a, array $b): int => ((int) $a['index']) <=> ((int) $b['index']));
        return $rows;
    }

    private function toKeywordRows(mixed ...$values): array
    {
        $rows = [];
        $seen = [];
        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }
            foreach ($value as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $word = trim((string) ($item['word'] ?? $item['term'] ?? $item['keyword'] ?? ''));
                if ($word === '') {
                    continue;
                }
                $key = strtolower($word);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = [
                    'word' => $word,
                    'part_of_speech' => $this->normalizePartOfSpeech(
                        (string) ($item['part_of_speech'] ?? $item['partOfSpeech'] ?? $item['pos'] ?? $item['pos_tag'] ?? $item['tag'] ?? 'other')
                    ),
                    'meaning' => (string) ($item['meaning'] ?? $item['definition'] ?? ''),
                    'usage_tip' => (string) ($item['usage_tip'] ?? $item['usage'] ?? ''),
                    'ipa' => [
                        'uk' => (string) ($item['ipa']['uk'] ?? $item['uk_ipa'] ?? '-'),
                        'us' => (string) ($item['ipa']['us'] ?? $item['us_ipa'] ?? '-'),
                    ],
                    'common_usage' => is_array($item['common_usage'] ?? null) ? $item['common_usage'] : [],
                    'memory_tip' => (string) ($item['memory_tip'] ?? ''),
                ];
            }
            if ($rows !== []) {
                break;
            }
        }
        return $rows;
    }

    private function toFeedback(mixed ...$values): array
    {
        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }

            $strengths = $value['strengths'] ?? [];
            $weaknesses = $value['weaknesses'] ?? [];
            $recommendations = $value['recommendations'] ?? [];

            if (! is_array($strengths)) {
                $strengths = [];
            }
            if (! is_array($weaknesses)) {
                $weaknesses = [];
            }
            if (! is_array($recommendations)) {
                $recommendations = [];
            }

            return [
                'strengths' => $strengths,
                'weaknesses' => $weaknesses,
                'recommendations' => $recommendations,
            ];
        }

        return [
            'strengths' => [],
            'weaknesses' => [],
            'recommendations' => [],
        ];
    }

    private function toLexicalTokens(mixed ...$values): array
    {
        $rows = [];
        $seen = [];
        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }
            foreach ($value as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $word = trim((string) ($item['word'] ?? $item['text'] ?? ''));
                if ($word === '') {
                    continue;
                }
                $normalized = strtolower((string) ($item['normalized'] ?? $word));
                if (isset($seen[$normalized])) {
                    continue;
                }
                $seen[$normalized] = true;
                $rows[] = [
                    'word' => $word,
                    'normalized' => $normalized,
                    'part_of_speech' => $this->normalizePartOfSpeech(
                        (string) ($item['part_of_speech'] ?? $item['partOfSpeech'] ?? $item['pos'] ?? $item['pos_tag'] ?? $item['tag'] ?? 'other')
                    ),
                ];
            }
            if ($rows !== []) {
                break;
            }
        }
        return $rows;
    }

    private function normalizePartOfSpeech(string $value): string
    {
        $source = strtolower(trim($value));
        if ($source === '') {
            return 'other';
        }

        if (
            str_contains($source, '名词')
            || str_contains($source, '代词')
            || $source === 'noun'
            || $source === 'n'
            || $source === 'nn'
            || $source === 'nns'
            || $source === 'nnp'
            || $source === 'nnps'
            || str_contains($source, 'proper_noun')
            || str_contains($source, 'pronoun')
        ) {
            return 'noun';
        }

        if (
            str_contains($source, '动词')
            || $source === 'verb'
            || $source === 'v'
            || $source === 'vb'
            || $source === 'vbd'
            || $source === 'vbg'
            || $source === 'vbn'
            || $source === 'vbp'
            || $source === 'vbz'
            || $source === 'aux'
            || $source === 'auxiliary'
            || $source === 'modal'
        ) {
            return 'verb';
        }

        if (
            str_contains($source, '形容词')
            || $source === 'adjective'
            || $source === 'adj'
            || $source === 'jj'
            || $source === 'jjr'
            || $source === 'jjs'
        ) {
            return 'adjective';
        }

        if (
            str_contains($source, '副词')
            || $source === 'adverb'
            || $source === 'adv'
            || $source === 'rb'
            || $source === 'rbr'
            || $source === 'rbs'
        ) {
            return 'adverb';
        }

        if (
            str_contains($source, '连接词')
            || str_contains($source, '连词')
            || str_contains($source, '介词')
            || str_contains($source, '冠词')
            || str_contains($source, '限定词')
            || $source === 'connector'
            || $source === 'connective'
            || $source === 'conjunction'
            || $source === 'conj'
            || $source === 'preposition'
            || $source === 'prep'
            || $source === 'in'
            || $source === 'to'
            || $source === 'det'
            || $source === 'determiner'
            || $source === 'article'
        ) {
            return 'connector';
        }

        return 'other';
    }

    private function buildMinimalAnalysisFromText(array $sentences): array
    {
        $rows = [];
        foreach ($sentences as $index => $sentence) {
            $text = trim((string) $sentence);
            if ($text === '') {
                continue;
            }
            $rows[] = [
                'index' => $index + 1,
                'original' => $text,
                'summary' => '',
                'issues' => [],
                'improved_sentence' => $text,
            ];
        }
        return $rows;
    }

    private function buildLexicalFromSentences(array $analysisSentences, array $sentences): array
    {
        $source = [];
        if ($analysisSentences !== []) {
            foreach ($analysisSentences as $item) {
                $text = (string) ($item['original'] ?? '');
                if ($text !== '') {
                    $source[] = $text;
                }
            }
        }
        if ($source === []) {
            $source = $sentences;
        }

        $tokens = [];
        $seen = [];
        foreach ($source as $line) {
            $words = preg_split('/[^\\p{L}\\p{N}\']+/u', (string) $line) ?: [];
            foreach ($words as $word) {
                $word = trim($word);
                if ($word === '') {
                    continue;
                }
                $normalized = strtolower($word);
                if (isset($seen[$normalized])) {
                    continue;
                }
                $seen[$normalized] = true;
                $tokens[] = [
                    'word' => $word,
                    'normalized' => $normalized,
                    'part_of_speech' => 'other',
                ];
            }
        }

        return $tokens;
    }

    private function buildKeywordFallback(array $lexicalTokens): array
    {
        $items = [];
        foreach ($lexicalTokens as $token) {
            if (! is_array($token)) {
                continue;
            }
            $word = trim((string) ($token['word'] ?? ''));
            if ($word === '') {
                continue;
            }
            $normalized = strtolower($word);
            if (strlen($normalized) < 4) {
                continue;
            }
            if (! preg_match('/^[a-z][a-z\\-]*$/i', $normalized)) {
                continue;
            }
            $items[] = [
                'word' => $word,
                'part_of_speech' => (string) ($token['part_of_speech'] ?? 'other'),
                'meaning' => '',
                'usage_tip' => '',
                'ipa' => [
                    'uk' => '-',
                    'us' => '-',
                ],
                'common_usage' => [],
                'memory_tip' => '',
            ];
            if (count($items) >= 10) {
                break;
            }
        }
        return $items;
    }

    private function buildFeedbackFallback(array $analysisSentences): array
    {
        $typeCounts = [];
        foreach ($analysisSentences as $sentence) {
            if (! is_array($sentence)) {
                continue;
            }
            $issues = $sentence['issues'] ?? [];
            if (! is_array($issues)) {
                continue;
            }
            foreach ($issues as $issue) {
                if (! is_array($issue)) {
                    continue;
                }
                $type = strtolower(trim((string) ($issue['type'] ?? 'other')));
                if ($type === '') {
                    $type = 'other';
                }
                $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
            }
        }

        arsort($typeCounts);
        $weaknesses = [];
        $recommendations = [];
        foreach (array_slice(array_keys($typeCounts), 0, 3) as $type) {
            $label = ucfirst(str_replace('_', ' ', $type));
            $weaknesses[] = [
                'issue' => $label,
                'description' => 'This issue appears multiple times in your draft.',
                'sentence_reference' => '',
                'practice' => 'Rewrite one paragraph while focusing only on this issue type.',
                'improvement_steps' => 'Identify, correct, and compare before/after versions.',
            ];
            $recommendations[] = [
                'focus' => $label,
                'actions' => [
                    'Review one concise rule and apply it to 3 original sentences.',
                    'Keep a personal error log and re-check before submitting.',
                ],
                'resources' => [],
            ];
        }

        $strengths = [];
        if ($analysisSentences !== []) {
            $strengths[] = [
                'aspect' => 'Idea delivery',
                'detail' => 'Your main points are understandable and can be improved with targeted edits.',
                'example' => (string) ($analysisSentences[0]['original'] ?? ''),
            ];
        }

        return [
            'strengths' => $strengths,
            'weaknesses' => $weaknesses,
            'recommendations' => $recommendations,
        ];
    }

    private function stringLength(string $text): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($text, 'UTF-8');
        }

        return strlen($text);
    }
}
