<?php
/**
 * class-revisr-db.php
 *
 * The base database class, acting as a factory via run() for
 * backups and imports. Also contains some methods shared
 * across all database operations.
 *
 * @package   	Revisr
 * @license   	GPLv3
 * @link      	https://revisr.io
 * @copyright 	Expanded Fronts, LLC
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) exit;

class Revisr_DB {

	/**
	 * The backup directory.
	 * @var string
	 */
	protected $backup_dir;

	/**
	 * The WordPress database class.
	 * @var WPDB
	 */
	protected $wpdb;

	/**
	 * Constructs the class.
	 * @access public
	 */
	public function __construct() {

		// Make WPDB available to the class.
		global $wpdb;
		$this->wpdb = $wpdb;

		$upload_dir = wp_upload_dir();

		$this->backup_dir = $upload_dir['basedir'] . '/revisr-backups/';

		// Set up the "revisr_backups" directory if necessary.
		$this->setup_env();

	}

	/**
	 * Runs an action on all tracked database tables.
	 *
	 * @access private
	 * @param  string 			$action 	The action to perform.
	 * @param  array 			$tables 	The tables to run on (optional).
	 * @param  string|array 	$args 		Any arguements to pass through (optional).
	 * @return boolean
	 */
	private function run( $action, $tables = array(), $args = '' ) {

		// An empty status array to update later.
		$status 	= array();

		// Builds the name of the class to construct.
		$class 		= 'Revisr_DB_' . $action;

		// Builds the name of the method to call.
		$method 	= $action . '_table';

		// The tables we want to run the action on.
		$tables 	= $tables ? $tables : $this->get_tracked_tables();

		if ( is_callable( array( $class, $method ) ) ) {

			// Construct the class.
			$class = new $class;

			/**
			 * Loop through the tracked tables,
			 * storing the results in the status array.
			 */
			foreach ( $tables as $table ) {
				$status[$table] = $class->$method( $table, $args );
			}

			// Fire off the callback.
			return $class->callback( $status );

		}

	}

	/**
	 * Returns an array of tables in the database.
	 * @access public
	 * @return array
	 */
	public function get_tables() {
		$tables = $this->wpdb->get_col( 'SHOW TABLES' );
		return $tables;
	}

	/**
	 * Returns an array containing the size of each database table.
	 * @access public
	 * @return array
	 */
	public function get_sizes() {
		$sizes 	= array();
		$tables = $this->wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		if ( is_array( $tables ) ) {

			foreach ( $tables as $table ) {
				$size = round( $table['Data_length'] / 1024 / 1024, 2 );
				$sizes[$table['Name']] = sprintf( __( '(%s MB)', 'revisr' ), $size );
			}

		}

		return $sizes;
	}

	/**
	 * Returns a list of tables that are in the "revisr-backups" directory,
	 * but not in the database. Necessary for importing tables that could
	 * not be added to the tracked tables due to them not existing.
	 * @access public
	 * @return array
	 */
	public function get_tables_not_in_db() {
		$backup_tables	= array();
		$db_tables 		= $this->get_tables();

		foreach ( scandir( $this->backup_dir ) as $file ) {

			if ( 'revisr_' !== substr( $file, 0, 7 ) ) {
				continue;
			}

			// Remove the prefix and extension.
	        $backup_tables[] 	= substr( substr( $file, 7 ), 0, -4 );
    	}

    	$new_tables = array_diff( $backup_tables, $db_tables );
    	return $new_tables;
	}

	/**
	 * Returns the array of tables that are to be tracked.
	 * @access public
	 * @return array
	 */
	public function get_tracked_tables() {

		// Get our saved tracking preferences from the .git/config.
		$db_tracking 	= revisr()->git->get_config( 'revisr', 'db-tracking' );
		$stored_tables 	= revisr()->git->run( 'config', array( '--get-all', 'revisr.tracked-tables' ) );

		// Determine which tables we want to track.
		if ( 'all_tables' == $db_tracking ) {
			$tracked_tables = $this->get_tables();
		} elseif ( 'custom' == $db_tracking && is_array( $stored_tables ) ) {
			$tracked_tables = array_intersect( $stored_tables, $this->get_tables() );
		} elseif ( 'none' == $db_tracking) {
			$tracked_tables = array();
		} else {
			$tracked_tables = $this->get_tables();
		}

		// Return an array of tables we should track.
		return $tracked_tables;
	}

	/**
	 * Adds a table to version control.
	 * @access private
	 * @param  string $table The table to add.
	 */
	protected function add_table( $table ) {
		revisr()->git->run( 'add', array( $this->backup_dir . 'revisr_' . $table. '.sql' ) );
	}

	/**
	 * Creates the backup folder and adds the .htaccess if necessary.
	 * @access private
	 */
	private function setup_env() {
		// Check if the backups directory has already been created.
		if ( is_dir( $this->backup_dir ) ) {
			return true;
		} else {
			// Make the backups directory.
			mkdir( $this->backup_dir );

			// Add .htaccess to prevent direct access.
			$htaccess_content = '<FilesMatch "\.sql">' .
			PHP_EOL . 'Order allow,deny' .
			PHP_EOL . 'Deny from all' .
			PHP_EOL . 'Satisfy All' .
			PHP_EOL . '</FilesMatch>';
			file_put_contents( $this->backup_dir . '/.htaccess', $htaccess_content );

			// Add index.php to prevent directory listing.
			$index_content = '<?php // Silence is golden' . PHP_EOL;
			file_put_contents( $this->backup_dir . '/index.php', $index_content );
		}
	}

	/**
	 * Mimics the mysql_real_escape_string function. Adapted from a post by 'feedr' on php.net.
	 * @link   http://php.net/manual/en/function.mysql-real-escape-string.php#101248
	 * @access public
	 * @param  string $input The string to escape.
	 */
	public function mysql_escape_mimic( $input ) {

	    if ( is_array( $input ) ) {
	        return array_map( __METHOD__, $input );
	    }

	    if( ! empty( $input ) && is_string( $input ) ) {
	        return str_replace( array( '\\', "\0", "\n", "\r", "'", '"', "\x1a" ), array( '\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z' ), $input );
	    }

	    return $input;
	}

	/**
	 * Callback for database backups (AJAX button and via New Commit)
	 * @access public
	 * @param  string  $commit_msg A commit message to pass to $this->commit_db() (optional)
	 * @param  boolean $commit_db  Whether or not to commit the DB immediately.
	 * @return boolean
	 */
	public function backup( $commit_msg = '', $commit_db = true ) {

		// Get the tables to backup.
		$tables = $this->get_tracked_tables();
		if ( empty( $tables ) ) {
			$tables = $this->get_tables();
		}

		// Run the backup.
		if ( $this->run( 'backup', $tables ) ) {

			// Commit the backed up database tables if necessary.
			if ( true === $commit_db ) {
				$this->commit_db( $commit_msg );
			}

			return true;
		}

		return false;
	}

	/**
	 * Reverts all tracked tables to an earlier commit.
	 * @access public
	 * @param  boolean $redirect Whether or not to redirect via PHP.
	 */
	public function restore( $redirect = true ) {

		// Make sure we really want to do this.
		if ( ! wp_verify_nonce( $_REQUEST['revisr_revert_nonce'], 'revisr_revert_nonce' ) ) {
			wp_die( __( 'Cheatin&#8217; uh?', 'revisr' ) );
		}

		$commit = $_REQUEST['db_hash'];

		/**
		 * Backup the database and store the resulting commit hash
		 * in memory so we can use it to create the revert link later.
		 */
		$msg = sprintf( __( 'Database backup before revert to #%s.', 'revisr' ), $commit );
		$this->backup( $msg );
		$current_temp = revisr()->git->current_commit();

		if ( $current_temp ) {

			/**
			 * If the backup was successful and we have the resulting commit hash,
			 * run a revert on the sql files for the tracked tables and import them.
			 */
			$tables 	= $this->get_tracked_tables();
			$checkout 	= array();

			foreach ( $tables as $table ) {
				$checkout[$table] = revisr()->git->run( 'checkout', array( $commit, "{$this->backup_dir}revisr_$table.sql" ) );
			}

			if ( ! in_array( 1, $checkout ) ) {

				$import = $this->import();

				if ( $import ) {

					// Create a link to undo the revert if necessary.
					$undo_nonce = wp_nonce_url( admin_url( "admin-post.php?action=process_revert&revert_type=db&db_hash=" . $current_temp ), 'revisr_revert_nonce', 'revisr_revert_nonce' );
					$undo_msg 	= sprintf( __( 'Successfully reverted the database to a previous commit. <a href="%s">Undo</a>', 'revisr' ), $undo_nonce );

					// Store the undo link and alert the user.
					Revisr_Admin::log( $undo_msg, 'revert' );
					Revisr_Admin::alert( $undo_msg );

				}

			} else {

				// There was an error importing the database.
				$msg = __( 'Error reverting one or more database tables.', 'revisr' );
				Revisr_Admin::log( $msg, 'error' );
				Revisr_Admin::alert( $msg, true );

			}

			// Redirect if necessary.
			if ( $redirect !== false ) {
				wp_redirect( get_admin_url() . 'admin.php?page=revisr' );
				exit();
			}

		} else {
			wp_die( __( 'Something went wrong. Check your settings and try again.', 'revisr' ) );
		}

	}

	/**
	 * Runs an import of all tracked tables, importing any new tables
	 * if tracking all_tables, or providing a link to import new tables
	 * if necessary.
	 * @access public
	 * @param  array $tables The tables to import.
	 * @return boolean
	 */
	public function import( $tables = array() ) {
		// The tables currently being tracked.
		$tracked_tables = $this->get_tracked_tables();

		// Tables that have import files but aren't in the database.
		$new_tables 	= $this->get_tables_not_in_db();

		// All tables.
		$all_tables		= array_unique( array_merge( $new_tables, $tracked_tables ) );

		// The URL to replace during import.
		$replace_url 	= revisr()->git->get_config( 'revisr', 'dev-url' ) ? revisr()->git->get_config( 'revisr', 'dev-url' ) : '';

		if ( empty( $tables ) ) {

			if ( ! empty( $new_tables ) ) {
				// If there are new tables that were imported.
				if ( isset( revisr()->options['db_tracking'] ) && revisr()->options['db_tracking'] == 'all_tables' ) {
					// If the user is tracking all tables, import all tables.
					$import = $this->run( 'import', $all_tables, $replace_url );
				} else {
					// Import only tracked tables, but provide a warning and import link.
					$import = $this->run( 'import', $tracked_tables, $replace_url );
					$url = wp_nonce_url( get_admin_url() . 'admin-post.php?action=revisr_import_tables_form&TB_iframe=true&width=400&height=225', 'import_table_form', 'import_nonce' );
					$msg = sprintf( __( 'New database tables detected. <a class="thickbox" title="Import Tables" href="%s">Click here</a> to view and import.', 'revisr' ), $url );
					Revisr_Admin::log( $msg, 'db' );
				}
			} else {
				// If there are no new tables, go ahead and import the tracked tables.
				$import = $this->run( 'import', $tracked_tables, $replace_url );
			}

		} else {
			// Import the provided tables.
			$import = $this->run( 'import', $tables, $replace_url );
		}

		return $import;
	}

	/**
	 * Commits the database to the repository and pushes if needed.
	 * @access public
	 * @param  string $commit_msg A custom commit message.
	 */
	public function commit_db( $commit_msg = '' ) {

		if ( '' === $commit_msg ) {
			$commit_msg  = __( 'Backed up the database with Revisr.', 'revisr' );
		}

		// Allow for overriding default commit message through the "New Commit" screen.
		if ( isset( $_REQUEST['post_title'] ) && $_REQUEST['post_title'] !== '' ) {
			$commit_msg = $_REQUEST['post_title'];
		}

		// Make the commit.
		revisr()->git->commit( $commit_msg );

		// Push changes if necessary.
		revisr()->git->auto_push();
	}

	/**
	 * Verifies a backup for a table.
	 * @access public
	 * @param  string $table The table to check.
	 * @return boolean
	 */
	public function verify_backup( $table ) {
		if ( ! file_exists( "{$this->backup_dir}revisr_$table.sql" ) || filesize( "{$this->backup_dir}revisr_$table.sql" ) < 100 ) {
			return false;
		}
		return true;
	}

}
