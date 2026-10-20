import React from 'react';
import '@testing-library/jest-dom';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { RankingList } from './RankingList';
import { studentApi } from './api';

jest.mock( './api', () => ( { studentApi: jest.fn() } ) );
const request = jest.mocked( studentApi );
const page = {
	items: [
		{
			rank: 23,
			display_name: 'İpek M. Y.',
			score: 8750,
			duration_seconds: 80,
		},
	],
	page: 1,
	total: 21,
	total_pages: 2,
	participant_total: 45,
};

beforeEach( () => {
	request.mockReset();
} );

test( 'loads only when opened and retains global rank while searching and paging', async () => {
	request.mockResolvedValue( page );
	render( <RankingList assessmentId={ 7 } restRoot="/api/" nonce="" /> );
	expect( request ).not.toHaveBeenCalled();
	fireEvent.click( screen.getByRole( 'button', { name: 'Ranking' } ) );
	expect( await screen.findByText( 'İpek M. Y.' ) ).toBeVisible();
	expect( screen.getByText( '23' ) ).toBeVisible();
	fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
	await waitFor( () =>
		expect( request ).toHaveBeenLastCalledWith(
			'/api/',
			'/assessments/7/ranking?page=2&search=',
			'',
			'',
			expect.objectContaining( { signal: expect.any( AbortSignal ) } )
		)
	);
	fireEvent.change( screen.getByRole( 'searchbox' ), {
		target: { value: 'ipek' },
	} );
	await waitFor( () =>
		expect( request ).toHaveBeenLastCalledWith(
			'/api/',
			'/assessments/7/ranking?page=1&search=ipek',
			'',
			'',
			expect.any( Object )
		)
	);
	expect( await screen.findByText( '23' ) ).toBeVisible();
} );

test( 'escapes public labels and handles an empty search', async () => {
	request.mockResolvedValueOnce( {
		...page,
		items: [
			{
				...page.items[ 0 ],
				display_name: '<img src=x onerror=alert(1)>',
			},
		],
	} );
	render( <RankingList assessmentId={ 7 } restRoot="/api/" nonce="" /> );
	fireEvent.click( screen.getByRole( 'button', { name: 'Ranking' } ) );
	expect(
		await screen.findByText( '<img src=x onerror=alert(1)>' )
	).toBeVisible();
	expect( document.querySelector( 'img' ) ).toBeNull();
	request.mockResolvedValueOnce( { ...page, total: 0, items: [] } );
	fireEvent.change( screen.getByRole( 'searchbox' ), {
		target: { value: 'missing' },
	} );
	expect(
		await screen.findByText( 'No matching participants.' )
	).toBeVisible();
} );

test( 'offers a retry after an unavailable list without showing stale rows', async () => {
	request.mockRejectedValueOnce(
		new Error( 'The participant list is not available.' )
	);
	render( <RankingList assessmentId={ 7 } restRoot="/api/" nonce="" /> );
	fireEvent.click( screen.getByRole( 'button', { name: 'Ranking' } ) );
	expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
		'The participant list is not available.'
	);
	request.mockResolvedValueOnce( page );
	fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
	expect( await screen.findByText( 'İpek M. Y.' ) ).toBeVisible();
	expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
} );
