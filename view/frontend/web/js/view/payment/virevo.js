define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'virevo',
        component: 'Virevo_Payment/js/view/payment/method-renderer/virevo-method'
    });

    return Component.extend({});
});
