<?php

namespace SpeckCheckoutOrder\Service;

use SpeckCart\Entity\CartItem;
use SpeckCartVoucher\Entity\CartVoucherMeta;
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
            $name['name'] = $item->getDescription();

            foreach ($item->getItems() as $child) {
                $name['children'][] = $recursiveDescription($child);
            }
            return $name;
        };

        // Recursive function to strip out any cart items that have product ids in the metadata
        $recursiveProduct = function(CartItem $item) use (&$recursiveProduct, $order, $recursiveDescription, $checkoutStrategy) {
            // Loop over the child items in the current item
            foreach($item->getItems() as $childItem) {
                // Look down to the deepest level first
                $recursiveProduct($childItem);

                // If there is a product ID on the current child item... remove it
                if($childItem->getMetadata()->getProductId()) {
                    $item->removeItem($childItem);
                }
            }

            /* @var $meta \SpeckCatalogCart\Model\CartProductMeta */
            $meta = $item->getMetadata();

            // If there is a product ID or it's a CartVoucherMeta item
            if($meta->getProductId() || (($meta instanceof CartVoucherMeta) && !$item->getParentItemId())) {
                // Create the OrderLine item
                $orderLine = new OrderLine();
                $orderLine->setOrder($order)
                    ->setDescription($recursiveDescription($item))
                    ->setPrice($item->getPrice(false, true))
                    ->setTax($item->getTax(true))
                    ->setQuantityInvoiced((int)$item->getQuantity())
                    ->setQuantityRefunded(0)
                    ->setQuantityShipped(0);

                // Create the relevant meta items
                $olMeta = new OrderLineMeta();

                // Set the delegates against the meta item.
                $delegates = $checkoutStrategy->getDelegates();
                if(isset($delegates[$item->getCartItemId()])) {
                    foreach ($checkoutStrategy->getDelegates()[$item->getCartItemId()] as $delegate) {
                        $olMeta->addDelegate($delegate->getFirstName(), $delegate->getSurname(), $delegate->getEmail());
                    }
                }

                // Set the product id correctly
                $olMeta->setProductId($meta->getProductId());
                $olMeta->setItemNumber($meta->getItemNumber());

                // See if anyone wants to add any additional metadata about this item
                $this->getEventManager()->trigger(
                    'additionalMetaRequest',
                    $this,
                    ['meta' => $olMeta, 'cartItem' => $item, 'checkoutStrategy' => $checkoutStrategy]
                );

                $orderLine->setMeta($olMeta);
                $order->addItem($orderLine);
            }
        };

        // Loop over all the items and call the recursive function
        foreach($cart->getItems() as $item) {
            $recursiveProduct($item);
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
