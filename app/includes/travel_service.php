<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/travel_photo.php';

/**
 * Travel directory: regions with notes, places with tags and an optional photo, and comments with ratings.
 *
 * A change to a place runs in one transaction; the photo file is written first and removed again when the
 * change fails, and a replaced or deleted photo's file is removed after the change is committed.
 */

/** Who is calling: the user id and whether the user is an administrator. */
final class TravelActor
{
    public function __construct(public readonly int $userId, public readonly bool $isAdmin)
    {
    }

    /** Whether the caller may change what $creatorId created. */
    public function mayChange(int $creatorId): bool
    {
        return $this->isAdmin || ($creatorId > 0 && $creatorId === $this->userId);
    }
}

/**
 * Run $work in a transaction; any failure rolls it back.
 *
 * @template T
 * @param callable(): T $work
 * @return T
 */
function travel_transaction(PDO $pdo, callable $work): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $work();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return list<array<string, mixed>> */
function travel_regions_list(PDO $pdo): array
{
    $stmt = $pdo->query('
        SELECT r.id, r.name, n.notes,
               (SELECT COUNT(*) FROM travel_places p WHERE p.region_id = r.id) AS place_count
        FROM regions r
        LEFT JOIN travel_region_notes n ON n.region_id = r.id
        ORDER BY r.name ASC
    ');
    return $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Set a region's notes (blank removes them).
 *
 * @throws NotFoundException
 */
function travel_save_region(PDO $pdo, int $regionId, string $notes, int $userId): void
{
    travel_transaction($pdo, static function () use ($pdo, $regionId, $notes, $userId): void {
        $stmt = $pdo->prepare('SELECT id FROM regions WHERE id = ? FOR UPDATE');
        $stmt->execute([$regionId]);
        if (!$stmt->fetchColumn()) {
            throw new NotFoundException('Region not found');
        }
        if (trim($notes) === '') {
            $pdo->prepare('DELETE FROM travel_region_notes WHERE region_id = ?')->execute([$regionId]);
            return;
        }
        $pdo->prepare('
            INSERT INTO travel_region_notes (region_id, notes, created_by, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE notes = VALUES(notes), updated_by = VALUES(updated_by)
        ')->execute([$regionId, trim($notes), $userId, $userId]);
    });
}

/** A text for a LIKE pattern with its wildcards taken literally. */
function travel_like(string $text): string
{
    return '%' . addcslashes($text, '\\%_') . '%';
}

/**
 * The parameters of the place filters, each filter as a pair (the value, then the same value again for the
 * comparison) so the query text stays fixed. A filter that was not sent matches every place.
 *
 * @param array<string, mixed> $filters region_id, type, tag, q, visited ('all', '0' or '1')
 * @return list<int|string>
 */
function travel_place_filter(array $filters): array
{
    $region = (int)($filters['region_id'] ?? 0);
    $type = (string)($filters['type'] ?? '');
    $visited = ($filters['visited'] ?? 'all') === 'all' ? -1 : (int)$filters['visited'];
    $tag = (string)($filters['tag'] ?? '');
    $search = (string)($filters['q'] ?? '');
    $like = travel_like($search);
    return [$region, $region, $type, $type, $visited, $visited, $tag, $tag, $search, $like, $like, $like];
}

/**
 * The places that match the filters, with their rating summary, tags and what the caller may do, and the pool of
 * all tags in use.
 *
 * @param array<string, mixed> $filters
 * @return array{places: list<array<string, mixed>>, tag_pool: list<string>}
 */
function travel_places_list(PDO $pdo, array $filters, TravelActor $actor): array
{
    $stmt = $pdo->prepare("
        SELECT p.id, p.region_id, p.name, p.type, p.description, p.safety_level, p.visited, p.photo_path, p.added_by,
               p.created_at, p.updated_at, r.name AS region_name, n.notes AS region_notes,
               u.email AS user_email, u.first_name, u.last_name, u.username,
               COALESCE(cs.avg_rating, 0) AS avg_rating, COALESCE(cs.rating_count, 0) AS rating_count,
               COALESCE(cs.comment_count, 0) AS comment_count, ts.tags
        FROM travel_places p
        INNER JOIN regions r ON r.id = p.region_id
        LEFT JOIN travel_region_notes n ON n.region_id = r.id
        LEFT JOIN users u ON u.id = p.added_by
        LEFT JOIN (
            SELECT place_id, AVG(rating) AS avg_rating,
                   SUM(CASE WHEN rating IS NOT NULL THEN 1 ELSE 0 END) AS rating_count, COUNT(*) AS comment_count
            FROM travel_comments GROUP BY place_id
        ) cs ON cs.place_id = p.id
        LEFT JOIN (
            SELECT place_id, GROUP_CONCAT(DISTINCT tag ORDER BY tag SEPARATOR ',') AS tags
            FROM travel_place_tags GROUP BY place_id
        ) ts ON ts.place_id = p.id
        WHERE (? = 0 OR p.region_id = ?)
          AND (? = '' OR p.type = ?)
          AND (? = -1 OR p.visited = ?)
          AND (? = '' OR EXISTS (SELECT 1 FROM travel_place_tags t WHERE t.place_id = p.id AND t.tag = ?))
          AND (? = '' OR p.name LIKE ? OR p.description LIKE ? OR r.name LIKE ?)
        ORDER BY r.name ASC, FIELD(p.type, 'recommended', 'avoid'), p.name ASC
    ");
    $stmt->execute(travel_place_filter($filters));
    $places = array_map(
        static fn(array $row): array => travel_place_row($row, $actor),
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
    $pool = $pdo->query('SELECT DISTINCT tag FROM travel_place_tags ORDER BY tag ASC');
    return ['places' => $places, 'tag_pool' => $pool === false ? [] : array_map('strval', $pool->fetchAll(PDO::FETCH_COLUMN))];
}

/**
 * One place row in the response shape.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function travel_place_row(array $row, TravelActor $actor): array
{
    $addedBy = (int)($row['added_by'] ?? 0);
    $tags = empty($row['tags']) ? [] : array_values(array_filter(array_map('trim', explode(',', (string)$row['tags']))));
    return [
        'id' => (int)$row['id'],
        'region_id' => (int)$row['region_id'],
        'region_name' => $row['region_name'],
        'region_notes' => $row['region_notes'],
        'name' => $row['name'],
        'type' => $row['type'],
        'description' => $row['description'],
        'safety_level' => $row['safety_level'],
        'visited' => (int)$row['visited'] === 1,
        'photo_path' => $row['photo_path'],
        'added_by' => $addedBy,
        'added_by_name' => build_user_display_name($row['first_name'] ?? null, $row['last_name'] ?? null, $row['username'] ?? null, $row['user_email'] ?? null),
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'avg_rating' => (float)$row['avg_rating'],
        'rating_count' => (int)$row['rating_count'],
        'comment_count' => (int)$row['comment_count'],
        'tags' => $tags,
        'can_edit' => $actor->mayChange($addedBy),
        'can_delete' => $actor->isAdmin,
    ];
}

/**
 * Create a place, or replace the posted fields of one (id > 0) the caller may edit. Returns the place id and whether
 * it was created.
 *
 * @param array<string, mixed> $data parsed input (id, region_id, name, type, description, safety_level, visited, remove_photo, tags)
 * @param array<string, mixed>|null $file the uploaded photo ($_FILES['photo']) when one was sent
 * @return array{id: int, created: bool}
 * @throws NotFoundException|ForbiddenException|UnprocessableException
 */
function travel_save_place(PDO $pdo, TravelActor $actor, array $data, ?array $file): array
{
    $newPhoto = $file !== null ? travel_store_photo($file) : null;
    try {
        $result = travel_transaction($pdo, static fn(): array => travel_write_place($pdo, $actor, $data, $newPhoto));
    } catch (Throwable $e) {
        travel_remove_photo_file($newPhoto);
        throw $e;
    }
    travel_remove_photo_file($result['replaced_photo']);
    return ['id' => $result['id'], 'created' => $result['created']];
}

/**
 * The place being edited, locked; null for a new place.
 *
 * @return array<string, mixed>|null
 * @throws NotFoundException|ForbiddenException
 */
function travel_lock_place_for_edit(PDO $pdo, TravelActor $actor, int $placeId): ?array
{
    if ($placeId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, added_by, photo_path FROM travel_places WHERE id = ? FOR UPDATE');
    $stmt->execute([$placeId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        throw new NotFoundException('Place not found');
    }
    if (!$actor->isAdmin && (int)($existing['added_by'] ?? 0) !== $actor->userId) {
        throw new ForbiddenException('You can only edit your own places');
    }
    return $existing;
}

/**
 * The columns of a place in the order the INSERT and UPDATE statements take them.
 *
 * @param array<string, mixed> $data
 * @return list<int|string|null>
 */
function travel_place_columns(array $data, ?string $photo, int $userId): array
{
    $type = (string)$data['type'];
    $description = ($data['description'] ?? '') !== '' ? $data['description'] : null;
    return [
        $data['region_id'], $data['name'], $type, $description,
        $type === 'avoid' ? ($data['safety_level'] ?? null) : null, !empty($data['visited']) ? 1 : 0, $photo, $userId,
    ];
}

/**
 * The transactional part of travel_save_place().
 *
 * @param array<string, mixed> $data
 * @return array{id: int, created: bool, replaced_photo: ?string}
 */
function travel_write_place(PDO $pdo, TravelActor $actor, array $data, ?string $newPhoto): array
{
    $region = $pdo->prepare('SELECT id FROM regions WHERE id = ?');
    $region->execute([$data['region_id']]);
    if (!$region->fetchColumn()) {
        throw new NotFoundException('Region not found');
    }
    $placeId = (int)($data['id'] ?? 0);
    $existing = travel_lock_place_for_edit($pdo, $actor, $placeId);
    $oldPhoto = $existing['photo_path'] ?? null;
    $photo = $newPhoto ?? (!empty($data['remove_photo']) ? null : $oldPhoto);
    $fields = travel_place_columns($data, $photo, $actor->userId);
    if ($existing !== null) {
        $pdo->prepare('UPDATE travel_places SET region_id = ?, name = ?, type = ?, description = ?, safety_level = ?, visited = ?, photo_path = ?, updated_by = ? WHERE id = ?')
            ->execute([...$fields, $placeId]);
    } else {
        $pdo->prepare('INSERT INTO travel_places (region_id, name, type, description, safety_level, visited, photo_path, updated_by, added_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([...$fields, $actor->userId]);
        $placeId = (int)$pdo->lastInsertId();
    }
    travel_sync_tags($pdo, $placeId, $data['tags'] ?? []);
    return ['id' => $placeId, 'created' => $existing === null, 'replaced_photo' => $oldPhoto !== $photo ? $oldPhoto : null];
}

/** @param list<string> $tags */
function travel_sync_tags(PDO $pdo, int $placeId, array $tags): void
{
    $pdo->prepare('DELETE FROM travel_place_tags WHERE place_id = ?')->execute([$placeId]);
    $insert = $pdo->prepare('INSERT INTO travel_place_tags (place_id, tag) VALUES (?, ?)');
    foreach ($tags as $tag) {
        $insert->execute([$placeId, $tag]);
    }
}

/**
 * Delete a place (its tags and comments go with it) and its photo file.
 *
 * @throws NotFoundException
 */
function travel_delete_place(PDO $pdo, int $placeId): void
{
    $photo = travel_transaction($pdo, static function () use ($pdo, $placeId): ?string {
        $stmt = $pdo->prepare('SELECT photo_path FROM travel_places WHERE id = ? FOR UPDATE');
        $stmt->execute([$placeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new NotFoundException('Place not found');
        }
        $pdo->prepare('DELETE FROM travel_places WHERE id = ?')->execute([$placeId]);
        return $row['photo_path'];
    });
    travel_remove_photo_file($photo);
}

/**
 * The comments of a place, newest first.
 *
 * @return list<array<string, mixed>>
 * @throws NotFoundException
 */
function travel_comments_list(PDO $pdo, int $placeId, TravelActor $actor): array
{
    $exists = $pdo->prepare('SELECT id FROM travel_places WHERE id = ?');
    $exists->execute([$placeId]);
    if (!$exists->fetchColumn()) {
        throw new NotFoundException('Place not found');
    }
    $stmt = $pdo->prepare('
        SELECT c.id, c.place_id, c.user_id, c.rating, c.comment_text, c.created_at, c.updated_at,
               u.email AS user_email, u.first_name, u.last_name, u.username
        FROM travel_comments c
        LEFT JOIN users u ON u.id = c.user_id
        WHERE c.place_id = ?
        ORDER BY c.created_at DESC, c.id DESC
    ');
    $stmt->execute([$placeId]);
    return array_map(static function (array $row) use ($actor): array {
        $author = (int)($row['user_id'] ?? 0);
        return [
            'id' => (int)$row['id'],
            'place_id' => (int)$row['place_id'],
            'user_id' => $author,
            'user_name' => build_user_display_name($row['first_name'] ?? null, $row['last_name'] ?? null, $row['username'] ?? null, $row['user_email'] ?? null),
            'rating' => $row['rating'] !== null ? (int)$row['rating'] : null,
            'comment_text' => $row['comment_text'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'can_edit' => $actor->mayChange($author),
            'can_delete' => $actor->isAdmin,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Add a comment, or replace the text and rating of one (id > 0) the caller may edit. Returns the comment id and
 * whether it was created.
 *
 * @param array<string, mixed> $data parsed input (id, place_id, comment_text, rating)
 * @return array{id: int, created: bool}
 * @throws NotFoundException|ForbiddenException
 */
function travel_save_comment(PDO $pdo, TravelActor $actor, array $data): array
{
    return travel_transaction($pdo, static function () use ($pdo, $actor, $data): array {
        $place = $pdo->prepare('SELECT id FROM travel_places WHERE id = ? FOR UPDATE');
        $place->execute([$data['place_id']]);
        if (!$place->fetchColumn()) {
            throw new NotFoundException('Place not found');
        }
        $commentId = (int)($data['id'] ?? 0);
        $rating = $data['rating'] ?? null;
        if ($commentId === 0) {
            $pdo->prepare('INSERT INTO travel_comments (place_id, user_id, rating, comment_text) VALUES (?, ?, ?, ?)')
                ->execute([$data['place_id'], $actor->userId, $rating, $data['comment_text']]);
            return ['id' => (int)$pdo->lastInsertId(), 'created' => true];
        }
        $stmt = $pdo->prepare('SELECT user_id, place_id FROM travel_comments WHERE id = ? FOR UPDATE');
        $stmt->execute([$commentId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing || (int)$existing['place_id'] !== (int)$data['place_id']) {
            throw new NotFoundException('Comment not found');
        }
        if (!$actor->isAdmin && (int)($existing['user_id'] ?? 0) !== $actor->userId) {
            throw new ForbiddenException('You can only edit your own comments');
        }
        $pdo->prepare('UPDATE travel_comments SET rating = ?, comment_text = ? WHERE id = ?')->execute([$rating, $data['comment_text'], $commentId]);
        return ['id' => $commentId, 'created' => false];
    });
}

/** @throws NotFoundException */
function travel_delete_comment(PDO $pdo, int $commentId): void
{
    $stmt = $pdo->prepare('DELETE FROM travel_comments WHERE id = ?');
    $stmt->execute([$commentId]);
    if ($stmt->rowCount() === 0) {
        throw new NotFoundException('Comment not found');
    }
}
