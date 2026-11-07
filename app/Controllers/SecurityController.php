<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Support\Crypto;
use App\Support\Logger;
use App\Support\Str;
use App\Support\Totp;

/** Sécurité du compte connecté : mot de passe et 2FA TOTP. */
final class SecurityController extends Controller
{
    /** @param array<string,string> $params */
    public function page(Request $request, array $params): Response
    {
        return $this->view('security/index', ['title' => 'Sécurité du compte', 'page' => 'security', 'user' => $request->user(), 'dashboards' => $this->dashboards($request)]);
    }

    /** @param array<string,string> $params */
    public function changePassword(Request $request, array $params): Response
    {
        $user = $request->user();
        $d = Validator::validate($request->body, ['current_password' => 'required|string|max:200', 'new_password' => 'required|string|password']);
        $hash = (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        if (!password_verify($d['current_password'], $hash)) {
            AuditService::log('security.password_failed', 'user', $user['id'], [], $user['id'], $user['organization_id']);
            throw ApiException::forbidden('Mot de passe actuel incorrect.', 'INVALID_CREDENTIALS');
        }
        if ($d['current_password'] === $d['new_password']) {
            throw ApiException::validation(['new_password' => ['Doit être différent de l’ancien.']]);
        }
        Database::execute('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [Str::hashPassword($d['new_password']), $user['id']]);
        Session::destroyForUser((int) $user['id'], Session::currentHash()); // révoque les autres appareils
        AuthService::forgetCache((int) $user['id']);
        $request->attributes['audit_resource_type'] = 'user';
        $request->attributes['audit_resource_id'] = $user['id'];
        return Response::data(['changed' => true]);
    }

    /** @param array<string,string> $params */
    public function setup2fa(Request $request, array $params): Response
    {
        $user = $request->user();
        if ($user['totp_enabled']) {
            throw ApiException::conflict('La double authentification est déjà active.', 'TWO_FACTOR_ALREADY_ENABLED');
        }
        $secret = Totp::generateSecret();
        // Le secret affiché (QR + clé manuelle) est exactement celui conservé en base : une seule source de vérité.
        Database::execute(
            'UPDATE users SET totp_pending_secret = ?, totp_pending_at = UTC_TIMESTAMP() WHERE id = ?',
            [Crypto::encrypt($secret), $user['id']]
        );
        return Response::data([
            'secret' => $secret,
            'otpauth_uri' => Totp::uri((string) Config::get('app.name'), $user['email'], $secret),
            'server_time' => gmdate('H:i:s') . ' UTC',
        ]);
    }

    /** @param array<string,string> $params */
    public function enable2fa(Request $request, array $params): Response
    {
        $user = $request->user();
        $d = Validator::validate($request->body, ['code' => 'required|string|min:6|max:9']);
        $row = Database::first('SELECT totp_pending_secret, totp_pending_at FROM users WHERE id = ?', [$user['id']]);
        $secret = ($row && $row['totp_pending_secret'] !== null) ? Crypto::decrypt((string) $row['totp_pending_secret']) : null;
        if ($secret === null || strtotime((string) $row['totp_pending_at'] . ' UTC') < time() - 3600) {
            throw ApiException::badRequest('Configuration 2FA expirée ou absente : cliquez sur « Configurer la 2FA » pour générer un nouveau QR code.');
        }
        $step = Totp::verify($secret, $d['code'], 0, AuthService::totpWindow());
        if ($step === null) {
            $offset = Totp::clockOffset($secret, $d['code']);
            Logger::info('2FA enable : code refusé', ['user_id' => $user['id'], 'clock_offset_seconds' => $offset, 'server_time_utc' => gmdate('c')]);
            if ($offset !== null) {
                $min = (int) round(abs($offset) / 60);
                $msg = 'Le code est correct mais l’heure de votre téléphone diffère de celle du serveur d’environ '
                    . ($min >= 1 ? $min . ' min' : abs($offset) . ' s')
                    . '. Activez l’heure automatique (Google Authenticator : Paramètres → Correction de l’heure pour les codes → Synchroniser maintenant) et réessayez.';
                throw new ApiException('TWO_FACTOR_CLOCK_SKEW', $msg, 422);
            }
            $msg = 'Code incorrect. Vérifiez que le code correspond bien au dernier QR code affiché (« ' . (string) Config::get('app.name') . ' ») et qu’il n’a pas expiré. Heure du serveur : ' . gmdate('H:i:s') . ' UTC.';
            throw new ApiException('TWO_FACTOR_INVALID', $msg, 422);
        }
        Database::execute(
            'UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_step = ?, totp_pending_secret = NULL, totp_pending_at = NULL WHERE id = ?',
            [Crypto::encrypt($secret), $step, $user['id']]
        );
        Session::regenerate();
        AuthService::forgetCache((int) $user['id']);
        $codes = AuthService::generateRecoveryCodes((int) $user['id']);
        $request->attributes['audit_resource_type'] = 'user';
        $request->attributes['audit_resource_id'] = $user['id'];
        return Response::data(['enabled' => true, 'recovery_codes' => $codes, 'csrf_token' => Session::csrfToken()]);
    }

    /** @param array<string,string> $params */
    public function disable2fa(Request $request, array $params): Response
    {
        $user = $request->user();
        if ($user['role_scope'] === 'internal' && (bool) Config::get('app.security.enforce_internal_2fa', true)) {
            throw ApiException::forbidden('La 2FA est obligatoire pour les comptes internes.', 'TWO_FACTOR_REQUIRED');
        }
        $d = Validator::validate($request->body, ['password' => 'required|string|max:200', 'code' => 'required|string|size:6']);
        $row = Database::first('SELECT password_hash, totp_secret, totp_last_step FROM users WHERE id = ?', [$user['id']]);
        $secret = $row ? Crypto::decrypt((string) $row['totp_secret']) : null;
        if ($row === null || !password_verify($d['password'], $row['password_hash']) || $secret === null
            || Totp::verify($secret, $d['code'], (int) $row['totp_last_step'], AuthService::totpWindow()) === null) {
            throw ApiException::forbidden('Mot de passe ou code invalide.', 'INVALID_CREDENTIALS');
        }
        Database::execute('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0 WHERE id = ?', [$user['id']]);
        Database::execute('DELETE FROM totp_recovery_codes WHERE user_id = ?', [$user['id']]);
        AuthService::forgetCache((int) $user['id']);
        $request->attributes['audit_resource_type'] = 'user';
        $request->attributes['audit_resource_id'] = $user['id'];
        return Response::data(['enabled' => false]);
    }
}
