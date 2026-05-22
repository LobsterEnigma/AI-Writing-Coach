<?php

namespace Services;

use Exception;

class AiClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $model;
    private static array $jsonModeSupportCache = [];

    public function __construct(array $config)
    {
        $settings = $config['open_source']['ai_settings'] ?? [];
        $baseUrl = trim((string) ($settings['base_url'] ?? ''));
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $model = trim((string) ($settings['model'] ?? ''));

        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            throw new Exception('AI settings not configured. Please set open_source.ai_settings in config/app.php.');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public function chat(array $messages, array $options = []): array
    {
        $endpoint = $this->baseUrl . '/chat/completions';

        $payload = array_merge([
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.0,
            'max_tokens' => $options['max_tokens'] ?? 2600,
        ], $options);

        $hasCustomResponseFormat = array_key_exists('response_format', $options);
        if (! $hasCustomResponseFormat && $this->supportsJsonMode()) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        if (array_key_exists('response_format', $payload) && $payload['response_format'] === null) {
            unset($payload['response_format']);
        }

        $response = $this->postJson($endpoint, $payload);
        $content = $this->extractContentFromResponse($response);
        $decoded = $this->decodeJsonObject($content);

        if (! is_array($decoded) && isset($payload['response_format'])) {
            $this->markJsonModeUnsupported();
            $fallbackPayload = $payload;
            unset($fallbackPayload['response_format']);
            $fallbackResponse = $this->postJson($endpoint, $fallbackPayload);
            $content = $this->extractContentFromResponse($fallbackResponse);
            $decoded = $this->decodeJsonObject($content);
        }

        if (! is_array($decoded) && $this->looksLikeTruncatedJson($content)) {
            $retryPayload = $payload;
            unset($retryPayload['response_format'], $retryPayload['temperature']);
            if (isset($retryPayload['max_tokens']) && is_numeric($retryPayload['max_tokens'])) {
                $retryPayload['max_tokens'] = min(6000, max(2200, (int) $retryPayload['max_tokens'] + 800));
            }

            try {
                $retryResponse = $this->postJson($endpoint, $retryPayload);
                $retryContent = $this->extractContentFromResponse($retryResponse);
                $retryDecoded = $this->decodeJsonObject($retryContent);
                if (is_array($retryDecoded)) {
                    return $retryDecoded;
                }
            } catch (Exception) {
                // Keep original error details below.
            }
        }

        if (! is_array($decoded)) {
            $error = $response['error'] ?? null;
            if (is_array($error)) {
                $message = (string) ($error['message'] ?? 'Unknown AI error');
                throw new Exception('AI returned error payload: ' . $message);
            }
        }

        if (! is_array($decoded)) {
            $preview = $this->preview($content);
            throw new Exception('AI response could not be decoded. Preview: ' . $preview);
        }

        return $decoded;
    }

    private function postJson(string $endpoint, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new Exception('Failed to encode AI payload as JSON.');
        }

        $maxAttempts = 3;
        $attempt = 0;
        $lastMessage = null;

        while ($attempt < $maxAttempts) {
            $attempt += 1;
            $ch = curl_init($endpoint);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_CONNECTTIMEOUT => 12,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => 'AIWritingCoach/1.0',
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ],
                CURLOPT_POSTFIELDS => $json,
            ]);

            $result = curl_exec($ch);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $totalSeconds = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            curl_close($ch);

            if ($result === false) {
                $lastMessage = $curlError ?: 'Unknown network error';
                $isTimeout = $curlErrno === 28 || stripos((string) $lastMessage, 'timed out') !== false;
                if ($attempt < $maxAttempts && ! $isTimeout) {
                    usleep(250000 * $attempt);
                    continue;
                }
                throw new Exception('AI request failed: ' . $lastMessage);
            }

            $decoded = json_decode((string) $result, true);
            if ($status >= 400) {
                $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
                $message = null;
                $type = null;
                $code = null;
                if (is_array($error)) {
                    $message = $error['message'] ?? null;
                    $type = $error['type'] ?? null;
                    $code = $error['code'] ?? null;
                } elseif (is_string($error)) {
                    $message = $error;
                }
                if (! is_string($message) || trim($message) === '') {
                    $message = 'Unknown error';
                }
                $details = [];
                if (is_string($type) && $type !== '') {
                    $details[] = 'type=' . $type;
                }
                if (is_string($code) && $code !== '') {
                    $details[] = 'code=' . $code;
                }
                if ($details !== []) {
                    $message .= ' (' . implode(', ', $details) . ')';
                }
                $lastMessage = $message;

                $unsupportedKey = $this->detectUnsupportedOption($message, $payload);
                if ($attempt < $maxAttempts && $status === 400 && $unsupportedKey !== null) {
                    if ($unsupportedKey === 'response_format') {
                        $this->markJsonModeUnsupported();
                    }
                    unset($payload[$unsupportedKey]);
                    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
                    if ($json === false) {
                        throw new Exception('Failed to encode AI payload as JSON.');
                    }
                    usleep(180000 * $attempt);
                    continue;
                }

                $shouldRetry = in_array($status, [429, 500, 502, 503, 504], true);
                if ($shouldRetry && $attempt < $maxAttempts && $totalSeconds < 20) {
                    usleep(300000 * $attempt);
                    continue;
                }

                $supportsFallback = isset($payload['response_format']);
                $mentionsJsonMode = stripos($message, 'response_format') !== false
                    || stripos($message, 'json') !== false
                    || stripos($message, 'json_object') !== false;

                if ($attempt < $maxAttempts && $status >= 400 && $supportsFallback && $mentionsJsonMode) {
                    $this->markJsonModeUnsupported();
                    $fallbackPayload = $payload;
                    unset($fallbackPayload['response_format']);
                    $json = json_encode($fallbackPayload, JSON_UNESCAPED_UNICODE);
                    if ($json === false) {
                        throw new Exception('Failed to encode AI payload as JSON.');
                    }
                    usleep(200000 * $attempt);
                    continue;
                }

                throw new Exception('AI request returned HTTP ' . $status . ': ' . $message, $status);
            }

            if (! is_array($decoded)) {
                $preview = $this->preview((string) $result);
                $lastMessage = 'AI response was not valid JSON. Preview: ' . $preview;
                if ($attempt < $maxAttempts) {
                    usleep(220000 * $attempt);
                    continue;
                }

                throw new Exception($lastMessage);
            }

            return $decoded;
        }

        throw new Exception('AI request failed: ' . ($lastMessage ?? 'Unknown error'));
    }

    private function supportsJsonMode(): bool
    {
        $key = $this->cacheKey();
        if (! array_key_exists($key, self::$jsonModeSupportCache)) {
            self::$jsonModeSupportCache[$key] = true;
        }
        return self::$jsonModeSupportCache[$key] === true;
    }

    private function markJsonModeUnsupported(): void
    {
        self::$jsonModeSupportCache[$this->cacheKey()] = false;
    }

    private function cacheKey(): string
    {
        return $this->baseUrl . '|' . $this->model;
    }

    private function decodeJsonObject(string $content): ?array
    {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (is_string($decoded)) {
            $nested = json_decode($decoded, true);
            if (is_array($nested)) {
                return $nested;
            }
        }

        $normalized = $this->normalizeJsonLikeText($content);
        $decoded = json_decode($normalized, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $candidate = $this->extractJsonPayload($normalized);
        if ($candidate !== null) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $repaired = $this->repairPossiblyTruncatedJson($normalized);
        if ($repaired !== null) {
            $decoded = json_decode($repaired, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function extractContentFromResponse(array $response): string
    {
        $content = $response['choices'][0]['message']['content'] ?? null;
        if (is_string($content) && trim($content) !== '') {
            return $content;
        }

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_string($part)) {
                    $parts[] = $part;
                    continue;
                }
                if (! is_array($part)) {
                    continue;
                }
                $text = $part['text'] ?? ($part['content'] ?? ($part['value'] ?? null));
                if (is_string($text) && trim($text) !== '') {
                    $parts[] = $text;
                }
            }
            $joined = trim(implode("\n", $parts));
            if ($joined !== '') {
                return $joined;
            }
        }

        $toolArguments = $response['choices'][0]['message']['tool_calls'][0]['function']['arguments'] ?? null;
        if (is_string($toolArguments) && trim($toolArguments) !== '') {
            return $toolArguments;
        }

        $legacyText = $response['choices'][0]['text'] ?? null;
        if (is_string($legacyText) && trim($legacyText) !== '') {
            return $legacyText;
        }

        $outputText = $response['output_text'] ?? null;
        if (is_string($outputText) && trim($outputText) !== '') {
            return $outputText;
        }

        $output = $response['output'] ?? null;
        if (is_array($output)) {
            $parts = [];
            foreach ($output as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $candidate = $item['text'] ?? ($item['content'] ?? null);
                if (is_string($candidate) && trim($candidate) !== '') {
                    $parts[] = $candidate;
                }
            }
            $joined = trim(implode("\n", $parts));
            if ($joined !== '') {
                return $joined;
            }
        }

        return '';
    }

    private function extractJsonPayload(string $content): ?string
    {
        $trimmed = $this->normalizeJsonLikeText($content);
        if ($trimmed === '') {
            return null;
        }

        $complete = $this->extractFirstCompleteJson($trimmed);
        if ($complete !== null) {
            return $complete;
        }

        $starts = [];
        $firstCurly = strpos($trimmed, '{');
        $firstSquare = strpos($trimmed, '[');
        if ($firstCurly !== false) {
            $starts[] = $firstCurly;
        }
        if ($firstSquare !== false) {
            $starts[] = $firstSquare;
        }
        if ($starts === []) {
            return null;
        }
        $first = min($starts);

        $ends = [];
        $lastCurly = strrpos($trimmed, '}');
        $lastSquare = strrpos($trimmed, ']');
        if ($lastCurly !== false) {
            $ends[] = $lastCurly;
        }
        if ($lastSquare !== false) {
            $ends[] = $lastSquare;
        }
        if ($ends === []) {
            return null;
        }
        $last = max($ends);
        if ($last < $first) {
            return null;
        }

        $json = substr($trimmed, $first, $last - $first + 1);
        return $json !== false ? trim($json) : null;
    }

    private function extractFirstCompleteJson(string $text): ?string
    {
        $length = strlen($text);
        if ($length === 0) {
            return null;
        }

        $start = null;
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '{' || $char === '[') {
                $start = $i;
                break;
            }
        }
        if ($start === null) {
            return null;
        }

        $stack = [];
        $inString = false;
        $escape = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($char === '\\') {
                    $escape = true;
                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{') {
                $stack[] = '}';
                continue;
            }
            if ($char === '[') {
                $stack[] = ']';
                continue;
            }

            if ($char === '}' || $char === ']') {
                $expected = array_pop($stack);
                if ($expected !== $char) {
                    return null;
                }
                if ($stack === []) {
                    $candidate = substr($text, $start, $i - $start + 1);
                    return $candidate !== false ? trim($candidate) : null;
                }
            }
        }

        return null;
    }

    private function repairPossiblyTruncatedJson(string $content): ?string
    {
        $trimmed = $this->normalizeJsonLikeText($content);
        if ($trimmed === '') {
            return null;
        }

        $start = strpos($trimmed, '{');
        $arrayStart = strpos($trimmed, '[');
        if ($arrayStart !== false && ($start === false || $arrayStart < $start)) {
            $start = $arrayStart;
        }
        if ($start === false) {
            return null;
        }

        $working = substr($trimmed, $start);
        if ($working === false || $working === '') {
            return null;
        }

        $stack = [];
        $inString = false;
        $escape = false;
        $buffer = '';
        $length = strlen($working);

        for ($i = 0; $i < $length; $i++) {
            $char = $working[$i];
            $buffer .= $char;

            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($char === '\\') {
                    $escape = true;
                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{') {
                $stack[] = '}';
                continue;
            }
            if ($char === '[') {
                $stack[] = ']';
                continue;
            }
            if (($char === '}' || $char === ']') && $stack !== []) {
                $expected = array_pop($stack);
                if ($expected !== $char) {
                    $stack = [];
                    break;
                }
            }
        }

        if ($escape && $buffer !== '' && str_ends_with($buffer, '\\')) {
            $buffer = substr($buffer, 0, -1);
        }
        if ($inString) {
            $buffer .= '"';
        }

        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $buffer .= $stack[$i];
        }

        $buffer = preg_replace('/,\s*([}\]])/', '$1', $buffer) ?? $buffer;
        $buffer = trim($buffer);

        if ($buffer === '' || ($buffer[0] !== '{' && $buffer[0] !== '[')) {
            return null;
        }

        return $buffer;
    }

    private function normalizeJsonLikeText(string $content): string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return '';
        }

        $trimmed = preg_replace('/^\xEF\xBB\xBF/', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
        $trimmed = trim($trimmed);

        $trimmed = preg_replace('/^json\s*(?=[{\[])/i', '', $trimmed) ?? $trimmed;

        return trim($trimmed);
    }

    private function detectUnsupportedOption(string $message, array $payload): ?string
    {
        $lower = strtolower($message);

        $candidates = ['response_format', 'max_tokens', 'temperature'];
        foreach ($candidates as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            if (str_contains($lower, strtolower($key))) {
                return $key;
            }
        }

        if (
            array_key_exists('response_format', $payload)
            && (str_contains($lower, 'json_object') || str_contains($lower, 'response format'))
        ) {
            return 'response_format';
        }

        return null;
    }

    private function looksLikeTruncatedJson(string $content): bool
    {
        $text = $this->normalizeJsonLikeText($content);
        if ($text === '') {
            return false;
        }

        $startsLikeJson = str_starts_with($text, '{') || str_starts_with($text, '[');
        if (! $startsLikeJson) {
            return false;
        }

        $openCurly = substr_count($text, '{');
        $closeCurly = substr_count($text, '}');
        $openSquare = substr_count($text, '[');
        $closeSquare = substr_count($text, ']');

        if ($openCurly > $closeCurly || $openSquare > $closeSquare) {
            return true;
        }

        $tail = rtrim($text);
        if ($tail === '') {
            return false;
        }

        $last = substr($tail, -1);
        return $last !== '}' && $last !== ']';
    }

    private function preview(string $content): string
    {
        $snippet = trim($content);
        if ($snippet === '') {
            return '[empty response]';
        }

        if (function_exists('mb_substr')) {
            $snippet = mb_substr($snippet, 0, 200);
        } else {
            $snippet = substr($snippet, 0, 200);
        }

        return $snippet;
    }
}
