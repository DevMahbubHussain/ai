/**
 * Hook that follows the visual revisions screen in the block editor.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { getQueryArg } from '@wordpress/url';

const HEADER_SELECTOR = '.editor-revisions-header';
const HEADER_SETTINGS_SELECTOR = '.editor-header__settings';
const ANCHOR_CLASS = 'ai-revision-change-summary-header-anchor';
const CHECK_INTERVAL_MS = 500;

interface VisualRevision {
	anchor: HTMLElement | null;
	revisionId: number | null;
}

const EMPTY: VisualRevision = { anchor: null, revisionId: null };

/**
 * Returns the revision shown in the visual revisions screen.
 *
 * Core keeps the selected revision ID in a private store, but writes it to
 * the `revision` query arg of the editor URL, which is read here.
 *
 * @return The revision ID, or null if none is selected.
 */
function getRevisionIdFromUrl(): number | null {
	const value = getQueryArg( window.location.href, 'revision' );
	const revisionId = Number( Array.isArray( value ) ? value[ 0 ] : value );

	return Number.isInteger( revisionId ) && revisionId > 0 ? revisionId : null;
}

/**
 * Returns the element our header button renders into, creating it if needed.
 *
 * Core has no extension point in the revisions header, so an element is
 * added to the start of its settings area. If core changes the header markup,
 * no element is found and the button is simply not shown.
 *
 * @return The anchor element, or null if the revisions header isn't found.
 */
function getHeaderAnchor(): HTMLElement | null {
	const settings = document.querySelector< HTMLElement >(
		`${ HEADER_SELECTOR } ${ HEADER_SETTINGS_SELECTOR }`
	);

	if ( ! settings ) {
		return null;
	}

	let anchor = settings.querySelector< HTMLElement >( `.${ ANCHOR_CLASS }` );

	if ( ! anchor ) {
		anchor = document.createElement( 'div' );
		anchor.className = ANCHOR_CLASS;
		settings.prepend( anchor );
	}

	return anchor;
}

/**
 * Tracks the visual revisions screen while the editor is in preview mode.
 *
 * The block editor switches to preview mode while revisions are shown. Only
 * then does this check, on a short interval, for the revisions header and
 * the selected revision, because core updates the URL after a delay and
 * without a store change.
 *
 * @param isPreviewMode Whether the block editor is in preview mode.
 * @return The header anchor element and the selected revision ID.
 */
export function useVisualRevision( isPreviewMode: boolean ): VisualRevision {
	const [ state, setState ] = useState< VisualRevision >( EMPTY );

	useEffect( () => {
		if ( ! isPreviewMode ) {
			setState( EMPTY );
			return undefined;
		}

		const check = () => {
			const anchor = getHeaderAnchor();
			const revisionId = anchor ? getRevisionIdFromUrl() : null;

			setState( ( previous ) =>
				previous.anchor === anchor && previous.revisionId === revisionId
					? previous
					: { anchor, revisionId }
			);
		};

		check();
		const interval = window.setInterval( check, CHECK_INTERVAL_MS );

		return () => {
			window.clearInterval( interval );
		};
	}, [ isPreviewMode ] );

	return state;
}
