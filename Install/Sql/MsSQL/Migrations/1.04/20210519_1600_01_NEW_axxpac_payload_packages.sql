-- UP
IF OBJECT_ID('dbo.axxpac_payload_packages', 'U') IS NULL
CREATE TABLE [dbo].[axxpac_payload_packages] (
  [oid] [binary](16) NOT NULL,
  [created_on] [datetime2] NOT NULL,
  [modified_on] [datetime2] NOT NULL,
  [created_by_user_oid] [binary](16) DEFAULT NULL,
  [modified_by_user_oid] [binary](16) DEFAULT NULL,
  [type] [nvarchar](50) NOT NULL,
  [name] [nvarchar](128) NOT NULL,
  [url] [nvarchar](200) NOT NULL,
  [version] [nvarchar](50) NOT NULL,
  CONSTRAINT PK_axxpac_payload_packages_oid PRIMARY KEY (oid),
  CONSTRAINT PK_axxpac_payload_packages_name UNIQUE (name)
);
	
-- DOWN

IF OBJECT_ID('dbo.axxpac_payload_packages', 'U') IS NOT NULL 	
DROP TABLE IF EXISTS [dbo].[axxpac_payload_packages];