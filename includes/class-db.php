<?php
/**
 * Database schema management and migrations.
 * Handles three tables: api_keys, logs, backups.
 */
class AICOM_DB {

    const DB_VERSION    = '4.8';
    const VERSION_OPT   = 'aicom_db_version';

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

        // Carry over version from old option names (rebrand chain: wpops_mcp → acl → aicom).
        $current = get_option( self::VERSION_OPT, false );
        if ( $current === false ) {
            $current = get_option( 'acl_db_version', false );
        }
        if ( $current === false ) {
            $current = get_option( 'wpops_mcp_db_version', '0' );
        }

        // v2.0: column changes (ran against old table names before rebrand).
        if ( version_compare( $current, '2.0', '<' ) ) {
            $wpdb->hide_errors();

            $wpdb->query( "ALTER TABLE {$wpdb->prefix}aicom_api_keys
                MODIFY COLUMN key_prefix  VARCHAR(16)  NOT NULL,
                MODIFY COLUMN status      VARCHAR(20)  NOT NULL DEFAULT 'active',
                ADD COLUMN IF NOT EXISTS restrictions_json LONGTEXT NULL AFTER scopes_json,
                ADD COLUMN IF NOT EXISTS last_used_at     DATETIME NULL,
                ADD COLUMN IF NOT EXISTS last_used_ip     VARCHAR(45) NULL,
                ADD COLUMN IF NOT EXISTS updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ADD COLUMN IF NOT EXISTS created_by_user_id BIGINT UNSIGNED NULL,
                ADD COLUMN IF NOT EXISTS revoked_at       DATETIME NULL" );

            $wpdb->query( "ALTER TABLE {$wpdb->prefix}aicom_logs
                MODIFY COLUMN request_id  VARCHAR(64) NOT NULL,
                MODIFY COLUMN tool_name   VARCHAR(191) NOT NULL,
                MODIFY COLUMN module      VARCHAR(64)  NOT NULL,
                MODIFY COLUMN status      VARCHAR(32)  NOT NULL,
                MODIFY COLUMN http_status SMALLINT NULL,
                MODIFY COLUMN duration_ms INT UNSIGNED NULL,
                ADD COLUMN IF NOT EXISTS target_id VARCHAR(100) NULL AFTER target_type" );

            $wpdb->show_errors();
        }

        // v3.0: rename tables from wpops_mcp_* → acl_* (first rebrand).
        if ( version_compare( $current, '3.0', '<' ) ) {
            $wpdb->hide_errors();

            $map = [
                'wpops_mcp_api_keys' => 'acl_api_keys',
                'wpops_mcp_logs'     => 'acl_logs',
                'wpops_mcp_backups'  => 'acl_backups',
            ];
            foreach ( $map as $old => $new ) {
                $old_full = $wpdb->prefix . $old;
                $new_full = $wpdb->prefix . $new;
                if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old_full ) ) ) === $old_full ) {
                    $wpdb->query( 'RENAME TABLE `' . esc_sql( $old_full ) . '` TO `' . esc_sql( $new_full ) . '`' );
                }
            }

            $wpdb->show_errors();
        }

        // v4.1: add aicom_presets table for user-defined scope presets.
        // dbDelta in create_tables() handles the actual CREATE; nothing to ALTER here.

        // v4.2: add expires_at to api_keys for TTL / scheduled expiry.
        if ( version_compare( $current, '4.2', '<' ) ) {
            $wpdb->hide_errors();
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}aicom_api_keys
                ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL AFTER revoked_at" );
            $wpdb->show_errors();
        }

        // v4.3: add sessions table + session_id to logs and backups.
        if ( version_compare( $current, '4.3', '<' ) ) {
            $wpdb->hide_errors();
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}aicom_logs ADD COLUMN IF NOT EXISTS session_id BIGINT UNSIGNED NULL AFTER request_id" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}aicom_logs ADD INDEX IF NOT EXISTS idx_session_id (session_id)" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}aicom_backups ADD COLUMN IF NOT EXISTS session_id BIGINT UNSIGNED NULL AFTER request_id" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}aicom_backups ADD INDEX IF NOT EXISTS idx_session_id (session_id)" );
            $wpdb->show_errors();
        }

        // v4.5: add skills + skill_revisions tables (handled by dbDelta in create_tables).

        // v4.6: add aicom_hub_pairings + aicom_hub_nonces for the Hub↔Local
        // management channel (PRD §16). dbDelta in create_tables() handles the
        // CREATE; nothing to ALTER here.

        // v4.4: add tool_class to logs for graph color breakdown.
        if ( version_compare( $current, '4.4', '<' ) ) {
            $wpdb->hide_errors();
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}aicom_logs ADD COLUMN IF NOT EXISTS tool_class VARCHAR(30) NULL AFTER module" );
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}aicom_logs ADD INDEX IF NOT EXISTS idx_tool_class (tool_class)" );
            // Backfill existing rows using tool_name patterns.
            $t = $wpdb->prefix . 'aicom_logs';
            $wpdb->query( "UPDATE $t SET tool_class = 'public'          WHERE tool_class IS NULL AND tool_name = 'server.status'" );
            $wpdb->query( "UPDATE $t SET tool_class = 'discovery'       WHERE tool_class IS NULL AND tool_name IN ('tools/list','wp.post_types.list','wp.taxonomies.list')" );
            $wpdb->query( "UPDATE $t SET tool_class = 'admin_sensitive' WHERE tool_class IS NULL AND (tool_name LIKE 'wp.options.set%' OR tool_name LIKE 'wp.users.create%' OR tool_name LIKE 'wp.users.update%' OR tool_name LIKE 'wp.roles.%' OR tool_name = 'backup.cleanup')" );
            $wpdb->query( "UPDATE $t SET tool_class = 'destructive'     WHERE tool_class IS NULL AND tool_name LIKE '%.delete'" );
            $wpdb->query( "UPDATE $t SET tool_class = 'read'            WHERE tool_class IS NULL AND (tool_name LIKE '%.list' OR tool_name LIKE '%.get')" );
            $wpdb->query( "UPDATE $t SET tool_class = 'write'           WHERE tool_class IS NULL" );
            $wpdb->show_errors();
        }

        // v4.7: SHOW COLUMNS-based defensive migration. Earlier versions had a
        // race in install() where run_migrations() ran before create_tables(),
        // so on a fresh install the ALTER TABLE statements that should have
        // added session_id failed (table didn't exist yet), then create_tables
        // ran with an older schema that lacked session_id, then VERSION_OPT
        // was bumped to current — so the v4.3 migration above never ran again.
        // Result: anconav.ro and any other fresh install of 3.x had a logs
        // table missing session_id, which made every audit log insert fail
        // silently. This block fixes that regardless of stored version.
        self::ensure_column( 'aicom_logs',    'session_id', 'BIGINT UNSIGNED NULL AFTER request_id' );
        self::ensure_column( 'aicom_backups', 'session_id', 'BIGINT UNSIGNED NULL AFTER request_id' );

        // v4.8: add aicom_idempotency_keys for tool-call idempotency (handled by
        // dbDelta in create_tables) — brand-new table, nothing to ALTER here.

        // v4.0: rename tables from acl_* → aicom_* (second rebrand).
        if ( version_compare( $current, '4.0', '<' ) ) {
            $wpdb->hide_errors();

            $map = [
                'acl_api_keys' => 'aicom_api_keys',
                'acl_logs'     => 'aicom_logs',
                'acl_backups'  => 'aicom_backups',
            ];
            foreach ( $map as $old => $new ) {
                $old_full = $wpdb->prefix . $old;
                $new_full = $wpdb->prefix . $new;
                if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old_full ) ) ) === $old_full ) {
                    $wpdb->query( 'RENAME TABLE `' . esc_sql( $old_full ) . '` TO `' . esc_sql( $new_full ) . '`' );
                }
            }

            $wpdb->show_errors();
        }
    }

    /**
     * Idempotent column add. Checks SHOW COLUMNS (works on any MySQL/MariaDB
     * version, unlike `ADD COLUMN IF NOT EXISTS` which requires MySQL ≥ 8.0.29
     * or MariaDB). Also creates an index on the new column.
     *
     * @param string $table_short e.g. 'aicom_logs' (without prefix)
     * @param string $column      column name
     * @param string $spec        SQL type + position (e.g. 'BIGINT UNSIGNED NULL AFTER request_id')
     */
    private static function ensure_column( string $table_short, string $column, string $spec ): void {
        global $wpdb;
        $table = $wpdb->prefix . $table_short;

        // Table must exist; otherwise create_tables() will build it correctly
        // a moment later with the column included.
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( $table_exists !== $table ) {
            return;
        }

        $exists = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );
        if ( ! empty( $exists ) ) {
            return;
        }

        $wpdb->hide_errors();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table/column/spec are internal, not user input
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$spec}" );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `idx_{$column}` (`{$column}`)" );
        $wpdb->show_errors();
    }

    private static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        // ── API Keys ─────────────────────────────────────────────────────
        $sql_keys = "CREATE TABLE {$wpdb->prefix}aicom_api_keys (
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
            expires_at          DATETIME        NULL,
            PRIMARY KEY  (id),
            KEY idx_key_prefix (key_prefix),
            KEY idx_status     (status)
        ) $charset;";

        // ── Audit Logs ────────────────────────────────────────────────────
        $sql_logs = "CREATE TABLE {$wpdb->prefix}aicom_logs (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at          DATETIME        NOT NULL,
            request_id          VARCHAR(64)     NOT NULL,
            session_id          BIGINT UNSIGNED NULL,
            remote_ip           VARCHAR(45)     NOT NULL,
            api_key_id          BIGINT UNSIGNED NULL,
            api_key_label       VARCHAR(191)    NULL,
            tool_name           VARCHAR(191)    NOT NULL,
            module              VARCHAR(64)     NOT NULL,
            tool_class          VARCHAR(30)     NULL,
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
            KEY idx_request_id       (request_id),
            KEY idx_session_id       (session_id),
            KEY idx_tool_class       (tool_class)
        ) $charset;";

        // ── Backups ───────────────────────────────────────────────────────
        $sql_backups = "CREATE TABLE {$wpdb->prefix}aicom_backups (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at    DATETIME        NOT NULL,
            request_id    VARCHAR(64)     NULL,
            session_id    BIGINT UNSIGNED NULL,
            api_key_id    BIGINT UNSIGNED NULL,
            tool_name     VARCHAR(191)    NULL,
            target_type   VARCHAR(64)     NULL,
            target_id     VARCHAR(100)    NULL,
            manifest_json LONGTEXT        NULL,
            payload_json  LONGTEXT        NULL,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at),
            KEY idx_session_id (session_id),
            KEY idx_target     (target_type, target_id)
        ) $charset;";

        // ── Sessions ──────────────────────────────────────────────────────
        $sql_sessions = "CREATE TABLE {$wpdb->prefix}aicom_sessions (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            api_key_id    BIGINT UNSIGNED NOT NULL,
            api_key_label VARCHAR(191)    NOT NULL DEFAULT '',
            name          VARCHAR(255)    NOT NULL,
            description   TEXT            NULL,
            status        VARCHAR(20)     NOT NULL DEFAULT 'open',
            opened_at     DATETIME        NOT NULL,
            closed_at     DATETIME        NULL,
            PRIMARY KEY (id),
            KEY idx_api_key_status (api_key_id, status),
            KEY idx_opened_at      (opened_at)
        ) $charset;";

        // ── User Presets ──────────────────────────────────────────────────
        $sql_presets = "CREATE TABLE {$wpdb->prefix}aicom_presets (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name                VARCHAR(191)    NOT NULL,
            scopes_json         LONGTEXT        NOT NULL,
            created_at          DATETIME        NOT NULL,
            created_by_user_id  BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at)
        ) $charset;";

        // ── Skills ────────────────────────────────────────────────────────
        $sql_skills = "CREATE TABLE {$wpdb->prefix}aicom_skills (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug              VARCHAR(100)    NOT NULL,
            name              VARCHAR(255)    NOT NULL,
            description       TEXT            NULL,
            type              VARCHAR(30)     NOT NULL DEFAULT 'simple',
            status            VARCHAR(30)     NOT NULL DEFAULT 'draft',
            input_schema_json LONGTEXT        NULL,
            rules_json        LONGTEXT        NULL,
            steps_json        LONGTEXT        NULL,
            permissions_json  LONGTEXT        NULL,
            tags_json         LONGTEXT        NULL,
            logging_enabled   TINYINT(1)      NOT NULL DEFAULT 1,
            rollback_enabled  TINYINT(1)      NOT NULL DEFAULT 0,
            version           INT UNSIGNED    NOT NULL DEFAULT 1,
            parent_skill_id   BIGINT UNSIGNED NULL,
            session_id        BIGINT UNSIGNED NULL,
            created_by_key_id BIGINT UNSIGNED NULL,
            created_at        DATETIME        NOT NULL,
            updated_at        DATETIME        NOT NULL,
            archived_at       DATETIME        NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_slug (slug),
            KEY idx_status (status),
            KEY idx_type   (type)
        ) $charset;";

        // ── Skill Revisions ───────────────────────────────────────────────
        $sql_skill_revisions = "CREATE TABLE {$wpdb->prefix}aicom_skill_revisions (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            skill_id          BIGINT UNSIGNED NOT NULL,
            version           INT UNSIGNED    NOT NULL,
            snapshot_json     LONGTEXT        NOT NULL,
            changed_by_key_id BIGINT UNSIGNED NULL,
            created_at        DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_skill_version (skill_id, version)
        ) $charset;";

        // ── Hub Pairings ──────────────────────────────────────────────────
        // One row per AICOM Hub paired with this site. management_secret is
        // sodium_crypto_secretbox-encrypted (key = wp_salt('secure_auth')).
        $sql_hub_pairings = "CREATE TABLE {$wpdb->prefix}aicom_hub_pairings (
            id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hub_id                      VARCHAR(64)     NOT NULL,
            hub_url                     VARCHAR(255)    NOT NULL,
            management_key_id           VARCHAR(64)     NOT NULL,
            management_secret_encrypted LONGTEXT        NOT NULL,
            paired_at                   DATETIME        NOT NULL,
            last_seen                   DATETIME        NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_hub (hub_id),
            KEY idx_key_id (management_key_id)
        ) $charset;";

        // ── Hub Nonces (replay protection for /management) ────────────────
        $sql_hub_nonces = "CREATE TABLE {$wpdb->prefix}aicom_hub_nonces (
            key_id  VARCHAR(64) NOT NULL,
            nonce   VARCHAR(64) NOT NULL,
            seen_at DATETIME    NOT NULL,
            PRIMARY KEY (key_id, nonce),
            KEY idx_seen (seen_at)
        ) $charset;";

        // ── Idempotency Keys ────────────────────────────────────────────────
        // Dedupe for retried write/destructive/admin_sensitive tool calls that
        // pass an idempotency_key argument. Claimed atomically via INSERT IGNORE
        // on the composite PK, same pattern as aicom_hub_nonces above.
        $sql_idempotency = "CREATE TABLE {$wpdb->prefix}aicom_idempotency_keys (
            api_key_id      BIGINT UNSIGNED NOT NULL,
            idempotency_key VARCHAR(191)    NOT NULL,
            tool_name       VARCHAR(191)    NOT NULL,
            args_hash       CHAR(64)        NOT NULL,
            status          VARCHAR(20)     NOT NULL DEFAULT 'in_progress',
            result_json     LONGTEXT        NULL,
            created_at      DATETIME        NOT NULL,
            PRIMARY KEY (api_key_id, idempotency_key),
            KEY idx_created (created_at)
        ) $charset;";

        dbDelta( $sql_keys );
        dbDelta( $sql_logs );
        dbDelta( $sql_backups );
        dbDelta( $sql_sessions );
        dbDelta( $sql_presets );
        dbDelta( $sql_skills );
        dbDelta( $sql_skill_revisions );
        dbDelta( $sql_hub_pairings );
        dbDelta( $sql_hub_nonces );
        dbDelta( $sql_idempotency );
    }
}
