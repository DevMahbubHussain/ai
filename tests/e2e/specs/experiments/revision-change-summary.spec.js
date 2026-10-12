/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	disableExperiment,
	enableExperiment,
} = require( '../../utils/helpers' );

const EXPERIMENT_LABEL = 'Revision Change Summary';
const SUMMARY_TEXT = 'Rewrote the introduction and added a closing paragraph.';

/**
 * Creates a post with three saved revisions.
 *
 * @param {Object} requestUtils Request utils.
 * @return {Promise<{postId: number, revisionIds: number[]}>} The post ID and revision IDs, newest first.
 */
async function createPostWithRevisions( requestUtils ) {
	const post = await requestUtils.createPost( {
		title: 'Revision Change Summary Test',
		content:
			'<!-- wp:paragraph --><p>First introduction.</p><!-- /wp:paragraph -->',
		status: 'draft',
	} );

	for ( const content of [
		'<!-- wp:paragraph --><p>Second introduction.</p><!-- /wp:paragraph -->',
		'<!-- wp:paragraph --><p>Third introduction.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Closing paragraph.</p><!-- /wp:paragraph -->',
	] ) {
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ post.id }`,
			data: { content },
		} );
	}

	const revisions = await requestUtils.rest( {
		path: `/wp/v2/posts/${ post.id }/revisions`,
	} );

	return {
		postId: post.id,
		revisionIds: revisions.map( ( revision ) => revision.id ),
	};
}

test.describe( 'Revision Change Summary Experiment', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'e2e-testing' );
	} );

	test( 'Can enable the experiment', async ( { admin, page } ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test( 'Summarizes changes in the visual revisions screen', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const { postId, revisionIds } =
			await createPostWithRevisions( requestUtils );

		// Opening the editor with a revision ID shows the visual revisions screen.
		await admin.visitAdminPage(
			'post.php',
			`post=${ postId }&action=edit&revision=${ revisionIds[ 0 ] }`
		);

		// The welcome guide modal would hide the rest of the editor.
		await page.waitForFunction( () => window?.wp?.data );
		await page.evaluate( () =>
			window.wp.data
				.dispatch( 'core/preferences' )
				.set( 'core/edit-post', 'welcomeGuide', false )
		);

		const headerButton = page.getByRole( 'button', {
			name: 'Change summary',
		} );
		await expect( headerButton ).toBeVisible();
		await headerButton.click();
		await expect( headerButton ).toHaveAttribute( 'aria-expanded', 'true' );

		const summarizeButton = page.getByRole( 'button', {
			name: 'Summarize changes',
		} );
		await expect( summarizeButton ).toBeEnabled();

		// Use the keyboard, to check the action is reachable without a mouse.
		await summarizeButton.focus();
		await page.keyboard.press( 'Enter' );

		await expect( page.getByText( SUMMARY_TEXT ) ).toBeVisible();
		await expect(
			page.getByText( 'Added a closing paragraph with a call to action.' )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Regenerate summary' } )
		).toBeVisible();

		// The post itself is not changed.
		const post = await requestUtils.rest( {
			path: `/wp/v2/posts/${ postId }`,
			params: { context: 'edit' },
		} );
		expect( post.content.raw ).toContain( 'Closing paragraph.' );
		expect( post.content.raw ).not.toContain( SUMMARY_TEXT );
	} );

	test( 'Summarizes changes in the classic revisions screen', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const { revisionIds } = await createPostWithRevisions( requestUtils );

		await admin.visitAdminPage(
			'revision.php',
			`revision=${ revisionIds[ 0 ] }`
		);

		await page.getByRole( 'button', { name: 'Summarize changes' } ).click();

		await expect( page.getByText( SUMMARY_TEXT ) ).toBeVisible();
	} );

	test( 'Disables the action for the oldest revision on the classic screen', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const { revisionIds } = await createPostWithRevisions( requestUtils );

		await admin.visitAdminPage(
			'revision.php',
			`revision=${ revisionIds[ revisionIds.length - 1 ] }`
		);

		await expect(
			page.getByText( 'There is no earlier revision to compare with.' )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Summarize changes' } )
		).toBeDisabled();
	} );

	test( 'Shows nothing when the experiment is disabled', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await disableExperiment( admin, page, EXPERIMENT_LABEL );

		const { revisionIds } = await createPostWithRevisions( requestUtils );

		await admin.visitAdminPage(
			'revision.php',
			`revision=${ revisionIds[ 0 ] }`
		);

		await expect(
			page.locator( '.revisions-control-frame' )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Summarize changes' } )
		).toHaveCount( 0 );
	} );
} );
