import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FacepileRow, Reactions } from '../reactions';

describe( 'FacepileRow', () => {
	const mockReactions = [
		{
			avatar: 'user1.jpg',
			url: 'https://example.com/user1',
			name: 'User One',
		},
		{
			avatar: 'user2.jpg',
			url: 'https://example.com/user2',
			name: 'User Two',
		},
	];

	beforeEach( () => {
		// Mock window._atmosphereOptions for useOptions hook
		window._atmosphereOptions = {
			defaultAvatarUrl: 'default.jpg',
		};
	} );

	afterEach( () => {
		delete window._atmosphereOptions;
		jest.clearAllMocks();
	} );

	test( 'renders reaction avatars', () => {
		render( <FacepileRow reactions={ mockReactions } /> );

		const avatars = screen.getAllByRole( 'img' );
		expect( avatars ).toHaveLength( 2 );
		expect( avatars[ 0 ].src ).toContain( 'user1.jpg' );
		expect( avatars[ 1 ].src ).toContain( 'user2.jpg' );
	} );

	test( 'creates clickable links to user profiles', () => {
		render( <FacepileRow reactions={ mockReactions } /> );

		const links = screen.getAllByRole( 'link' );
		expect( links ).toHaveLength( 2 );
		expect( links[ 0 ].href ).toBe( 'https://example.com/user1' );
		expect( links[ 1 ].href ).toBe( 'https://example.com/user2' );
	} );

	test( 'uses default avatar when reaction avatar is missing', () => {
		const reactionsWithoutAvatar = [
			{
				url: 'https://example.com/user3',
				name: 'User Three',
			},
		];

		render( <FacepileRow reactions={ reactionsWithoutAvatar } /> );

		const avatar = screen.getByRole( 'img' );
		expect( avatar.src ).toContain( 'default.jpg' );
	} );

	test( 'uses the built-in fallback avatar when no default is set', () => {
		delete window._atmosphereOptions;

		render(
			<FacepileRow
				reactions={ [
					{ url: 'https://example.com/user3', name: 'User Three' },
				] }
			/>
		);

		expect( screen.getByRole( 'img' ).src ).toMatch(
			/^data:image\/svg\+xml,/
		);
	} );

	test( 'renders empty list when no reactions provided', () => {
		render( <FacepileRow reactions={ [] } /> );

		const list = screen.getByRole( 'list' );
		expect( list.children ).toHaveLength( 0 );
	} );

	test( 'renders avatars when displayStyle is facepile', () => {
		render(
			<FacepileRow reactions={ mockReactions } displayStyle="facepile" />
		);

		const avatars = screen.getAllByRole( 'img' );
		expect( avatars ).toHaveLength( 2 );
	} );

	test( 'returns null when displayStyle is compact', () => {
		const { container } = render(
			<FacepileRow reactions={ mockReactions } displayStyle="compact" />
		);

		expect( container.firstChild ).toBeNull();
	} );

	test( 'renders avatars when displayStyle is undefined (default)', () => {
		render( <FacepileRow reactions={ mockReactions } /> );

		const avatars = screen.getAllByRole( 'img' );
		expect( avatars ).toHaveLength( 2 );
	} );
} );

describe( 'Reactions', () => {
	const reactions = {
		likes: {
			label: '1 like',
			items: [
				{
					avatar: 'user1.jpg',
					url: 'https://example.com/user1',
					name: 'User One',
				},
			],
		},
		reposts: {
			label: '0 reposts',
			items: [],
		},
	};

	beforeEach( () => {
		window._atmosphereOptions = {
			defaultAvatarUrl: 'default.jpg',
		};
	} );

	afterEach( () => {
		delete window._atmosphereOptions;
		jest.clearAllMocks();
	} );

	test( 'renders nothing without reactions', () => {
		const { container } = render( <Reactions /> );

		expect( container.firstChild ).toBeNull();
	} );

	test( 'renders nothing when every group is empty', () => {
		const { container } = render(
			<Reactions
				reactions={ {
					likes: { label: '0 likes', items: [] },
					reposts: { label: '0 reposts', items: [] },
				} }
			/>
		);

		expect( container.firstChild ).toBeNull();
	} );

	test( 'renders a group per reaction type and skips empty ones', () => {
		render( <Reactions reactions={ reactions } /> );

		expect(
			screen.getByRole( 'button', { name: '1 like' } )
		).toBeInTheDocument();
		expect( screen.queryByText( '0 reposts' ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'img' ).src ).toContain( 'user1.jpg' );
	} );

	test( 'shows no avatars in compact mode', () => {
		render( <Reactions reactions={ reactions } displayStyle="compact" /> );

		expect(
			screen.getByRole( 'button', { name: '1 like' } )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'img' ) ).not.toBeInTheDocument();
	} );

	test( 'shows the facepile only for the first 20 reactions', () => {
		const items = Array.from( { length: 25 }, ( _, index ) => ( {
			avatar: `user${ index }.jpg`,
			url: `https://example.com/user${ index }`,
			name: `User ${ index }`,
		} ) );

		render(
			<Reactions reactions={ { likes: { label: '25 likes', items } } } />
		);

		expect( screen.getAllByRole( 'img' ) ).toHaveLength( 20 );
	} );

	test( 'opens the full list when the label is clicked', async () => {
		const user = userEvent.setup();

		render( <Reactions reactions={ reactions } /> );

		const button = screen.getByRole( 'button', { name: '1 like' } );
		expect( button ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( screen.queryByText( 'User One' ) ).not.toBeInTheDocument();

		await user.click( button );

		expect( button ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( screen.getByText( 'User One' ) ).toBeInTheDocument();
	} );
} );
