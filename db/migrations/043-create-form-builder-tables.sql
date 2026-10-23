-- Form Builder / Secure Public Forms (MVP)
-- Adds dynamic forms, campaigns (snapshot questions), invitations, responses, and answers.
-- Security notes:
-- - Invitation tokens MUST be high entropy; store only SHA-256 hashes in token_hash.
-- - Never store or log raw tokens.

CREATE TABLE IF NOT EXISTS forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_user_id INT NOT NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_forms_owner_user FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_forms_owner (owner_user_id),
    INDEX idx_forms_status (status),
    INDEX idx_forms_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    question_key CHAR(36) NOT NULL,
    type ENUM('short_text','long_text','number','date','yes_no','single_choice','multi_choice') NOT NULL,
    label VARCHAR(255) NOT NULL,
    help_text VARCHAR(255) DEFAULT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    position INT NOT NULL DEFAULT 0,
    options_json JSON DEFAULT NULL,
    validation_json JSON DEFAULT NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_form_questions_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
    UNIQUE KEY uk_form_questions_form_key (form_id, question_key),
    INDEX idx_form_questions_form (form_id),
    INDEX idx_form_questions_position (form_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A campaign represents a request round tied to a store build / deployment.
-- Internal responders can submit without invites; external responders typically submit via invite link.
CREATE TABLE IF NOT EXISTS form_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    created_by_user_id INT NOT NULL,

    -- Linkage / context
    deployment_project_id INT DEFAULT NULL,
    external_project_id VARCHAR(100) DEFAULT NULL,
    site_name VARCHAR(150) DEFAULT NULL,

    name VARCHAR(190) NOT NULL,
    status ENUM('active','closed','archived') NOT NULL DEFAULT 'active',
    due_at DATETIME DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    close_grace_days INT NOT NULL DEFAULT 30,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_form_campaigns_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_campaigns_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_form_campaigns_deployment FOREIGN KEY (deployment_project_id) REFERENCES deployment_projects(id) ON DELETE SET NULL,

    INDEX idx_form_campaigns_form (form_id),
    INDEX idx_form_campaigns_status (status),
    INDEX idx_form_campaigns_deployment (deployment_project_id),
    INDEX idx_form_campaigns_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot questions at campaign start for stability in reporting/exports.
CREATE TABLE IF NOT EXISTS form_campaign_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    source_question_id INT DEFAULT NULL,
    question_key CHAR(36) NOT NULL,
    type ENUM('short_text','long_text','number','date','yes_no','single_choice','multi_choice') NOT NULL,
    label VARCHAR(255) NOT NULL,
    help_text VARCHAR(255) DEFAULT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    position INT NOT NULL DEFAULT 0,
    options_json JSON DEFAULT NULL,
    validation_json JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_form_campaign_questions_campaign FOREIGN KEY (campaign_id) REFERENCES form_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_campaign_questions_source FOREIGN KEY (source_question_id) REFERENCES form_questions(id) ON DELETE SET NULL,
    UNIQUE KEY uk_campaign_question_key (campaign_id, question_key),
    INDEX idx_campaign_questions_campaign (campaign_id),
    INDEX idx_campaign_questions_position (campaign_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-recipient invitations for external responders.
-- Tokens are multi-use by design (multiple submissions allowed).
CREATE TABLE IF NOT EXISTS form_invites (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    recipient_email VARCHAR(190) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,

    sent_at DATETIME DEFAULT NULL,
    last_opened_at DATETIME DEFAULT NULL,
    last_submitted_at DATETIME DEFAULT NULL,
    submission_count INT NOT NULL DEFAULT 0,

    created_by_user_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_form_invites_campaign FOREIGN KEY (campaign_id) REFERENCES form_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_invites_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,

    UNIQUE KEY uk_form_invites_token_hash (token_hash),
    INDEX idx_form_invites_campaign (campaign_id),
    INDEX idx_form_invites_recipient (recipient_email),
    INDEX idx_form_invites_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only submissions. Each submit creates a new response.
CREATE TABLE IF NOT EXISTS form_responses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    invite_id BIGINT UNSIGNED DEFAULT NULL,

    respondent_type ENUM('contractor','internal') NOT NULL,
    respondent_user_id INT DEFAULT NULL,
    respondent_email VARCHAR(190) DEFAULT NULL,

    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_form_responses_campaign FOREIGN KEY (campaign_id) REFERENCES form_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_responses_invite FOREIGN KEY (invite_id) REFERENCES form_invites(id) ON DELETE SET NULL,
    CONSTRAINT fk_form_responses_user FOREIGN KEY (respondent_user_id) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_form_responses_campaign (campaign_id),
    INDEX idx_form_responses_invite (invite_id),
    INDEX idx_form_responses_type (respondent_type),
    INDEX idx_form_responses_submitted (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_response_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    response_id BIGINT UNSIGNED NOT NULL,
    campaign_question_id INT NOT NULL,

    -- Store answers as text and/or JSON depending on field type.
    answer_text TEXT DEFAULT NULL,
    answer_json JSON DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_form_response_answers_response FOREIGN KEY (response_id) REFERENCES form_responses(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_response_answers_question FOREIGN KEY (campaign_question_id) REFERENCES form_campaign_questions(id) ON DELETE RESTRICT,

    UNIQUE KEY uk_response_question (response_id, campaign_question_id),
    INDEX idx_answers_response (response_id),
    INDEX idx_answers_question (campaign_question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: log exports later; for now we rely on audit_logs if needed.
