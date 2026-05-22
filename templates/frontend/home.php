<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AI Writing Coach</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<header class="site-header">
    <div class="branding">
        <div class="title">
            <h1>AI Writing Coach</h1>
        </div>
    </div>
    <a class="source-badge" href="https://github.com/LobsterEnigma/AI-Writing-Coach" target="_blank" rel="noopener noreferrer" aria-label="Open source project on GitHub">
        <span class="source-badge-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" role="img" focusable="false">
                <path d="M12 2C6.48 2 2 6.58 2 12.22c0 4.5 2.87 8.32 6.84 9.66.5.1.68-.22.68-.49 0-.24-.01-1.04-.01-1.88-2.78.62-3.37-1.21-3.37-1.21-.45-1.18-1.11-1.5-1.11-1.5-.91-.64.07-.63.07-.63 1 .07 1.53 1.06 1.53 1.06.9 1.57 2.35 1.12 2.92.86.09-.67.35-1.12.64-1.38-2.22-.26-4.56-1.14-4.56-5.1 0-1.13.39-2.05 1.03-2.77-.1-.26-.45-1.32.1-2.74 0 0 .84-.28 2.75 1.06A9.3 9.3 0 0 1 12 6.84c.85 0 1.71.12 2.51.36 1.91-1.34 2.75-1.06 2.75-1.06.55 1.42.2 2.48.1 2.74.64.72 1.03 1.64 1.03 2.77 0 3.97-2.34 4.83-4.57 5.08.36.32.69.95.69 1.92 0 1.39-.01 2.5-.01 2.84 0 .27.18.59.69.49A10.24 10.24 0 0 0 22 12.22C22 6.58 17.52 2 12 2Z"/>
            </svg>
        </span>
        <span class="source-badge-text">
            <strong>AI-Writing-Coach</strong>
            <span>View on GitHub</span>
        </span>
    </a>
</header>

<main class="workspace">
    <section class="panel input-panel">
        <header class="panel-header">
            <h2>Write</h2>
            <p>Paste your essay and run analysis.</p>
        </header>
        <textarea id="essayInput" placeholder="Paste your English writing here..."></textarea>
        <div class="controls">
            <button id="analyzeButton" class="primary" type="button">Analyze Writing</button>
            <span id="statusMessage" class="status"></span>
        </div>
        <div id="analyzeProgress" class="analyze-progress" aria-hidden="true">
            <div class="analyze-progress-track">
                <span id="analyzeProgressBar"></span>
            </div>
        </div>
        <section class="original-wrapper">
            <header>
                <h3>Original Text</h3>
                <p>Select any word to view details, pronunciation, and usage tips.</p>
                <ul class="pos-legend">
                    <li class="legend-noun">Noun (名词) <span class="legend-chip"></span></li>
                    <li class="legend-verb">Verb (动词) <span class="legend-chip"></span></li>
                    <li class="legend-adjective">Adjective (形容词) <span class="legend-chip"></span></li>
                    <li class="legend-adverb">Adverb (副词) <span class="legend-chip"></span></li>
                    <li class="legend-connector">Connector (连接词) <span class="legend-chip"></span></li>
                    <li class="legend-other">Other (其他) <span class="legend-chip"></span></li>
                </ul>
            </header>
            <div id="originalText" class="original-text">Your highlighted text will appear here after analysis.</div>
        </section>
    </section>

    <section class="panel analysis-panel">
        <div class="tab-bar" role="tablist">
            <button class="tab active" data-target="analysisSection" role="tab">Sentence Analysis</button>
            <button class="tab" data-target="rewriteSection" role="tab">Sentence Rewrite</button>
            <button class="tab" data-target="keywordSection" role="tab">Key Word Analysis</button>
            <button class="tab" data-target="feedbackSection" role="tab">Feedback & Growth</button>
        </div>
        <div class="analysis-sections">
            <section id="analysisSection" class="analysis-section active">
                <h2>Sentence Analysis</h2>
                <div id="sentenceAnalysisList" class="list"></div>
            </section>
            <section id="rewriteSection" class="analysis-section">
                <h2>Sentence Rewrite</h2>
                <div id="sentenceRewriteList" class="list"></div>
            </section>
            <section id="keywordSection" class="analysis-section">
                <h2>Key Word Analysis</h2>
                <div id="keywordList" class="keyword-grid"></div>
            </section>
            <section id="feedbackSection" class="analysis-section">
                <h2>Overall Feedback</h2>
                <div id="feedbackSummary" class="feedback-grid"></div>
            </section>
        </div>
    </section>
</main>

<aside class="word-inspector" id="wordInspector" aria-hidden="true">
    <header>
        <h3 id="wordInspectorTitle">Word Details</h3>
        <button id="closeWordInspector" aria-label="Close word details">&times;</button>
    </header>
    <div id="wordInspectorContent" class="inspector-content">
        <p>Select a highlighted word to see its details.</p>
    </div>
</aside>

<script src="/assets/js/app.js"></script>
</body>
</html>
