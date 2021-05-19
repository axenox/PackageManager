-- UP

CREATE TABLE IF NOT EXISTS `axxpac_payload_packages` (
  `oid` binary(16) NOT NULL,
  `name` varchar(128) NOT NULL,
  `type` varchar(50) NOT NULL,
  `url` varchar(250) NOT NULL,
  `version` varchar(50) DEFAULT NULL,
  `created_on` datetime NOT NULL,
  `modified_on` datetime NOT NULL,
  `created_by_user_oid` binary(16) DEFAULT NULL,
  `modified_by_user_oid` binary(16) DEFAULT NULL,
  PRIMARY KEY (`oid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
	
-- DOWN

DROP TABLE `axxdep_payload_packages`;