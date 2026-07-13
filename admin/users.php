<?php
/**
 * Gestion des utilisateurs — réservé au rôle owner.
 *
 * Garde-fous : on ne peut ni changer son propre rôle, ni se supprimer,
 * ni supprimer le dernier owner (sinon plus personne n'administre).
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/layout.php';

checkAuth();
checkRole(['owner']);

const ROLES = ['owner', 'admin', 'user'];

$connect = db();
$me      = (int)$_SESSION['user_id'];
$error   = '';
$notice  = '';

function ownerCount(mysqli $connect): int
{
    $res = $connect->query('SELECT COUNT(*) AS n FROM ' . tbl('users') . ' WHERE role = "owner"');
    return (int)$res->fetch_assoc()['n'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $action = (string)($_POST['action'] ?? '');
    $userId = (int)($_POST['user_id'] ?? 0);

    try {
        switch ($action) {
            case 'create':
                $username = trim((string)($_POST['username'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                $email    = trim((string)($_POST['email'] ?? ''));
                $role     = in_array($_POST['role'] ?? '', ROLES, true) ? $_POST['role'] : 'user';

                if ($username === '' || strlen($password) < 8) {
                    throw new RuntimeException('Identifiant requis, mot de passe d\'au moins 8 caractères.');
                }

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $connect->prepare(
                    'INSERT INTO ' . tbl('users') . ' (username, role, password, email) VALUES (?, ?, ?, ?)'
                );
                $stmt->bind_param('ssss', $username, $role, $hash, $email);
                $stmt->execute();
                $stmt->close();

                $notice = 'Utilisateur « ' . $username . ' » créé.';
                break;

            case 'role':
                $role = in_array($_POST['role'] ?? '', ROLES, true) ? $_POST['role'] : 'user';

                if ($userId === $me) {
                    throw new RuntimeException('Tu ne peux pas changer ton propre rôle.');
                }

                $stmt = $connect->prepare('UPDATE ' . tbl('users') . ' SET role = ? WHERE id = ?');
                $stmt->bind_param('si', $role, $userId);
                $stmt->execute();
                $stmt->close();

                $notice = 'Rôle mis à jour.';
                break;

            case 'password':
                $password = (string)($_POST['password'] ?? '');
                if (strlen($password) < 8) {
                    throw new RuntimeException('Mot de passe trop court (8 caractères minimum).');
                }

                // Changer le mot de passe invalide les sessions « rester connecté ».
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $connect->prepare(
                    'UPDATE ' . tbl('users') . '
                     SET password = ?, remember_token = NULL, remember_expires = NULL WHERE id = ?'
                );
                $stmt->bind_param('si', $hash, $userId);
                $stmt->execute();
                $stmt->close();

                $notice = 'Mot de passe mis à jour.';
                break;

            case 'delete':
                if ($userId === $me) {
                    throw new RuntimeException('Tu ne peux pas te supprimer toi-même.');
                }

                $stmt = $connect->prepare('SELECT role FROM ' . tbl('users') . ' WHERE id = ?');
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $target = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($target && $target['role'] === 'owner' && ownerCount($connect) <= 1) {
                    throw new RuntimeException('Impossible de supprimer le dernier owner.');
                }

                $stmt = $connect->prepare('DELETE FROM ' . tbl('users') . ' WHERE id = ?');
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->close();

                $notice = 'Utilisateur supprimé.';
                break;
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (mysqli_sql_exception $e) {
        $error = $e->getCode() === 1062
            ? 'Cet identifiant existe déjà.'
            : 'Opération impossible : ' . $e->getMessage();
    }
}

$users     = $connect->query(
    'SELECT id, username, role, email, created_at, last_login
     FROM ' . tbl('users') . ' ORDER BY FIELD(role, "owner", "admin", "user"), username'
)->fetch_all(MYSQLI_ASSOC);
$lastOwner = ownerCount($connect) <= 1;

renderHeader('Utilisateurs', ['css' => ['admin'], 'icons' => true]);
?>

<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>
<?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>Ajouter un utilisateur</h2></div>

    <form method="post" class="form-inline" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">

        <div class="field">
            <label class="label" for="new-username">Identifiant</label>
            <input class="input" type="text" id="new-username" name="username" required>
        </div>

        <div class="field">
            <label class="label" for="new-password">Mot de passe</label>
            <input class="input" type="password" id="new-password" name="password" required
                   minlength="8" autocomplete="new-password">
        </div>

        <div class="field">
            <label class="label" for="new-email">E-mail</label>
            <input class="input" type="email" id="new-email" name="email">
        </div>

        <div class="field">
            <label class="label" for="new-role">Rôle</label>
            <select class="select" id="new-role" name="role">
                <?php foreach (ROLES as $role): ?>
                    <option value="<?= e($role) ?>"<?= $role === 'user' ? ' selected' : '' ?>><?= e($role) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn">Créer</button>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2>Comptes</h2>
        <span class="muted text-sm"><?= count($users) ?> utilisateur(s)</span>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>Identifiant</th>
                <th>Rôle</th>
                <th>E-mail</th>
                <th>Créé le</th>
                <th>Dernière connexion</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user):
                $isSelf       = (int)$user['id'] === $me;
                $isLastOwner  = $user['role'] === 'owner' && $lastOwner;
                ?>
                <tr>
                    <td>
                        <strong><?= e($user['username']) ?></strong>
                        <?php if ($isSelf): ?><span class="muted text-sm">(toi)</span><?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= e($user['role']) ?>"><?= e($user['role']) ?></span></td>
                    <td class="muted"><?= e($user['email'] ?: '—') ?></td>
                    <td class="muted text-sm"><?= e(fmtDate($user['created_at'], 'd.m.Y')) ?></td>
                    <td class="muted text-sm"><?= e(fmtDate($user['last_login'])) ?></td>
                    <td class="row-actions">
                        <?php if (!$isSelf): ?>
                            <button type="button" class="btn-icon js-modal"
                                    data-modal="modal-role"
                                    data-user-id="<?= (int)$user['id'] ?>"
                                    data-username="<?= e($user['username']) ?>"
                                    data-role="<?= e($user['role']) ?>"
                                    title="Changer le rôle" aria-label="Changer le rôle">
                                <i class="fa-solid fa-user-shield" aria-hidden="true"></i>
                            </button>
                        <?php endif; ?>

                        <button type="button" class="btn-icon js-modal"
                                data-modal="modal-password"
                                data-user-id="<?= (int)$user['id'] ?>"
                                data-username="<?= e($user['username']) ?>"
                                title="Changer le mot de passe" aria-label="Changer le mot de passe">
                            <i class="fa-solid fa-key" aria-hidden="true"></i>
                        </button>

                        <?php if (!$isSelf && !$isLastOwner): ?>
                            <button type="button" class="btn-icon js-modal"
                                    data-modal="modal-delete"
                                    data-user-id="<?= (int)$user['id'] ?>"
                                    data-username="<?= e($user['username']) ?>"
                                    title="Supprimer" aria-label="Supprimer">
                                <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modales : une seule instance de chacune, alimentée par les data-* du bouton cliqué -->

<div class="modal" id="modal-role">
    <form class="modal-box" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="role">
        <input type="hidden" name="user_id" class="js-user-id">

        <h2 class="modal-title">Rôle de <span class="js-username"></span></h2>

        <div class="field">
            <label class="label" for="edit-role">Nouveau rôle</label>
            <select class="select" id="edit-role" name="role">
                <?php foreach (ROLES as $role): ?>
                    <option value="<?= e($role) ?>"><?= e($role) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn btn-ghost js-close">Annuler</button>
            <button type="submit" class="btn">Enregistrer</button>
        </div>
    </form>
</div>

<div class="modal" id="modal-password">
    <form class="modal-box" method="post" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="password">
        <input type="hidden" name="user_id" class="js-user-id">

        <h2 class="modal-title">Mot de passe de <span class="js-username"></span></h2>

        <div class="field">
            <label class="label" for="edit-password">Nouveau mot de passe</label>
            <input class="input" type="password" id="edit-password" name="password" required
                   minlength="8" autocomplete="new-password">
            <p class="muted text-sm">8 caractères minimum. Déconnecte les sessions « rester connecté ».</p>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn btn-ghost js-close">Annuler</button>
            <button type="submit" class="btn">Enregistrer</button>
        </div>
    </form>
</div>

<div class="modal" id="modal-delete">
    <form class="modal-box" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="user_id" class="js-user-id">

        <h2 class="modal-title">Supprimer <span class="js-username"></span> ?</h2>
        <p class="muted">Cette action est définitive. L'historique de connexion est conservé.</p>

        <div class="modal-actions">
            <button type="button" class="btn btn-ghost js-close">Annuler</button>
            <button type="submit" class="btn btn-danger">Supprimer</button>
        </div>
    </form>
</div>

<?php renderFooter(['scripts' => [url('assets/js/modal.js') . '?v=' . APP_VERSION]]); ?>
