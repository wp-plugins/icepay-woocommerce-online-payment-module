jQuery(function() {
    jQuery('.icepay-postback-url').click(function(){
        jQuery(this).select(); 
    });
    
    var button = '<input id="ic_refreshpaymentmethods" type="submit" value="Refresh Payment Methods" class="button" />';
                
    jQuery('.icpaymentmethods').append(button);
    
    jQuery('#ic_refreshpaymentmethods').click(function(e){
        e.preventDefault();

        var val = jQuery(this).val();                   

        jQuery.ajax({
            type: 'post',
            url: 'admin-ajax.php',
            data: {
                action: 'ic_getpaymentmethods'
            },
            beforeSend: function() {
                jQuery('#ic_refreshpaymentmethods').val('Loading paymentmethod data...').css('cursor', 'waiting').attr('disabled', 'disabled');
                jQuery('body').css('cursor', 'progress');
                jQuery('.ic_getpaymentmethods_error').remove();
                jQuery('#icepay-paymentmethod-list > li').remove();
            }, 
            success: function(html){                            
                jQuery('#icepay-paymentmethod-list').append(html);
                jQuery('#ic_refreshpaymentmethods').val(val).removeAttr('disabled');
                jQuery('body').css('cursor', 'auto');
            }
        });   
    });
});