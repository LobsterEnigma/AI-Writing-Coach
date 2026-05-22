const essayInput = document.getElementById("essayInput");
const analyzeButton = document.getElementById("analyzeButton");
const statusMessage = document.getElementById("statusMessage");
const analyzeProgress = document.getElementById("analyzeProgress");
const analyzeProgressBar = document.getElementById("analyzeProgressBar");
const originalTextEl = document.getElementById("originalText");
const tabs = Array.from(document.querySelectorAll(".tab"));
const analysisSections = Array.from(document.querySelectorAll(".analysis-section"));
const sentenceAnalysisList = document.getElementById("sentenceAnalysisList");
const sentenceRewriteList = document.getElementById("sentenceRewriteList");
const keywordList = document.getElementById("keywordList");
const feedbackSummary = document.getElementById("feedbackSummary");
const wordInspector = document.getElementById("wordInspector");
const wordInspectorTitle = document.getElementById("wordInspectorTitle");
const wordInspectorContent = document.getElementById("wordInspectorContent");
const closeWordInspector = document.getElementById("closeWordInspector");

const POS_CLASSES = {
    noun: "pos-noun",
    verb: "pos-verb",
    adjective: "pos-adjective",
    adverb: "pos-adverb",
    connector: "pos-connector",
    other: "pos-other",
};

const state = {
    lexicalTokens: [],
    keywordMap: new Map(),
    lastAnalysis: null,
};

function escapeHtml(str = "") {
    return String(str).replace(/[&<>"']/g, (char) => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;",
    }[char]));
}

function setStatus(message, type = "info") {
    if (!statusMessage) return;
    statusMessage.textContent = message;
    statusMessage.dataset.status = type;
}

function toggleLoading(button, isLoading, label = "Processing...") {
    if (!button) return;
    button.disabled = isLoading;
    button.classList.toggle("loading", isLoading);
    if (isLoading) {
        button.dataset.originalText = button.textContent;
        button.textContent = label;
    } else if (button.dataset.originalText) {
        button.textContent = button.dataset.originalText;
    }
}

function setAnalyzeProgress(percent, visible = true) {
    if (!analyzeProgress || !analyzeProgressBar) return;
    const value = Math.max(0, Math.min(100, Math.round(percent)));
    analyzeProgress.classList.toggle("active", visible);
    analyzeProgress.setAttribute("aria-hidden", visible ? "false" : "true");
    analyzeProgressBar.style.width = `${value}%`;
}

function normalizePartOfSpeech(rawPos) {
    const source = String(rawPos || "").trim().toLowerCase();
    if (!source) return "other";
    if (source.includes("名词") || source === "noun" || source === "n") return "noun";
    if (source.includes("动词") || source === "verb" || source === "v") return "verb";
    if (source.includes("形容词") || source === "adjective" || source === "adj") return "adjective";
    if (source.includes("副词") || source === "adverb" || source === "adv") return "adverb";
    if (
        source.includes("连接词")
        || source.includes("连词")
        || source.includes("介词")
        || source === "connector"
        || source === "conjunction"
        || source === "preposition"
    ) {
        return "connector";
    }
    return "other";
}

function safeJsonParse(text) {
    if (typeof text !== "string") return null;
    const trimmed = text.trim();
    if (!trimmed) return null;
    try {
        return JSON.parse(trimmed);
    } catch {
        const objectStart = trimmed.indexOf("{");
        const objectEnd = trimmed.lastIndexOf("}");
        if (objectStart !== -1 && objectEnd > objectStart) {
            const maybeObject = trimmed.slice(objectStart, objectEnd + 1);
            try {
                return JSON.parse(maybeObject);
            } catch {
                // continue
            }
        }

        const arrayStart = trimmed.indexOf("[");
        const arrayEnd = trimmed.lastIndexOf("]");
        if (arrayStart !== -1 && arrayEnd > arrayStart) {
            const maybeArray = trimmed.slice(arrayStart, arrayEnd + 1);
            try {
                return JSON.parse(maybeArray);
            } catch {
                // continue
            }
        }

        return null;
    }
}

function isHtmlLike(text = "") {
    return /<!doctype html|<html[\s>]|<body[\s>]|<title[\s>]/i.test(text);
}

function previewText(text = "", max = 220) {
    const compact = String(text || "").replace(/\s+/g, " ").trim();
    if (!compact) return "[empty response]";
    if (compact.length <= max) return compact;
    return `${compact.slice(0, max)}...`;
}

function toArray(value) {
    if (!value) return [];
    return Array.isArray(value) ? value : [value];
}

function normalizeAnalysisPayload(raw = {}) {
    const data = raw && typeof raw === "object" ? raw : {};

    const analysisSource = Array.isArray(data.analysis?.sentences)
        ? data.analysis.sentences
        : Array.isArray(data.analysis)
            ? data.analysis
            : [];
    const analysisSentences = analysisSource
        .filter((item) => item && typeof item === "object")
        .map((item, index) => ({
            index: Number.isInteger(Number(item.index)) ? Number(item.index) : index + 1,
            original: String(item.original || item.sentence || item.text || "").trim(),
            summary: String(item.summary || item.description || ""),
            issues: Array.isArray(item.issues) ? item.issues : [],
            improved_sentence: String(item.improved_sentence || item.rewrite || item.corrected_sentence || item.original || ""),
        }))
        .filter((item) => item.original !== "");

    const rewriteSource = Array.isArray(data.rewrite?.sentences)
        ? data.rewrite.sentences
        : Array.isArray(data.rewrite)
            ? data.rewrite
            : [];
    const rewriteSentences = rewriteSource
        .filter((item) => item && typeof item === "object")
        .map((item, index) => ({
            index: Number.isInteger(Number(item.index)) ? Number(item.index) : index + 1,
            original: String(item.original || item.sentence || ""),
            rewrite: String(item.rewrite || item.improved_sentence || item.original || ""),
            rationale: String(item.rationale || item.explanation || ""),
        }))
        .filter((item) => item.original || item.rewrite);

    const lexicalSource = Array.isArray(data.lexical?.tokens)
        ? data.lexical.tokens
        : Array.isArray(data.lexical)
            ? data.lexical
            : [];
    const lexicalTokens = [];
    const lexicalSeen = new Set();
    lexicalSource.forEach((item) => {
        if (!item || typeof item !== "object") return;
        const word = String(item.word || item.text || "").trim();
        if (!word) return;
        const normalized = String(item.normalized || word).toLowerCase();
        if (lexicalSeen.has(normalized)) return;
        lexicalSeen.add(normalized);
        lexicalTokens.push({
            word,
            normalized,
            part_of_speech: normalizePartOfSpeech(item.part_of_speech || item.pos || "other"),
        });
    });

    const keywordSource = Array.isArray(data.keywords?.keywords)
        ? data.keywords.keywords
        : Array.isArray(data.keywords)
            ? data.keywords
            : [];
    const keywords = keywordSource
        .filter((item) => item && typeof item === "object")
        .map((item) => ({
            word: String(item.word || item.term || item.keyword || "").trim(),
            part_of_speech: normalizePartOfSpeech(item.part_of_speech || item.pos || "other"),
            meaning: String(item.meaning || item.definition || ""),
            usage_tip: String(item.usage_tip || item.usage || ""),
            ipa: {
                uk: String(item.ipa?.uk || item.uk_ipa || "-"),
                us: String(item.ipa?.us || item.us_ipa || "-"),
            },
            common_usage: Array.isArray(item.common_usage) ? item.common_usage : [],
            memory_tip: String(item.memory_tip || ""),
        }))
        .filter((item) => item.word !== "");

    const feedbackSource = data.feedback && typeof data.feedback === "object" ? data.feedback : {};
    const feedback = {
        strengths: Array.isArray(feedbackSource.strengths) ? feedbackSource.strengths : [],
        weaknesses: Array.isArray(feedbackSource.weaknesses) ? feedbackSource.weaknesses : [],
        recommendations: Array.isArray(feedbackSource.recommendations) ? feedbackSource.recommendations : [],
    };

    return {
        analysis: { sentences: analysisSentences },
        rewrite: { sentences: rewriteSentences },
        keywords: { keywords },
        feedback,
        lexical: { tokens: lexicalTokens },
    };
}

function buildWordTokenMap(tokens) {
    const map = new Map();
    toArray(tokens).forEach((token) => {
        if (!token || typeof token !== "object") return;
        const normalized = String(token.normalized || token.word || "").toLowerCase();
        if (!normalized || map.has(normalized)) return;
        map.set(normalized, {
            part_of_speech: normalizePartOfSpeech(token.part_of_speech || token.pos || "other"),
        });
    });
    return map;
}

function highlightOriginalText(text, lexicalTokens) {
    if (!originalTextEl) return;
    if (!text || !text.trim()) {
        originalTextEl.innerHTML = "Your highlighted text will appear here after analysis.";
        return;
    }

    const tokenMap = buildWordTokenMap(lexicalTokens);
    const rendered = text.replace(/\b([A-Za-z][A-Za-z'-]*)\b/g, (match) => {
        const normalized = match.toLowerCase();
        const meta = tokenMap.get(normalized);
        if (!meta) {
            return escapeHtml(match);
        }

        const pos = normalizePartOfSpeech(meta.part_of_speech);
        const cls = POS_CLASSES[pos] || POS_CLASSES.other;
        return `<span class="word-token ${cls}" data-word="${escapeHtml(match)}">${escapeHtml(match)}</span>`;
    });

    originalTextEl.innerHTML = rendered;
}

function renderSentenceAnalysis(sentences) {
    if (!sentenceAnalysisList) return;
    if (!Array.isArray(sentences) || sentences.length === 0) {
        sentenceAnalysisList.innerHTML = '<p class="empty">No sentence analysis yet.</p>';
        return;
    }

    sentenceAnalysisList.innerHTML = sentences.map((item) => {
        const issues = Array.isArray(item.issues) ? item.issues : [];
        const issueHtml = issues.length
            ? `<ul class="issue-list">${issues.map((issue) => `
                <li class="issue-card">
                    <strong>${escapeHtml(issue.type || "issue")}</strong>
                    <div>${escapeHtml(issue.description || "")}</div>
                    ${issue.error_excerpt ? `<div class="issue-meta">Error: ${escapeHtml(issue.error_excerpt)}</div>` : ""}
                    ${issue.corrected_form ? `<div class="issue-meta">Fix: ${escapeHtml(issue.corrected_form)}</div>` : ""}
                    ${issue.practice_tip ? `<div class="issue-meta">Tip: ${escapeHtml(issue.practice_tip)}</div>` : ""}
                </li>
            `).join("")}</ul>`
            : '<p class="muted">No major issues detected in this sentence.</p>';

        return `
            <article class="card">
                <h4>Sentence ${Number(item.index) || ""}</h4>
                <p>${escapeHtml(item.original || "")}</p>
                ${item.summary ? `<p class="muted">${escapeHtml(item.summary)}</p>` : ""}
                ${issueHtml}
                ${item.improved_sentence ? `<p><strong>Improved:</strong> ${escapeHtml(item.improved_sentence)}</p>` : ""}
            </article>
        `;
    }).join("");
}

function renderSentenceRewrite(sentences) {
    if (!sentenceRewriteList) return;
    if (!Array.isArray(sentences) || sentences.length === 0) {
        sentenceRewriteList.innerHTML = '<p class="empty">No rewrite suggestions yet.</p>';
        return;
    }

    sentenceRewriteList.innerHTML = sentences.map((item) => `
        <article class="card">
            <h4>Sentence ${Number(item.index) || ""}</h4>
            <p><strong>Original:</strong> ${escapeHtml(item.original || "")}</p>
            <p><strong>Rewrite:</strong> ${escapeHtml(item.rewrite || "")}</p>
            ${item.rationale ? `<p class="muted">${escapeHtml(item.rationale)}</p>` : ""}
        </article>
    `).join("");
}

function renderKeywords(keywords) {
    if (!keywordList) return;
    if (!Array.isArray(keywords) || keywords.length === 0) {
        keywordList.innerHTML = '<p class="empty">No keywords yet.</p>';
        return;
    }

    state.keywordMap.clear();
    keywords.forEach((item) => {
        if (!item || !item.word) return;
        state.keywordMap.set(String(item.word).toLowerCase(), { details: item });
    });

    keywordList.innerHTML = keywords.map((item) => `
        <button type="button" class="keyword" data-word="${escapeHtml(item.word)}">
            <strong>${escapeHtml(item.word)}</strong>
            <span class="badge">${escapeHtml(item.part_of_speech || "other")}</span>
            ${item.meaning ? `<p>${escapeHtml(item.meaning)}</p>` : ""}
            ${item.usage_tip ? `<p class="muted">${escapeHtml(item.usage_tip)}</p>` : ""}
        </button>
    `).join("");
}

function renderFeedback(feedback) {
    if (!feedbackSummary) return;
    const strengths = Array.isArray(feedback?.strengths) ? feedback.strengths : [];
    const weaknesses = Array.isArray(feedback?.weaknesses) ? feedback.weaknesses : [];
    const recommendations = Array.isArray(feedback?.recommendations) ? feedback.recommendations : [];

    feedbackSummary.innerHTML = `
        <article class="feedback-card">
            <h3>Strengths</h3>
            ${
    strengths.length
        ? `<ul>${strengths.map((item) => `<li>${escapeHtml(item.aspect || item.detail || JSON.stringify(item))}</li>`).join("")}</ul>`
        : '<p class="muted">No strengths summary yet.</p>'
}
        </article>
        <article class="feedback-card">
            <h3>Weaknesses</h3>
            ${
    weaknesses.length
        ? `<ul>${weaknesses.map((item) => `<li>${escapeHtml(item.issue || item.description || JSON.stringify(item))}</li>`).join("")}</ul>`
        : '<p class="muted">No weaknesses summary yet.</p>'
}
        </article>
        <article class="feedback-card">
            <h3>Recommendations</h3>
            ${
    recommendations.length
        ? `<ul>${recommendations.map((item) => `<li>${escapeHtml(item.focus || item.action || JSON.stringify(item))}</li>`).join("")}</ul>`
        : '<p class="muted">No recommendations yet.</p>'
}
        </article>
    `;
}

function updateAnalysisView(result) {
    const analysisSentences = result?.analysis?.sentences || [];
    const rewriteSentences = result?.rewrite?.sentences || [];
    const keywords = result?.keywords?.keywords || [];
    const feedback = result?.feedback || {};
    const lexical = result?.lexical?.tokens || [];

    state.lastAnalysis = result;
    state.lexicalTokens = lexical;

    const originalText = essayInput?.value || analysisSentences.map((item) => item.original).join(" ");
    highlightOriginalText(originalText, lexical);
    renderSentenceAnalysis(analysisSentences);
    renderSentenceRewrite(rewriteSentences);
    renderKeywords(keywords);
    renderFeedback(feedback);
}

async function analyzeEssay() {
    const text = essayInput?.value?.trim() || "";
    if (!text) {
        setStatus("Please paste your writing first.", "warning");
        return;
    }

    toggleLoading(analyzeButton, true, "Analyzing...");
    setAnalyzeProgress(10, true);
    setStatus("Analyzing...");

    try {
        const response = await fetch("/?api=analyze", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ text }),
        });

        const raw = await response.text();
        const json = safeJsonParse(raw);
        if (!json || typeof json !== "object") {
            if (response.status === 404) {
                throw new Error("Analyze endpoint returned 404. The request is not reaching PHP route handling.");
            }
            if (response.status === 502 || response.status === 503 || response.status === 504) {
                throw new Error(`Gateway error ${response.status}. The web server could not get a valid response from PHP.`);
            }
            if (isHtmlLike(raw)) {
                throw new Error(`Server returned HTML instead of JSON (HTTP ${response.status}). Preview: ${previewText(raw)}`);
            }
            throw new Error(`Invalid response from server (HTTP ${response.status}). Preview: ${previewText(raw)}`);
        }

        if (!response.ok) {
            throw new Error(json.error || "Analysis failed.");
        }

        const normalized = normalizeAnalysisPayload(json.data || {});
        updateAnalysisView(normalized);
        setAnalyzeProgress(100, true);
        setStatus("Analysis completed.", "success");
        window.setTimeout(() => setAnalyzeProgress(0, false), 400);
    } catch (error) {
        setAnalyzeProgress(0, false);
        setStatus(error.message || "Analysis failed.", "error");
    } finally {
        toggleLoading(analyzeButton, false);
    }
}

async function fetchWordDetails(word) {
    const cached = state.keywordMap.get(String(word).toLowerCase());
    if (cached && cached.details) {
        openWordInspector(word, cached.details);
        return;
    }

    openWordInspector(word, { loading: true });
    try {
        const response = await fetch("/?api=word-detail", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ word }),
        });
        const json = await response.json();
        if (!response.ok) {
            throw new Error(json.error || "Unable to fetch word details.");
        }
        const details = json.data || {};
        const existing = state.keywordMap.get(String(word).toLowerCase()) || {};
        existing.details = details;
        state.keywordMap.set(String(word).toLowerCase(), existing);
        openWordInspector(word, details);
    } catch (error) {
        openWordInspector(word, { error: error.message || "Failed to load word details." });
    }
}

function formatIpa(value) {
    const text = String(value || "").trim();
    if (!text || text === "-") return "";
    return text.startsWith("/") ? text : `/${text}/`;
}

function openWordInspector(word, payload) {
    if (!wordInspector) return;
    wordInspector.classList.add("active");
    wordInspector.setAttribute("aria-hidden", "false");
    wordInspectorTitle.textContent = word;

    if (payload?.loading) {
        wordInspectorContent.innerHTML = "<p>Loading word details...</p>";
        return;
    }

    if (payload?.error) {
        wordInspectorContent.innerHTML = `<p class="error">${escapeHtml(payload.error)}</p>`;
        return;
    }

    const phonetics = payload.phonetics || {};
    const ipaUk = formatIpa(phonetics.uk || phonetics.uk_ipa);
    const ipaUs = formatIpa(phonetics.us || phonetics.us_ipa);
    const ipaRow = [ipaUk && `UK ${escapeHtml(ipaUk)}`, ipaUs && `US ${escapeHtml(ipaUs)}`].filter(Boolean).join(" | ");

    const entries = toArray(payload.entries).map((entry) => {
        const meanings = toArray(entry.meanings).map((meaning) => `
            <li>
                <strong>${escapeHtml(meaning.definition || "")}</strong>
                ${meaning.usage ? `<div class="issue-meta">${escapeHtml(meaning.usage)}</div>` : ""}
                ${meaning.example ? `<div class="issue-example"><span>Example</span>${escapeHtml(meaning.example)}</div>` : ""}
            </li>
        `).join("");

        return `
            <div class="entry-card">
                <strong>${escapeHtml(entry.part_of_speech || "")}</strong>
                ${meanings ? `<ul>${meanings}</ul>` : ""}
                ${entry.memory_tip ? `<div class="memory-tip">${escapeHtml(entry.memory_tip)}</div>` : ""}
            </div>
        `;
    }).join("");

    wordInspectorContent.innerHTML = `
        ${ipaRow ? `<div class="ipa-row">${ipaRow}</div>` : ""}
        ${entries || "<p>No detail available.</p>"}
        <button type="button" class="speak" data-word="${escapeHtml(word)}">Play pronunciation</button>
    `;
}

function closeInspector() {
    if (!wordInspector) return;
    wordInspector.classList.remove("active");
    wordInspector.setAttribute("aria-hidden", "true");
}

function playPronunciation(word) {
    if (!("speechSynthesis" in window)) {
        alert("Speech synthesis is not supported in this browser.");
        return;
    }
    const utterance = new SpeechSynthesisUtterance(word);
    utterance.lang = "en-US";
    speechSynthesis.speak(utterance);
}

function handleWordClick(event) {
    const token = event.target.closest(".word-token");
    if (!token) return;
    const word = token.dataset.word;
    if (!word) return;
    fetchWordDetails(word);
}

function handleKeywordClick(event) {
    const keyword = event.target.closest(".keyword");
    const word = keyword?.dataset?.word;
    if (!word) return;
    fetchWordDetails(word);
}

function handleTabClick(event) {
    const target = event.currentTarget.dataset.target;
    tabs.forEach((tab) => tab.classList.toggle("active", tab.dataset.target === target));
    analysisSections.forEach((section) => {
        section.classList.toggle("active", section.id === target);
    });
}

tabs.forEach((tab) => tab.addEventListener("click", handleTabClick));
analyzeButton?.addEventListener("click", analyzeEssay);
originalTextEl?.addEventListener("click", handleWordClick);
keywordList?.addEventListener("click", handleKeywordClick);
closeWordInspector?.addEventListener("click", closeInspector);
wordInspectorContent?.addEventListener("click", (event) => {
    const speak = event.target.closest(".speak");
    if (!speak) return;
    const word = speak.dataset.word;
    if (word) playPronunciation(word);
});

setAnalyzeProgress(0, false);
setStatus("Ready.");
