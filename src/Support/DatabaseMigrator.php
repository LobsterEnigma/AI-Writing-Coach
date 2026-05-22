<?php

namespace Support;

use PDO;

class DatabaseMigrator
{
    public static function migrate(PDO $pdo): void
    {
        $queries = [
            'CREATE TABLE IF NOT EXISTS admins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                account_status TEXT NOT NULL DEFAULT "normal",
                status_updated_at TEXT NULL,
                last_login_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS ai_settings (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                base_url TEXT NOT NULL,
                api_key TEXT NOT NULL,
                model TEXT NOT NULL,
                registration_enabled INTEGER NOT NULL DEFAULT 1,
                maintenance_mode INTEGER NOT NULL DEFAULT 0,
                summary_history_limit INTEGER NOT NULL DEFAULT 8,
                summary_refresh_days INTEGER NOT NULL DEFAULT 30,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS prompts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                prompt_key TEXT NOT NULL UNIQUE,
                prompt_template TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS user_documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                session_id TEXT NOT NULL,
                filename TEXT NOT NULL,
                content TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS chat_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                session_id TEXT NOT NULL,
                role TEXT NOT NULL,
                message TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS analysis_histories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                input_hash TEXT NOT NULL,
                input_text TEXT NOT NULL,
                result_json TEXT NOT NULL,
                duration_ms INTEGER NULL,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS analysis_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT "queued",
                progress INTEGER NOT NULL DEFAULT 0,
                message TEXT NULL,
                input_text TEXT NOT NULL,
                input_hash TEXT NOT NULL,
                save_flag INTEGER NOT NULL DEFAULT 1,
                force_flag INTEGER NOT NULL DEFAULT 0,
                result_json TEXT NULL,
                meta_json TEXT NULL,
                error_message TEXT NULL,
                history_id INTEGER NULL,
                duration_ms INTEGER NULL,
                cached INTEGER NOT NULL DEFAULT 0,
                worker_token TEXT NULL,
                started_at TEXT NULL,
                finished_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS question_bank (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                prompt TEXT NOT NULL,
                options_json TEXT NULL,
                answer_json TEXT NOT NULL,
                explanation TEXT NULL,
                difficulty INTEGER NULL,
                source TEXT NOT NULL DEFAULT "manual",
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS question_tags (
                question_id INTEGER NOT NULL,
                tag TEXT NOT NULL,
                PRIMARY KEY (question_id, tag),
                FOREIGN KEY (question_id) REFERENCES question_bank(id) ON DELETE CASCADE
            )',
            'CREATE TABLE IF NOT EXISTS history_summaries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                history_hash TEXT NOT NULL,
                history_ids TEXT NOT NULL,
                summary_json TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_analysis_histories_user_created_at ON analysis_histories (user_id, created_at DESC)',
            'CREATE INDEX IF NOT EXISTS idx_analysis_histories_user_hash ON analysis_histories (user_id, input_hash)',
            'CREATE INDEX IF NOT EXISTS idx_analysis_jobs_user_created_at ON analysis_jobs (user_id, created_at DESC)',
            'CREATE INDEX IF NOT EXISTS idx_analysis_jobs_status_updated ON analysis_jobs (status, updated_at)',
            'CREATE INDEX IF NOT EXISTS idx_question_tags_tag ON question_tags (tag)',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_history_summaries_user_hash ON history_summaries (user_id, history_hash)'
        ];

        foreach ($queries as $sql) {
            $pdo->exec($sql);
        }

        self::ensureColumn($pdo, 'ai_settings', 'registration_enabled', 'INTEGER NOT NULL DEFAULT 1');
        self::ensureColumn($pdo, 'ai_settings', 'maintenance_mode', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'ai_settings', 'summary_history_limit', 'INTEGER NOT NULL DEFAULT 8');
        self::ensureColumn($pdo, 'ai_settings', 'summary_refresh_days', 'INTEGER NOT NULL DEFAULT 30');
        $pdo->exec('UPDATE ai_settings SET registration_enabled = 1 WHERE registration_enabled IS NULL');
        $pdo->exec('UPDATE ai_settings SET maintenance_mode = 0 WHERE maintenance_mode IS NULL');
        $pdo->exec('UPDATE ai_settings SET summary_history_limit = 8 WHERE summary_history_limit IS NULL');
        $pdo->exec('UPDATE ai_settings SET summary_refresh_days = 30 WHERE summary_refresh_days IS NULL');

        self::ensureColumn($pdo, 'user_documents', 'user_id', 'INTEGER NULL');
        self::ensureColumn($pdo, 'chat_messages', 'user_id', 'INTEGER NULL');
        self::ensureColumn($pdo, 'users', 'account_status', 'TEXT NOT NULL DEFAULT "normal"');
        self::ensureColumn($pdo, 'users', 'status_updated_at', 'TEXT NULL');
        $pdo->exec('UPDATE users SET account_status = "normal" WHERE account_status IS NULL OR account_status = ""');
        $pdo->exec('UPDATE users SET account_status = "normal" WHERE account_status NOT IN ("normal", "pending", "restricted", "banned")');

        self::seedAdmin($pdo);
        self::seedAiSettings($pdo);
        self::seedPrompts($pdo);
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $info) {
            if (($info['name'] ?? null) === $column) {
                return;
            }
        }

        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }

    private static function seedAdmin(PDO $pdo): void
    {
        $stmt = $pdo->query('SELECT COUNT(*) as total FROM admins');
        $total = (int) ($stmt->fetch()['total'] ?? 0);
        if ($total > 0) {
            return;
        }

        $username = 'admin';
        $password = password_hash('admin123', PASSWORD_DEFAULT);
        $now = (new \DateTimeImmutable())->format(DATE_ATOM);

        $insert = $pdo->prepare('INSERT INTO admins (username, password_hash, created_at) VALUES (:username, :password, :created_at)');
        $insert->execute([
            ':username' => $username,
            ':password' => $password,
            ':created_at' => $now,
        ]);
    }

    private static function seedAiSettings(PDO $pdo): void
    {
        $stmt = $pdo->query('SELECT COUNT(*) as total FROM ai_settings');
        $total = (int) ($stmt->fetch()['total'] ?? 0);
        if ($total > 0) {
            $pdo->exec('UPDATE ai_settings SET registration_enabled = 1 WHERE registration_enabled IS NULL');
            $pdo->exec('UPDATE ai_settings SET maintenance_mode = 0 WHERE maintenance_mode IS NULL');
            return;
        }

        $now = (new \DateTimeImmutable())->format(DATE_ATOM);

        $insert = $pdo->prepare('INSERT INTO ai_settings (id, base_url, api_key, model, registration_enabled, maintenance_mode, summary_history_limit, summary_refresh_days, updated_at) VALUES (1, :base_url, :api_key, :model, :registration_enabled, :maintenance_mode, :summary_history_limit, :summary_refresh_days, :updated_at)');
        $insert->execute([
            ':base_url' => 'https://api.newai.com/v1',
            ':api_key' => 'your-api-key-here',
            ':model' => 'newai-writing-advanced',
            ':registration_enabled' => 1,
            ':maintenance_mode' => 0,
            ':summary_history_limit' => 8,
            ':summary_refresh_days' => 30,
            ':updated_at' => $now,
        ]);
    }

    private static function seedPrompts(PDO $pdo): void
    {
        $now = (new \DateTimeImmutable())->format(DATE_ATOM);
        $upsert = $pdo->prepare('INSERT INTO prompts (prompt_key, prompt_template, updated_at) VALUES (:key, :template, :updated_at)
            ON CONFLICT(prompt_key) DO UPDATE SET prompt_template = excluded.prompt_template, updated_at = excluded.updated_at');

        $prompts = [
            'analysis_bundle' => <<<TEXT
You are an expert English writing mentor.
ALWAYS respond with STRICT JSON that matches this schema exactly (no commentary, no markdown):
{
  "analysis": {
    "sentences": [
      {
        "index": 1,
        "original": "string",
        "summary": "brief description of what the sentence tries to do",
        "issues": [
          {
            "type": "grammar|spelling|word_choice|structure|tone|punctuation",
            "description": "explain the problem in learner-friendly language",
            "error_excerpt": "the exact fragment that is wrong",
            "corrected_form": "minimal correction of the fragment",
            "explanation": "why it is wrong",
            "practice_tip": "short practice suggestion"
          }
        ],
        "improved_sentence": "full corrected sentence keeping the learner's meaning"
      }
    ]
  },
  "rewrite": {
    "sentences": [
      {
        "original": "string",
        "rewrite": "polished rewrite",
        "rationale": "what changed and why"
      }
    ]
  },
  "keywords": {
    "keywords": [
      {
        "word": "string",
        "part_of_speech": "noun|verb|adjective|adverb|connector|other",
        "meaning": "short definition",
        "usage_tip": "how to use it",
        "ipa": {"uk": "IPA or '-' if unavailable", "us": "IPA or '-'"},
        "common_usage": ["collocation or pattern"],
        "memory_tip": "mnemonic or association"
      }
    ]
  },
  "feedback": {
    "strengths": [
      {
        "aspect": "area that works well",
        "detail": "specific explanation",
        "example": "quote the sentence or phrase that shows it"
      }
    ],
    "weaknesses": [
      {
        "issue": "name of the weakness",
        "description": "what is happening",
        "sentence_reference": "sentence numbers involved",
        "practice": "targeted exercise idea",
        "improvement_steps": "step-by-step action plan"
      }
    ],
    "recommendations": [
      {
        "focus": "skill to build",
        "actions": ["concrete action"],
        "resources": ["suggested resource or activity"]
      }
    ]
  },
  "lexical": {
    "tokens": [
      {
        "word": "surface form",
        "normalized": "lowercase lemma",
        "part_of_speech": "noun|verb|adjective|adverb|connector|other"
      }
    ]
  }
}

Rules:
- Include EVERY sentence from the learner essay in order. If a sentence has no issues, return an empty array for "issues" and still provide "improved_sentence" (same as original if no change).
- Sentence numbering starts at 1 and should align with "sentence_reference" values.
- Keep explanations concise but meaningful (aim for 1?2 sentences each) and respond in English.
TEXT,
            'analysis_sentences' => <<<TEXT
You are an expert English writing mentor.
Return ONLY valid JSON (no commentary, no markdown).

Input is JSON:
{
  "sentences": [{"index": 1, "text": "string"}],
  "language_level": "optional"
}

Output JSON schema (and no extra keys):
{
  "analysis": {
    "sentences": [
      {
        "index": 1,
        "original": "string",
        "summary": "1 short sentence",
        "issues": [
          {
            "type": "grammar|spelling|word_choice|structure|tone|punctuation",
            "description": "learner-friendly explanation",
            "error_excerpt": "exact wrong fragment",
            "corrected_form": "minimal correction",
            "explanation": "why it is wrong (1-2 sentences)",
            "practice_tip": "short practice suggestion"
          }
        ],
        "improved_sentence": "full corrected sentence (or same if no change)"
      }
    ]
  },
  "lexical": {
    "tokens": [
      {
        "word": "surface form",
        "normalized": "lowercase lemma",
        "part_of_speech": "noun|verb|adjective|adverb|connector|other"
      }
    ]
  }
}

Rules:
- Use EXACTLY the input sentences; do not merge/split them.
- Keep the same "index" as the input.
- Always return one analysis item per input sentence.
- "issues": include up to 3 most important issues per sentence; if none, return an empty array.
- "lexical.tokens": return UNIQUE tokens by "normalized" (do not repeat). Limit to max 250 tokens.
TEXT,
            'rewrite_sentences' => <<<TEXT
You are an expert English writing mentor.
Return ONLY valid JSON (no commentary, no markdown).

Input is JSON:
{
  "sentences": [{"index": 1, "text": "string"}],
  "language_level": "optional"
}

Output JSON schema (and no extra keys):
{
  "rewrite": {
    "sentences": [
      {
        "index": 1,
        "original": "string",
        "rewrite": "polished rewrite",
        "rationale": "what changed and why (1-2 sentences)"
      }
    ]
  }
}

Rules:
- Use EXACTLY the input sentences; do not merge/split them.
- Keep the same "index" as the input.
- Always return one rewrite item per input sentence.
TEXT,
            'analysis_overall' => <<<TEXT
You are an expert English writing coach.
Return ONLY valid JSON (no commentary, no markdown).

Input is JSON:
{
  "essay": "string",
  "language_level": "optional"
}

Output JSON schema (and no extra keys):
{
  "keywords": {
    "keywords": [
      {
        "word": "string",
        "part_of_speech": "noun|verb|adjective|adverb|connector|other",
        "meaning": "short definition",
        "usage_tip": "how to use it",
        "ipa": {"uk": "IPA or '-' if unavailable", "us": "IPA or '-'"},
        "common_usage": ["collocation or pattern (max 3)"],
        "memory_tip": "mnemonic or association"
      }
    ]
  },
  "feedback": {
    "strengths": [
      {"aspect": "area that works well", "detail": "specific explanation", "example": "quote a phrase"}
    ],
    "weaknesses": [
      {"issue": "name", "description": "what is happening", "sentence_reference": "sentence numbers", "practice": "exercise", "improvement_steps": "step-by-step plan"}
    ],
    "recommendations": [
      {"focus": "skill", "actions": ["concrete action"], "resources": ["resource or activity"]}
    ]
  }
}

Rules:
- Provide 8-12 keywords maximum.
- Keep feedback concise but helpful (max 3 strengths, 3 weaknesses, 3 recommendations).
TEXT,
            'word_detail' => <<<TEXT
You are a patient English lexicographer. Always output valid JSON with this structure and no extra text:
{
  "word": "string",
  "phonetics": {"uk": "IPA or '-'", "us": "IPA or '-'"},
  "entries": [
    {
      "part_of_speech": "noun|verb|adjective|adverb|connector|other|phrase",
      "meanings": [
        {
          "definition": "clear learner-friendly definition",
          "usage": "how it is used (collocation, grammar pattern)",
          "example": "short example sentence"
        }
      ],
      "common_patterns": ["useful pattern or collocation"],
      "memory_tip": "mnemonic or story"
    }
  ],
  "collocations": ["common collocation"],
  "idioms": ["related idiom"],
  "pronunciation_tips": "articulation guidance",
  "notes": "any cultural or register notes"
}
TEXT,
            'chat_assistant' => "You are an AI English writing mentor. Use the learner's previous essays and feedback to hold a helpful conversation. ALWAYS respond in JSON with field 'reply'.",
            'history_summary' => <<<TEXT
You are an expert English writing coach.
Return ONLY valid JSON (no commentary, no markdown).

Input is JSON:
{
  "recent_histories": [
    {
      "id": 1,
      "created_at": "ISO-8601 timestamp",
      "feedback": {
        "strengths": [{"aspect": "string", "detail": "string", "example": "string"}],
        "weaknesses": [{"issue": "string", "description": "string", "sentence_reference": "string", "practice": "string", "improvement_steps": "string"}],
        "recommendations": [{"focus": "string", "actions": ["string"], "resources": ["string"]}]
      },
      "issue_types": {"grammar": 2, "word_choice": 1},
      "examples": [{"type": "grammar", "error": "string", "correction": "string"}]
    }
  ],
  "language_level": "optional"
}

Output JSON schema (and no extra keys):
{
  "ai_summary": "2-4 sentence overview in English",
  "strengths": [
    {"aspect": "string", "detail": "string"}
  ],
  "weaknesses": [
    {"issue": "string", "detail": "string", "example": "string"}
  ],
  "improvements": [
    {"action": "string", "steps": "string"}
  ],
  "weakness_tags": ["articles"],
  "practice_focus": [
    {"tag": "articles", "reason": "string"}
  ]
}

Rules:
- Write in English.
- Keep each list to 3-5 items max. If data is limited, return fewer items.
- Choose 3-6 weakness_tags from this list only:
  ["articles","prepositions","verb_tense","subject_verb_agreement","plural_nouns","pronouns","word_choice","collocations","sentence_structure","punctuation","spelling","cohesion","capitalization","modifiers","parallelism","run_on","fragments","voice","transition_words"]
- practice_focus tags must come from weakness_tags.
TEXT,
            'targeted_micro_practice' => <<<TEXT
You are an expert English writing coach creating short, targeted micro-practice for one learner.
Return ONLY valid JSON (no commentary, no markdown).

Input is JSON:
{
  "essay_excerpt": "string",
  "issues_by_sentence": [
    {
      "index": 1,
      "original": "string",
      "improved_sentence": "string",
      "issues": [
        {
          "type": "grammar|spelling|word_choice|structure|tone|punctuation",
          "description": "string",
          "error_excerpt": "string",
          "corrected_form": "string",
          "practice_tip": "string"
        }
      ]
    }
  ],
  "weaknesses": [
    {
      "issue": "string",
      "description": "string",
      "sentence_reference": "string",
      "improvement_steps": "string"
    }
  ],
  "question_count": 4,
  "language": "en"
}

Output JSON schema (and no extra keys):
{
  "questions": [
    {
      "type": "multiple_choice|fill_blank|correction|rewrite",
      "prompt": "string",
      "options": ["string"],
      "answer": "string or number or array",
      "explanation": "short coaching explanation",
      "focus": "short focus tag"
    }
  ]
}

Rules:
- Generate exactly question_count questions.
- Every question must be based on the learner issues provided in the input.
- Prioritize practical correction and rewrite tasks over abstract grammar trivia.
- Keep each prompt concise and learner-friendly.
- For multiple_choice questions, provide 3-4 options and make answer resolvable.
- Keep explanation to 1-2 short sentences.
TEXT,
            'question_bank_generate' => <<<TEXT
You are an expert English teacher creating practice questions for learners.
Return ONLY valid JSON (no commentary, no markdown).

Input is JSON:
{
  "tags": ["articles", "verb_tense"],
  "question_type": "multiple_choice|fill_blank|correction|rewrite|mixed",
  "count": 10,
  "difficulty": 1,
  "language": "en"
}

Output JSON schema (and no extra keys):
{
  "questions": [
    {
      "type": "multiple_choice|fill_blank|correction|rewrite",
      "prompt": "string",
      "options": ["string"],
      "answer": "string or number or array",
      "explanation": "string",
      "difficulty": 1,
      "tags": ["string"]
    }
  ]
}

Rules:
- If question_type is "mixed", include a balanced mix of types.
- For multiple_choice, provide 3-5 options and the answer must match one option (use the option text or a 1-based index).
- For fill_blank, use a single blank like "___" and provide the correct answer.
- For correction, provide an incorrect sentence and a corrected answer.
- For rewrite, provide a sentence and a target instruction (e.g., "Rewrite using a relative clause").
- Tags must be chosen from the input tags list (use 1-3 per question).
- Keep prompts concise and learner-friendly.
TEXT
        ];

        foreach ($prompts as $key => $template) {
            $upsert->execute([
                ':key' => $key,
                ':template' => $template,
                ':updated_at' => $now,
            ]);
        }
    }
}
