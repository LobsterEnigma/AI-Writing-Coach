<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access Password</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="auth-page">
<main class="auth-container">
    <section class="auth-card">
        <h1>Open Source Edition</h1>
        <p class="auth-subtitle">Enter access password to use the app.</p>
        <?php if (! empty($error)): ?>
            <div class="auth-alert"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post" action="/" class="auth-form">
            <label>
                Access Password
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button type="submit" class="primary">Enter</button>
        </form>
    </section>
</main>
</body>
</html>
