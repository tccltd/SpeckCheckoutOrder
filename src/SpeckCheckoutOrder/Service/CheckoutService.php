<?php

namespace SpeckCheckoutOrder\Service;

use SpeckOrder\Entity\Order;
use SpeckOrder\Entity\OrderLine;
use SpeckOrder\Entity\OrderLineMeta;
use SpeckOrder\Entity\OrderMeta;
use TccCheckout\Strategy\TccCheckoutStrategy;

class CheckoutService
{
    protected $checkoutService;
    protected $cartService;

    public function __construct($checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }

    public function getOrder()
    {
        $cart = $this->getCartService()->getSessionCart();

        /* @var $checkoutStrategy \TccCheckout\Strategy\TccCheckoutStrategy */
        $checkoutStrategy = $this->checkoutService->getCheckoutStrategy();

        // Should this use hydrators?
        $order = new Order();
        $order->setCreatedNow();
        $order->setCustomerId($checkoutStrategy->getCustomer()->getUserId());
        $order->setStatus('received');
        $order->setShippingAddress($checkoutStrategy->getShippingAddress());
        $order->setBillingAddress($checkoutStrategy->getBillingAddress());

        $customer = $checkoutStrategy->getCustomer();

        $meta = new OrderMeta();
        $meta->setCustomerTitle($customer->getTitle())
             ->setCustomerFirstName($customer->getFirstName())
             ->setCustomerLastName($customer->getLastName())
//             ->setCustomerEmail($customer->getEmailAddress())
             ->setCustomerTelephone($customer->getTelephone())
             ->setCustomerAddress($order->getShippingAddress())
             ->setCustomerJobTitle($customer->getJobRole())
             ->setCustomerCompanyName($customer->getCompany())
             ->setCustomerCompanySize($customer->getCompanySize())
//              ->setBillingFirstName()
//              ->setBillingLastName()
//              ->setBillingTelephone()
             ->setBillingAddress($order->getBillingAddress());

        $order->setMeta($meta);


        // TODO: Bridge module between Cart and Order...
        // TODO: Abstract this somewhere.
        $recursiveDescription = function ($item) use (&$recursiveDescription) {
            $name = $item->getDescription();
            foreach ($item->getItems() as $child) {
                $name .= ' - ' . $recursiveDescription($child);
            }
            return $name;
        };

        foreach($cart as $item) {
            $orderLine = new OrderLine();
            $orderLine->setOrder($order);
            $orderLine->setDescription($recursiveDescription($item));

            $meta = new OrderLineMeta();
            foreach ($checkoutStrategy->getDelegates()[$item->getCartItemId()] as $delegate) {
                $meta->addDelegate($delegate->getFirstName(), $delegate->getSurname(), $delegate->getEmail());
            }
            $orderLine->setMeta($meta);
            $order->addItem($orderLine);
        }

//         echo '===== ORDER =====' . PHP_EOL;
//         var_dump($order);
//         echo '===== CART =====' . PHP_EOL;
//         var_dump($cart);
//         echo '===== STRATEGY =====' . PHP_EOL;
//         var_dump($checkoutStrategy);
//         die("SpeckCheckoutOrder - CheckoutService");

        return $order;
    }

    /**
     * @return \SpeckCatalogCart\Service\CartService
     */
    public function getCartService()
    {
        return $this->cartService;
    }


    public function setCartService($cartService)
    {
        $this->cartService = $cartService;

        // Fluent interface.
        return $this;
    }
}
