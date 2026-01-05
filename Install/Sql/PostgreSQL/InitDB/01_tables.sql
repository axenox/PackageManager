CREATE TABLE axxpac_payload_packages (
                                         oid uuid NOT NULL,
                                         created_on timestamp NOT NULL,
                                         modified_on timestamp NOT NULL,
                                         created_by_user_oid uuid,
                                         modified_by_user_oid uuid,
                                         type varchar(50) NOT NULL,
                                         name varchar(128) NOT NULL,
                                         url varchar(200),
                                         version varchar(50) NOT NULL,
                                         CONSTRAINT pk_axxpac_payload_packages_oid PRIMARY KEY (oid),
                                         CONSTRAINT uq_axxpac_payload_packages_name UNIQUE (name)
);