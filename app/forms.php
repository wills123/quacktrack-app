<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/form_builder_service.php';

require_login();

$canManageForms = has_permission('forms.manage');
$canManageCampaigns = has_permission('forms.campaigns.manage');
$canViewResponses = has_permission('forms.view_responses');

// Minimum access gate: if you can manage forms OR campaigns OR view responses
if (!$canManageForms && !$canManageCampaigns && !$canViewResponses) {
    require_permission('forms.manage');
}

$pdo = db();
$tab = req_raw_string($_GET, 'tab', 'forms');
$tab = in_array($tab, ['forms', 'campaigns'], true) ? $tab : 'forms';

csrf_verify();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = req_raw_string($_POST, 'action');

    try {
        if ($action === 'create_form') {
            require_permission('forms.manage');
            $title = req_raw_string($_POST, 'title');
            $description = req_raw_string($_POST, 'description');
            $templateFormId = req_int($_POST, 'template_form_id');

            $formId = form_create((int)($_SESSION['uid'] ?? 0), $title, $description);

            if ($templateFormId > 0) {
                // Copy questions/options from an existing form to reduce repetitive setup.
                // NOTE: This does not copy responses/campaigns, only the form questions.
                form_clone_questions($templateFormId, $formId);
                flash_message('Form created from template. Review questions and publish when ready.', 'success');
            } else {
                flash_message('Form created (draft). Add questions next.', 'success');
            }

            redirect('/forms?tab=forms&form_id=' . $formId);
        }

        if ($action === 'add_question' || $action === 'update_question') {
            require_permission('forms.manage');
            $formId = req_int($_POST, 'form_id');
            if ($formId <= 0) {
                throw new InvalidArgumentException('Invalid form selection.');
            }

            $type = req_raw_string($_POST, 'type');
            $optionsLines = req_raw_string($_POST, 'options_lines');
            $options = null;
            if (in_array(strtolower(trim($type)), ['single_choice', 'multi_choice'], true)) {
                $lines = preg_split('/\r?\n/', $optionsLines);
                $opts = [];
                foreach ((array)$lines as $line) {
                    $v = trim((string)$line);
                    if ($v !== '') {
                        $opts[] = $v;
                    }
                }
                $opts = array_values(array_unique($opts));
                if (empty($opts)) {
                    throw new InvalidArgumentException('Choice questions require at least one option.');
                }
                $options = $opts;
            }

            if ($action === 'add_question') {
                form_add_question($formId, [
                    'type' => $type,
                    'label' => req_raw_string($_POST, 'label'),
                    'help_text' => req_raw_string($_POST, 'help_text'),
                    'is_required' => !empty($_POST['is_required']),
                    'position' => req_int($_POST, 'position'),
                    'options_json' => $options,
                ]);
                flash_message('Question added.', 'success');
            } else {
                $questionId = req_int($_POST, 'question_id');
                if ($questionId <= 0) {
                    throw new InvalidArgumentException('Invalid question selection.');
                }
                form_update_question($questionId, [
                    'type' => $type,
                    'label' => req_raw_string($_POST, 'label'),
                    'help_text' => req_raw_string($_POST, 'help_text'),
                    'is_required' => !empty($_POST['is_required']),
                    'position' => req_int($_POST, 'position'),
                    'options_json' => $options,
                ]);
                flash_message('Question updated.', 'success');
            }

            redirect('/forms?tab=forms&form_id=' . $formId);
        }

        if ($action === 'delete_question') {
            require_permission('forms.manage');
            $formId = req_int($_POST, 'form_id');
            $questionId = req_int($_POST, 'question_id');
            if ($formId <= 0 || $questionId <= 0) {
                throw new InvalidArgumentException('Invalid question selection.');
            }
            form_delete_question($questionId);
            flash_message('Question removed.', 'success');
            redirect('/forms?tab=forms&form_id=' . $formId);
        }

        if ($action === 'move_question') {
            require_permission('forms.manage');
            $formId = req_int($_POST, 'form_id');
            $questionId = req_int($_POST, 'question_id');
            $dir = req_raw_string($_POST, 'direction');
            if ($formId <= 0 || $questionId <= 0 || !in_array($dir, ['up','down'], true)) {
                throw new InvalidArgumentException('Invalid move request.');
            }
            form_move_question($formId, $questionId, $dir);
            redirect('/forms?tab=forms&form_id=' . $formId);
        }

        if ($action === 'create_campaign') {
            require_permission('forms.campaigns.manage');
            $formId = req_int($_POST, 'form_id');
            if ($formId <= 0) {
                throw new InvalidArgumentException('Invalid form selection.');
            }

            $campaignId = form_campaign_create_and_snapshot($formId, (int)($_SESSION['uid'] ?? 0), [
                'name' => req_raw_string($_POST, 'name'),
                'deployment_project_id' => $_POST['deployment_project_id'] ?? null,
                'deployment_quest_id' => $_POST['deployment_quest_id'] ?? null,
                'external_project_id' => req_raw_string($_POST, 'external_project_id'),
                'site_name' => req_raw_string($_POST, 'site_name'),
                'due_at' => $_POST['due_at'] ?? null,
            ]);

            flash_message('Campaign created and questions snapshotted.', 'success');
            redirect('/forms?tab=campaigns&campaign_id=' . $campaignId);
        }

        flash_message('Unsupported action.', 'error');
        redirect('/forms?tab=' . $tab);
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        Logger::error('forms_page_action_failed', $e, ['action' => $action]);
        // Validation messages are written for people; anything else (a database error) stays in the log (audit H11).
        $userMessage = $e instanceof InvalidArgumentException || $e instanceof ClientErrorException
            ? $e->getMessage() : 'Please try again or contact an administrator.';
        flash_message('Action failed: ' . $userMessage, 'error');
        redirect('/forms?tab=' . $tab);
    }
}

// Data fetch
$forms = [];
if ($canManageForms || $canManageCampaigns || $canViewResponses) {
    $forms = $pdo->query('SELECT id, title, status, created_at, updated_at FROM forms ORDER BY updated_at DESC, id DESC')->fetchAll() ?: [];
}

$deploymentProjects = [];
if ($canManageCampaigns) {
    $deploymentProjects = $pdo->query('SELECT id, site_name, project_code, stage FROM deployment_projects WHERE is_archived = 0 ORDER BY created_at DESC, id DESC LIMIT 200')->fetchAll() ?: [];
}

$activeFormId = req_int($_GET, 'form_id');
$formQuestions = [];
if ($activeFormId > 0 && ($canManageForms || $canManageCampaigns || $canViewResponses)) {
    $stmt = $pdo->prepare('SELECT id, type, label, help_text, options_json, is_required, position FROM form_questions WHERE form_id = ? AND is_deleted = 0 ORDER BY position ASC, id ASC');
    $stmt->execute([$activeFormId]);
    $formQuestions = $stmt->fetchAll() ?: [];
}

$title        = 'Forms';
$main_class   = 'main-content';
$extra_styles = ['/assets/opdash.css'];
$extra_scripts = ['/assets/pages/forms.js'];
require __DIR__ . '/views/forms.view.php';
