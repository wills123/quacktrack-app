<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/snipe_api_errors.php';
require_once __DIR__ . '/snipe_review_service.php';

/**
 * api/snipe-asset-review.php. Order of checks: the action's method (405 with Allow), dismiss needs assets.manage (403),
 * CSRF on the changes (403), input (400 wrong type, 422 rule), Snipe-IT switched on (503; dismiss does not need it),
 * then the review: missing (404), the person may not receive its order (403), the item is not a tracked asset (422), the
 * order is cancelled or has no free unit, or the review is resolved (409). Then Snipe-IT: no asset for the identifier
 * (404), one already has the new serial or tag, or is assigned elsewhere or to another order (409), no personal sign-in
 * (401 with snipe_auth_required), a refused creation (422 with Snipe-IT's message), an unusable answer (502). An asset
 * created but not linked locally is a 500 that says so; repeating the request links it.
 */

/** Refuse the wrong method, a dismissal without assets.manage and a change without its CSRF token. */
function snipe_review_guard(string $action): void
{
    $method = SNIPE_REVIEW_METHODS[$action];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        throw new ClientErrorException('Method not allowed', 405);
    }
    if ($action === 'dismiss' && !has_permission('assets.manage')) {
        throw new ForbiddenException('Access denied. Insufficient permissions.');
    }
    if ($method === 'POST' && !csrf_verify_api()) {
        throw new ForbiddenException('Invalid CSRF token');
    }
}

/** Answer one review action ($action is a key of SNIPE_REVIEW_METHODS); every answer ends the request. */
function snipe_review_dispatch(string $action, int $actorId): void
{
    try {
        snipe_review_guard($action);
        $answer = match ($action) {
            'options' => snipe_review_answer_options($actorId, snipe_review_options_input()),
            'create_asset' => snipe_review_answer_create($actorId, snipe_review_create_input()),
            'link_existing' => snipe_review_answer_link($actorId, snipe_review_link_input()),
            default => snipe_review_answer_dismiss($actorId, snipe_review_dismiss_input()),
        };
        echo json_encode(['success' => true] + $answer);
    } catch (SnipeReviewUnlinkedException $e) {
        // Logged for reconciliation where it happened.
        api_error($e->getMessage(), 500);
    } catch (Throwable $e) {
        snipe_api_fail($e, 'snipe_asset_review_provider_error', 'snipe_asset_review_api', ['action' => $action, 'user_id' => $actorId], 'Unable to complete asset review.');
    }
}

/**
 * @param array{review_id: int} $in
 * @return array<string, mixed>
 */
function snipe_review_answer_options(int $actorId, array $in): array
{
    return snipe_review_options(db(), snipe_catalog_client($actorId), snipe_review_viewer($actorId), $in['review_id']);
}

/**
 * @param array{review_id: int, name: string, asset_tag: string, serial: string, model_id: int, status_id: int, review_note: string} $in
 * @return array<string, mixed>
 */
function snipe_review_answer_create(int $actorId, array $in): array
{
    $pdo = db();
    $client = snipe_catalog_client($actorId);
    $viewer = snipe_review_viewer($actorId);
    return snipe_review_locked($pdo, $in['review_id'], static fn(): array => snipe_review_create($pdo, $client, $viewer, $in));
}

/**
 * @param array{review_id: int, identifier: string} $in
 * @return array<string, mixed>
 */
function snipe_review_answer_link(int $actorId, array $in): array
{
    $pdo = db();
    $client = snipe_catalog_client($actorId);
    $viewer = snipe_review_viewer($actorId);
    return snipe_review_locked($pdo, $in['review_id'], static fn(): array => snipe_review_link($pdo, $client, $viewer, $in));
}

/**
 * Dismissing needs no Snipe-IT.
 *
 * @param array{review_id: int, review_note: string} $in
 * @return array<string, mixed>
 */
function snipe_review_answer_dismiss(int $actorId, array $in): array
{
    $pdo = db();
    snipe_review_locked($pdo, $in['review_id'], static fn() => snipe_review_dismiss($pdo, $actorId, $in));
    return ['message' => 'Review item dismissed.'];
}
