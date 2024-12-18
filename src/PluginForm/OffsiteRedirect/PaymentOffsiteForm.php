<?php

namespace Drupal\commerce_paymentdrupal_v9\PluginForm\OffsiteRedirect;

use Drupal;
use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityStorageException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PaymentOffsiteForm class
 */
class PaymentOffsiteForm extends BasePaymentOffsiteForm implements ContainerInjectionInterface
{
    protected $requestStack;

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('request_stack')
        );
    }

    private function generateRandomString($length = 6): string {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        $form = parent::buildConfigurationForm($form, $form_state);

        /** @var PaymentInterface $payment */
        $payment = $this->entity;

        /** @var OffsitePaymentGatewayInterface $payment_gateway_plugin */
        $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

        $merchant_id  = $payment_gateway_plugin->getConfiguration()['merchant_id'];
        $partner_name  = $payment_gateway_plugin->getConfiguration()['partner_name'];
        $secret_key  = $payment_gateway_plugin->getConfiguration()['secret_key'];
        $redirect_url  = $payment_gateway_plugin->getConfiguration()['redirect_url'];
        $test_url = $payment_gateway_plugin->getConfiguration()['test_url'];

        $mode = $test_url;
        $url =  $mode;

        $orderId = $payment->getOrderId();
        try {
            $payment->save();
        } catch (EntityStorageException $e) {
            Drupal::logger('your_module')->error(
                'Entity storage exception: @message',
                ['@message' => $e->getMessage()]
            );
        }

        $merchantTransactionId = $this->generateRandomString();
        $amount = number_format((float)$payment->getAmount()->getNumber(), 2, '.', '');

        $checksum_maker = $merchant_id . '|' . $partner_name . '|' . $amount . '|' . $merchantTransactionId . '|' . $redirect_url. '|' . $secret_key;
                
        $checksum = md5($checksum_maker);
       

        $order = Order::load($orderId);

        $data = [
            'toid'  => $merchant_id,
            'totype'  => $partner_name,
            'merchantRedirectUrl'  => $redirect_url,
            'currency'     => $payment->getAmount()->getCurrencyCode(),   
            'amount'       => $amount,
            'description'  => $merchantTransactionId,
            'checksum'  => $checksum,
        ];

        foreach ($order->getItems() as $order_item) {
            $product = $order_item->getPurchasedEntity()->getProduct();

            if (isset($product->field_subscription_type->value) && isset($product->field_recurring_amount->value)
                && isset($product->field_frequency->value) && isset($product->field_cycles->value)) {
                $data['custom_str2']       = gmdate('Y-m-d');
                $data['subscription_type'] = $product->field_subscription_type->value;
                $data['recurring_amount']  = number_format(
                    (float)sprintf('%.2f', $product->field_recurring_amount->value),
                    2,
                    '.',
                    ''
                );
                $data['frequency']         = $product->field_frequency->value;
                $data['cycles']            = $product->field_cycles->value;
            }
        }

        $pfOutput = '';
        // Create output string
        foreach ($data as $key => $value) {
            $pfOutput .= $key . '=' . urlencode(trim($value)) . '&';
        }
        $passPhrase = trim($payment_gateway_plugin->getConfiguration()['passphrase']);

        if (empty($passPhrase)) {
            $pfOutput = substr($pfOutput, 0, -1);
        } else {
            $pfOutput = $pfOutput . 'passphrase=' . urlencode($passPhrase);
        }

        $data['signature']  = md5($pfOutput);
        $data['user_agent'] = 'Drupal Commerce 2';

        try {
            $redirect_form = [];
            $redirect_form = $this->buildRedirectForm($form, $form_state, $url, $data, 'post');
        } catch (NeedsRedirectException $e) {
            Drupal::logger('your_module')->error(
                'Needs redirect exception: @message',
                ['@message' => $e->getMessage()]
            );
        }

        return $redirect_form;
    }

    public function getSiteUrl()
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            return $request->getSchemeAndHttpHost();
        }

        return '';
    }
}

