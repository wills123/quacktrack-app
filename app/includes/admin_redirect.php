<?php

declare(strict_types=1);

/**
 * Where an admin form should send the browser after it has been handled.
 *
 * The admin dashboard embeds the same forms as the standalone admin pages
 * (/admin/users, /admin/stores, ...). Each form carries a hidden `redirect_to`
 * naming where it was submitted from; the controller should go back there, and
 * only fall back to its own standalone page when none was sent.
 *
 * The value is user input, so only a plain on-site admin path is accepted:
 *   /admin, /admin.php, /admin/<page>, /admin/<page>.php, each optionally with
 *   a simple query string. Anything else (other hosts, "//host", backslashes,
 *   control characters, other paths) is ignored.
 */
function admin_return_url(string $default): string
{
    $target = $_POST['redirect_to'] ?? '';
    if (!is_string($target) || $target === '' || strlen($target) > 200) {
        return $default;
    }

    $pattern = '#^/admin(?:\.php|/[a-z_-]+(?:\.php)?)?(?:\?[A-Za-z0-9_=&%.\-]*)?$#';
    return preg_match($pattern, $target) === 1 ? $target : $default;
}
