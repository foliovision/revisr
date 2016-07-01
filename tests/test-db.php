<?php

class RevisrDBTest extends WP_UnitTestCase {

	/**
	 * The Revisr instance.
	 */
	protected $revisr;

	/**
	 * Initialize the database object.
	 */
	function setUp() {
		$this->revisr 		= revisr();
		$this->revisr->git 	= new Revisr_Git();
		$this->revisr->db 	= new Revisr_DB();
	}

	/**
	 * Tests the setup_env() method.
	 */
	function test_setup_env() {
		$this->assertFileExists( ABSPATH . 'wp-content/uploads/revisr-backups/.htaccess' );
		$this->assertFileExists( ABSPATH . 'wp-content/uploads/revisr-backups/index.php' );
	}

	/**
	 * Tests the get_tables() method.
	 */
	function test_get_tables() {
		$tables = serialize( $this->revisr->db->get_tables() );
		$this->assertContains( '_posts', $tables );
		$this->assertContains( '_revisr', $tables );

	}

	/**
	 * Tests the get_sizes() method.
	 */
	function test_get_sizes() {
		global $wpdb;
		$sizes 	= $this->revisr->db->get_sizes();
		$key 	= $wpdb->prefix . 'posts';
		$this->assertArrayHasKey($key, $sizes );
		$this->assertContains( 'MB)', $sizes[$key] );
	}

	/**
	 * Tests the get_tables_not_in_db() method.
	 */
	function test_get_tables_not_in_db() {
		file_put_contents( ABSPATH . 'wp-content/uploads/revisr-backups/revisr_faketable.sql', 'test' );
		$tables = serialize( $this->revisr->db->get_tables_not_in_db() );
		$this->assertContains( 'faketable', $tables );
	}

	/**
	 * Tests a database backup.
	 */
	function test_backup() {
		$this->revisr->git->set_config( 'revisr', 'db-tracking', 'all_tables' );
		$this->revisr->db->backup();
		$this->assertFileExists( ABSPATH . 'wp-content/uploads/revisr-backups/revisr_wptests_posts.sql' );
	}

	/**
	 * Tests the verify_backup() function.
	 */
	function test_verify_backup() {
		$verify = $this->revisr->db->verify_backup( 'wptests_posts' );
		$this->assertEquals( true, $verify );
	}
}
