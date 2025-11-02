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
import { PanelBody, RangeControl, SelectControl } from '@wordpress/components';

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
	const { limit, orderby, order } = attributes;
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Note Settings', 'nostr-for-wp' ) }>
					<RangeControl
						label={ __( 'Number of Notes', 'nostr-for-wp' ) }
						value={ limit }
						onChange={ ( value ) =>
							setAttributes( { limit: value } )
						}
						min={ 1 }
						max={ 50 }
					/>
					<SelectControl
						label={ __( 'Order By', 'nostr-for-wp' ) }
						value={ orderby }
						options={ [
							{
								label: __( 'Date', 'nostr-for-wp' ),
								value: 'date',
							},
							{
								label: __( 'Title', 'nostr-for-wp' ),
								value: 'title',
							},
							{
								label: __( 'Modified', 'nostr-for-wp' ),
								value: 'modified',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { orderby: value } )
						}
					/>
					<SelectControl
						label={ __( 'Order', 'nostr-for-wp' ) }
						value={ order }
						options={ [
							{
								label: __( 'Descending', 'nostr-for-wp' ),
								value: 'DESC',
							},
							{
								label: __( 'Ascending', 'nostr-for-wp' ),
								value: 'ASC',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { order: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div className="nostr-notes-block-placeholder">
				<p>{ __( 'Nostr Notes', 'nostr-for-wp' ) }</p>
				<p className="description">
					{ __( 'Displaying', 'nostr-for-wp' ) } { limit }{ ' ' }
					{ __( 'notes', 'nostr-for-wp' ) }
				</p>
			</div>
		</div>
	);
}

