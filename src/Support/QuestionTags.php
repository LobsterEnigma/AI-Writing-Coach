<?php

namespace Support;

class QuestionTags
{
    public const DEFAULT_TAGS = [
        'articles',
        'prepositions',
        'verb_tense',
        'subject_verb_agreement',
        'plural_nouns',
        'pronouns',
        'word_choice',
        'collocations',
        'sentence_structure',
        'punctuation',
        'spelling',
        'cohesion',
        'capitalization',
        'modifiers',
        'parallelism',
        'run_on',
        'fragments',
        'voice',
        'transition_words',
    ];

    public static function normalizeList(array|string $tags): array
    {
        $items = is_array($tags) ? $tags : preg_split('/[,\|;]+/u', (string) $tags);
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $tag = self::normalizeTag((string) $item);
            if ($tag === '') {
                continue;
            }
            $normalized[$tag] = true;
        }

        return array_keys($normalized);
    }

    public static function normalizeTag(string $tag): string
    {
        $tag = trim($tag);
        if ($tag === '') {
            return '';
        }

        $tag = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
        $tag = preg_replace('/\s+/u', '_', $tag) ?? $tag;
        $tag = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $tag) ?? $tag;

        return $tag;
    }
}
