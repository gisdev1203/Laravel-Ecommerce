<?php

namespace App\Http\Controllers\Front;

use App\Addresses\Repositories\Interfaces\AddressRepositoryInterface;
use App\Cart\Requests\CartCheckoutRequest;
use App\Carts\Repositories\Interfaces\CartRepositoryInterface;
use App\Couriers\Repositories\Interfaces\CourierRepositoryInterface;
use App\Customers\Repositories\Interfaces\CustomerRepositoryInterface;
use App\OrderDetails\OrderProduct;
use App\OrderDetails\Repositories\OrderProductRepository;
use App\Orders\Order;
use App\Orders\Repositories\Interfaces\OrderRepositoryInterface;
use App\PaymentMethods\PaymentMethod;
use App\PaymentMethods\Paypal\Exceptions\PaypalRequestError;
use App\PaymentMethods\Paypal\PaypalExpress;
use App\PaymentMethods\Repositories\Interfaces\PaymentMethodRepositoryInterface;
use App\Products\Product;
use App\Products\Repositories\Interfaces\ProductRepositoryInterface;
use App\Products\Repositories\ProductRepository;
use Exception;
use Gloudemans\Shoppingcart\CartItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Exception\PayPalConnectionException;
use Ramsey\Uuid\Uuid;

class CheckoutController extends Controller
{
    private $cartRepo;
    private $courierRepo;
    private $paymentRepo;
    private $addressRepo;
    private $customerRepo;
    private $productRepo;
    private $orderRepo;
    private $paypal;

    public function __construct(
        CartRepositoryInterface $cartRepository,
        CourierRepositoryInterface $courierRepository,
        PaymentMethodRepositoryInterface $paymentMethodRepository,
        AddressRepositoryInterface $addressRepository,
        CustomerRepositoryInterface $customerRepository,
        ProductRepositoryInterface $productRepository,
        OrderRepositoryInterface $orderRepository
    )
    {
        $this->middleware('checkout');

        $this->cartRepo = $cartRepository;
        $this->courierRepo = $courierRepository;
        $this->paymentRepo = $paymentMethodRepository;
        $this->addressRepo = $addressRepository;
        $this->customerRepo = $customerRepository;
        $this->productRepo =  $productRepository;
        $this->orderRepo = $orderRepository;
        $this->paypal = new PaypalExpress(
            config('paypal.client_id'),
            config('paypal.client_secret'),
            config('paypal.mode'),
            config('paypal.api_url')

        );
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $productRepo = new ProductRepository(new Product);

        $items = collect($this->cartRepo->getCartItems())->map(function (CartItem $item) use ($productRepo) {
            $product = $productRepo->findProductById($item->id);
            $item->product = $product;
            $item->cover = $product->cover;
            return $item;
        });

        $customer = $this->customerRepo->findCustomerById(auth()->user()->id);

        $payments = collect($this->paymentRepo->listPaymentMethods())->filter(function (PaymentMethod $method){
            return $method->status == 1;
        });

        return view('front.checkout', [
            'customer' => $customer,
            'products' => $items,
            'subtotal' => $this->cartRepo->getSubTotal(),
            'tax' => $this->cartRepo->getTax(),
            'total' => $this->cartRepo->getTotal(),
            'couriers' => $this->courierRepo->listCouriers(),
            'payments' => $payments,
            'addresses' => $customer->addresses
        ]);
    }

    /**
     * Checkout the items
     *
     * @param CartCheckoutRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(CartCheckoutRequest $request)
    {
        $cartItems = collect($this->cartRepo->getCartItems())->map(function (CartItem $item){
            $product = $this->productRepo->findProductById($item->id);
            $item->description = $product->description;
            return $item;
        });

        $method = $this->paymentRepo->findPaymentMethodById($request->input('payment'));

        if ($method->slug == 'paypal') {

            $this->paypal->setPayer();
            $this->paypal->setItems($cartItems);
            $this->paypal->setOtherFees(
                $this->cartRepo->getSubTotal(),
                $this->cartRepo->getTax()
            );
            $this->paypal->setAmount($this->cartRepo->getTotal());
            $this->paypal->setTransactions();

            try {

                $response = $this->paypal->createPayment(
                    route('checkout.execute', $request->except('_token')),
                    route('checkout.cancel')
                );

                if ($response) {
                    $redirectUrl = $response->links[1]->href;
                    return redirect()->to($redirectUrl);
                }

            } catch (PayPalConnectionException $e) {

                throw new PaypalRequestError($e->getMessage());
            }
        }
    }

    /**
     * Execute the paypal payment
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function execute(Request $request)
    {
        $apiContext = $this->paypal->getApiContext();

        $payment = Payment::get($request->input('paymentId'), $apiContext);

        $execution = new PaymentExecution();
        $execution->setPayerId($request->input('PayerID'));

        try {

            if($payment->execute($execution, $apiContext)) {

                foreach ($payment->getTransactions() as $t) {
                    return $this->buildOrder([
                        'reference' => Uuid::uuid4()->toString(),
                        'courier_id' => $request->input('courier'),
                        'customer_id' => Auth::id(),
                        'address_id' => $request->input('address'),
                        'order_status_id' => 1,
                        'payment_method_id' => $request->input('payment'),
                        'discounts' => 0,
                        'total_products' =>  $this->cartRepo->getSubTotal(),
                        'total' => $this->cartRepo->getTotal(),
                        'total_paid' => $t->getAmount()->getTotal(),
                        'tax' => $this->cartRepo->getTax()
                    ]);
                }
            }

        } catch (PayPalConnectionException $e) {
            throw new PaypalRequestError($e->getData());
        } catch (Exception $e) {
            throw new PaypalRequestError($e->getMessage());
        }
    }

    /**
     * Cancel page
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function cancel(Request $request)
    {
        return view('front.checkout-cancel');
    }

    /**
     * Success page
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function success()
    {
        return view('front.checkout-success');
    }

    /**
     * Build the order
     *
     * @param array $params
     * @return \Illuminate\Http\RedirectResponse
     */
    private function buildOrder(array $params)
    {
        $order = $this->orderRepo->createOrder($params);
        return $this->buildOrderDetails($order);
    }

    /**
     * Build the order details
     *
     * @param Order $order
     * @return \Illuminate\Http\RedirectResponse
     */
    private function buildOrderDetails(Order $order)
    {
        foreach ($this->cartRepo->getCartItems() as $item) {

            $product = $this->productRepo->find($item->id);

            $orderDetailRepo = new OrderProductRepository(new OrderProduct);
            $orderDetailRepo->createOrderDetail($order, $product, $item->qty);
        }

        return $this->clearCart();
    }

    /**
     * Clear the cart
     */
    private function clearCart()
    {
        $this->cartRepo->clearCart();
        return redirect()->route('checkout.success');
    }
}
