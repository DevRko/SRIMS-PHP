<?php
/**
 * SRIMS - Auth helpers
 * Session-based replacement for NextAuth (src/lib/auth.ts + middleware.ts).
 */

function isLoggedIn(): bool
{
    return isset($_SESSION['user']) && !empty($_SESSION['user']['id']);
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Call at the top of every protected page (after config.php is required).
 * Mirrors middleware.ts's route matcher, which protects every page except
 * /login, /forgot-password and /reset-password.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . BASE_URL . '/login.php?redirect=' . $redirect);
        exit;
    }
}

/**
 * Call after requireLogin() on pages restricted to specific roles.
 * Mirrors the `roles` arrays in src/lib/navigation.ts.
 */
function requireRole(array $allowedRoles): void
{
    $user = currentUser();
    if (!$user || !in_array($user['role'], $allowedRoles, true)) {
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }
}

function login(array $dbUser): void
{
    $_SESSION['user'] = [
        'id'             => $dbUser['id'],
        'name'           => $dbUser['name'],
        'email'          => $dbUser['email'],
        'role'           => $dbUser['role'],
        'departmentId'   => $dbUser['department_id'],
        'departmentName' => $dbUser['department_name'] ?? '',
        'avatarUrl'      => $dbUser['avatar_url'] ?? null,
    ];
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function addAuditLog(PDO $pdo, string $actorId, string $action, string $entity, string $entityId, ?string $detail = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (id, actor_id, action, entity, entity_id, after_json, `timestamp`) VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([generateId('audit'), $actorId, $action, $entity, $entityId, $detail]);
}
