/* global wc_ebanx_params */
EBANX.config.setMode(wc_ebanx_params.mode);
EBANX.config.setPublishableKey(wc_ebanx_params.key);

jQuery(function($) {
	/**
	 * Object to handle EBANX payment forms.
	 */
	var wc_ebanx_form = {

		/**
		 * Initialize event handlers and UI state.
		 */
		init: function(form) {
			this.form = form;

			$(this.form)
				.on('click', '#place_order', this.onSubmit)
				.on('submit checkout_place_order_ebanx-credit-card-br')
				.on('submit checkout_place_order_ebanx-credit-card-mx')
				.on('submit checkout_place_order_ebanx-credit-card-co');

			$(document)
				.on(
					'change',
					'#wc-ebanx-cc-form :input',
					this.onCCFormChange
				)
				.on(
					'ebanxErrorCreditCard',
					this.onError
				);
		},

		isEBANXPaymentMethod: function() {
			return $('input[value*=ebanx-credit-card]').is(':checked');
		},
		isEBANXTokenPayment: function() {
			return $('.woocommerce-SavedPaymentMethods-tokenInput:checked').length > 0 
				&& $('.woocommerce-SavedPaymentMethods-tokenInput:checked').val() != 'new';
		},
		hasToken: function() {
			return 0 < $('input#ebanx_token').length;
		},

		hasDeviceFingerprint: function() {
			return 0 < $('input#ebanx_device_fingerprint').length;
		},

		block: function() {
			wc_ebanx_form.form.block({
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			});
		},

		unblock: function() {
			wc_ebanx_form.form.unblock();
		},

		onError: function(e, message) {
			wc_ebanx_form.removeErrors();

			$('#ebanx-credit-cart-form').prepend('<p class="woocommerce-error">' + (message || 'Some error happened. Please, verify the data of your credit card and try again.') + '</p>');

			$('body, html').animate({
				scrollTop: $('#ebanx-credit-cart-form').find('.woocommerce-error').offset().top - 20
			});

			wc_ebanx_form.unblock();
		},

		removeErrors: function() {
			$('.woocommerce-error, .ebanx_token').remove();
		},

		onSubmit: function(e) {
			wc_ebanx_form.removeHiddenInputs();

			//console.log('onSubmit');

			if( wc_ebanx_form.isEBANXPaymentMethod() ) {
				//console.log('isEBANXPaymentMethod');

				e.preventDefault();
				e.stopPropagation();
				e.stopImmediatePropagation();

				// $(document).trigger( 'ebanxErrorCreditCard', response.error.err.status_message );

				if( wc_ebanx_form.isEBANXTokenPayment() ) {
					// console.log( 'isEBANXTokenPayment' );
					wc_ebanx_form.block();
					wc_ebanx_form.form.submit();
					return true;
				}
				else {
					// console.log('isEBANXNewCard');
					// console.log( $('#ebanx-card-expiry').val() );
					var card = $('#ebanx-card-number').val();
					var expires = $('#ebanx-card-expiry').payment('cardExpiryVal');
					var cvv = $('#ebanx-card-cvv').val();
					var card_name = $('#ebanx-card-holder-name').val() || ($('#billing_first_name').val() + ' ' + $('#billing_last_name').val());
					var country = $('#billing_country, input[name*="billing_country"]').val().toLowerCase();
					var instalments = $('#ebanx-container-new-credit-card').find('select.ebanx-instalments').val() || 1;

					if( ! $.payment.validateCardNumber( card ) ) {
						$(document).trigger( 'ebanxErrorCreditCard', wc_ebanx_params.invalid_card_number );
					} else if( ! $.payment.validateCardExpiry( expires['month'], expires['year'] ) ) {
						$(document).trigger( 'ebanxErrorCreditCard', wc_ebanx_params.invalid_card_expiry );
					} else if( ! $.payment.validateCardCVC( cvv, $.payment.cardType( card ) ) ) {
						$(document).trigger( 'ebanxErrorCreditCard', wc_ebanx_params.invalid_card_cvv );
					} else {
						wc_ebanx_form.block();
	
						EBANX.config.setCountry(country);
						var creditcard = {
							"card_number": parseInt(card.replace(/ /g, '')),
							"card_name": card_name,
							"card_due_date": (parseInt(expires['month']) || 0) + '/' + (parseInt(expires['year']) || 0),
							"card_cvv": cvv,
							"instalments": instalments
						};
						wc_ebanx_form.renderInstalments(creditcard.instalments || 1);
						wc_ebanx_form.renderCvv(creditcard.card_cvv);
						wc_ebanx_form.renderExpiry(creditcard.card_due_date);
						EBANX.card.createToken(creditcard, wc_ebanx_form.onEBANXReponse);
					}
				}
			}
		},

		onCCFormChange: function() {
			$('.woocommerce-error, .ebanx_token').remove();
		},

		toggleCardUse: function() {
			$(document).on('click', 'li[class*="payment_method_ebanx-credit-card"] .ebanx-credit-card-label', function() {
				$('.ebanx-container-credit-card').hide();
				$(this).siblings('.ebanx-container-credit-card').show();
			});
		},

		onEBANXReponse: function(response) {
			// console.log( response );
			if ( response.data && (response.data.status == 'ERROR' || ! response.data.token )) {
				$(document).trigger( 'ebanxErrorCreditCard', response.error.err.status_message );

				wc_ebanx_form.removeHiddenInputs();
			} else {
				wc_ebanx_form.form.append('<input type="hidden" name="ebanx_token" id="ebanx_token" value="' + response.data.token + '"/>');
				wc_ebanx_form.form.append('<input type="hidden" name="ebanx_brand" id="ebanx_brand" value="' + response.data.payment_type_code + '"/>');
				wc_ebanx_form.form.append('<input type="hidden" name="ebanx_masked_card_number" id="ebanx_masked_card_number" value="' + response.data.masked_card_number + '"/>');
				wc_ebanx_form.form.append('<input type="hidden" name="ebanx_device_fingerprint" id="ebanx_device_fingerprint" value="' + response.data.deviceId + '">');

				wc_ebanx_form.form.submit();
			}
		},

		renderInstalments: function(instalments) {
			wc_ebanx_form.form.append('<input type="hidden" name="ebanx_billing_instalments" id="ebanx_billing_instalments" value="' + instalments + '">');
		},

		renderCvv: function(cvv) {
			wc_ebanx_form.form.append('<input type="hidden" name="ebanx_billing_cvv" id="ebanx_billing_cvv" value="' + cvv + '">');
		},

		renderExpiry: function(expiry) {
			wc_ebanx_form.form.append('<input type="hidden" name="ebanx_billing_expiry" id="ebanx_billing_expiry" value="' + expiry + '">');
		},

		removeHiddenInputs: function() {
			$('#ebanx_token').remove();
			$('#ebanx_brand').remove();
			$('#ebanx_masked_card_number').remove();
			$('#ebanx_device_fingerprint').remove();
			$('#ebanx_billing_instalments').remove();
			$('#ebanx_billing_cvv').remove();
			$('#ebanx_card_expiry').remove();
		}
	};

	wc_ebanx_form.init($("form.checkout, form#order_review, form#add_payment_method, form.woocommerce-checkout"));

	wc_ebanx_form.toggleCardUse();

	// Update IOF value when instalments is changed
	var update_converted = function(self) {
		var instalments = self.val();
		var country = self.attr('data-country');
		var amount = self.attr('data-amount');
		var currency = self.attr('data-currency');
		var order_id = self.attr('data-order-id');
		var text = self.parents('.payment_box').find('.ebanx-payment-converted-amount span');
		var spinner = self.parents('.payment_box').find('.ebanx-spinner');

		spinner.fadeIn();

		$.ajax({
			url: wc_ebanx_params.ajaxurl,
			type: 'POST',
			data: {
				action: 'ebanx_update_converted_value',
				instalments: instalments,
				country: country,
				amount: amount,
				currency: currency,
				order_id: order_id
			}
		})
		.done(function(data) {
			text.html(data);
		})
		.always(function() {
			spinner.fadeOut();
		});
	};

	$(document).on('change', 'select.ebanx-instalments', function() {
		update_converted($(this));
	});

	$(document).on('change', 'input[name="ebanx-credit-card-use"]', function() {
		update_converted($(this)
			.parents('.ebanx-credit-card-option')
			.find('select.ebanx-instalments'));
	});
});