<?php

use SpeckCheckoutOrder\Service\CheckoutService;

return array(
    'factories' => array(
        'speckcheckoutorder_checkoutService' => function ($sm) {
            $checkoutService = new CheckoutService($sm->get('SpeckCheckout\Service\Checkout'));
            // TODO: Not sure this is in the right module.
            $checkoutService->setCartService($sm->get('catalog_cart_service'));
            return $checkoutService;
        },
    ),
);
