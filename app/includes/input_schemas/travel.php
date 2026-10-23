<?php

/**
 * Request input of api/travel.php, one schema per action (see includes/input_schema.php). Column limits
 * mirror db/migrations/036-create-travel-feature-tables.sql and 037; tests/schema_drift_test.php checks them.
 * The tag list is described as one text field and parsed by travel_parse_tags().
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** regions.id, travel_places.id and travel_comments.id are INT columns. */
const TRAVEL_MAX_ID = 2147483647;

/** Most tags one place may carry, and the longest tag (travel_place_tags.tag is VARCHAR(50)). */
const TRAVEL_MAX_TAGS = 12;
const TRAVEL_TAG_MAX = 50;

/** The longest text the whole tag field may hold before it is split. */
const TRAVEL_TAGS_FIELD_MAX = 1000;

/** The longest search text of the place list (place names are at most 200 characters). */
const TRAVEL_SEARCH_MAX = 200;

/** travel_comments.comment_text is VARCHAR(1000). */
const TRAVEL_COMMENT_MAX = 1000;

/**
 * A required row id.
 *
 * @return array<string, mixed>
 */
function travel_id(string $label): array
{
    return in_int(min: 1, max: TRAVEL_MAX_ID, required: true, label: $label);
}

/**
 * Every action's schema, keyed by action name (regions takes no input).
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function travel_input_schemas(): array
{
    return [
        'save_region' => [
            'id' => travel_id('Region'),
            'notes' => in_text(label: 'Notes', column: 'travel_region_notes.notes'),
        ],
        'places' => [
            'region_id' => in_int(min: 1, max: TRAVEL_MAX_ID, label: 'Region'),
            'type' => in_enum(['recommended', 'avoid'], label: 'Type'),
            'tag' => in_string(max: TRAVEL_TAG_MAX, label: 'Tag'),
            'q' => in_string(max: TRAVEL_SEARCH_MAX, label: 'Search'),
            'visited' => in_enum(['all', '0', '1'], default: 'all', label: 'Visited'),
        ],
        'save_place' => [
            'id' => in_int(min: 0, max: TRAVEL_MAX_ID, label: 'Place'),
            'region_id' => travel_id('Region'),
            'name' => in_string(max: 200, required: true, label: 'Name', column: 'travel_places.name'),
            'type' => in_enum(['recommended', 'avoid'], default: 'recommended', label: 'Type'),
            'description' => in_text(label: 'Description', column: 'travel_places.description'),
            'safety_level' => in_enum(['low', 'medium', 'high'], label: 'Safety level'),
            'visited' => in_flag(default: false, label: 'Visited'),
            'remove_photo' => in_flag(default: false, label: 'Remove photo'),
            'tags' => in_string(max: TRAVEL_TAGS_FIELD_MAX, label: 'Tags'),
        ],
        'delete_place' => ['id' => travel_id('Place')],
        'comments' => ['place_id' => travel_id('Place')],
        'save_comment' => [
            'id' => in_int(min: 0, max: TRAVEL_MAX_ID, label: 'Comment'),
            'place_id' => travel_id('Place'),
            'comment_text' => in_string(max: TRAVEL_COMMENT_MAX, required: true, label: 'Comment', column: 'travel_comments.comment_text'),
            'rating' => in_int(min: 1, max: 5, label: 'Rating'),
        ],
        'delete_comment' => ['id' => travel_id('Comment')],
    ];
}

/**
 * Parse the request for $action: reads take the query, changes the body. Defaults make absent optional
 * fields explicit (a place or comment is saved as the whole form).
 *
 * @return array<string, mixed>
 */
function travel_input(string $action): array
{
    static $schemas = null;
    $schemas ??= travel_input_schemas();
    $source = in_array($action, ['places', 'comments'], true) ? $_GET : $_POST;
    $values = input_parse($schemas[$action], $source);
    if ($action === 'save_place') {
        $values['tags'] = travel_parse_tags((string)($values['tags'] ?? ''));
    }
    return $values;
}

/**
 * The tags of a place from the comma- or semicolon-separated field. Blank entries are skipped, repeats are kept
 * once, and a tag that is nothing but punctuation, one that is longer than the column or more than the allowed
 * number of tags is refused instead of being cut.
 *
 * @return list<string>
 * @throws UnprocessableException
 */
function travel_parse_tags(string $raw): array
{
    $tags = [];
    foreach (preg_split('/[,;]+/', $raw) ?: [] as $part) {
        if (trim($part) !== '') {
            $tags[travel_normalize_tag($part)] = true;
        }
    }
    if (count($tags) > TRAVEL_MAX_TAGS) {
        throw new UnprocessableException('A place can have at most ' . TRAVEL_MAX_TAGS . ' tags.');
    }
    return array_map('strval', array_keys($tags));
}

/**
 * One tag in its stored form: lower case, words joined by hyphens, other punctuation removed.
 *
 * @throws UnprocessableException
 */
function travel_normalize_tag(string $part): string
{
    $tag = (string)preg_replace('/\s+/', '-', (string)preg_replace('/[^a-z0-9\s-]/', '', strtolower(trim($part))));
    $tag = trim($tag, '-');
    if ($tag === '') {
        throw new UnprocessableException('Tag "' . trim($part) . '" has no letters or digits.');
    }
    if (strlen($tag) > TRAVEL_TAG_MAX) {
        throw new UnprocessableException('Tags can have at most ' . TRAVEL_TAG_MAX . ' characters.');
    }
    return $tag;
}
