/**
 * Revision change summary plugin for the visual revisions screen.
 */

/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { createPortal } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useRevisionSummary } from '../hooks/useRevisionSummary';
import { useVisualRevision } from '../hooks/useVisualRevision';
import SummaryPanel from './SummaryPanel';

export const PLUGIN_NAME = 'ai-revision-change-summary';

/**
 * Adds a "Change summary" panel to the Revision tab of the editor sidebar.
 *
 * The summary state lives here rather than in the panel, so a running
 * request and its result are kept when the sidebar is closed or switched
 * to another tab.
 */
export default function RevisionChangeSummaryPlugin() {
	const { postId, isPreviewMode } = useSelect(
		( select ) => ( {
			postId: select( editorStore ).getCurrentPostId() as number | null,
			isPreviewMode: !! (
				select( blockEditorStore ).getSettings() as {
					isPreviewMode?: boolean;
				}
			 ).isPreviewMode,
		} ),
		[]
	);
	const { anchor, revisionId } = useVisualRevision( isPreviewMode );
	const summary = useRevisionSummary( postId ?? 0, revisionId );

	if (
		! window.aiRevisionChangeSummaryData?.enabled ||
		! postId ||
		! anchor
	) {
		return null;
	}

	return createPortal(
		<PanelBody title={ __( 'Change summary', 'ai' ) }>
			<SummaryPanel summary={ summary } />
		</PanelBody>,
		anchor
	);
}
