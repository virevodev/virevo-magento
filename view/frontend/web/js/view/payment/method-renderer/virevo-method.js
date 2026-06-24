define([
    'Magento_Checkout/js/view/payment/default'
], function (Component) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Virevo_Payment/payment/virevo'
        }
        // Flux offsite : la redirection après placement de commande est gérée
        // côté serveur par Model\Virevo::getOrderPlaceRedirectUrl().
    });
});
