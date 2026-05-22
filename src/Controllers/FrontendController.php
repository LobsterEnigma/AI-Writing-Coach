<?php

namespace Controllers;

use Http\Response;
use Support\OpenSourceAccess;
use Support\View;

class FrontendController
{
    public static function home(array $config, ?string $error = null): void
    {
        if (! OpenSourceAccess::isGranted($config)) {
            View::render('frontend/access', [
                'error' => $error,
            ]);
            return;
        }

        View::render('frontend/home');
    }

    public static function unlock(array $config): void
    {
        if (! OpenSourceAccess::isEnabled($config)) {
            Response::redirect('/');
            return;
        }

        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $ok = OpenSourceAccess::attempt($password, $config);
        if ($ok) {
            Response::redirect('/');
            return;
        }

        self::home($config, 'Invalid password.');
    }
}
