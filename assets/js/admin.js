/* WPLedger admin JS — journal entry totals + account subtype helper */
( function () {

	/* ------------------------------------------------------------------
	 * Chart of Accounts: populate Subtype dropdown based on selected Type.
	 * Data matches the accounting model in class-coa.php exactly.
	 * ------------------------------------------------------------------ */
	var lcSubtypes = {
		ASSET:     [ 'CURRENT_ASSET', 'NONCURRENT_ASSET' ],
		LIABILITY: [ 'CURRENT_LIABILITY', 'NONCURRENT_LIABILITY' ],
		EQUITY:    [ 'EQUITY' ],
		REVENUE:   [ 'OPERATING_REVENUE', 'OTHER_REVENUE' ],
		EXPENSE:   [ 'COGS', 'OPERATING_EXPENSE', 'OTHER_EXPENSE' ]
	};

	window.lcUpdateSubtypes = function ( type ) {
		var sel = document.getElementById( 'wpl-acc-subtype' );
		if ( ! sel ) { return; }

		var placeholder = sel.getAttribute( 'data-placeholder' ) || '— Select —';
		sel.innerHTML   = '<option value="">' + placeholder + '</option>';

		if ( lcSubtypes[ type ] ) {
			lcSubtypes[ type ].forEach( function ( s ) {
				var opt       = document.createElement( 'option' );
				opt.value     = s;
				opt.textContent = s;
				sel.appendChild( opt );
			} );
		}
	};

	/* ------------------------------------------------------------------
	 * Journal entry: live running debit / credit totals.
	 * ------------------------------------------------------------------ */
	var i18n = ( typeof wpledgerData !== 'undefined' && wpledgerData.i18n )
		? wpledgerData.i18n
		: { debits: 'Debits', credits: 'Credits', balanced: 'Balanced', unbalanced: 'Unbalanced' };

	function updateTotals() {
		var debits = 0, credits = 0;

		document.querySelectorAll( 'input[name="debit[]"]' ).forEach( function ( el ) {
			debits += parseFloat( el.value ) || 0;
		} );
		document.querySelectorAll( 'input[name="credit[]"]' ).forEach( function ( el ) {
			credits += parseFloat( el.value ) || 0;
		} );

		var label = document.getElementById( 'wpl-totals' );
		if ( ! label ) { return; }

		var isBalanced = debits.toFixed( 2 ) === credits.toFixed( 2 );
		label.textContent = i18n.debits + ': ' + debits.toFixed( 2 )
			+ '   ' + i18n.credits + ': ' + credits.toFixed( 2 )
			+ '   — ' + ( isBalanced ? i18n.balanced : i18n.unbalanced );
		label.style.color = isBalanced ? 'green' : '#c00';
	}

	document.addEventListener( 'input', function ( e ) {
		if ( e.target.name === 'debit[]' || e.target.name === 'credit[]' ) {
			updateTotals();
		}
	} );

	/* ------------------------------------------------------------------
	 * Chart of Accounts: Edit Account modal.
	 * Triggered by .wpl-edit-btn buttons that carry data-* attributes.
	 * ------------------------------------------------------------------ */
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.wpl-edit-btn' );
		if ( btn ) {
			var modal = document.getElementById( 'wpl-edit-modal' );
			if ( ! modal ) { return; }
			document.getElementById( 'wpl-edit-id' ).value      = btn.getAttribute( 'data-account-id' );
			document.getElementById( 'wpl-edit-code' ).value    = btn.getAttribute( 'data-code' );
			document.getElementById( 'wpl-edit-name' ).value    = btn.getAttribute( 'data-name' );
			document.getElementById( 'wpl-edit-cash' ).checked  = btn.getAttribute( 'data-is-cash' ) === '1';
			modal.style.display = 'flex';
		}

		if ( e.target.closest( '.wpl-modal-cancel' ) ) {
			var m = document.getElementById( 'wpl-edit-modal' );
			if ( m ) { m.style.display = 'none'; }
		}

		// Click on backdrop closes modal.
		if ( e.target.id === 'wpl-edit-modal' ) {
			e.target.style.display = 'none';
		}
	} );

}() );
