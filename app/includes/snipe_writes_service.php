<?php

/**
 * Asset writes in Snipe-IT for api/snipe-it-assets.php: checkout, checkin and update. Every write uses the caller's own
 * personal token (Snipe-IT's log then names the person), never the shared service key. Snipe-IT's answer decides the
 * outcome: a token it no longer accepts asks the person to reconnect, a refusal carries Snipe-IT's message, anything
 * unusable is a provider failure. After a checkout or checkin the asset's status label follows (deployed or
 * deployable) when Snipe-IT has one of that type; the write stands when only that follow-up fails.
 */

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/SnipeItClient.php';
require_once __DIR__ . '/SnipeTokenManager.php';
require_once __DIR__ . '/snipe_catalog_service.php';

/** The caller has no usable personal Snipe-IT token: they must reconnect their account. */
final class SnipeReconnectException extends RuntimeException
{
}

/** One person's writes: their client, their id, and the token the client sends (to forget it if Snipe-IT rejects it). */
final class SnipeWriteSession
{
    public function __construct(public readonly SnipeItClient $client, public readonly int $userId, public readonly string $token)
    {
    }
}

/** The longest Snipe-IT message passed on to the person. */
const SNIPE_WRITES_MESSAGE_MAX = 500;

/**
 * The caller's writes, with their own token.
 *
 * @throws SnipeReconnectException
 */
function snipe_writes_client(int $userId): SnipeWriteSession
{
    $token = SnipeTokenManager::getValid($userId);
    if ($token === null) {
        throw new SnipeReconnectException('Your Snipe-IT session has expired. Please reconnect your account.');
    }
    return new SnipeWriteSession(SnipeItClient::forToken($token, $userId), $userId, $token);
}

/**
 * Snipe-IT's messages as one line of text (they come as a string, a list, or field => list), at most 500 characters.
 */
function snipe_writes_message(mixed $messages, string $fallback): string
{
    $parts = [];
    $list = is_array($messages) ? $messages : [$messages];
    array_walk_recursive($list, static function (mixed $value) use (&$parts): void {
        if (is_string($value) && trim($value) !== '') {
            $parts[] = trim($value);
        }
    });
    return $parts === [] ? $fallback : mb_substr(implode(', ', $parts), 0, SNIPE_WRITES_MESSAGE_MAX);
}

/**
 * Send one write and return Snipe-IT's answer when it accepted it.
 *
 * @param array<string, mixed> $body
 * @return array<mixed>
 * @throws ClientErrorException|SnipeProviderException|SnipeReconnectException
 */
function snipe_writes_send(SnipeWriteSession $writer, string $method, string $endpoint, array $body): array
{
    $answer = $writer->client->request($method, $endpoint, $body);
    $status = $answer['status'];
    $data = $answer['data'];
    if ($status === 401) {
        // Snipe-IT rejects this token: forget it (only if it is still the stored one, so a token another request has
        // refreshed meanwhile survives), so the next request asks to reconnect straight away.
        SnipeTokenManager::revokeIfCurrent($writer->userId, $writer->token);
        throw new SnipeReconnectException('Snipe-IT no longer accepts your sign-in. Please reconnect your account.');
    }
    snipe_writes_refusal($status, $data);
    if ($status < 200 || $status >= 300 || !is_array($data) || array_is_list($data)) {
        throw new SnipeProviderException('Snipe-IT did not answer the ' . $method . ' ' . $endpoint . ' usably (HTTP ' . $status . ').');
    }
    snipe_writes_confirmed($data);
    return $data;
}

/**
 * Accept a 2xx answer only when Snipe-IT says the write succeeded.
 *
 * @param array<mixed> $data
 * @throws ConflictException|SnipeProviderException
 */
function snipe_writes_confirmed(array $data): void
{
    if (($data['status'] ?? null) === 'error') {
        throw new ConflictException(snipe_writes_message($data['messages'] ?? null, 'Snipe-IT refused the change.'));
    }
    if (!is_string($data['status'] ?? null) || strtolower($data['status']) !== 'success') {
        throw new SnipeProviderException('Snipe-IT did not confirm the write.');
    }
}

/**
 * Turn a Snipe-IT 4xx answer into the matching refusal.
 *
 * @throws ClientErrorException
 */
function snipe_writes_refusal(int $status, mixed $data): void
{
    $message = snipe_writes_message(is_array($data) ? ($data['messages'] ?? $data['message'] ?? null) : null, 'Snipe-IT refused the change.');
    match (true) {
        $status === 403 => throw new ForbiddenException('Snipe-IT does not allow your account to make this change.'),
        $status === 404 => throw new NotFoundException('Snipe-IT has no such asset.'),
        $status === 422 => throw new UnprocessableException($message),
        $status === 429 => throw new ClientErrorException('Snipe-IT is busy. Try again in a moment.', 429),
        $status >= 400 && $status < 500 => throw new ConflictException($message),
        default => null,
    };
}

/**
 * The first status label of $type ('deployed', 'deployable', ...), read from the shared cached list; null when there is
 * none or the list cannot be read.
 */
function snipe_writes_status_id(string $type): ?int
{
    $labels = SnipeItClient::getInstance()->referenceList('statuslabels');
    foreach (is_array($labels['rows'] ?? null) ? $labels['rows'] : [] as $label) {
        if (snipe_writes_label_type($label) === $type && is_int($label['id'] ?? null) && $label['id'] > 0) {
            return $label['id'];
        }
    }
    return null;
}

/** The type of a status label row ('' when it has none). Snipe-IT reports it as "type"; the older keys stay as fallbacks. */
function snipe_writes_label_type(mixed $label): string
{
    return is_array($label) ? strtolower((string)($label['type'] ?? $label['status_type'] ?? $label['status_meta'] ?? '')) : '';
}

/**
 * Set the asset's status label to the first one of $type; whether that happened. The checkout or checkin before it
 * stands either way, so a failure here is logged, not raised, unless Snipe-IT no longer accepts the person's sign-in:
 * that still asks them to reconnect, saying the change itself went through.
 *
 * @throws SnipeReconnectException
 */
function snipe_writes_follow_status(SnipeWriteSession $writer, int $assetId, string $type): bool
{
    $statusId = snipe_writes_status_id($type);
    if ($statusId === null) {
        return false;
    }
    try {
        snipe_writes_send($writer, 'PATCH', '/hardware/' . $assetId, ['status_id' => $statusId]);
        return true;
    } catch (SnipeReconnectException $e) {
        $writer->client->clearCache();
        throw new SnipeReconnectException('The change was saved in Snipe-IT, but the asset status was not updated. ' . $e->getMessage(), 0, $e);
    } catch (Throwable $e) {
        Logger::warning('snipe_status_follow_up_failed', $e->getMessage(), ['asset_id' => $assetId, 'type' => $type, 'user_id' => $writer->userId]);
        return false;
    }
}

/**
 * Check an asset out to a Snipe-IT user, then mark it deployed.
 *
 * @param array<string, mixed> $in parsed checkout input
 * @return array<string, mixed>
 */
function snipe_writes_checkout(SnipeWriteSession $writer, array $in): array
{
    $body = ['checkout_to_type' => 'user', 'assigned_user' => $in['assigned_to']] + (($in['note'] ?? '') !== '' ? ['note' => $in['note']] : []);
    snipe_writes_send($writer, 'POST', '/hardware/' . $in['asset_id'] . '/checkout', $body);
    $statusUpdated = snipe_writes_follow_status($writer, $in['asset_id'], 'deployed');
    $writer->client->clearCache();
    return ['asset_id' => $in['asset_id'], 'assigned_to' => $in['assigned_to'], 'note' => $in['note'] ?? '', 'status_updated' => $statusUpdated];
}

/**
 * Check an asset back in, then mark it deployable.
 *
 * @param array<string, mixed> $in parsed checkin input
 * @return array<string, mixed>
 */
function snipe_writes_checkin(SnipeWriteSession $writer, array $in): array
{
    $body = ($in['note'] ?? '') !== '' ? ['note' => $in['note']] : [];
    snipe_writes_send($writer, 'POST', '/hardware/' . $in['asset_id'] . '/checkin', $body);
    $statusUpdated = snipe_writes_follow_status($writer, $in['asset_id'], 'deployable');
    $writer->client->clearCache();
    return ['asset_id' => $in['asset_id'], 'note' => $in['note'] ?? '', 'status_updated' => $statusUpdated];
}

/**
 * Change the given fields of an asset (an empty note clears it).
 *
 * @param array<string, mixed> $in parsed update input
 * @return array{asset_id: int, updated_fields: list<string>}
 */
function snipe_writes_update(SnipeWriteSession $writer, array $in): array
{
    $fields = array_intersect_key($in, array_flip(SNIPE_WRITES_UPDATE_FIELDS));
    snipe_writes_send($writer, 'PATCH', '/hardware/' . $in['asset_id'], $fields);
    $writer->client->clearCache();
    return ['asset_id' => $in['asset_id'], 'updated_fields' => array_keys($fields)];
}
