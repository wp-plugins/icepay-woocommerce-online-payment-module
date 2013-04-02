<div style="background-image: url('{image}');" id="icepay-header">
    <div id="icepay-header-info">
        {version} |
        <a href="http://www.icepay.com/downloads/pdf/manuals/wordpress-woocommerce/manual-wordpress-woocommerce.pdf" target="_BLANK">{manual}</a> | 
        <a href="http://www.icepay.com"  target="_BLANK">{website}</a>                    
    </div>
</div>

<table class="icepay-settings">
    {upgrade_notice}
    
    {settings}
   
    <ul id='icepay-paymentmethod-list'> 
        {list}
    </ul>
    
    {error}
    
    <noscript>
        <div class='error ic_getpaymentmethods_error'>Javascript must be enabled in your browser in order to fetch paymentmethods.</div>
    </noscript> 
    
    <i>{configure_text}</i>


</table>