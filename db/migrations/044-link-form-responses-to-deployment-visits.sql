-- Link form submissions to deployment visits (optional)

ALTER TABLE form_responses
    ADD COLUMN deployment_visit_id INT DEFAULT NULL AFTER respondent_email,
    ADD CONSTRAINT fk_form_responses_deployment_visit
        FOREIGN KEY (deployment_visit_id) REFERENCES deployment_visits(id) ON DELETE SET NULL,
    ADD INDEX idx_form_responses_deployment_visit (deployment_visit_id);
