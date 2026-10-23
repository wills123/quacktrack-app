-- Quest system schema for Deployment Specialists

CREATE TABLE IF NOT EXISTS deployment_quest_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    brief TEXT DEFAULT NULL,
    default_status ENUM('available','in_progress','blocked','ready_review','complete','archived') NOT NULL DEFAULT 'available',
    default_tags JSON DEFAULT NULL,
    recommended_role VARCHAR(80) DEFAULT NULL,
    auto_assign_role VARCHAR(80) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deployment_quest_template_objectives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    label VARCHAR(160) NOT NULL,
    description TEXT DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    requires_review TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_template_objectives_template FOREIGN KEY (template_id) REFERENCES deployment_quest_templates(id) ON DELETE CASCADE,
    INDEX idx_template_objectives_template (template_id),
    UNIQUE KEY uk_template_label (template_id, label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deployment_quests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deployment_id INT NOT NULL,
    template_id INT DEFAULT NULL,
    title VARCHAR(160) NOT NULL,
    brief TEXT DEFAULT NULL,
    status ENUM('available','in_progress','blocked','ready_review','complete','archived') NOT NULL DEFAULT 'available',
    priority TINYINT DEFAULT 0,
    assigned_user_id INT DEFAULT NULL,
    tags JSON DEFAULT NULL,
    due_date DATE DEFAULT NULL,
    blocker_reason TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_quest_deployment_status (deployment_id, status),
    INDEX idx_quest_assignee (assigned_user_id),
    CONSTRAINT fk_quest_deployment FOREIGN KEY (deployment_id) REFERENCES deployment_projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_quest_template FOREIGN KEY (template_id) REFERENCES deployment_quest_templates(id) ON DELETE SET NULL,
    CONSTRAINT fk_quest_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deployment_quest_objectives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quest_id INT NOT NULL,
    label VARCHAR(160) NOT NULL,
    description TEXT DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('not_started','in_progress','done') NOT NULL DEFAULT 'not_started',
    requires_review TINYINT(1) NOT NULL DEFAULT 0,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_quest_objectives_quest FOREIGN KEY (quest_id) REFERENCES deployment_quests(id) ON DELETE CASCADE,
    INDEX idx_quest_objectives_quest (quest_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deployment_quest_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    quest_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    entry_type ENUM('update','blocker','resolution','handoff','completion','comment') NOT NULL DEFAULT 'update',
    message TEXT NOT NULL,
    metadata JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_quest_logs_quest FOREIGN KEY (quest_id) REFERENCES deployment_quests(id) ON DELETE CASCADE,
    CONSTRAINT fk_quest_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_quest_logs_quest (quest_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed starter templates (optional minimal set)
INSERT INTO deployment_quest_templates (title, brief, default_tags, recommended_role, created_by)
VALUES
    ('Dark Week Prep', 'Stage materials, verify temporary power readiness, capture site notes.', JSON_ARRAY('dark_week','prep'), 'deployment_specialist_lead', NULL),
    ('Camera Install', 'Install and test all security cameras per spec.', JSON_ARRAY('cameras','security'), 'deployment_specialist', NULL)
ON DUPLICATE KEY UPDATE
    brief = VALUES(brief),
    default_tags = VALUES(default_tags),
    recommended_role = VALUES(recommended_role),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO deployment_quest_template_objectives (template_id, label, description, sort_order)
SELECT t.id, o.label, o.description, o.sort_order
FROM deployment_quest_templates t
JOIN (
    SELECT 'Dark Week Prep' AS tpl_title, 'Confirm generator and temp power' AS label, 'Verify vendor timeline for power-on' AS description, 10 AS sort_order
    UNION ALL
    SELECT 'Dark Week Prep', 'Pre-stage racks and cabling', 'Lay all cable runs and label drops', 20
    UNION ALL
    SELECT 'Camera Install', 'Mount cameras', 'Per layout, note any obstructions', 10
    UNION ALL
    SELECT 'Camera Install', 'Run tests + add notes', 'Record MACs and upload photos', 20
) o ON t.title = o.tpl_title
ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    sort_order = VALUES(sort_order);
