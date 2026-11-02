<?php
use App\Http\Session;
$title = 'Create account · Bulk Mailer';
require __DIR__ . '/partials/head.php';
?>
<body class="auth-body">
<main class="auth-card">
    <h1 class="app-title">SMTP MAIL BULK MAIL - SERVER</h1>
    <p class="auth-sub">Create your account</p>

    <?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="post" action="/register" novalidate>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(Session::csrfToken()) ?>">
        <div class="mb-3">
            <label class="form-label" for="name">Name</label>
            <input class="form-control" id="name" name="name" required maxlength="120" value="<?= htmlspecialchars($old['name'] ?? '') ?>" autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label" for="email">Email</label>
            <input class="form-control" type="email" id="email" name="email" required autocomplete="username" value="<?= htmlspecialchars($old['email'] ?? '') ?>">
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">Password <span class="text-muted fw-normal">(min. 8 characters)</span></label>
            <input class="form-control" type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
        </div>
        <div class="mb-3">
            <label class="form-label" for="password_confirmation">Confirm password</label>
            <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
        </div>
        <button class="btn btn-primary w-100" type="submit">Create account</button>
    </form>
    <p class="auth-switch">Already registered? <a href="/login">Log in</a></p>
</main>
</body>
</html>
