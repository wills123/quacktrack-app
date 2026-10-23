<?php

/**
 * Stock Settings Administration
 *
 * Allows administrators to configure stock operation limits and rate limiting.
 * Settings are stored in app_settings table.
 */

require_once __DIR__ . '/../config.php';
require_login();
require_permission('admin.settings');
csrf_verify();
$pdo = db();
$current_user_id = (int)($_SESSION['uid'] ?? 0);
// Handle POST - Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = req_string($_POST, 'action');
    if ($action === 'save_settings') {
        try {
        // Validate and save each setting
            $settings = [
                'stock_max_quantity' => [
                    'value' => req_int($_POST, 'stock_max_quantity', 10000),
                    'min' => 1,
                    'max' => 1000000,
                    'label' => 'Maximum stock quantity'
                ],
                'stock_max_note_length' => [
                    'value' => req_int($_POST, 'stock_max_note_length', 500),
                    'min' => 10,
                    'max' => 5000,
                    'label' => 'Maximum note length'
                ],
                'stock_rate_limit_per_minute' => [
                    'value' => req_int($_POST, 'stock_rate_limit_per_minute', 50),
                    'min' => 1,
                    'max' => 1000,
                    'label' => 'Rate limit (operations per window)'
                ],
                'stock_rate_limit_window_seconds' => [
                    'value' => req_int($_POST, 'stock_rate_limit_window_seconds', 60),
                    'min' => 1,
                    'max' => 3600,
                    'label' => 'Rate limit window (seconds)'
                ]
            ];
            $errors = [];
            foreach ($settings as $key => $config) {
                $value = $config['value'];
                if ($value < $config['min'] || $value > $config['max']) {
                    $errors[] = "{$config['label']} must be between {$config['min']} and {$config['max']}";
                }
            }

            if (!empty($errors)) {
                flash(implode(', ', $errors), 'error');
            } else {
            // Save all settings
                $stmt = $pdo->prepare('
                    INSERT INTO app_settings (setting_key, setting_value)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                ');
                foreach ($settings as $key => $config) {
                    $stmt->execute([$key, (string)$config['value']]);
                }

                Logger::info('stock_settings', 'Stock settings updated', [
                'user_id' => $current_user_id,
                'settings' => array_map(fn($s) => $s['value'], $settings)
                ]);
                flash('Stock settings updated successfully.');
                redirect('/admin/stock_settings');
            }
        } catch (Exception $e) {
            qt_rethrow_bad_input($e);
            Logger::error('stock_settings', $e);
            flash('Error saving settings. Please try again.', 'error');
        }
    }
}

// Fetch current settings with defaults
$current_settings = [
    'stock_max_quantity' => (int)get_app_setting('stock_max_quantity', '10000'),
    'stock_max_note_length' => (int)get_app_setting('stock_max_note_length', '500'),
    'stock_rate_limit_per_minute' => (int)get_app_setting('stock_rate_limit_per_minute', '50'),
    'stock_rate_limit_window_seconds' => (int)get_app_setting('stock_rate_limit_window_seconds', '60')
];
// Include view
require VIEW_ROOT . '/admin/stock_settings.view.php';
