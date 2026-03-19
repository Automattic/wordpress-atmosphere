/**
 * ATmosphere settings page JavaScript.
 *
 * Handles the backfill UI: count → batch → progress bar.
 */

/* global atmosphere */
( function () {
	'use strict';

	var BATCH_SIZE = 5;
	var btn, bar, status;

	function init() {
		btn = document.getElementById( 'atmosphere-backfill-start' );
		bar = document.getElementById( 'atmosphere-backfill-bar' );
		status = document.getElementById( 'atmosphere-backfill-status' );

		if ( btn ) {
			btn.addEventListener( 'click', startBackfill );
		}
	}

	function startBackfill() {
		btn.disabled = true;
		btn.textContent = 'Counting…';

		var data = new FormData();
		data.append( 'action', 'atmosphere_backfill_count' );
		data.append( 'nonce', atmosphere.backfill_nonce );

		fetch( atmosphere.ajax_url, { method: 'POST', body: data } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( ! res.success || res.data.total === 0 ) {
					btn.textContent = 'Nothing to backfill';
					return;
				}

				var ids = res.data.post_ids;
				var total = ids.length;
				var done = 0;
				var errors = 0;

				document.getElementById( 'atmosphere-backfill-progress' ).style.display = 'block';
				bar.max = total;
				bar.value = 0;
				status.textContent = '0 / ' + total;
				btn.style.display = 'none';

				function nextBatch() {
					if ( done >= total ) {
						status.textContent = total + ' / ' + total + ' done' + ( errors ? ' (' + errors + ' errors)' : '' );
						return;
					}

					var batch = ids.slice( done, done + BATCH_SIZE );
					var batchData = new FormData();
					batchData.append( 'action', 'atmosphere_backfill_batch' );
					batchData.append( 'nonce', atmosphere.backfill_nonce );
					batch.forEach( function ( id ) {
						batchData.append( 'post_ids[]', id );
					} );

					fetch( atmosphere.ajax_url, { method: 'POST', body: batchData } )
						.then( function ( r ) {
							return r.json();
						} )
						.then( function ( batchRes ) {
							if ( batchRes.success ) {
								batchRes.data.results.forEach( function ( r ) {
									if ( ! r.success ) {
										errors++;
									}
								} );
							}
							done += batch.length;
							bar.value = done;
							status.textContent = done + ' / ' + total + ( errors ? ' (' + errors + ' errors)' : '' );
							nextBatch();
						} )
						.catch( function () {
							errors += batch.length;
							done += batch.length;
							bar.value = done;
							status.textContent = done + ' / ' + total + ' (' + errors + ' errors)';
							nextBatch();
						} );
				}

				nextBatch();
			} )
			.catch( function () {
				btn.textContent = 'Error counting posts';
				btn.disabled = false;
			} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
