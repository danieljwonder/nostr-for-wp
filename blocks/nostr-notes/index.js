/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl, SelectControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';

/**
 * Edit component for Nostr Notes block
 */
export default function Edit({ attributes, setAttributes }) {
    const { limit, orderby, order } = attributes;
    const blockProps = useBlockProps();

    return (
        <div {...blockProps}>
            <InspectorControls>
                <PanelBody title={__('Note Settings', 'nostr-for-wp')}>
                    <RangeControl
                        label={__('Number of Notes', 'nostr-for-wp')}
                        value={limit}
                        onChange={(value) => setAttributes({ limit: value })}
                        min={1}
                        max={50}
                    />
                    <SelectControl
                        label={__('Order By', 'nostr-for-wp')}
                        value={orderby}
                        options={[
                            { label: __('Date', 'nostr-for-wp'), value: 'date' },
                            { label: __('Title', 'nostr-for-wp'), value: 'title' },
                            { label: __('Modified', 'nostr-for-wp'), value: 'modified' },
                        ]}
                        onChange={(value) => setAttributes({ orderby: value })}
                    />
                    <SelectControl
                        label={__('Order', 'nostr-for-wp')}
                        value={order}
                        options={[
                            { label: __('Descending', 'nostr-for-wp'), value: 'DESC' },
                            { label: __('Ascending', 'nostr-for-wp'), value: 'ASC' },
                        ]}
                        onChange={(value) => setAttributes({ order: value })}
                    />
                </PanelBody>
            </InspectorControls>
            <div className="nostr-notes-block-placeholder">
                <p>{__('Nostr Notes', 'nostr-for-wp')}</p>
                <p className="description">
                    {__('Displaying', 'nostr-for-wp')} {limit} {__('notes', 'nostr-for-wp')}
                </p>
            </div>
        </div>
    );
}

