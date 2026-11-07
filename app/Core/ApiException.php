<?php
declare(strict_types=1);

namespace App\Core;

/** Erreur typée : code applicatif + message + statut HTTP. */
final class ApiException extends \RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $m = 'Requête invalide.'): self
    {
        return new self('BAD_REQUEST', $m, 400);
    }

    public static function unauthorized(string $m = 'Authentification requise.', string $code = 'AUTH_REQUIRED'): self
    {
        return new self($code, $m, 401);
    }

    public static function forbidden(string $m = 'Accès refusé.', string $code = 'FORBIDDEN'): self
    {
        return new self($code, $m, 403);
    }

    public static function notFound(string $m = 'Ressource introuvable.'): self
    {
        return new self('NOT_FOUND', $m, 404);
    }

    public static function conflict(string $m, string $code = 'CONFLICT'): self
    {
        return new self($code, $m, 409);
    }

    /** @param array<string,array<int,string>> $errors */
    public static function validation(array $errors): self
    {
        return new self('VALIDATION_FAILED', 'Les données fournies sont invalides.', 422, ['fields' => $errors]);
    }

    public static function tooMany(string $m = 'Trop de tentatives. Réessayez plus tard.'): self
    {
        return new self('RATE_LIMITED', $m, 429);
    }
}
