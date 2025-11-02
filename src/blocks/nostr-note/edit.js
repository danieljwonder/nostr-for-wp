/**
 * Retrieves the translation of text.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-i18n/
 */
import { __ } from '@wordpress/i18n';

/**
 * React hook that is used to mark the block wrapper element.
 * It provides all the necessary props like the class name.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-block-editor/#useblockprops
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Lets webpack process CSS, SASS or SCSS files referenced in JavaScript files.
 * Those files can contain any CSS code that gets applied to the editor.
 *
 * @see https://www.npmjs.com/package/@wordpress/scripts#using-css
 */
import './editor.scss';

/**
 * The edit function describes the structure of your block in the context of the
 * editor. This represents what the editor will render when the block is used.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#edit
 *
 * @return {Element} Element to render.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { noteId } = attributes;
	const blockProps = useBlockProps();

	// Fetch notes
	const notes = useSelect( ( select ) => {
		return select( 'core' ).getEntityRecords( 'postType', 'note', {
			per_page: 100,
			orderby: 'date',
			order: 'desc',
			status: 'publish',
		} );
	}, [] );

	const selectedNote = noteId
		? notes?.find( ( note ) => note.id === noteId )
		: null;

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Note Settings', 'nostr-for-wp' ) }>
					<SelectControl
						label={ __( 'Select Note', 'nostr-for-wp' ) }
						value={ noteId || '' }
						options={ [
							{
								label: __(
									'-- Select a Note --',
									'nostr-for-wp'
								),
								value: 0,
							},
							...( notes || [] ).map( ( note ) => ( {
								label: decodeEntities(
									note.title.rendered ||
										note.title.raw ||
										`Note #${ note.id }`
								),
								value: note.id,
							} ) ),
						] }
						onChange={ ( value ) =>
							setAttributes( { noteId: parseInt( value, 10 ) } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div className="nostr-note-block-placeholder">
				{ selectedNote ? (
					<>
						<p>
							<strong>
								{ __( 'Nostr Note', 'nostr-for-wp' ) }
							</strong>
						</p>
						<div className="nostr-note-preview">
							<p>
								{ selectedNote.excerpt?.rendered ||
									selectedNote.title?.rendered ||
									__(
										'Note content will appear here.',
										'nostr-for-wp'
									) }
							</p>
						</div>
					</>
				) : (
					<p>
						{ __(
							'Please select a note from the block settings.',
							'nostr-for-wp'
						) }
					</p>
				) }
			</div>
		</div>
	);
}

