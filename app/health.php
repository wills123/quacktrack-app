<?php

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

http_response_code(200);

echo json_encode([
    'status' => 'ok',
    'version' => getenv('QT_VERSION') ?: 'dev',
    'commit' => getenv('QT_COMMIT') ?: 'unknown',
    'time' => gmdate('c'),
]);
