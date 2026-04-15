<?php

require_once __DIR__ . '/db.php';

class UserRepo {

    public static function all(): array {
        return Database::get()
            ->query('SELECT id, username, email, role, is_active, created_at, last_login_at FROM admin_users ORDER BY created_at DESC')
            ->fetchAll();
    }

    public static function find(int $id): ?array {
        $stmt = Database::get()->prepare('SELECT * FROM admin_users WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r ?: null;
    }

    public static function setRole(int $id, string $role): bool {
        if (!in_array($role, ['admin', 'agent'], true)) return false;
        return Database::get()
            ->prepare('UPDATE admin_users SET role = ? WHERE id = ?')
            ->execute([$role, $id]);
    }

    public static function setActive(int $id, bool $active): bool {
        return Database::get()
            ->prepare('UPDATE admin_users SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
    }

    public static function adminCount(): int {
        return (int) Database::get()
            ->query('SELECT COUNT(*) FROM admin_users WHERE role = "admin" AND is_active = 1')
            ->fetchColumn();
    }

    /** Usernames that can be assigned to tickets (active agents + admins). */
    public static function assignableUsernames(): array {
        return Database::get()
            ->query('SELECT username FROM admin_users WHERE is_active = 1 ORDER BY username')
            ->fetchAll(PDO::FETCH_COLUMN);
    }
}
