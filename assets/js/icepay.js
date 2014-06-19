jQuery(function() {
    (function($) {
        $('.icepay-postback-url').click(function(){
            $(this).select();
        });

        var button = '<input id="ic_refreshpaymentmethods" type="submit" value="'+objectL10n.refresh+'" class="button" />';                
        $('.icpaymentmethods').append(button);     
        
        $('.icepay-postback-url').attr('readonly', 'readonly');
        
        $('#ic_refreshpaymentmethods').click(function(e){
            e.preventDefault();

            $.ajax({
                type: 'post',
                url: 'admin-ajax.php',
                data: {
                    action: 'ic_getpaymentmethods'
                },
                beforeSend: function() {
                    $('#ic_refreshpaymentmethods').val(objectL10n.loading).css('cursor', 'waiting').attr('disabled', 'disabled');
                    $('body').css('cursor', 'progress');
                    $('.ic_getpaymentmethods_error').remove();
                    $('#icepay-paymentmethod-list > li').remove();
                },
                success: function(html){
                    $('#icepay-paymentmethod-list').append(html);
                    $('#ic_refreshpaymentmethods').val(objectL10n.refresh).removeAttr('disabled');
                    $('body').css('cursor', 'auto');
                }
            });
        });
    })(jQuery.noConflict());
});