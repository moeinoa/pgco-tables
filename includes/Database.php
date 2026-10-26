<?php

namespace PGCO_Tables;

defined('ABSPATH') || exit;

/**
 * Database handler class
 */
class Database
{
    /**
     * Single instance of the class
     */
    private static ?Database $instance = null;

    private object $database;

    private string $charset;

    private array $tables;

    /**
     * Get instance
     *
     * @return self
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        global $wpdb;

        // Set database object
        $this->database = $wpdb;

        // Set database charset
        $this->charset = $this->database->get_charset_collate();

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
    }

    /**
     * Define database tables
     *
     * @return void
     */
    public function tables(): void
    {
        $this->tables = [
            'tables' => [
                'post' => [
                    'bigint(20)',
                    'UNSIGNED',
                    'NOT NULL',
                ],
                'content' => [
                    'longtext',
                    'NOT NULL',
                ],

            ],
            'sync_logs' => [
                'post' => [
                    'bigint(20)',
                    'UNSIGNED',
                    'NOT NULL',
                ],
                'status' => [
                    "varchar(20)",
                    'NOT NULL',
                ],
                'message' => [
                    'text',
                    'NULL',
                ],
                'rows_count' => [
                    'int(11)',
                    'NULL',
                ],
                'created_at' => [
                    'datetime',
                    'NOT NULL',
                ],
            ],
        ];

        foreach ($this->tables as $table => $columns) {
            $this->tables[$table] = array_merge([
                'ID' => [
                    'bigint(20)',
                    'UNSIGNED',
                    'NOT NULL',
                    'AUTO_INCREMENT',
                ],
            ], $this->tables[$table]);

            $this->tables[$table] = array_merge($this->tables[$table], [
                'PRIMARY' => [
                    'KEY (ID)',
                ],
            ]);
        }
    }

    /**
     * Create database tables
     *
     * @return void
     */
    public function create(): void
    {
        $this->tables();

        foreach ($this->tables as $table => $columns) {
            $sql = "CREATE TABLE IF NOT EXISTS `{$this->database->prefix}pgco_$table` (";
            foreach ($columns as $column => $value) {
                $sql .= "$column ";
                $sql .= implode(' ', $value);
                $sql .= ', ';
            }
            $sql = rtrim($sql, ', ');
            $sql .= ") {$this->charset};";

            dbDelta($sql);
        }
    }

    public function drop(): void
    {
        $this->tables();
        
        foreach ($this->tables as $table => $columns) {
            $sql = "DROP TABLE IF EXISTS `{$this->database->prefix}pgco_$table`";
            $this->database->query($sql);
            
            dbDelta($sql);
        }
    }

    /**
     * Insert a sync log record for a given table post.
     *
     * @param int    $post_id
     * @param string $status  'success' | 'error'
     * @param string $message
     * @param int|null $rows_count
     * @return void
     */
    public function log(int $post_id, string $status, string $message = '', ?int $rows_count = null): void
    {
        $this->database->insert(
            "{$this->database->prefix}pgco_sync_logs",
            [
                'post'       => $post_id,
                'status'     => $status,
                'message'    => $message,
                'rows_count' => $rows_count,
                'created_at' => current_time('mysql'),
            ],
            [
                '%d',
                '%s',
                '%s',
                '%d',
                '%s',
            ]
        );

        // Keep log table from growing unbounded: retain latest 200 rows per post.
        $this->trim_logs($post_id);
    }

    /**
     * Trim log history for a single post, keeping only the most recent N rows.
     *
     * @param int $post_id
     * @param int $keep
     * @return void
     */
    private function trim_logs(int $post_id, int $keep = 200): void
    {
        $table = "{$this->database->prefix}pgco_sync_logs";

        $this->database->query(
            $this->database->prepare(
                "DELETE FROM `$table` WHERE post = %d AND ID NOT IN (
                    SELECT ID FROM (
                        SELECT ID FROM `$table` WHERE post = %d ORDER BY created_at DESC, ID DESC LIMIT %d
                    ) AS keep_ids
                )",
                $post_id,
                $post_id,
                $keep
            )
        );
    }

    /**
     * Get recent sync logs for a single table post.
     *
     * @param int $post_id
     * @param int $limit
     * @return array
     */
    public function logs_for(int $post_id, int $limit = 20): array
    {
        $table = "{$this->database->prefix}pgco_sync_logs";

        return $this->database->get_results(
            $this->database->prepare(
                "SELECT * FROM `$table` WHERE post = %d ORDER BY created_at DESC, ID DESC LIMIT %d",
                $post_id,
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get the most recent log entry per table post (for the overview page).
     *
     * @param int $limit Number of distinct posts to fetch the latest log for.
     * @return array
     */
    public function latest_logs(int $limit = 100): array
    {
        $table = "{$this->database->prefix}pgco_sync_logs";

        $sql = "SELECT l1.* FROM `$table` l1
                INNER JOIN (
                    SELECT post, MAX(created_at) AS max_created, MAX(ID) AS max_id
                    FROM `$table`
                    GROUP BY post
                ) l2 ON l1.post = l2.post AND l1.ID = l2.max_id
                ORDER BY l1.created_at DESC
                LIMIT %d";

        return $this->database->get_results(
            $this->database->prepare($sql, $limit),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get all logs across all tables (for a global log view), most recent first.
     *
     * @param int $limit
     * @return array
     */
    public function all_logs(int $limit = 300): array
    {
        $table = "{$this->database->prefix}pgco_sync_logs";

        return $this->database->get_results(
            $this->database->prepare(
                "SELECT * FROM `$table` ORDER BY created_at DESC, ID DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }
}
