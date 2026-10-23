<?php

require_once __DIR__ . '/config.php';
require_login();
require_permission('admin.files.manage');

// Handle file management actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = req_raw_string($_POST, 'action');

    switch ($action) {
        case 'cleanup_old_files':
            try {
                $maxAgeDays = req_int_strict($_POST, 'max_age', 1, 3650);
            } catch (BadRequestException $e) {
                // A bad age must never fall back to a default: it decides which files are deleted.
                flash_message('Cleanup age must be a whole number of days between 1 and 3650.', 'error');
                break;
            }
            $cleaned = FileManager::cleanupOldFiles($maxAgeDays * 86400);
            flash_message("Cleaned up $cleaned old files", 'success');
            Logger::info('admin_action', 'Manual file cleanup performed', [
                'cleaned_count' => $cleaned,
                'max_age_days' => $maxAgeDays,
                'admin_user' => $_SESSION['uid']
            ]);
            break;

        case 'delete_file':
            $filename = req_raw_string($_POST, 'filename');
            if ($filename) {
                if (FileManager::deleteFile($filename, 'admin_delete')) {
                    flash_message("File '$filename' deleted successfully", 'success');
                } else {
                    flash_message("Failed to delete file '$filename'", 'error');
                }
            }
            break;

        case 'test_upload':
            // Run a real upload through the same code path the app uses, then remove it again.
            $upload = $_FILES['image'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                flash_message('Choose an image to test the upload.', 'error');
                break;
            }
            $result = FileManager::uploadFile($upload, 'admin_upload_test');
            if (!empty($result['success'])) {
                $removed = FileManager::deleteFile($result['filename'], 'admin_upload_test');
                flash_message(
                    $removed
                        ? 'Upload test passed: the file was saved and removed again.'
                        : 'Upload test saved the file but could not remove it: ' . $result['filename'],
                    $removed ? 'success' : 'warning'
                );
            } else {
                flash_message('Upload test failed: ' . ($result['error'] ?? 'unknown error'), 'error');
            }
            break;

        case 'get_file_info':
            $filename = req_raw_string($_POST, 'filename');
            if ($filename) {
                $info = FileManager::getFileInfo($filename);
                header('Content-Type: application/json');
                echo json_encode($info);
                exit;
            }
            break;
    }

    redirect('/admin_files.php');
}

// Get disk usage information
$diskUsage = FileManager::getDiskUsage();

// Pagination and sorting for file listing
$items_per_page = 50;
$page = max(1, req_int($_GET, 'page', 1));
$sort = req_raw_string($_GET, 'sort', 'modified'); // Can be: modified, name, size
$order = req_raw_string($_GET, 'order', 'desc'); // Can be: asc, desc

// Get list of uploaded files with optimization
$uploadDir = '/var/www/uploads';
$allFiles = [];
$totalFileCount = 0;

if (is_dir($uploadDir)) {
    // Use scandir for better performance than glob with large directories
    $fileNames = @scandir($uploadDir, SCANDIR_SORT_NONE);

    if ($fileNames !== false) {
        // Initialize finfo for MIME type detection
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        foreach ($fileNames as $filename) {
            // Skip . and ..
            if ($filename === '.' || $filename === '..') {
                continue;
            }

            $filePath = $uploadDir . '/' . $filename;

            // Only process files (not directories)
            if (is_file($filePath)) {
                // Use finfo_file instead of deprecated mime_content_type
                $mimeType = $finfo->file($filePath);
                if (!$mimeType) {
                    $mimeType = 'application/octet-stream';
                }

                $allFiles[] = [
                    'name' => $filename,
                    'size' => filesize($filePath),
                    'modified' => filemtime($filePath),
                    'type' => $mimeType,
                    'path' => '/uploads/' . $filename
                ];
            }
        }

        // Sort files based on requested sort order
        usort($allFiles, function ($a, $b) use ($sort, $order) {
            $aVal = $a[$sort];
            $bVal = $b[$sort];

            if ($aVal === $bVal) {
                return 0;
            }

            $result = ($aVal < $bVal) ? -1 : 1;
            return ($order === 'asc') ? $result : -$result;
        });

        $totalFileCount = count($allFiles);
    }
}

// Paginate the files
$offset = ($page - 1) * $items_per_page;
$totalPages = max(1, ceil($totalFileCount / $items_per_page));

// Ensure page is within valid range
if ($page > $totalPages) {
    $page = $totalPages;
}

// Get files for current page
$files = array_slice($allFiles, ($page - 1) * $items_per_page, $items_per_page);

// Get file statistics (calculate from paginated data for performance)
$fileStats = [
    'total_files' => $totalFileCount,
    'total_size' => array_sum(array_column($allFiles, 'size')),
    'image_files' => count(array_filter($allFiles, function ($f) {
        return strpos($f['type'], 'image/') === 0;
    })),
    'document_files' => count(array_filter($allFiles, function ($f) {
        return strpos($f['type'], 'application/') === 0;
    })),
    'oldest_file' => !empty($allFiles) ? min(array_column($allFiles, 'modified')) : null,
    'newest_file' => !empty($allFiles) ? max(array_column($allFiles, 'modified')) : null
];

require __DIR__ . '/views/admin_files.view.php';
