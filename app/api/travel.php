<?php

/**
 * Travel API
 *
 * Regions with notes, places (tags, photo), and comments/ratings. Order of checks: method (405), CSRF (403),
 * permission (403), input (400 wrong type, 422 rule), then rows (404). Reads take GET, changes POST.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input_schemas/travel.php';
require_once __DIR__ . '/../includes/travel_service.php';

require_login();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

/** The actions that only read; every other action changes data. */
const TRAVEL_READ_ACTIONS = ['regions', 'places', 'comments'];

/**
 * Method and CSRF guards of an action.
 *
 * @throws ClientErrorException|ForbiddenException
 */
function travel_guard(string $action): void
{
    $reads = in_array($action, TRAVEL_READ_ACTIONS, true);
    $allowed = $reads ? ['GET', 'HEAD'] : ['POST'];
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        throw new ClientErrorException('Method not allowed', 405);
    }
    if (!$reads && !csrf_verify_api()) {
        throw new ForbiddenException('Invalid CSRF token');
    }
}

/**
 * Answer a read action.
 *
 * @throws NotFoundException
 */
function travel_handle_read(string $action, PDO $pdo, TravelActor $actor): void
{
    if ($action === 'regions') {
        api_success(['regions' => travel_regions_list($pdo)]);
    }
    $input = travel_input($action);
    if ($action === 'places') {
        api_success(travel_places_list($pdo, $input, $actor));
    }
    api_success(['comments' => travel_comments_list($pdo, $input['place_id'], $actor)]);
}

/**
 * Answer a place action (saving takes the optional photo upload).
 *
 * @throws ClientErrorException
 */
function travel_handle_place(string $action, PDO $pdo, TravelActor $actor): void
{
    if ($action === 'delete_place') {
        if (!$actor->isAdmin) {
            throw new ForbiddenException('Only admins can delete places');
        }
        $placeId = travel_input($action)['id'];
        travel_delete_place($pdo, $placeId);
        api_success(['id' => $placeId], 'Place deleted');
    }
    $input = travel_input($action);
    $upload = travel_uploaded_photo();
    $saved = travel_save_place($pdo, $actor, $input, $upload);
    api_success(['id' => $saved['id']], $saved['created'] ? 'Place created' : 'Place updated');
}

/**
 * The photo upload of this request, null when none was sent.
 *
 * @return array<string, mixed>|null
 * @throws BadRequestException
 */
function travel_uploaded_photo(): ?array
{
    $file = $_FILES['photo'] ?? null;
    if (!is_array($file) || ($file['name'] ?? '') === '') {
        return null;
    }
    if (is_array($file['name'])) {
        throw new BadRequestException('Send one photo.');
    }
    return $file;
}

/**
 * Answer a region or comment change.
 *
 * @throws ClientErrorException
 */
function travel_handle_change(string $action, PDO $pdo, TravelActor $actor): void
{
    if ($action === 'save_region') {
        require_permission('admin.regions.manage');
        $input = travel_input($action);
        travel_save_region($pdo, $input['id'], $input['notes'] ?? '', $actor->userId);
        api_success(['id' => $input['id']], 'Region notes updated');
    }
    if ($action === 'save_comment') {
        $saved = travel_save_comment($pdo, $actor, travel_input($action));
        api_success(['id' => $saved['id']], $saved['created'] ? 'Comment added' : 'Comment updated');
    }
    if (!$actor->isAdmin) {
        throw new ForbiddenException('Only admins can delete comments');
    }
    $commentId = travel_input($action)['id'];
    travel_delete_comment($pdo, $commentId);
    api_success(['id' => $commentId], 'Comment deleted');
}

$action = req_action();
$userId = (int)($_SESSION['uid'] ?? 0);
try {
    $actor = new TravelActor($userId, has_permission('admin.access'));
    switch ($action) {
        case 'regions':
        case 'places':
        case 'comments':
            travel_guard($action);
            travel_handle_read($action, db(), $actor);
            break;
        case 'save_place':
        case 'delete_place':
            travel_guard($action);
            travel_handle_place($action, db(), $actor);
            break;
        case 'save_region':
        case 'save_comment':
        case 'delete_comment':
            travel_guard($action);
            travel_handle_change($action, db(), $actor);
            break;
        default:
            throw new BadRequestException('Invalid action');
    }
} catch (Throwable $e) {
    api_fail($e, 'travel_api_error', ['action' => $action, 'user_id' => $userId], 'An error occurred. Please try again.');
}
