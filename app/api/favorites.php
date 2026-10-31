<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input_schemas/favorites.php';
require_login();
// Security headers for API endpoints
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
$pdo = db();
$current_user_id = $_SESSION['uid'];
$action = req_action();

try {
// Use the new CSRF verification helper for API
    function api_csrf_verify(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify_api()) {
                throw new ForbiddenException('Invalid CSRF token');
            }
        }
    }

    switch ($action) {
        case 'add':
            api_csrf_verify();
            $product_id = favorites_input('add')['product_id'];

            // Verify product exists
            $product_check = $pdo->prepare('SELECT id FROM products WHERE id = ?');
            $product_check->execute([$product_id]);
            if (!$product_check->fetch()) {
                throw new NotFoundException('Product not found');
            }

            // Add to favorites (ignore if already exists)
            $stmt = $pdo->prepare('INSERT IGNORE INTO user_favorites (user_id, product_id) VALUES (?, ?)');
            $stmt->execute([$current_user_id, $product_id]);
            api_success(null, 'Added to favorites');

            break;
        case 'remove':
            api_csrf_verify();
            $product_id = favorites_input('remove')['product_id'];

            $stmt = $pdo->prepare('DELETE FROM user_favorites WHERE user_id = ? AND product_id = ?');
            $stmt->execute([$current_user_id, $product_id]);
            api_success(null, 'Removed from favorites');

            break;

        case 'list':
            $in = favorites_input('list');
            $limit = $in['limit'];
            $page = $in['page'];
            $offset = ($page - 1) * $limit;
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM user_favorites WHERE user_id = ?');
            $countStmt->execute([$current_user_id]);
            $total = (int)$countStmt->fetchColumn();
            $favorites = $pdo->prepare('
                SELECT p.id, p.product_id as sku, p.name, p.image_path,
                       COALESCE(SUM(vs.quantity), 0) as van_stock
                FROM user_favorites uf
                JOIN products p ON uf.product_id = p.id
                LEFT JOIN van_stock vs ON p.id = vs.product_id AND vs.user_id = ?
                WHERE uf.user_id = ?
                GROUP BY p.id, p.product_id, p.name, p.image_path
                ORDER BY p.name ASC
                LIMIT ? OFFSET ?
            ');
            $favorites->execute([$current_user_id, $current_user_id, $limit, $offset]);
            api_success([
                'favorites' => $favorites->fetchAll(),
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'has_more' => ($offset + $limit) < $total
                ]
            ], 'Favorites retrieved');

            break;
        case 'check':
            $product_id = favorites_input('check')['product_id'];

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_favorites WHERE user_id = ? AND product_id = ?');
            $stmt->execute([$current_user_id, $product_id]);
            $is_favorite = (bool)$stmt->fetchColumn();
            api_success(['is_favorite' => $is_favorite], 'Favorite status checked');

            break;
        default:
            throw new BadRequestException('Invalid action');
    }
} catch (Exception $e) {
    // api_fail() answers every ClientError itself (status, details, endpoint context), so this
    // catch does not hand them to the boundary via qt_rethrow_bad_input().
// Client errors answer with their own 4xx; anything else is logged and answers 500
    api_fail($e, 'favorites_api_error', [
        'action' => $action,
        'user_id' => $current_user_id,
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    ], 'Favorites operation failed. Please try again.');
}
