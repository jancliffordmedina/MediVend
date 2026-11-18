<?php
declare(strict_types=1);
require_once __DIR__ . '/staff.php';

/* Staff/admin account management. Every change is server-validated and keeps
 * at least one active administrator. Password changes and deactivation end
 * that user's current sign-in. */

const MEDIVEND_ROLES = ['admin', 'staff'];

function medivendValidatePassword(string $password): void
{
    if (strlen($password) < 12 || strlen($password) > 72 || strpos($password, "\0") !== false) {
        throw new MedivendCheckoutError(422, 'invalid_password', 'Choose a password of 12 to 72 characters.');
    }
}

function medivendValidateUsername(string $username): void
{
    if (preg_match(MEDIVEND_USERNAME_PATTERN, $username) !== 1) {
        throw new MedivendCheckoutError(422, 'invalid_username', 'Usernames need 3 to 80 letters, numbers, dots, underscores or hyphens, starting with a letter or number.');
    }
}

function medivendValidateDisplayName(string $name): string
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 120 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1 || !mb_check_encoding($name, 'UTF-8')) {
        throw new MedivendCheckoutError(422, 'invalid_name', 'Enter a name of 1 to 120 characters.');
    }
    return $name;
}

function medivendListUsers(PDO $database): array
{
    $rows = $database->query('SELECT id, username, display_name, role, is_active, session_token_hash IS NOT NULL AS signed_in, last_login_at, created_at, created_by
        FROM staff_users ORDER BY is_active DESC, role, username')->fetchAll();
    return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'username' => $row['username'], 'display_name' => $row['display_name'],
        'role' => $row['role'], 'is_active' => (bool) $row['is_active'], 'signed_in' => (bool) $row['signed_in'],
        'last_login_at' => $row['last_login_at'], 'created_at' => $row['created_at'], 'created_by' => $row['created_by']], $rows);
}

function medivendCreateUser(PDO $database, string $username, string $displayName, string $role, string $password, string $actor): array
{
    medivendValidateUsername($username);
    $displayName = medivendValidateDisplayName($displayName);
    if (!in_array($role, MEDIVEND_ROLES, true)) throw new MedivendCheckoutError(422, 'invalid_role', 'Choose the admin or staff role.');
    medivendValidatePassword($password);
    try {
        $insert = $database->prepare('INSERT INTO staff_users (username, display_name, role, password_hash, created_by) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([$username, $displayName, $role, password_hash($password, PASSWORD_DEFAULT), $actor]);
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) throw new MedivendCheckoutError(409, 'username_taken', 'That username is already in use.');
        throw $error;
    }
    return ['id' => (int) $database->lastInsertId(), 'username' => $username];
}

/** $actorId is null for the bootstrap (private-environment) administrator. */
function medivendUpdateUser(PDO $database, ?int $actorId, int $id, array $changes): void
{
    $database->beginTransaction();
    try {
        // Lock every admin row so concurrent edits cannot remove the last admin.
        $database->query("SELECT id FROM staff_users WHERE role = 'admin' FOR UPDATE")->fetchAll();
        $query = $database->prepare('SELECT id, role, is_active FROM staff_users WHERE id = ? FOR UPDATE');
        $query->execute([$id]); $user = $query->fetch();
        if (!$user) throw new MedivendCheckoutError(404, 'user_not_found', 'That account no longer exists.');
        $role = $changes['role'] ?? $user['role'];
        $active = array_key_exists('is_active', $changes) ? (bool) $changes['is_active'] : (bool) $user['is_active'];
        if (!in_array($role, MEDIVEND_ROLES, true)) throw new MedivendCheckoutError(422, 'invalid_role', 'Choose the admin or staff role.');
        if ($actorId === $id && ($role !== 'admin' || !$active)) {
            throw new MedivendCheckoutError(409, 'self_change', 'You cannot remove your own administrator access or deactivate yourself.');
        }
        if ($user['role'] === 'admin' && (int) $user['is_active'] && ($role !== 'admin' || !$active)
            && (int) $database->query("SELECT COUNT(*) FROM staff_users WHERE role = 'admin' AND is_active = 1")->fetchColumn() <= 1) {
            throw new MedivendCheckoutError(409, 'last_admin', 'Keep at least one active administrator.');
        }
        $sets = ['role = ?', 'is_active = ?']; $values = [$role, $active ? 1 : 0];
        if (isset($changes['display_name'])) { $sets[] = 'display_name = ?'; $values[] = medivendValidateDisplayName($changes['display_name']); }
        if (isset($changes['password'])) {
            medivendValidatePassword($changes['password']);
            $sets[] = 'password_hash = ?'; $values[] = password_hash($changes['password'], PASSWORD_DEFAULT);
        }
        if (isset($changes['password']) || !$active || $role !== $user['role']) $sets[] = 'session_token_hash = NULL'; // end their sign-in
        $values[] = $id;
        $update = $database->prepare('UPDATE staff_users SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $update->execute($values);
        $database->commit();
    } catch (Throwable $error) { if ($database->inTransaction()) $database->rollBack(); throw $error; }
}
