<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_logged_in(): bool {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

function get_user_role(): ?string {
    return $_SESSION['role'] ?? null;
}

function is_admin(): bool {
    return get_user_role() === 'admin';
}

function is_analyst(): bool {
    return get_user_role() === 'analyst';
}

function is_viewer(): bool {
    return get_user_role() === 'viewer';
}

function can_access_section(string $section): bool {
    $role = get_user_role();
    
    // Admin can access everything
    if ($role === 'admin') {
        return true;
    }
    
    // Viewers cannot access any specific sections (only saved reports)
    if ($role === 'viewer') {
        return false;
    }
    
    // Analysts can only access their allowed sections
    if ($role === 'analyst') {
        $allowed = $_SESSION['allowed_sections'] ?? [];
        return in_array($section, $allowed, true);
    }
    
    return false;
}

function get_allowed_sections(): array {
    return $_SESSION['allowed_sections'] ?? [];
}

function require_role(string $role): void {
    require_login();
    if (get_user_role() !== $role) {
        http_response_code(403);
        die('Access denied. This page requires ' . htmlspecialchars($role) . ' role.');
    }
}

function require_section_access(string $section): void {
    require_login();
    if (!can_access_section($section)) {
        http_response_code(403);
        die('Access denied. You do not have access to the ' . htmlspecialchars($section) . ' section.');
    }
}