<?php
/**
 * Database schema management and migrations.
 * Handles three tables: api_keys, logs, backups.
 */
class ACL_DB {

    const DB_VERSION    = '3.0';
    const VERSION_OPT   = 'acl_db_version';

    public static function install(): void {
        self::run_migrations();
        self::create_tables();
        update_option( self::VERSION_OPT, self::DB_VERSION );
    }

    /**
     * Run migrations in order. Safe to call on fresh installs (errors suppressed).
     */
    private static function run_migrations(): void {
        global $wpdb;

        // Carry over version from old option name (rebrand from wpops_mcp_db_version).
        $current = get_option( self::VERSION_OPT, false );
        if ( $current === false ) {
            $current = get_option( 'wpops_mcp_db_version', '0' );
        }

        // v2.0: column changes (ran against old table names before rebrand).
        if ( version_compare( $current, '2.0', '<' ) ) {
            $wpdb->hide_errors();

            $wpdb->query( "ALTER TABLE {$wpdb->prefix}acl_api_keys
                MODIFY COLUMN key_prefix  VARCHAR(16)  NOT NULL,
                MODIFY COLUMN status      VARCHAR(20)  NOT NULL DEFAULT 'active',
                ADD COLUMN IF NOT EXISTS restrictions_json LONGTEXT NULL AFTER scopes_json,
                ADD COLUMN IF NOT EXISTS last_used_at     DATETIME NULL,
                ADD COLUMN IF NOT EXISTS last_used_ip     VARCHAR(45) NULL,
                ADD COLUMN IF NOT EXISTS updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ADD COLUMN IF NOT EXISTS created_by_user_id BIGINT UNSIGNED NULL,
                ADD COLUMN IF NOT EXISTS revoked_at       DATETIME NULL" );

            $wpdb->query( "ALTER TABLE {$wpdb->prefix}acl_logs
                MODIFY COLUMN request_id  VARCHAR(64) NOT NULL,
                MODIFY COLUMN tool_name   VARCHAR(191) NOT NULL,
                MODIFY COLUMN module      VARCHAR(64)  NOT NULL,
                MODIFY COLUMN status      VARCHAR(32)  NOT NULL,
                MODIFY COLUMN http_status SMALLINT NULL,
                MODIFY COLUMN duration_ms INT UNSIGNED NULL,
                ADD COLUMN IF NOT EXISTS target_id VARCHAR(100) NULL AFTER target_type" );

            $wpdb->show_errors();
        }

        // v3.0: rename tables from old wpops_mcp_* prefix to acl_* (rebrand).
        if ( version_compare( $current, '3.0', '<' ) ) {
            $wpdb->hide_errors();

            $old_keys    = $wpdb->prefix . 'wpops_mcp_api_keys';
            $old_logs    = $wpdb->prefix . 'wpops_mcp_logs';
            $old_backups = $wpdb->prefix . 'wpops_mcp_backups';

            if ( $wpdb->get_var( "SHOW TABLES LIKE '$old_keys'" ) === $old_keys ) {
                $wpdb->query( "RENAME TABLE `$old_keys` TO `{$wpdb->prefix}acl_api_keys`" );
            }
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$old_logs'" ) === $old_logs ) {
                $wpdb->query( "RENAME TABLE `$old_logs` TO `{$wpdb->prefix}acl_logs`" );
            }
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$old_backups'" ) === $old_backups ) {
                $wpdb->query( "RENAME TABLE `$old_backups` TO `{$wpdb->prefix}acl_backups`" );
            }

            $wpdb->show_errors();
        }
    }

    private static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        // ── API Keys ─────────────────────────────────────────────────────
        $sql_keys = "CREATE TABLE {$wpdb->prefix}acl_api_keys (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            label               VARCHAR(191)    NOT NULL,
            key_prefix          VARCHAR(16)     NOT NULL,
            key_hash            VARCHAR(255)    NOT NULL,
            scopes_json         LONGTEXT        NOT NULL,
            restrictions_json   LONGTEXT        NULL,
            status              VARCHAR(20)     NOT NULL DEFAULT 'active',
            last_used_at        DATETIME        NULL,
            last_used_ip        VARCHAR(45)     NULL,
            created_at          DATETIME        NOT NULL,
            updated_at          DATETIME        NOT NULL,
            created_by_user_id  BIGINT UNSIGNED NULL,
            revoked_at          DATETIME        NULL,
            PRIMARY KEY  (id),
            KEY idx_key_prefix (key_prefix),
            KEY idx_status     (status)
        ) $charset;";

        // ── Audit Logs ────────────────────────────────────────────────────
        $sql_logs = "CREATE TABLE {$wpdb->prefix}acl_logs (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at          DATETIME        NOT NULL,
            request_id          VARCHAR(64)     NOT NULL,
            remote_ip           VARCHAR(45)     NOT NULL,
            api_key_id          BIGINT UNSIGNED NULL,
            api_key_label       VARCHAR(191)    NULL,
            tool_name           VARCHAR(191)    NOT NULL,
            module              VARCHAR(64)     NOT NULL,
            status              VARCHAR(32)     NOT NULL,
            http_status         SMALLINT        NULL,
            duration_ms         INT UNSIGNED    NULL,
            target_type         VARCHAR(64)     NULL,
            target_id           VARCHAR(100)    NULL,
            is_dry_run          TINYINT(1)      NOT NULL DEFAULT 0,
            error_code          VARCHAR(100)    NULL,
            error_message       TEXT            NULL,
            params_json         LONGTEXT        NULL,
            result_summary_json LONGTEXT        NULL,
            PRIMARY KEY  (id),
            KEY idx_created_at       (created_at),
            KEY idx_tool_created     (tool_name, created_at),
            KEY idx_status_created   (status, created_at),
            KEY idx_ip_created       (remote_ip, created_at),
            KEY idx_key_created      (api_key_id, created_at),
            KEY idx_request_id       (request_id)
        ) $charset;";

        // ── Backups ───────────────────────────────────────────────────────
        $sql_backups = "CREATE TABLE {$wpdb->prefix}acl_backups (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at    DATETIME        NOT NULL,
            request_id    VARCHAR(64)     NULL,
            api_key_id    BIGINT UNSIGNED NULL,
            tool_name     VARCHAR(191)    NULL,
            target_type   VARCHAR(64)     NULL,
            target_id     VARCHAR(100)    NULL,
            manifest_json LONGTEXT        NULL,
            payload_json  LONGTEXT        NULL,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at),
            KEY idx_target     (target_type, target_id)
        ) $charset;";

        dbDelta( $sql_keys );
        dbDelta( $sql_logs );
        dbDelta( $sql_backups );
    }
}
