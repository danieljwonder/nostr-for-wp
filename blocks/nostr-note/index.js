/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Edit component for Nostr Note block
 */
export default function Edit({ attributes, setAttributes }) {
    const { noteId } = attributes;
    const blockProps = useBlockProps();

    // Fetch notes
    const notes = useSelect((select) => {
        return select('core').getEntityRecords('postType', 'note', {
            per_page: 100,
            orderby: 'date',
            order: 'desc',
            status: 'publish',
        });
    }, []);

    const selectedNote = noteId ? notes?.find((note) => note.id === noteId) : null;

    return (
        <div {...blockProps}>
            <InspectorControls>
                <PanelBody title={__('Note Settings', 'nostr-for-wp')}>
                    <SelectControl
                        label={__('Select Note', 'nostr-for-wp')}
                        value={noteId || ''}
                        options={[
                            { label: __('-- Select a Note --', 'nostr-for-wp'), value: 0 },
                            ...(notes || []).map((note) => ({
                                label: decodeEntities(note.title.rendered || note.title.raw || `Note #${note.id}`),
                                value: note.id,
                            })),
                        ]}
                        onChange={(value) => setAttributes({ noteId: parseInt(value, 10) })}
                    />
                </PanelBody>
            </InspectorControls>
            <div className="nostr-note-block-placeholder">
                {selectedNote ? (
                    <>
                        <p><strong>{__('Nostr Note', 'nostr-for-wp')}</strong></p>
                        <div className="nostr-note-preview">
                            <p>{selectedNote.excerpt?.rendered || selectedNote.title?.rendered || __('Note content will appear here.', 'nostr-for-wp')}</p>
                        </div>
                    </>
                ) : (
                    <p>{__('Please select a note from the block settings.', 'nostr-for-wp')}</p>
                )}
            </div>
        </div>
    );
}

