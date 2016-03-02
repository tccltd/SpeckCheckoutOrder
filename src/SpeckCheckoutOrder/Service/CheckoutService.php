<?php

namespace SpeckCheckoutOrder\Service;

use SpeckCart\Entity\CartItemInterface;
use SpeckOrder\Entity\Order;
use SpeckOrder\Entity\OrderInterface;
use SpeckOrder\Entity\OrderLine;
use SpeckOrder\Entity\OrderLineInterface;
use SpeckOrder\Entity\OrderLineMeta;
use SpeckOrder\Entity\OrderMeta;
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


        foreach($cart as $cartItem) {
            $order->addItem($this->createOrderLineFromCartItem($cartItem, $order, null));
        }

        return $order;
    }


    protected function createOrderLineFromCartItem(CartItemInterface $cartItem, OrderInterface $order, OrderLineInterface $parentLine=null)
    {
        $orderLine = new OrderLine();
        $orderLine->setOrder($order)
            ->setDescription($cartItem->getDescription())
            ->setPrice($cartItem->getPrice())
            ->setTax($cartItem->getTax())
            ->setQuantityInvoiced($cartItem->getQuantity())
            ->setQuantityRefunded(0)
            ->setQuantityShipped(0);

        if($parentLine) {
            $orderLine->setParentLineId($parentLine->getId());
        }

        $cartItemMeta = $cartItem->getMetadata();
        $olMeta = new OrderLineMeta();

        /* @var $checkoutStrategy \TccCheckout\Strategy\TccCheckoutStrategy */
        $checkoutStrategy = $this->checkoutService->getCheckoutStrategy();

        $delegates = $checkoutStrategy->getDelegates();
        if(isset($delegates[$cartItem->getCartItemId()])) {
            foreach($checkoutStrategy->getDelegates()[$cartItem->getCartItemId()] as $delegate) {
                $olMeta->addDelegate($delegate->getFirstName(), $delegate->getSurname(), $delegate->getEmail());
            }
        }

        // TODO: Don't hard code this
        switch($cartItemMeta->getProductTypeId()) {
            case 1: $category = 'Shell'; break;
            case 2: $category = 'Product'; break;
            case 3: $category = 'Course'; break;
            case 4: $category = 'Quizical Code'; break;
            case 5: $category = 'Book'; break;
            case 6: $category = 'Examination'; break;
            case 7: $category = 'Course Group'; break;
            case 8: $category = 'Voucher'; break;
            default: $category = "";
        }

        $olMeta->setProductType($category);
        $olMeta->setProductId($cartItemMeta->getProductId());
        $olMeta->setItemNumber($cartItemMeta->getItemNumber());
        $olMeta->setManufacturer($cartItemMeta->getProductManufacturer());

        $orderLine->setMeta($olMeta);

        $this->getEventManager()->trigger(
            'additionalMetaRequest',
            $this,
            [
                'meta'             => $olMeta,
                'cartItem'         => $cartItem,
                'checkoutStragegy' => $checkoutStrategy,
                'orderLine'        => $orderLine,
            ]
        );

        foreach ($cartItem->getItems() as $item) {
            $orderLine->addItem($this->createOrderLineFromCartItem($item, $order, $orderLine));
        }

        return $orderLine;
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
