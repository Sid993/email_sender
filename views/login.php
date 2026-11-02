<?php
use App\Http\Session;
$title = 'Log in · Bulk Mailer';
require __DIR__ . '/partials/head.php';
?>
<body class="auth-body">
<main class="auth-card">
    <h1 class="app-title">SMTP MAIL BULK MAIL - SERVER</h1>
    <p class="auth-sub">Log in to your account</p>

    <?php if (!empty($notice)): ?><div class="alert alert-success py-2"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="post" action="/login" novalidate>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(Session::csrfToken()) ?>">
        <div class="mb-3">
            <label class="form-label" for="email">Email</label>
            <input class="form-control" type="email" id="email" name="email" required autocomplete="username" value="<?= htmlspecialchars($old['email'] ?? '') ?>" autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button class="btn btn-primary w-100" type="submit">Log in</button>
    </form>
    <p class="auth-switch">No account yet? <a href="/register">Create one</a></p>
</main>
</body>
</html>
