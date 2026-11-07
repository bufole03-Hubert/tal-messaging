<?php
declare(strict_types=1);

use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\FeatureFlagController;
use App\Controllers\HealthController;
use App\Controllers\OrganizationController;
use App\Controllers\RoleController;
use App\Controllers\SecurityController;
use App\Controllers\ThemeController;
use App\Controllers\UserController;
use App\Core\Router;

return static function (Router $r): void {
    // ---- Public ----
    $r->get('/health', [HealthController::class, 'index'], 'health');
    $r->get('/theme.css', [ThemeController::class, 'css'], 'theme.css');
    $r->get('/login', [AuthController::class, 'loginPage'], 'auth.login_page', ['guest']);
    $r->post('/api/auth/login', [AuthController::class, 'login'], 'auth.login', ['csrf']);
    $r->post('/api/auth/2fa/verify-login', [AuthController::class, 'verifyLogin2fa'], 'auth.2fa_login', ['csrf']);

    // ---- Authentifié (session + isolation multi-tenant) ----
    $r->group(['audit', 'auth', 'tenant'], function (Router $r): void {
        // Pages
        $r->get('/', [DashboardController::class, 'home'], 'dashboard.home');
        $r->get('/{slug:ceo|cto|operations|sales|data|support|client}', [DashboardController::class, 'show'], 'dashboard.show');
        $r->get('/security', [SecurityController::class, 'page'], 'security.page');
        $r->get('/appearance', [ThemeController::class, 'page'], 'appearance.page', ['perm:manage_appearance']);

        // Thème (super admin)
        $r->get('/api/theme', [ThemeController::class, 'show'], 'theme.show', ['perm:manage_appearance']);
        $r->put('/api/theme', [ThemeController::class, 'update'], 'theme.update', ['csrf', 'perm:manage_appearance']);
        $r->post('/api/theme/reset', [ThemeController::class, 'reset'], 'theme.reset', ['csrf', 'perm:manage_appearance']);

        // Auth / sécurité du compte (autorisés même si 2FA/mot de passe à finaliser)
        $r->get('/api/auth/me', [AuthController::class, 'me'], 'security.me');
        $r->post('/api/auth/logout', [AuthController::class, 'logout'], 'security.logout', ['csrf']);
        $r->post('/api/auth/password', [SecurityController::class, 'changePassword'], 'security.password', ['csrf']);
        $r->post('/api/auth/2fa/setup', [SecurityController::class, 'setup2fa'], 'security.2fa_setup', ['csrf']);
        $r->post('/api/auth/2fa/enable', [SecurityController::class, 'enable2fa'], 'security.2fa_enable', ['csrf']);
        $r->post('/api/auth/2fa/disable', [SecurityController::class, 'disable2fa'], 'security.2fa_disable', ['csrf']);

        // Organisations
        $r->get('/api/organizations', [OrganizationController::class, 'index'], 'organizations.list', ['perm:view_organization']);
        $r->post('/api/organizations', [OrganizationController::class, 'store'], 'organizations.create', ['csrf', 'perm:manage_organizations']);
        $r->get('/api/organizations/{id}', [OrganizationController::class, 'show'], 'organizations.show', ['perm:view_organization']);
        $r->put('/api/organizations/{id}', [OrganizationController::class, 'update'], 'organizations.update', ['csrf']);
        $r->delete('/api/organizations/{id}', [OrganizationController::class, 'destroy'], 'organizations.delete', ['csrf', 'perm:manage_organizations']);

        // Utilisateurs
        $r->get('/api/users', [UserController::class, 'index'], 'users.list', ['perm:manage_team']);
        $r->post('/api/users', [UserController::class, 'store'], 'users.create', ['csrf', 'perm:manage_team']);
        $r->get('/api/users/{id}', [UserController::class, 'show'], 'users.show', ['perm:manage_team']);
        $r->put('/api/users/{id}', [UserController::class, 'update'], 'users.update', ['csrf', 'perm:manage_team']);
        $r->delete('/api/users/{id}', [UserController::class, 'destroy'], 'users.delete', ['csrf', 'perm:manage_team']);

        // Rôles & permissions (lecture)
        $r->get('/api/roles', [RoleController::class, 'roles'], 'roles.list', ['perm:manage_team']);
        $r->get('/api/permissions', [RoleController::class, 'permissions'], 'permissions.list', ['perm:view_roles']);

        // Audit
        $r->get('/api/audit-logs', [AuditController::class, 'index'], 'audit.list', ['perm:view_audit_logs']);

        // Feature flags
        $r->get('/api/feature-flags/effective', [FeatureFlagController::class, 'effective'], 'flags.effective');
        $r->get('/api/feature-flags', [FeatureFlagController::class, 'index'], 'flags.list', ['perm:view_feature_flags']);
        $r->put('/api/feature-flags/{key:[a-z0-9_.-]+}', [FeatureFlagController::class, 'update'], 'flags.update', ['csrf', 'perm:manage_feature_flags']);
    });
};
