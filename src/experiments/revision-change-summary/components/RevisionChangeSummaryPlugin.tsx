/**
 * Revision change summary plugin for the visual revisions screen.
 */

/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginSidebar, store as editorStore } from '@wordpress/editor';
import { createPortal, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { backup } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { useVisualRevision } from '../hooks/useVisualRevision';
import SummaryPanel from './SummaryPanel';

export const PLUGIN_NAME = 'ai-revision-change-summary';
const SIDEBAR_NAME = 'sidebar';
const SIDEBAR_ID = `${ PLUGIN_NAME }/${ SIDEBAR_NAME }`;
const DOCUMENT_SIDEBAR_ID = 'edit-post/document';
const INTERFACE_STORE = 'core/interface';

/**
 * Adds a "Change summary" button to the visual revisions header and a
 * sidebar that shows the summary for the selected revision.
 */
export default function RevisionChangeSummaryPlugin() {
	const { postId, isPreviewMode, activeArea } = useSelect(
		( select ) => ( {
			postId: select( editorStore ).getCurrentPostId() as number | null,
			isPreviewMode: !! (
				select( blockEditorStore ).getSettings() as {
					isPreviewMode?: boolean;
				}
			 ).isPreviewMode,
			activeArea: (
				select( INTERFACE_STORE ) as {
					getActiveComplementaryArea: (
						scope: string
					) => string | null;
				}
			 ).getActiveComplementaryArea( 'core' ),
		} ),
		[]
	);
	const { enableComplementaryArea, disableComplementaryArea } = useDispatch(
		INTERFACE_STORE
	) as {
		enableComplementaryArea: ( scope: string, id: string ) => void;
		disableComplementaryArea: ( scope: string ) => void;
	};
	const { anchor, revisionId } = useVisualRevision( isPreviewMode );
	const isInRevisions = !! anchor;
	const isOpen = activeArea === SIDEBAR_ID;

	// The open sidebar is remembered, so close ours whenever revisions aren't shown.
	useEffect( () => {
		if ( ! isPreviewMode && isOpen ) {
			enableComplementaryArea( 'core', DOCUMENT_SIDEBAR_ID );
		}
	}, [ isPreviewMode, isOpen, enableComplementaryArea ] );

	if ( ! window.aiRevisionChangeSummaryData?.enabled || ! postId ) {
		return null;
	}

	return (
		<>
			{ anchor &&
				createPortal(
					<Button
						__next40pxDefaultSize
						size="compact"
						icon={ backup }
						label={ __( 'Change summary', 'ai' ) }
						isPressed={ isOpen }
						aria-expanded={ isOpen }
						onClick={ () =>
							isOpen
								? disableComplementaryArea( 'core' )
								: enableComplementaryArea( 'core', SIDEBAR_ID )
						}
					/>,
					anchor
				) }
			<PluginSidebar
				name={ SIDEBAR_NAME }
				title={ __( 'Change summary', 'ai' ) }
				icon={ backup }
				isPinnable={ false }
			>
				<div className="ai-revision-change-summary__sidebar">
					<SummaryPanel
						postId={ postId }
						revisionId={ isInRevisions ? revisionId : null }
						unavailableMessage={
							isInRevisions && revisionId
								? undefined
								: __(
										'Open a revision to summarize its changes.',
										'ai'
								  )
						}
					/>
				</div>
			</PluginSidebar>
		</>
	);
}
