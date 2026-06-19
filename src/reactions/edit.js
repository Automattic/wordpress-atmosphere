import { useBlockProps, InnerBlocks } from '@wordpress/block-editor';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { Reactions } from './reactions';

/**
 * Build a black-circle avatar data URI carrying a single character, so the
 * editor previews the real facepile structure without live data.
 *
 * @param {string} char Character to draw in the avatar.
 * @return {string} An SVG data URI.
 */
const dummyAvatar = ( char ) =>
	"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Ccircle cx='32' cy='32' r='32' fill='%231e1e1e'/%3E%3Ctext x='32' y='40' font-family='sans-serif' font-size='28' fill='white' text-anchor='middle'%3E" +
	char +
	'%3C/text%3E%3C/svg%3E';

/**
 * Build dummy reaction items from a list of avatar characters.
 *
 * @param {string[]} chars Characters, one per avatar.
 * @return {Array} Dummy reaction items.
 */
const dummyItems = ( chars ) =>
	chars.map( ( char, i ) => ( {
		name: `User ${ i + 1 }`,
		url: '#',
		avatar: dummyAvatar( char ),
	} ) );

const LIKE_CHARS = [ 'q', 'w', 'e', 'r', 't', 'y' ];
const REPOST_CHARS = [ '1', '3', '3', '7' ];

const DUMMY_REACTIONS = {
	like: {
		label: sprintf(
			/* translators: %s: number of likes. */
			_n( '%s like', '%s likes', LIKE_CHARS.length, 'atmosphere' ),
			LIKE_CHARS.length
		),
		count: LIKE_CHARS.length,
		items: dummyItems( LIKE_CHARS ),
	},
	repost: {
		label: sprintf(
			/* translators: %s: number of reposts. */
			_n( '%s repost', '%s reposts', REPOST_CHARS.length, 'atmosphere' ),
			REPOST_CHARS.length
		),
		count: REPOST_CHARS.length,
		items: dummyItems( REPOST_CHARS ),
	},
};

// Only a heading is allowed inside the block (the optional title).
const TEMPLATE = [
	[
		'core/heading',
		{
			level: 6,
			placeholder: __( 'ATmosphere Reactions', 'atmosphere' ),
			content: __( 'ATmosphere Reactions', 'atmosphere' ),
		},
	],
];

/**
 * Editor view for the Bluesky Reactions block.
 *
 * Renders an editable title heading plus a dummy facepile preview using the
 * same `Reactions` component markup the front end mirrors, so the editor and
 * live output stay consistent and the facepile / compact block styles apply.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @return {Element} The editor view.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { className = '', displayStyle = 'facepile' } = attributes;
	const blockProps = useBlockProps();

	// Keep the displayStyle attribute in sync with the selected block style.
	const classNameStyle = className?.includes( 'is-style-compact' )
		? 'compact'
		: 'facepile';
	useEffect( () => {
		if ( classNameStyle !== displayStyle ) {
			setAttributes( { displayStyle: classNameStyle } );
		}
	}, [ classNameStyle, displayStyle, setAttributes ] );

	return (
		<div { ...blockProps }>
			<InnerBlocks
				template={ TEMPLATE }
				allowedBlocks={ [ 'core/heading' ] }
				templateLock="all"
				renderAppender={ false }
			/>
			<Reactions
				reactions={ DUMMY_REACTIONS }
				displayStyle={ classNameStyle }
			/>
		</div>
	);
}
