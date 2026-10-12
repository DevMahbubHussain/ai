<?php
/**
 * Integration tests for the Revision_Change_Summary experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Revision_Change_Summary;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Experiments;
use WordPress\AI\Experiments\Revision_Change_Summary\Revision_Change_Summary;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Revision_Change_Summary experiment test case.
 *
 * @since x.x.x
 */
class Revision_Change_SummaryTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		update_option( 'wpai_feature_revision-change-summary_enabled', true );

		$experiments = new Experiments();
		$experiments->init();

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$this->assertInstanceOf(
			Revision_Change_Summary::class,
			$registry->get_feature( 'revision-change-summary' ),
			'Revision change summary experiment should be registered in the registry.'
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_feature_revision-change-summary_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		remove_filter( 'wpai_default_feature_classes', array( Experiments::class, 'register_default_experiment_classes' ), 9 );

		foreach ( array( 'ai_revision_change_summary', 'ai_revision_change_summary_classic' ) as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}

		unset( $GLOBALS['post'] );
		set_current_screen( 'front' );

		parent::tearDown();
	}

	/**
	 * Tests that the experiment is registered correctly.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_registration() {
		$experiment = new Revision_Change_Summary();

		$this->assertSame( 'revision-change-summary', $experiment->get_id() );
		$this->assertSame( 'Revision Change Summary', $experiment->get_label() );
		$this->assertSame( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Tests that register() adds the expected hooks.
	 *
	 * @since x.x.x
	 */
	public function test_register_adds_hooks() {
		$experiment = new Revision_Change_Summary();

		try {
			$experiment->register();

			$this->assertSame( 10, has_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) ) );
			$this->assertSame( 10, has_action( 'enqueue_block_editor_assets', array( $experiment, 'enqueue_assets' ) ) );
			$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $experiment, 'maybe_enqueue_classic_assets' ) ) );
		} finally {
			remove_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) );
			remove_action( 'enqueue_block_editor_assets', array( $experiment, 'enqueue_assets' ) );
			remove_action( 'admin_enqueue_scripts', array( $experiment, 'maybe_enqueue_classic_assets' ) );
		}
	}

	/**
	 * Tests that the editor script only loads on the post editor.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_skips_non_post_screens() {
		$experiment = new Revision_Change_Summary();

		set_current_screen( 'dashboard' );
		$experiment->enqueue_assets();

		$this->assertFalse( wp_script_is( 'ai_revision_change_summary', 'enqueued' ) );
	}

	/**
	 * Tests that the editor script loads for post types with revisions.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_on_post_editor() {
		$experiment = new Revision_Change_Summary();

		set_current_screen( 'post' );
		$experiment->enqueue_assets();

		$this->assertTrue( wp_script_is( 'ai_revision_change_summary', 'enqueued' ) );

		$data = wp_scripts()->get_data( 'ai_revision_change_summary', 'data' );
		$this->assertIsString( $data );
		$this->assertStringContainsString( 'aiRevisionChangeSummaryData', $data );
	}

	/**
	 * Tests that the editor script skips post types without revisions.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_skips_post_type_without_revisions() {
		register_post_type(
			'rcs_no_revisions',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor' ),
			)
		);

		$experiment = new Revision_Change_Summary();

		set_current_screen( 'rcs_no_revisions' );
		get_current_screen()->base      = 'post';
		get_current_screen()->post_type = 'rcs_no_revisions';
		$experiment->enqueue_assets();

		unregister_post_type( 'rcs_no_revisions' );

		$this->assertFalse( wp_script_is( 'ai_revision_change_summary', 'enqueued' ) );
	}

	/**
	 * Tests that the classic script only loads on the revisions screen.
	 *
	 * @since x.x.x
	 */
	public function test_classic_assets_skip_other_screens() {
		$experiment = new Revision_Change_Summary();

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the revisions screen post.
		$GLOBALS['post'] = self::factory()->post->create_and_get();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$experiment->maybe_enqueue_classic_assets( 'post.php' );

		$this->assertFalse( wp_script_is( 'ai_revision_change_summary_classic', 'enqueued' ) );
	}

	/**
	 * Tests that the classic script loads on the revisions screen.
	 *
	 * @since x.x.x
	 */
	public function test_classic_assets_load_on_revisions_screen() {
		$experiment = new Revision_Change_Summary();

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the revisions screen post.
		$GLOBALS['post'] = self::factory()->post->create_and_get();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$experiment->maybe_enqueue_classic_assets( 'revision.php' );

		$this->assertTrue( wp_script_is( 'ai_revision_change_summary_classic', 'enqueued' ) );

		$data = wp_scripts()->get_data( 'ai_revision_change_summary_classic', 'data' );
		$this->assertIsString( $data );
		$this->assertStringContainsString( '"postId":"' . $GLOBALS['post']->ID . '"', $data );
	}

	/**
	 * Tests that the classic script skips users who can't edit the post.
	 *
	 * @since x.x.x
	 */
	public function test_classic_assets_skip_users_who_cannot_edit() {
		$experiment = new Revision_Change_Summary();

		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the revisions screen post.
		$GLOBALS['post'] = self::factory()->post->create_and_get( array( 'post_author' => $author_id ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$experiment->maybe_enqueue_classic_assets( 'revision.php' );

		$this->assertFalse( wp_script_is( 'ai_revision_change_summary_classic', 'enqueued' ) );
	}
}
