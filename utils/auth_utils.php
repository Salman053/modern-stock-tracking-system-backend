<?php

const SESSION_TIMEOUT = 1800; // 30 minutes in seconds (1800)
const SESSION_LAST_ACTIVITY = 'last_activity';
const SESSION_USER_AGENT = 'user_agent';

function is_user_authenticated()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 2. Check for Basic Login Status
    if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true || !isset($_SESSION['user_id'])) {
        // Session is invalid or not logged in.
        return destroy_session_and_return_false();
    }

    if (isset($_SESSION[SESSION_LAST_ACTIVITY]) && (time() - $_SESSION[SESSION_LAST_ACTIVITY]) > SESSION_TIMEOUT) {
        // Session has expired due to inactivity
        return destroy_session_and_return_false();
    }


    $current_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (isset($_SESSION[SESSION_USER_AGENT]) && $_SESSION[SESSION_USER_AGENT] !== $current_user_agent) {
        return destroy_session_and_return_false();
    }

    $_SESSION[SESSION_LAST_ACTIVITY] = time();

    if (!isset($_SESSION[SESSION_USER_AGENT])) {
        $_SESSION[SESSION_USER_AGENT] = $current_user_agent;
    }

    session_write_close();

    // Return essential session data
    return [
        'authenticated' => true,
        'user_id' => $_SESSION['user_id'],
        'role' => $_SESSION['role']
    ];
}


function destroy_session_and_return_false()
{

    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        session_start();
    }

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    return ['authenticated' => false];
}
