<?php

/**
 * Reads and the one write behind api/purchasing_emails.php. Nothing here sends mail: the rows are the
 * templates the cart page turns into a mailto link.
 */

declare(strict_types=1);

/**
 * The active purchasing emails in display order.
 *
 * @return list<array<string, mixed>>
 */
function purchasing_emails_active(PDO $pdo): array
{
    $stmt = $pdo->prepare('
        SELECT id, email, label, is_default
        FROM purchasing_emails
        WHERE is_active = 1
        ORDER BY sort_order ASC, label ASC
    ');
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * The user's stored preference, or null. It is returned as stored (with the type the database driver
 * gives, as before), even if that email is no longer active: reading never repairs a preference.
 */
function purchasing_email_preferred_id(PDO $pdo, int $userId): int|string|null
{
    $stmt = $pdo->prepare('SELECT preferred_purchasing_email_id FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetchColumn() ?: null;
}

/**
 * One active purchasing email with its templates.
 *
 * @return array<string, mixed>
 */
function purchasing_email_template(PDO $pdo, int $emailId): array
{
    $stmt = $pdo->prepare('
        SELECT email, label, cc_addresses, subject_template, body_template
        FROM purchasing_emails
        WHERE id = ? AND is_active = 1
    ');
    $stmt->execute([$emailId]);
    $template = $stmt->fetch();
    if (!$template) {
        throw new NotFoundException('Purchasing email not found');
    }
    return $template;
}

function purchasing_email_is_active(PDO $pdo, int $emailId): bool
{
    $stmt = $pdo->prepare('SELECT id FROM purchasing_emails WHERE id = ? AND is_active = 1');
    $stmt->execute([$emailId]);
    return $stmt->fetch() !== false;
}

/**
 * Store the user's own preference; 0 clears it. A positive id must name an active email, and is checked
 * before anything is written. Saving the value already stored succeeds.
 */
function purchasing_email_save_preference(PDO $pdo, int $userId, int $emailId): void
{
    if ($emailId > 0 && !purchasing_email_is_active($pdo, $emailId)) {
        throw new UnprocessableException('Invalid purchasing email selected');
    }
    $stmt = $pdo->prepare('UPDATE users SET preferred_purchasing_email_id = ? WHERE id = ?');
    $stmt->execute([$emailId > 0 ? $emailId : null, $userId]);
}
