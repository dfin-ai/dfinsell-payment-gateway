( function() {
	const { registerPaymentMethod } = window.wc?.wcBlocksRegistry || {};
	const { createElement } = window.wp?.element || {};

	// Bail if registry isn't ready
	if ( typeof registerPaymentMethod !== 'function' ) {
		console.error( '[DFinSell] wcBlocksRegistry not available yet' );
		return;
	}

	// Load settings from WC or fallback to localized params
	const settings =
		window.wc?.wcSettings?.getPaymentMethodData?.('dfinsell') ||
		window.dfinsell_params?.settings ||
		{};

	console.log( '[DFinSell] settings:', settings );

	const methodConfig = {
		name: settings.id || 'dfinsell',
		label: settings.title || 'DFinSell',
		ariaLabel: settings.title || 'DFinSell',
		content: createElement(
			'div',
			{ className: 'dfinsell-description' },
			settings.description || ''
		),
		edit: createElement(
			'div',
			{ className: 'dfinsell-edit' },
			settings.title || 'DFinSell'
		),
		canMakePayment: () => {
			console.log( '[DFinSell] canMakePayment called' );
			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ],
		},
	};

	console.log( '[DFinSell] Registering payment method:', methodConfig );
	registerPaymentMethod( methodConfig );
} )();
