<?php

require_once __DIR__ . '/db.php';

class Auth {

    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) {
            // Use a distinct session name so this app's cookies don't collide
            // with the main helpdesk app on the same host.
            session_name('UPOU_ADMIN_SID');
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    /**
     * Register a new admin/agent user.
     * The FIRST user ever registered becomes an admin; all subsequent
     * users are agents.
     */
    public static function register(string $username, string $email, string $password): array {
        $username = trim($username);
        $email    = trim($email);

        if ($username === '' || $email === '' || $password === '') {
            return ['ok' => false, 'error' => 'All fields are required.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid email address.'];
        }
        if (strlen($password) < 8) {
            return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
            return ['ok' => false, 'error' => 'Username must be 3-32 chars (letters, digits, _ . -).'];
        }

        $pdo = Database::get();

        // Check for duplicates
        $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'error' => 'Username or email already in use.'];
        }

        // First user becomes admin, rest are agents
        $count = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
        $role  = $count === 0 ? 'admin' : 'agent';

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare(
            'INSERT INTO admin_users (username, email, password_hash, role, is_active, created_at)
             VALUES (?, ?, ?, ?, 1, NOW())'
        );
        $stmt->execute([$username, $email, $hash, $role]);

        return [
            'ok'      => true,
            'user_id' => (int) $pdo->lastInsertId(),
            'role'    => $role,
            'is_first'=> $role === 'admin',
        ];
    }

    public static function login(string $usernameOrEmail, string $password): array {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT * FROM admin_users WHERE (username = ? OR email = ?) LIMIT 1'
        );
        $stmt->execute([$usernameOrEmail, $usernameOrEmail]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['ok' => false, 'error' => 'Invalid username or password.'];
        }
        if ((int) $user['is_active'] !== 1) {
            return ['ok' => false, 'error' => 'Account is deactivated. Contact an admin.'];
        }

        // Update last_login
        $pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?')
            ->execute([$user['id']]);

        self::start();
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'       => (int) $user['id'],
            'username' => $user['username'],
            'email'    => $user['email'],
            'role'     => $user['role'],
        ];
        return ['ok' => true, 'user' => $_SESSION['user']];
    }

    public static function logout(): void {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool {
        self::start();
        return isset($_SESSION['user']);
    }

    public static function user(): ?array {
        self::start();
        return $_SESSION['user'] ?? null;
    }

    public static function isAdmin(): bool {
        $u = self::user();
        return $u !== null && ($u['role'] ?? '') === 'admin';
    }

    public static function requireLogin(): void {
        if (!self::check()) {
            header('Location: /login.php');
            exit;
        }
    }

    public static function requireAdmin(): void {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            echo '<div style="font-family:sans-serif;padding:2rem;">';
            echo '<h1>403 Forbidden</h1><p>Admin role required for this page.</p>';
            echo '<p><a href="/tickets.php">Back to tickets</a></p></div>';
            exit;
        }
    }

    public static function csrfToken(): string {
        self::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function csrfCheck(?string $token): bool {
        self::start();
        return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
    }

    public static function log(string $action, ?string $ticketId = null, ?string $details = null): void {
        $u = self::user();
        if (!$u) return;
        try {
            $pdo = Database::get();
            $pdo->prepare(
                'INSERT INTO audit_log (user_id, username, action, ticket_id, details, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            )->execute([$u['id'], $u['username'], $action, $ticketId, $details]);
        } catch (Exception $e) {
            error_log('audit log failed: ' . $e->getMessage());
        }
    }
}
