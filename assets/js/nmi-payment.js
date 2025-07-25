jQuery(document).ready(function($) {
    // Handle amount button selection
    $(document).on('click', '.amount-btn', function() {
        console.log('Amount button clicked:', $(this).data('amount'));
        
        const amount = $(this).data('amount');
        
        // Remove selected class from all buttons
        $('.amount-btn').removeClass('selected');
        $('#other-amount-btn').removeClass('active');
        
        // Add selected class to clicked button
        $(this).addClass('selected');
        
        // Hide custom amount input
        $('#custom-amount-input').hide();
        
        // Set the amount
        $('#selected_amount').val(amount);
        $('#step1_amount').val(amount);
        
        console.log('Selected amount set to:', $('#selected_amount').val());
    });
    
    // Handle "Other Amount" button
    $(document).on('click', '#other-amount-btn', function() {
        console.log('Other amount button clicked');
        
        // Remove selected class from amount buttons
        $('.amount-btn').removeClass('selected');
        
        // Toggle active state
        $(this).toggleClass('active');
        
        if ($(this).hasClass('active')) {
            // Show custom amount input
            $('#custom-amount-input').show();
            $('#step1_amount').focus();
            
            // Clear selected amount if switching to custom
            $('#selected_amount').val('');
        } else {
            // Hide custom amount input
            $('#custom-amount-input').hide();
            $('#selected_amount').val('');
            $('#step1_amount').val('');
        }
    });
    
    // Handle custom amount input
    $(document).on('input', '#step1_amount', function() {
        const customAmount = $(this).val();
        console.log('Custom amount entered:', customAmount);
        
        if (customAmount && customAmount > 0) {
            $('#selected_amount').val(customAmount);
        } else {
            $('#selected_amount').val('');
        }
        
        console.log('Selected amount updated to:', $('#selected_amount').val());
    });
    
    // Handle "Give" button click
    $(document).on('click', '#give-button', function() {
        console.log('Give button clicked');
        
        const amount = $('#selected_amount').val();
        const description = $('#step1_description').val().trim();
        const $messages = $('#step1-messages');
        
        console.log('Amount:', amount, 'Description:', description);
        
        // Clear previous messages
        $messages.removeClass('error success').empty();
        
        // Validate step 1
        let isValid = true;
        
        if (!amount || amount <= 0) {
            isValid = false;
            $('.amount-buttons, .other-amount-container').addClass('error');
        } else {
            $('.amount-buttons, .other-amount-container').removeClass('error');
        }
        
        if (!description) {
            isValid = false;
            $('#step1_description').addClass('error');
        } else {
            $('#step1_description').removeClass('error');
        }
        
        if (!isValid) {
            let errorMsg = '<p><strong>Error:</strong> ';
            if (!amount || amount <= 0) {
                errorMsg += 'Please select an amount';
            }
            if ((!amount || amount <= 0) && !description) {
                errorMsg += ' and enter a description';
            } else if (!description) {
                errorMsg += 'Please enter a description';
            }
            errorMsg += '.</p>';
            
            $messages.addClass('error').html(errorMsg);
            return;
        }
        
        // Transfer data to step 2
        $('#final_amount').val(amount);
        $('#final_description').val(description);
        $('.summary-amount').text('$' + parseFloat(amount).toFixed(2));
        $('.summary-description').text(description);
        
        console.log('Switching to step 2');
        
        // Switch to step 2
        $('#step-1').hide();
        $('#step-2').show();
    });
    
    // Handle "Back" button click
    $(document).on('click', '#back-button', function() {
        console.log('Back button clicked');
        $('#step-2').hide();
        $('#step-1').show();
    });
    
    // Format card number input
    $('#nmi_card_number').on('input', function() {
        let value = $(this).val().replace(/\s+/g, '').replace(/[^0-9]/gi, '');
        let formattedValue = value.match(/.{1,4}/g);
        if (formattedValue) {
            formattedValue = formattedValue.join(' ');
        } else {
            formattedValue = value;
        }
        if (formattedValue.length > 19) {
            formattedValue = formattedValue.substring(0, 19);
        }
        $(this).val(formattedValue);
    });
    
    // Only allow numbers for CVV
    $('#nmi_cvv').on('input', function() {
        $(this).val($(this).val().replace(/[^0-9]/g, ''));
    });
    
    // Only allow numbers for ZIP
    $('#nmi_zip').on('input', function() {
        $(this).val($(this).val().replace(/[^0-9]/g, ''));
    });
    
    // Handle form submission
    $('#nmi-payment-form').on('submit', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const $submitBtn = $form.find('.nmi-pay-button');
        const $messages = $('#nmi-payment-messages');
        
        // Store original button text
        if (!$submitBtn.data('original-text')) {
            $submitBtn.data('original-text', $submitBtn.text());
        }
        
        // Disable submit button and show loading
        $submitBtn.prop('disabled', true).text('Processing...');
        $messages.removeClass('error success').empty();
        
        // Validate form
        if (!validatePaymentForm($form)) {
            $submitBtn.prop('disabled', false).text($submitBtn.data('original-text'));
            return;
        }
        
        // Submit via AJAX
        $.ajax({
            url: nmi_ajax.ajax_url,
            type: 'POST',
            data: $form.serialize() + '&action=process_nmi_payment',
            success: function(response) {
                if (response.success) {
                    $messages.addClass('success').html(
                        '<p><strong>Success!</strong> ' + response.data.message + '</p>' +
                        '<p>Transaction ID: ' + response.data.transaction_id + '</p>'
                    );
                    $form[0].reset();
                    
                    // Reset to step 1 after successful payment
                    setTimeout(function() {
                        $('#step-2').hide();
                        $('#step-1').show();
                        $messages.empty();
                        // Reset step 1 as well
                        $('#selected_amount').val('');
                        $('#step1_amount').val('');
                        $('#step1_description').val('');
                        $('.amount-btn').removeClass('selected');
                        $('#other-amount-btn').removeClass('active');
                        $('#custom-amount-input').hide();
                    }, 3000);
                } else {
                    $messages.addClass('error').html(
                        '<p><strong>Error:</strong> ' + response.data + '</p>'
                    );
                }
            },
            error: function() {
                $messages.addClass('error').html(
                    '<p><strong>Error:</strong> Unable to process payment. Please try again.</p>'
                );
            },
            complete: function() {
                $submitBtn.prop('disabled', false).text($submitBtn.data('original-text'));
            }
        });
    });
    
    function validatePaymentForm($form) {
        let isValid = true;
        const $messages = $('#nmi-payment-messages');
        
        // Check required fields (skip amount and description as they're handled in step 1)
        $form.find('input[required], select[required]').not('#final_amount, #final_description').each(function() {
            if (!$(this).val().trim()) {
                isValid = false;
                $(this).addClass('error');
            } else {
                $(this).removeClass('error');
            }
        });
        
        // Validate email
        const email = $('#nmi_email').val();
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (email && !emailRegex.test(email)) {
            isValid = false;
            $('#nmi_email').addClass('error');
        }
        
        // Validate card number (basic Luhn algorithm)
        const cardNumber = $('#nmi_card_number').val().replace(/\s/g, '');
        if (cardNumber && !isValidCardNumber(cardNumber)) {
            isValid = false;
            $('#nmi_card_number').addClass('error');
        }
        
        // Validate CVV
        const cvv = $('#nmi_cvv').val();
        if (cvv && (cvv.length < 3 || cvv.length > 4)) {
            isValid = false;
            $('#nmi_cvv').addClass('error');
        }
        
        // Validate expiration date
        const expMonth = parseInt($('#nmi_exp_month').val());
        const expYear = parseInt($('#nmi_exp_year').val());
        if (expMonth && expYear) {
            const currentDate = new Date();
            const currentMonth = currentDate.getMonth() + 1;
            const currentYear = currentDate.getFullYear();
            
            if (expYear < currentYear || (expYear === currentYear && expMonth < currentMonth)) {
                isValid = false;
                $('#nmi_exp_month, #nmi_exp_year').addClass('error');
            }
        }
        
        if (!isValid) {
            $messages.addClass('error').html(
                '<p><strong>Error:</strong> Please check the highlighted fields and try again.</p>'
            );
        }
        
        return isValid;
    }
    
    function isValidCardNumber(number) {
        if (!/^\d{13,19}$/.test(number)) {
            return false;
        }
        
        // Luhn algorithm
        let sum = 0;
        let alternate = false;
        for (let i = number.length - 1; i >= 0; i--) {
            let n = parseInt(number.charAt(i), 10);
            if (alternate) {
                n *= 2;
                if (n > 9) {
                    n = (n % 10) + 1;
                }
            }
            sum += n;
            alternate = !alternate;
        }
        return (sum % 10) === 0;
    }
});