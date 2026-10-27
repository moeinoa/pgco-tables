<?php

/**
 * Plugin Name: PGCO Tables
 * Plugin URI: https://github.com/moeinoa/pgco-tables
 * Description: Plugin to retrieve and save data from Google Sheets and display tables on the website
 * Version: 2.1.1
 * Author: Moein Akbari
 * Author URI: https://moein-akbari.ir/
 * Text Domain: pgco-tables
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.0
 */


if (!defined('ABSPATH')) {
    exit;
}

use PGCO_Tables\Enqueue;
use PGCO_Tables\Database;
use PGCO_Tables\PostType;
use PGCO_Tables\Shortcode;
use PGCO_Tables\Sync;
use PGCO_Tables\Admin\SyncPage;
use PGCO_Tables\Debug\Debugger;

// Define plugin constants
define('PGCO_TABLES_VERSION', '2.1.1');
define('PGCO_TABLES_FILE', __FILE__);
define('PGCO_TABLES_DIR', plugin_dir_path(PGCO_TABLES_FILE));
define('PGCO_TABLES_URL', plugin_dir_url(PGCO_TABLES_FILE));
define('PGCO_TABLES_BASENAME', plugin_basename(PGCO_TABLES_FILE));

final class PGCO_Tables
{
    /**
     * Single instance of the class
     */
    private static ?PGCO_Tables $instance = null;

    /**
     * Reference to the PSR‑4 style autoloader used by the plugin.
     */
    private ?PGCO_Tables\Autoloader $autoloader = null;

    /**
     * Get plugin instance
     *
     * @return self
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        self::$instance->autoload();
        self::$instance->hooks();
        self::$instance->components();
        self::$instance->init();

        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {}

    /**
     * Prevent cloning
     */
    public function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize singleton');
    }

    /**
     * Register plugin autoloader
     *
     * @return void
     */
    public function autoload(): void
    {
        require_once PGCO_TABLES_DIR . 'vendor/Autoloader.php';

        if ($this->autoloader instanceof PGCO_Tables\Autoloader) {
            return;
        }

        $this->autoloader = new PGCO_Tables\Autoloader();
        $this->autoloader->register();

        // Map main namespace to includes folder
        $this->autoloader->namespace('PGCO_Tables', PGCO_TABLES_DIR . 'includes');
    }

    /**
     * Register plugin hooks
     *
     * @return void
     */
    public function hooks(): void
    {
        register_activation_hook(PGCO_TABLES_FILE, [$this, 'activate']);
        register_deactivation_hook(PGCO_TABLES_FILE, [$this, 'deactivate']);
        add_action('init', [$this, 'translations']);
        add_action('plugins_loaded', [$this, 'maybe_upgrade']);
    }

    /**
     * Ensure the sync_logs table exists even on sites where the plugin was
     * already active before this table was introduced (activation hook
     * only runs once, on (re)activation).
     *
     * @return void
     */
    public function maybe_upgrade(): void
    {
        $installed_version = get_option('pgco_tables_db_version', '0.0.0');

        if (version_compare($installed_version, PGCO_TABLES_VERSION, '<')) {
            Database::instance()->create();
            update_option('pgco_tables_db_version', PGCO_TABLES_VERSION);
        }
    }

    /**
     * Initialize the plugin
     *
     * @return void
     */
    public function init(): void
    {        
        PostType::instance();
        Enqueue::instance();
        Shortcode::instance();
        Sync::instance();
        SyncPage::instance();
        Debugger::instance();
    }

    /**
     * Initialize the plugin components
     *
     * @return void
     */
    public function components(): void
    {
        // Initialize the plugin
    }

    /**
     * Plugin activation
     *
     * @return void
     */
    public function activate(): void
    {
        // Create tables
        Database::instance()->create();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     *
     * @return void
     */
    public function deactivate(): void
    {
        wp_clear_scheduled_hook('pgco_tables_debug_log_cleanup');

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    public function translations(): void
    {
        load_plugin_textdomain('pgco-tables', false, dirname(PGCO_TABLES_BASENAME) . '/languages');

        load_script_textdomain('pgco-tables-admin', 'pgco-tables', PGCO_TABLES_DIR . 'languages');
    }
}

/**
 * Get plugin instance
 *
 * @return PGCO_Tables
 */
function pgco_tables(): PGCO_Tables
{
    return PGCO_Tables::instance();
}

// Initialize plugin
pgco_tables();