<?php
/**
 * class-revisr-meta-boxes.php
 *
 * Configures custom metaboxes for the plugin.
 *
 * @package   	Revisr
 * @license   	GPLv3
 * @link      	https://revisr.io
 * @copyright 	Expanded Fronts, LLC
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) exit;

class Revisr_Meta_Boxes {

	/**
	 * Adds actions responsible for triggering our custom meta boxes.
	 * @access public
	 */
	public function add_meta_box_actions() {

		do_action( 'add_meta_boxes_admin_page_revisr_new_commit', null );
		do_action( 'add_meta_boxes', 'admin_page_revisr_new_commit', null );
		do_action( 'add_meta_boxes_admin_page_revisr_view_commit', null );
		do_action( 'add_meta_boxes', 'admin_page_revisr_view_commit', null );

		wp_enqueue_script( 'postbox' );

		add_screen_option( 'layout_columns', array( 'max' => 2, 'default' => 2 ) );
	}

	/**
	 * Initializes JS for the Revisr custom meta boxes.
	 * @access public
	 */
	public function init_meta_boxes() {
		?>
 		<script>jQuery(document).ready(function(){ postboxes.add_postbox_toggles(pagenow); });</script>
		<?php
	}

	/**
	 * Adds/removes meta boxes for the "revisr_commits" post type.
	 * @access public
	 */
	public function post_meta() {
		add_meta_box( 'revisr_committed_files', __( 'Committed Files', 'revisr' ), array( $this, 'committed_files_meta' ), 'admin_page_revisr_view_commit', 'normal', 'high' );
		add_meta_box( 'revisr_view_commit', __( 'Commit Details', 'revisr' ), array( $this, 'view_commit_meta' ), 'admin_page_revisr_view_commit', 'side', 'core' );
		add_meta_box( 'revisr_pending_files', __( 'Stage Changes', 'revisr' ), array( $this, 'pending_files_meta' ), 'admin_page_revisr_new_commit', 'normal', 'high' );
		add_meta_box( 'revisr_add_tag', __( 'Add Tag', 'revisr' ), array( $this, 'add_tag_meta' ), 'admin_page_revisr_new_commit', 'side', 'default' );
		add_meta_box( 'revisr_save_commit', __( 'Save Commit', 'revisr' ), array( $this, 'save_commit_meta' ), 'admin_page_revisr_new_commit', 'side', 'core' );
	}

	/**
	 * Shows a list of the pending files on the current branch. Clicking a modified file shows the diff.
	 * @access public
	 */
	public function pending_files() {
		check_ajax_referer( 'staging_nonce', 'security' );
		$output 		= revisr()->git->status();
		$total_pending 	= count( $output );
    $commit_items = array(); // Categorize the changed files into categories for automated commit message creation
    $unstaged = array(); // Store changes that do not match wp-content/plugins/plugin-name/ or wp-content/plugins/plugin-name.php
		$warnings = array();
    $text = sprintf( __( 'There are <strong>%s</strong> untracked files that can be added to this commit.', 'revisr' ), $total_pending, revisr()->git->branch );
		echo "<br>" . $text . "<br><br>";
		_e( 'Use the boxes below to select the files to include in this commit. Only files in the "Staged Files" section will be included.<br>Double-click files marked as "Modified" to view the changes to the file.<br><br>', 'revisr' );
		if ( is_array( $output ) ) {
				?>
				<!-- Staging -->
				<div class="stage-container">
					<p><strong><?php _e( 'Staged Files', 'revisr' ); ?></strong></p>
					<select id='staged' multiple="multiple" name="staged_files[]" size="15" style="resize: vertical;">
					<?php
					// Clean up output from git status and echo the results.
					foreach ( $output as $result ) {

						if ( stripos( $result, 'warning: ' ) !== false ) {
							$warnings[] = $result;
							continue;
						}

						$result 		= str_replace( '"', '', $result );
						$short_status 	= substr( $result, 0, 3 );
						$file 			= substr( $result, 3 );
            $status 		= Revisr_Git::get_status( $short_status );
            $item = "<option class='pending' value='{$result}'>{$file} [{$status}]</option>";
            
            if ( preg_match('/wp-content\/plugins\/((.*?)\/|(.*?)\.php)/', $file, $match) ) { // Match plugin name, example : wp-content/plugins/plugin-name/ or wp-content/plugins/plugin-name.php
              $plugin_matched = !empty($match[2]) ? $match[2] : $match[3];
              
              if( $status == 'Untracked' && isset($commit_items['Modified']) ) { // New file or folder created in existing plugin is not added to commit_items if plugin name is in modified
                if(in_array($plugin_matched, $commit_items['Modified'])) {
                  echo $item;
                  continue;
                }
              }
              
              if( !isset($commit_items[$status]) ) { // No status yet
                $commit_items[$status][] = $plugin_matched;
              } else if( !in_array($plugin_matched, $commit_items[$status]) ) { // Prevent duplicates
                $commit_items[$status][] = $plugin_matched; 
              }
              
              echo $item;
            } else { // No plugin matched, move to unstaged
              $unstaged[] = $item;
            }
						
					}
					?>
					</select>
					<div class="stage-nav">
						<input id="unstage-file" type="button" class="button button-primary stage-nav-button" value="<?php _e( 'Unstage Selected', 'revisr' ); ?>" onclick="unstage_file()" />
						<br>
						<input id="unstage-all" type="button" class="button stage-nav-button" value="<?php _e( 'Unstage All', 'revisr' ); ?>" onclick="unstage_all()" />
					</div>
				</div><!-- /Staging -->
				<br>

				<!-- Unstaging -->
				<div class="stage-container">
					<p><strong><?php _e( 'Unstaged Files', 'revisr' ); ?></strong></p>
					<select id="unstaged" multiple="multiple" name="unstaged_files[]" size="15" style="resize: vertical;">
            <?php
            // Echo all files that are in unstaged array
              if( !empty($unstaged) ) { 
                foreach( $unstaged as $option ) {
                  echo $option;
                }
              } 
            ?>
					</select>
					<div class="stage-nav">
						<input id="stage-file" type="button" class="button button-primary stage-nav-button" value="<?php _e( 'Stage Selected', 'revisr' ); ?>" onclick="stage_file()" />
						<br>
						<input id="stage-all" type="button" class="button stage-nav-button" value="<?php _e( 'Stage All', 'revisr' ); ?>" onclick="stage_all()" />
					</div>
				</div><!-- /Unstaging -->

        <?php
        // Improve commit message
          $commit_msg = "";
          foreach( $commit_items as $status => $plugins ) {
            switch($status) {
              case 'Modified':
                $commit_msg .=" Plugin updates";
              break;
              case 'Untracked':
                $commit_msg .=" New plugins";
              break;
              case 'Deleted':
                $commit_msg .=" Deleted plugins";
              break;
              default:
              $commit_msg .=" " . $status; 
            }
            $commit_msg .= " - " . implode(", ", $plugins); // Build commit message from plugin names and statuses
          }
          $commit_msg = trim($commit_msg);
        ?>

        <!-- New input for commit msg -->
        <input type="text" name="post_title" size="30" value="<?php echo $commit_msg; ?>" id="title-tmp" spellcheck="true" autocomplete="off" placeholder="Enter a message for your commit">

				<?php if ( ! empty( $warnings ) ) : ?>
					<p><strong><?php _e( 'Warnings:', 'revisr' ); ?></strong></p>
					<?php echo implode( '<br />', $warnings ); ?>
				<?php endif; ?>

			<?php
		}
		exit();
	}

	/**
	 * Shows the files that were added in a given commit.
	 * @access public
	 */
	public function committed_files_meta() {

		// Get details about the commit.
		$commit_hash 	= isset( $_GET['commit'] ) ? esc_attr( $_GET['commit'] ) : '';
		$commit 		= Revisr_Git::get_commit_details( $commit_hash );

		// Start outputting the metabox.
		echo '<div id="message"></div><div id="committed_files_result">';

		// Files were included in this commit.
		if ( 0 !== count( $commit['committed_files'] ) ) {

			printf( __('<br><strong>%s</strong> files were included in this commit. Double-click files marked as "Modified" to view the changes in a diff.', 'revisr' ), $commit['files_changed'] );

			echo '<input id="commit_hash" name="commit_hash" value="' . $commit['hash'] . '" type="hidden" />';
			echo '<br><br><select id="committed" multiple="multiple" size="6">';

				// Display the files that were included in the commit.
				foreach ( $commit['committed_files'] as $result ) {

					$result 		= str_replace( '"', '', $result );
					$short_status 	= substr( $result, 0, 3 );
					$file 			= substr( $result, 2 );
					$status 		= Revisr_Git::get_status( $short_status );

					printf( '<option class="committed" value="%s">%s [%s]</option>', $result, $file, $status );
				}

			echo '</select>';

		} else {
			printf( '<p>%s</p>', __( 'No files were included in this commit.', 'revisr' ) );
		}

		echo '</div>';

	}

	/**
	 * Displays the "Add Tag" meta box on the sidebar.
	 * @access public
	 */
	public function add_tag_meta() {
		printf(
			'<label for="tag_name"><p>%s</p></label>
			<input id="tag_name" type="text" name="tag_name" />',
			__( 'Tag Name:', 'revisr' )
		);
	}

	/**
	 * Displays the "Save Commit" meta box in the sidebar.
	 * @access public
	 */
	public function save_commit_meta() {
		?>

		<div id="minor-publishing">
			<div id="misc-publishing-actions">

				<div class="misc-pub-section revisr-pub-status">
					<label for="post_status"><?php _e( 'Status:', 'revisr' ); ?></label>
					<span><strong><?php _e( 'Pending', 'revisr' ); ?></strong></span>
				</div>

				<div class="misc-pub-section revisr-pub-branch">
					<label for="revisr-branch" class="revisr-octicon-label"><?php _e( 'Branch:', 'revisr' ); ?></label>
					<span><strong><?php echo revisr()->git->branch; ?></strong></span>
				</div>

				<div class="misc-pub-section revisr-backup-cb">
					<span><input id="revisr-backup-cb" type="checkbox" name="backup_db" /></span>
					<label for="revisr-backup-cb"><?php _e( 'Backup database?', 'revisr' ); ?></label>
				</div>

				<div class="misc-pub-section revisr-push-cb">
					<?php if ( revisr()->git->get_config( 'revisr', 'auto-push' ) == 'true' ): ?>
						<input type="hidden" name="autopush_enabled" value="true" />
						<span><input id="revisr-push-cb" type="checkbox" name="auto_push" checked /></span>
					<?php else: ?>
						<span><input id="revisr-push-cb" type="checkbox" name="auto_push" /></span>
					<?php endif; ?>
					<label for="revisr-push-cb"><?php _e( 'Push changes?', 'revisr' ); ?></label>
				</div>

			</div><!-- /#misc-publishing-actions -->
		</div>

		<div id="major-publishing-actions">
			<div id="delete-action"></div>
			<div id="publishing-action">
				<span id="revisr-spinner" class="spinner"></span>
				<?php wp_nonce_field( 'process_commit', 'revisr_commit_nonce' ); ?>
				<input type="submit" name="publish" id="commit" class="button button-primary button-large" value="<?php _e( 'Commit Changes', 'revisr' ); ?>" onclick="commit_files();" accesskey="p">
			</div>
			<div class="clear"></div>
		</div>

		<?php
	}

	/**
	 * Displays the "Commit Details" meta box on a previous commit.
	 * @access public
	 */
	public function view_commit_meta() {

		// Get details about the commit.
		$commit_hash 		= isset( $_GET['commit'] ) ? esc_attr( $_GET['commit'] ) : '';
		$commit 			= Revisr_Git::get_commit_details( $commit_hash );

		$revert_url 		= get_admin_url() . 'admin-post.php?action=revisr_revert_form&commit=' . $commit_hash . '&TB_iframe=true&width=350&height=200';

		$time_format 	 	= __( 'M j, Y @ G:i' );
		$timestamp 		 	= sprintf( __( 'Committed on: <strong>%s</strong>', 'revisr' ), date_i18n( $time_format, $commit['time'] ) );

		if ( false === $commit['status'] ) {
			$commit['status'] = __( 'Error', 'revisr' );
			$revert_btn = '<a class="button button-primary disabled" href="#">' . __( 'Revert to this Commit', 'revisr' ) . '</a>';
		} else {
			$revert_btn = '<a class="button button-primary thickbox" href="' . $revert_url . '" title="' . __( 'Revert', 'revisr' ) . '">' . __( 'Revert to this Commit', 'revisr' ) . '</a>';
		}

		?>
		<div id="minor-publishing">
			<div id="misc-publishing-actions">

				<div class="misc-pub-section revisr-pub-status">
					<label for="post_status"><?php _e( 'Status:', 'revisr' ); ?></label>
					<span><strong><?php echo $commit['status']; ?></strong></span>
				</div>

				<div class="misc-pub-section revisr-pub-branch">
					<label for="revisr-branch" class="revisr-octicon-label"><?php _e( 'Branch:', 'revisr' ); ?></label>
					<span><strong><?php echo $commit['branch']; ?></strong></span>
				</div>

				<div class="misc-pub-section curtime misc-pub-curtime">
					<span id="timestamp" class="revisr-timestamp"><?php echo $timestamp; ?></span>
				</div>

				<?php if ( $commit['tag'] !== '' ): ?>
				<div class="misc-pub-section revisr-git-tag">
					<label for="revisr-tag" class="revisr-octicon-label"><?php _e( 'Tagged:', 'revisr' ); ?></label>
					<span><strong><?php echo $commit['tag']; ?></strong></span>
				</div>
				<?php endif; ?>

			</div><!-- /#misc-publishing-actions -->
		</div>

		<div id="major-publishing-actions">
			<div id="delete-action"></div>
			<div id="publishing-action">
				<span id="revisr-spinner" class="spinner"></span>
				<?php echo $revert_btn; ?>
			</div>
			<div class="clear"></div>
		</div>
		<?php
	}

	/**
	 * The container for the staging area.
	 * @access public
	 */
	public function pending_files_meta() {
		echo "<div id='message'></div>
		<div id='pending_files_result'></div>";
	}

}
