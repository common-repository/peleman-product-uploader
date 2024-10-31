(function ($) {
    'use strict';
    $('#generate-peleman-auth-key').on('click', generateRandomString);

    function generateRandomString(e) {
        e.preventDefault();
        const chars =
            'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        let result = '';
        for (var i = 0; i < 31; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }

        $('#peleman-authorization-key').val(result);
    }
})(jQuery);
