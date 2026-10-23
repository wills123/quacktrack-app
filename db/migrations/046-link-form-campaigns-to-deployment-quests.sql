-- Link form campaigns to a specific deployment quest/task

ALTER TABLE form_campaigns
    ADD COLUMN deployment_quest_id INT DEFAULT NULL AFTER deployment_project_id,
    ADD CONSTRAINT fk_form_campaigns_deployment_quest
        FOREIGN KEY (deployment_quest_id) REFERENCES deployment_quests(id) ON DELETE SET NULL,
    ADD INDEX idx_form_campaigns_deployment_quest (deployment_quest_id);
