<?php

namespace Support;

use Support\UserAuth;

class UserContext
{
    private const SESSION_KEY = 'learner_session_id';

    public static function id(): string
    {
        if (UserAuth::check()) {
            $userId = UserAuth::id();
            return 'user_' . $userId;
        }

        if (! isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(16));
        }

        return $_SESSION[self::SESSION_KEY];
    }
}
