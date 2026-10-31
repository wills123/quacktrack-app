-- Create core tables for Deployment Specialists workflow

CREATE TABLE IF NOT EXISTS deployment_projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_id INT DEFAULT NULL,
    site_name VARCHAR(150) NOT NULL,
    site_number VARCHAR(50) DEFAULT NULL,
    address_line1 VARCHAR(150) DEFAULT NULL,
    address_line2 VARCHAR(150) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    state VARCHAR(50) DEFAULT NULL,
    postal_code VARCHAR(25) DEFAULT NULL,
    region_id INT NOT NULL,
    project_code VARCHAR(100) NOT NULL,
    go_live_date DATE DEFAULT NULL,
    stage ENUM('planned','dark_week','in_progress','waiting_vendor','complete') NOT NULL DEFAULT 'planned',
    overall_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    current_summary TEXT DEFAULT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_deployment_projects_stage (stage),
    INDEX idx_deployment_projects_region (region_id),
    INDEX idx_deployment_projects_store (store_id),
    INDEX idx_deployment_projects_golive (go_live_date),
    CONSTRAINT fk_deployment_projects_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE SET NULL,
    CONSTRAINT fk_deployment_projects_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deployment_stage_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    deployment_id INT NOT NULL,
    from_stage ENUM('planned','dark_week','in_progress','waiting_vendor','complete') DEFAULT NULL,
    to_stage ENUM('planned','dark_week','in_progress','waiting_vendor','complete') NOT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    changed_by INT DEFAULT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stage_history_deployment FOREIGN KEY (deployment_id) REFERENCES deployment_projects(id) ON DELETE CASCADE,
    INDEX idx_stage_history_deployment (deployment_id),
    INDEX idx_stage_history_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deployment_task_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    default_sort INT NOT NULL DEFAULT 0,
    default_weight DECIMAL(6,2) NOT NULL DEFAULT 1.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_task_template_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deployment_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deployment_id INT NOT NULL,
    template_id INT DEFAULT NULL,
    label VARCHAR(150) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('not_started','in_progress','complete') NOT NULL DEFAULT 'not_started',
    percent_complete DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    notes TEXT DEFAULT NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_deployment_tasks_project FOREIGN KEY (deployment_id) REFERENCES deployment_projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_deployment_tasks_template FOREIGN KEY (template_id) REFERENCES deployment_task_templates(id) ON DELETE SET NULL,
    INDEX idx_deployment_tasks_project (deployment_id),
    INDEX idx_deployment_tasks_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deployment_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deployment_id INT NOT NULL,
    visit_date DATETIME NOT NULL,
    stage_at_visit ENUM('planned','dark_week','in_progress','waiting_vendor','complete') DEFAULT NULL,
    summary TEXT DEFAULT NULL,
    hours_spent DECIMAL(5,2) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_deployment_visits_project FOREIGN KEY (deployment_id) REFERENCES deployment_projects(id) ON DELETE CASCADE,
    INDEX idx_deployment_visits_project (deployment_id),
    INDEX idx_deployment_visits_date (visit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deployment_visit_techs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL,
    user_id INT NOT NULL,
    role_label VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_deployment_visit_techs_visit FOREIGN KEY (visit_id) REFERENCES deployment_visits(id) ON DELETE CASCADE,
    CONSTRAINT fk_deployment_visit_techs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_visit_user (visit_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deployment_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deployment_id INT NOT NULL,
    note_text TEXT NOT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_deployment_notes_project FOREIGN KEY (deployment_id) REFERENCES deployment_projects(id) ON DELETE CASCADE,
    INDEX idx_deployment_notes_project (deployment_id),
    FULLTEXT INDEX ft_deployment_notes_text (note_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deployment_exports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    filters_json JSON DEFAULT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_deployment_exports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_deployment_exports_generated (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed base task templates
INSERT INTO deployment_task_templates (label, description, default_sort)
VALUES
    ('Network rack installed', 'Rack, cabling, and power pre-staged', 10),
    ('QoS configured', 'Quality of service configured for network devices', 20),
    ('Receipt printers installed', 'Receipt printers placed, cabled, and tested', 30),
    ('Cameras installed', 'Security cameras mounted and online', 40)
ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    default_sort = VALUES(default_sort),
    updated_at = CURRENT_TIMESTAMP;

-- Ensure Deployment Specialist roles exist
INSERT INTO roles (name, slug, description, is_protected)
VALUES
    ('Deployment Specialist', 'deployment_specialist', 'Technicians executing store deployments', 1),
    ('Deployment Specialist Lead', 'deployment_specialist_lead', 'Leads coordinating deployment projects', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    updated_at = CURRENT_TIMESTAMP;

-- Grant deployment permissions to roles
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
JOIN (
    SELECT 'deployment.view' AS permission_key UNION ALL
    SELECT 'deployment.manage'
) perms ON r.slug = 'deployment_specialist';

INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
JOIN (
    SELECT 'deployment.view' AS permission_key UNION ALL
    SELECT 'deployment.manage' UNION ALL
    SELECT 'deployment.tasks.customize' UNION ALL
    SELECT 'deployment.reports.export'
) perms ON r.slug = 'deployment_specialist_lead';

-- Admins should automatically receive deployment permissions
INSERT IGNORE INTO role_permissions (role_id, permission_key)
SELECT r.id, perms.permission_key
FROM roles r
JOIN (
    SELECT 'deployment.view' AS permission_key UNION ALL
    SELECT 'deployment.manage' UNION ALL
    SELECT 'deployment.tasks.customize' UNION ALL
    SELECT 'deployment.reports.export'
) perms ON r.slug = 'admin';
