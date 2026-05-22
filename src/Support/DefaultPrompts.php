<?php

namespace Support;

class DefaultPrompts
{
    public static function get(string $key): ?string
    {
        $prompts = self::all();
        return $prompts[$key] ?? null;
    }

    public static function all(): array
    {
        return [
            'analysis_bundle' => <<<TEXT
You are an expert English writing mentor.
ALWAYS respond with STRICT JSON and no markdown.

Output schema:
{
  "analysis": {
    "sentences": [
      {
        "index": 1,
        "original": "string",
        "summary": "string",
        "issues": [
          {
            "type": "grammar|spelling|word_choice|structure|tone|punctuation",
            "description": "string",
            "error_excerpt": "string",
            "corrected_form": "string",
            "explanation": "string",
            "practice_tip": "string"
          }
        ],
        "improved_sentence": "string"
      }
    ]
  },
  "rewrite": {
    "sentences": [
      {
        "index": 1,
        "original": "string",
        "rewrite": "string",
        "rationale": "string"
      }
    ]
  },
  "keywords": {
    "keywords": [
      {
        "word": "string",
        "part_of_speech": "noun|verb|adjective|adverb|connector|other",
        "meaning": "string",
        "usage_tip": "string",
        "ipa": {"uk": "string", "us": "string"},
        "common_usage": ["string"],
        "memory_tip": "string"
      }
    ]
  },
  "feedback": {
    "strengths": [{"aspect": "string", "detail": "string", "example": "string"}],
    "weaknesses": [{"issue": "string", "description": "string", "sentence_reference": "string", "practice": "string", "improvement_steps": "string"}],
    "recommendations": [{"focus": "string", "actions": ["string"], "resources": ["string"]}]
  },
  "lexical": {
    "tokens": [
      {
        "word": "string",
        "normalized": "string",
        "part_of_speech": "noun|verb|adjective|adverb|connector|other"
      }
    ]
  }
}
TEXT,
            'analysis_sentences' => <<<TEXT
You are an expert English writing mentor.
Return ONLY valid JSON and no markdown.
Input JSON:
{
  "sentences": [{"index": 1, "text": "string"}],
  "language_level": "optional"
}
Output:
{
  "analysis": {
    "sentences": [
      {
        "index": 1,
        "original": "string",
        "summary": "string",
        "issues": [
          {
            "type": "grammar|spelling|word_choice|structure|tone|punctuation",
            "description": "string",
            "error_excerpt": "string",
            "corrected_form": "string",
            "explanation": "string",
            "practice_tip": "string"
          }
        ],
        "improved_sentence": "string"
      }
    ]
  },
  "lexical": {
    "tokens": [
      {
        "word": "string",
        "normalized": "string",
        "part_of_speech": "noun|verb|adjective|adverb|connector|other"
      }
    ]
  }
}
TEXT,
            'analysis_overall' => <<<TEXT
You are an expert English writing coach.
Return ONLY valid JSON and no markdown.
Input JSON:
{
  "essay": "string",
  "language_level": "optional"
}
Output:
{
  "keywords": {
    "keywords": [
      {
        "word": "string",
        "part_of_speech": "noun|verb|adjective|adverb|connector|other",
        "meaning": "string",
        "usage_tip": "string",
        "ipa": {"uk": "string", "us": "string"},
        "common_usage": ["string"],
        "memory_tip": "string"
      }
    ]
  },
  "feedback": {
    "strengths": [{"aspect": "string", "detail": "string", "example": "string"}],
    "weaknesses": [{"issue": "string", "description": "string", "sentence_reference": "string", "practice": "string", "improvement_steps": "string"}],
    "recommendations": [{"focus": "string", "actions": ["string"], "resources": ["string"]}]
  }
}
TEXT,
            'word_detail' => <<<TEXT
You are a patient English lexicographer.
Return only valid JSON:
{
  "word": "string",
  "phonetics": {"uk": "string", "us": "string"},
  "entries": [
    {
      "part_of_speech": "noun|verb|adjective|adverb|connector|other|phrase",
      "meanings": [
        {
          "definition": "string",
          "usage": "string",
          "example": "string"
        }
      ],
      "common_patterns": ["string"],
      "memory_tip": "string"
    }
  ],
  "collocations": ["string"],
  "idioms": ["string"],
  "pronunciation_tips": "string",
  "notes": "string"
}
TEXT,
            'chat_assistant' => "You are an AI English writing mentor. Always respond in JSON with key 'reply'.",
        ];
    }
}
