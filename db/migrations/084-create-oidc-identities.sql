-- Explicit issuer/subject bindings; email addresses are never authentication identifiers.
CREATE TABLE oidc_identities (
    user_id INT NOT NULL,
    issuer VARBINARY(512) NOT NULL,
    subject VARBINARY(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (issuer, subject),
    UNIQUE KEY uq_oidc_user (user_id),
    CONSTRAINT fk_oidc_identity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
