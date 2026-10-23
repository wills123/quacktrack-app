-- Link form responses to a public share link (if submitted via share token)

ALTER TABLE form_responses
    ADD COLUMN share_link_id BIGINT UNSIGNED DEFAULT NULL AFTER invite_id,
    ADD CONSTRAINT fk_form_responses_share_link
        FOREIGN KEY (share_link_id) REFERENCES form_share_links(id) ON DELETE SET NULL,
    ADD INDEX idx_form_responses_share_link (share_link_id);
