<?php
/**
 * Connexion. La protection brute force vit dans includes/bruteforce.php.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/bruteforce.php';
require_once __DIR__ . '/includes/activity.php';
require_once __DIR__ . '/includes/layout.php';

// --- Traitement ------------------------------------------------------------


startSession();

if (isset($_SESSION['user_id']) || restoreFromRememberCookie()) {
    header('Location: ' . url('index.php'));
    exit;
}

$connect = db();
bf_purge($connect);

$ip    = clientIp();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);

    // Le blocage est évalué avant toute requête sur users : pas de travail offert à l'attaquant.
    $status = bf_get_status($connect, $ip, $username);

    if ($status['blocked']) {
        $error = 'Trop de tentatives échouées. Réessaie dans ' . BF_WINDOW_MINUTES . ' minutes.';
    } elseif ($username === '' || $password === '') {
        $error = 'Identifiant et mot de passe requis.';
    } else {
        $sql  = 'SELECT id, username, role, password FROM ' . tbl('users') . ' WHERE username = ? LIMIT 1';
        $stmt = $connect->prepare($sql);
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            bf_log($connect, $ip, $username, true);

            session_regenerate_id(true);
            $_SESSION['user_id']  = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'] ?? 'user';

            $upd = $connect->prepare('UPDATE ' . tbl('users') . ' SET last_login = NOW() WHERE id = ?');
            $upd->bind_param('i', $user['id']);
            $upd->execute();
            $upd->close();

            logAction('login', 'utilisateur', (int)$user['id'], 'rôle : ' . ($user['role'] ?? 'user'));

            if ($remember) {
                $token = bin2hex(random_bytes(32));
                $exp   = date('Y-m-d H:i:s', time() + REMEMBER_LIFETIME);

                $rmb = $connect->prepare(
                    'UPDATE ' . tbl('users') . ' SET remember_token = ?, remember_expires = ? WHERE id = ?'
                );
                $rmb->bind_param('ssi', $token, $exp, $user['id']);
                $rmb->execute();
                $rmb->close();

                setRememberCookie($token);
            }

            $target = $_SESSION['redirect_after_login'] ?? url('index.php');
            unset($_SESSION['redirect_after_login']);
            header('Location: ' . $target);
            exit;
        }

        // Échec : message neutre, on ne révèle jamais si c'est l'identifiant ou le mot de passe.
        bf_log($connect, $ip, $username, false);

        $status = bf_get_status($connect, $ip, $username);
        $error  = $status['blocked']
            ? 'Trop de tentatives échouées. Réessaie dans ' . BF_WINDOW_MINUTES . ' minutes.'
            : 'Identifiants incorrects. ' . $status['remaining'] . ' essai(s) restant(s).';
    }
}

renderHeader('Connexion', [
    'css'       => ['login'],
    'nav'       => false,
    'chrome'    => false,
    'bodyClass' => 'login-body',
]);
?>

<div class="login-card">
    <div class="login-head">
        <span class="brand-mark">VO</span>
        <h1 class="login-title">Version Originale Cycles</h1>
        <p class="login-sub">Outils internes</p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
        <?= csrfField() ?>

        <div class="field">
            <label class="label" for="username">Identifiant</label>
            <input class="input" type="text" id="username" name="username" required autofocus
                   autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
        </div>

        <div class="field">
            <label class="label" for="password">Mot de passe</label>
            <div class="password-wrap">
                <input class="input" type="password" id="password" name="password" required
                       autocomplete="current-password">
                <button type="button" class="password-toggle" id="togglePassword"
                        aria-label="Afficher le mot de passe">
                    <svg class="icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg class="icon-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/>
                        <path d="M6.61 6.61A18.15 18.15 0 0 0 2 11s3.5 7 10 7a9.12 9.12 0 0 0 5.39-1.61"/>
                        <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>
                        <line x1="2" y1="2" x2="22" y2="22"/>
                    </svg>
                </button>
            </div>
        </div>

        <label class="check">
            <input type="checkbox" name="remember" value="1">
            Rester connecté (30 jours)
        </label>

        <button type="submit" class="btn btn-block">Se connecter</button>
    </form>
</div>

<?php renderFooter(['chrome' => false, 'scripts' => [url('assets/js/login.js') . '?v=' . APP_VERSION]]); ?>
