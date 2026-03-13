-- UP

ALTER TABLE "exf_app_install_log"
    ALTER "oid" TYPE uuid USING convert_from(oid, 'UTF8')::uuid,
    ALTER "created_by_user_oid" TYPE uuid USING convert_from(created_by_user_oid, 'UTF8')::uuid,
    ALTER "modified_by_user_oid" TYPE uuid USING convert_from(modified_by_user_oid, 'UTF8')::uuid,
    ALTER "app_oid" TYPE uuid USING convert_from(app_oid, 'UTF8')::uuid;

-- DOWN