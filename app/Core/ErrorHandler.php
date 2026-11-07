<?php
declare(strict_types=1);

namespace App\Core;

use App\Support\Logger;

final class ErrorHandler
{
    public static function render(\Throwable $e, Request $request): Response
    {
        if ($e instanceof ApiException) {
            $status = $e->status;
            $code = $e->errorCode;
            $message = $e->getMessage();
            $details = $e->details;
        } else {
            Logger::error('Exception non gérée : ' . $e->getMessage(), [
                'class' => get_class($e),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
                'path' => $request->path,
            ]);
            $status = 500;
            $code = 'INTERNAL_ERROR';
            $message = 'Une erreur interne est survenue.';
            $details = [];
            if (Config::get('app.debug')) {
                $details = ['debug' => $e->getMessage()];
            }
        }

        if ($request->isApi() || str_contains($request->header('accept') ?? '', 'application/json')) {
            $error = ['code' => $code, 'message' => $message];
            if ($details) {
                $error['details'] = $details;
            }
            return Response::json(['error' => $error], $status);
        }

        return Response::html(View::render('errors/error', [
            'status' => $status,
            'message' => $message,
            'title' => 'Erreur ' . $status,
        ], 'layouts/auth'), $status);
    }
}
