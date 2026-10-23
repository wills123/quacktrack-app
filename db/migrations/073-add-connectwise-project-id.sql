-- Back-reference to the ConnectWise Manage project a deployment was created from, if any.

ALTER TABLE deployment_projects
    ADD COLUMN connectwise_project_id VARCHAR(50) DEFAULT NULL AFTER project_code,
    ADD INDEX idx_deployment_projects_cw_id (connectwise_project_id);
