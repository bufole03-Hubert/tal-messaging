<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AuthService;

final class AuthController extends Controller
{
    /** @param array<string,string> $params */
    public function loginPage(Request $request, array $params): Response
    {
        return $this->view('auth/login', ['title' => 'Connexion'], 'layouts/auth');
    }

    /** @param array<string,string> $params */
    public function login(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, ['email' => 'required|email|max:190', 'password' => 'required|string|max:200']);
        return Response::data(AuthService::attempt($d['email'], $d['password']));
    }

    /** @param array<string,string> $params */
    public function verifyLogin2fa(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, ['code' => 'required|string|max:20']);
        return Response::data(AuthService::verifyLogin2fa($d['code']));
    }

    /** @param array<string,string> $params */
    public function me(Request $request, array $params): Response
    {
        return Response::data($this->publicUser($request->user()));
    }

    /** @param array<string,string> $params */
    public function logout(Request $request, array $params): Response
    {
        AuthService::logout();
        return Response::data(['redirect' => '/login']);
    }
}
