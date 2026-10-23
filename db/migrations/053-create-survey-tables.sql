-- Store IT Standards Survey tables
-- Supports template management, versioned snapshots, submissions with 1-4 scoring,
-- per-question notes/photos, and manager-vs-tech comparison workflows.

-- ============================================================
-- Template tables (editable by managers)
-- ============================================================

CREATE TABLE IF NOT EXISTS survey_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    created_by_user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_survey_templates_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_survey_templates_status (status),
    INDEX idx_survey_templates_creator (created_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS survey_template_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    version_number INT NOT NULL,
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_by_user_id INT NOT NULL,
    CONSTRAINT fk_survey_versions_template FOREIGN KEY (template_id) REFERENCES survey_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_survey_versions_publisher FOREIGN KEY (published_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_survey_version (template_id, version_number),
    INDEX idx_survey_versions_template (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS survey_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    name VARCHAR(190) NOT NULL,
    position INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_survey_categories_template FOREIGN KEY (template_id) REFERENCES survey_templates(id) ON DELETE CASCADE,
    INDEX idx_survey_categories_template_pos (template_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS survey_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    category_id INT NOT NULL,
    question_text VARCHAR(500) NOT NULL,
    scoring_guidance TEXT DEFAULT NULL,
    position INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_survey_questions_template FOREIGN KEY (template_id) REFERENCES survey_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_survey_questions_category FOREIGN KEY (category_id) REFERENCES survey_categories(id) ON DELETE CASCADE,
    INDEX idx_survey_questions_category_pos (category_id, position),
    INDEX idx_survey_questions_template (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Version snapshot tables (frozen at publish time)
-- ============================================================

CREATE TABLE IF NOT EXISTS survey_version_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version_id INT NOT NULL,
    source_category_id INT DEFAULT NULL,
    name VARCHAR(190) NOT NULL,
    position INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_svc_version FOREIGN KEY (version_id) REFERENCES survey_template_versions(id) ON DELETE CASCADE,
    CONSTRAINT fk_svc_source FOREIGN KEY (source_category_id) REFERENCES survey_categories(id) ON DELETE SET NULL,
    INDEX idx_svc_version_pos (version_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS survey_version_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version_id INT NOT NULL,
    version_category_id INT NOT NULL,
    source_question_id INT DEFAULT NULL,
    question_text VARCHAR(500) NOT NULL,
    scoring_guidance TEXT DEFAULT NULL,
    position INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_svq_version FOREIGN KEY (version_id) REFERENCES survey_template_versions(id) ON DELETE CASCADE,
    CONSTRAINT fk_svq_category FOREIGN KEY (version_category_id) REFERENCES survey_version_categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_svq_source FOREIGN KEY (source_question_id) REFERENCES survey_questions(id) ON DELETE SET NULL,
    INDEX idx_svq_category_pos (version_category_id, position),
    INDEX idx_svq_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Submission tables
-- ============================================================

CREATE TABLE IF NOT EXISTS survey_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT NOT NULL,
    region_id INT DEFAULT NULL,
    user_id INT NOT NULL,
    submitter_role ENUM('tech','manager') NOT NULL,
    version_id INT NOT NULL,
    status ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
    overall_avg_score DECIMAL(3,2) DEFAULT NULL,
    summary_notes TEXT DEFAULT NULL,
    submitted_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_survey_sub_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE RESTRICT,
    CONSTRAINT fk_survey_sub_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL,
    CONSTRAINT fk_survey_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_survey_sub_version FOREIGN KEY (version_id) REFERENCES survey_template_versions(id) ON DELETE RESTRICT,
    INDEX idx_survey_sub_store_date (store_id, submitted_at DESC),
    INDEX idx_survey_sub_store_role_date (store_id, submitter_role, submitted_at DESC),
    INDEX idx_survey_sub_user_date (user_id, submitted_at DESC),
    INDEX idx_survey_sub_version (version_id),
    INDEX idx_survey_sub_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS survey_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id BIGINT UNSIGNED NOT NULL,
    version_question_id INT NOT NULL,
    score TINYINT UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_survey_ans_submission FOREIGN KEY (submission_id) REFERENCES survey_submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_survey_ans_question FOREIGN KEY (version_question_id) REFERENCES survey_version_questions(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_survey_answer (submission_id, version_question_id),
    INDEX idx_survey_ans_submission (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS survey_photos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id BIGINT UNSIGNED NOT NULL,
    answer_id BIGINT UNSIGNED DEFAULT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_survey_photo_submission FOREIGN KEY (submission_id) REFERENCES survey_submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_survey_photo_answer FOREIGN KEY (answer_id) REFERENCES survey_answers(id) ON DELETE CASCADE,
    INDEX idx_survey_photo_submission (submission_id),
    INDEX idx_survey_photo_answer (answer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
