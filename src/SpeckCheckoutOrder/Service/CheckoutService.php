<?php

namespace SpeckCheckoutOrder\Service;

use SpeckOrder\Entity\Order;
use SpeckOrder\Entity\OrderLine;
use SpeckOrder\Entity\OrderLineMeta;
use SpeckOrder\Entity\OrderMeta;
use TccCheckout\Strategy\TccCheckoutStrategy;
use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerAwareTrait;

class CheckoutService implements EventManagerAwareInterface
{
    use EventManagerAwareTrait;


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
        $billee = $checkoutStrategy->getBillee();

        $meta = new OrderMeta();
        $meta->setCustomerTitle($customer->getTitle())
             ->setCustomerFirstName($customer->getFirstName())
             ->setCustomerLastName($customer->getSurname())
             ->setCustomerEmail($checkoutStrategy->getEmailAddress())
             ->setCustomerTelephone($customer->getTelephone())
             ->setCustomerAddress($order->getShippingAddress())
             ->setCustomerJobTitle($customer->getJobRole())
             ->setCustomerCompanyName($customer->getCompany())
             ->setCustomerCompanySize($customer->getCompanySize())
             ->setBillingName($billee->getName())
             ->setBillingEmail($billee->getEmail())
             ->setBillingTelephone($billee->getTelephone())
             ->setBillingAddress($order->getBillingAddress())
             ->setPaymentMethod($checkoutStrategy->getPaymentMethod())
             ->setPaymentDue($checkoutStrategy->getPaymentDate()->format('Ymd'));

        $order->setMeta($meta);


        // TODO: Bridge module between Cart and Order...
        // TODO: Abstract this somewhere.
        // TODO: I think it IS abstracted somewhere but no time to find it now...
        $recursiveDescription = function ($item) use (&$recursiveDescription) {
            $name = $item->getDescription();
            foreach ($item->getItems() as $child) {
                $name .= ' - ' . $recursiveDescription($child);
            }
            return $name;
        };

        /* @var $item \SpeckCart\Entity\CartItem */
        foreach($cart as $item) {
            $orderLine = new OrderLine();
            $orderLine->setOrder($order)
                      ->setDescription($recursiveDescription($item))
                      ->setPrice($item->getPrice(false, true))
                      ->setTax($item->getTax(true))
                      ->setQuantityInvoiced((int)$item->getQuantity())
                      ->setQuantityRefunded(0)
                      ->setQuantityShipped(0);

            $meta = new OrderLineMeta();
            $delegates = $checkoutStrategy->getDelegates();
            if(isset($delegates[$item->getCartItemId()])) {
                foreach ($checkoutStrategy->getDelegates()[$item->getCartItemId()] as $delegate) {
                    $meta->addDelegate($delegate->getFirstName(), $delegate->getSurname(), $delegate->getEmail());
                }
            }

            $meta->setProductId($item->getMetadata()->getProductId());

            // See if anyone wants to add any additional metadata about this item
            $this->getEventManager()->trigger('additionalMetaRequest', $this, array('meta' => $meta, 'cartItem' => $item, 'checkoutStrategy' => $checkoutStrategy));

            $orderLine->setMeta($meta);
            $order->addItem($orderLine);
        }
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
