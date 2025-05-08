jQuery(document).ready(function ($) {
	$(document).on('click', '#payu_gateway_capture', function (e) {
		e.preventDefault();
		const nonce = $(this).data('nonce');
		const order_id = $(this).data('order-id');
		const self = $(this);

		$.ajax({
			url: PayU_Gateway_Admin.ajax_url,
			type: 'POST',
			data: {
				action: 'payu_gateway_capture',
				nonce: nonce,
				order_id: order_id
			},
			beforeSend: function () {
				self.data('text', self.html());
				self.html(PayU_Gateway_Admin.text_wait);
				self.prop('disabled', true);
			},
			success: function (response) {
				self.html(self.data('text'));
				self.prop('disabled', false);
				if (!response.success) {
					alert(response.data);
					return false;
				}

				window.location.href = location.href;
			}
		});
	});

	$(document).on('click', '#payu_gateway_cancel', function (e) {
		e.preventDefault();

		const nonce = $(this).data('nonce');
		const order_id = $(this).data('order-id');
        const self = $(this);
        
		$.ajax({
			url: PayU_Gateway_Admin.ajax_url,
			type: 'POST',
			data: {
				action: 'payu_gateway_cancel',
				nonce: nonce,
				order_id: order_id
			},
			beforeSend: function () {
				self.data('text', self.html());
				self.html(PayU_Gateway_Admin.text_wait);
				self.prop('disabled', true);
			},
			success   : function (response) {
				self.html(self.data('text'));
				self.prop('disabled', false);
				if (!response.success) {
					alert(response.data);
					return false;
				}

				window.location.href = location.href;
			}
		});
	});

	$(document).on('click', '#payu_gateway_refund', function (e) {
		e.preventDefault();
		const nonce = $(this).data('nonce');
		const order_id = $(this).data('order-id');
		const amount = $(this).data('amount');
        const self = $(this);
        
		$.ajax({
			url: PayU_Gateway_Admin.ajax_url,
			type: 'POST',
			data: {
				action: 'payu_gateway_refund',
				nonce: nonce,
				order_id: order_id,
				amount: amount
			},
			beforeSend: function () {
				self.data('text', self.html());
				self.html(PayU_Gateway_Admin.text_wait);
				self.prop('disabled', true);
			},
			success: function (response) {
				self.html(self.data('text'));
				self.prop('disabled', false);
				if (!response.success) {
					alert(response.data);
					return false;
				}

				window.location.href = location.href;
			},
			error: function (response) {
				alert(response);
			}
		});
	});

	$(document).on('click', '#payu_gateway_capture_partly', function (e) {
		e.preventDefault();
		const nonce = $(this).data('nonce');
		const order_id = $(this).data('order-id');
		const amount = $("#payu-capture_partly_amount-field").val();
		const self = $(this);

		$.ajax({
			url: PayU_Gateway_Admin.ajax_url,
			type: 'POST',
			data: {
				action: 'payu_gateway_capture_partly',
				nonce: nonce,
				order_id: order_id,
				amount: amount
			},
			beforeSend: function () {
				self.data('text', self.html());
				self.html(PayU_Gateway_Admin.text_wait);
				self.prop('disabled', true);
			},
			success   : function (response) {
				self.html(self.data('text'));
				self.prop('disabled', false);
				if (!response.success) {
					alert(response.data);
					return false;
				}
				window.location.href = location.href;
			},
			error: function (response) {
				alert("error response: " + JSON.stringify(response));
			}
		});
	});

	$(document).on('click', '#payu_gateway_refund_partly', function (e) {
		e.preventDefault();
		const nonce = $(this).data('nonce');
		const order_id = $(this).data('order-id');
		const amount = $("#payu-refund_partly_amount-field").val();
		const self = $(this);

		$.ajax({
			url: PayU_Gateway_Admin.ajax_url,
			type: 'POST',
			data: {
				action: 'payu_gateway_refund_partly',
				nonce: nonce,
				order_id: order_id,
				amount: amount
			},
			beforeSend: function () {
				self.data('text', self.html());
				self.html(PayU_Gateway_Admin.text_wait);
				self.prop('disabled', true);
			},
			success: function (response) {
				self.html(self.data('text'));
				self.prop('disabled', false);
				if (!response.success) {
					alert(response.data);
					return false;
				}
				window.location.href = location.href;
			},
			error: function (response) {
				alert("error response: " + JSON.stringify(response));
			}
		});
	});
});
