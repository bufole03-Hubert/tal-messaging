<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ThemeService;

/** Thème : feuille de style publique + page et API réservées au super admin (permission manage_appearance). */
final class ThemeController extends Controller
{
    /** @param array<string,string> $params */
    public function css(Request $request, array $params): Response
    {
        return new Response(200, ThemeService::css(), ['Content-Type' => 'text/css; charset=utf-8']);
    }

    /** @param array<string,string> $params */
    public function page(Request $request, array $params): Response
    {
        return $this->view('appearance/index', [
            'title' => 'Apparence',
            'page' => 'appearance',
            'user' => $request->user(),
            'dashboards' => $this->dashboards($request),
            'tokens' => ThemeService::TOKENS,
            'defaults' => ThemeService::DEFAULTS,
            'theme' => ThemeService::settings(),
        ]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        return Response::data(ThemeService::settings() + ['defaults' => ThemeService::DEFAULTS]);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $request->attributes['audit_resource_type'] = 'theme';
        return Response::data(ThemeService::save($request->body, (int) $request->user()['id']));
    }

    /** @param array<string,string> $params */
    public function reset(Request $request, array $params): Response
    {
        ThemeService::reset();
        $request->attributes['audit_resource_type'] = 'theme';
        return Response::data(ThemeService::settings());
    }
}
