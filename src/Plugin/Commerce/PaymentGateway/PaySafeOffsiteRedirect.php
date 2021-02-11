<?php

namespace Drupal\mj_commerce_paysafe\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use GuzzleHttp\ClientInterface;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "paysafe_offsite_redirect",
 *   label = "PaySafe (Off-site redirect)",
 *   display_label = "Paysafe",
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa",
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
class PaySafeOffsiteRedirect extends OnsitePaymentGatewayBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'endpoint' => 'https://api.paysafe.com',
      'account_id' => '',
      'username' => '',
      'api_key' => '',
      'redirect_method' => 'post',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // The endpoint type for Paysafe.
    $form['endpoint'] = [
      '#type' => 'select',
      '#title' => $this->t('Endpoint'),
      '#description' => $this->t("Select your API endpoint"),
      '#options' => [
        'https://api.test.paysafe.com' => 'Test',
        'https://api.paysafe.com' => 'Production',
      ],
      '#default_value' => $this->configuration['endpoint'] ?: 'https://api.paysafe.com',
    ];

    // The account id for Paysafe.
    $form['account_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Account ID'),
      '#description' => $this->t('Enter your Paysafe Account ID'),
      '#default_value' => $this->configuration['account_id'] ?: '',
      '#required' => TRUE,
    ];

    // The username for Paysafe.
    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#description' => $this->t('Enter your Paysafe username'),
      '#default_value' => $this->configuration['username'],
      '#required' => TRUE,
    ];

    // The API key for Paysafe.
    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#description' => $this->t('Enter your Paysafe API secret key'),
      '#default_value' => $this->configuration['api_key'] ?: '',
      '#required' => TRUE,
    ];

    // A real gateway would always know which redirect method should be used,
    // it's made configurable here for test purposes.
    $form['redirect_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Redirect method'),
      '#options' => [
        'get' => $this->t('Redirect via GET (302 header)'),
        'post' => $this->t('Redirect via POST (automatic)'),
        'post_manual' => $this->t('Redirect via POST (manual)'),
      ],
      '#default_value' => $this->configuration['redirect_method'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['endpoint'] = $values['endpoint'];
      $this->configuration['account_id'] = $values['account_id'];
      $this->configuration['username'] = $values['username'];
      $this->configuration['api_key'] = $values['api_key'];
      $this->configuration['redirect_method'] = $values['redirect_method'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    // @todo Add examples of request validation.
    // Note: Since requires_billing_information is FALSE, the order is
    // not guaranteed to have a billing profile. Confirm that
    // $order->getBillingProfile() is not NULL before trying to use it.
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => 'completed',
      'amount' => $order->getBalance(),
      'payment_gateway' => $this->parentEntity->id(),
      'order_id' => $order->id(),
      'remote_id' => $request->query->get('txn_id'),
      'remote_state' => $request->query->get('payment_status'),
    ]);
    $payment->save();
  }

  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details)
  {
    $test = '';
    // TODO: Implement createPaymentMethod() method.
  }

  public function createPayment(PaymentInterface $payment, $capture = TRUE)
  {

    $url = 'https://api.test.paysafe.com/cardpayments/v1/accounts/89987201/auths';

    $payment_method = $payment->getPaymentMethod();
    $amount = $payment->getAmount()->getNumber();
    $currency_code = $payment->getAmount()->getCurrencyCode();
    $order = $payment->getOrder();
    $order_id = $payment->getOrderId();
    $owner = $payment_method->getOwner();
    $billing_address = $payment_method->getBillingProfile()->address->first();
    $country = $billing_address->getCountryCode();


    $data = json_encode([
      "merchantRefNum" => "authonlydemo-11612642284243",
      "amount" => 10098,
      "settleWithAuth" => false,
      "card" => [
        "cardNum" => 4111111111111111,
        "cardExpiry" => [
          "month" => 2,
          "year" => 2027,
        ],
      ],
      "billingDetails" => [
        "street" => "100 Queen Street West",
        "city" => "Toronto",
        "state" => "ON",
        "country" => "CA",
        "zip" => "M5H 2N2",
      ],
    ]);

    $test = '';
    // Generated by curl-to-PHP: http://incarnate.github.io/curl-to-php/
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, 'https://api.test.paysafe.com/cardpayments/v1/accounts/89987201/auths');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_USERPWD, 'devcentre322' . ':' . 'B-qa2-0-53625f86-302c021476f52bdc9deab7aea876bb28762e62f92fc6712d0214736abf501e9675e55940e83ef77f5c304edc7968');

    $headers = array();
    $headers[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
      echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);


    // Decode the API call.
    $response = json_decode($result->getBody());

    $test = '';
    // TODO: Implement createPayment() method.
  }

  public function deletePaymentMethod(PaymentMethodInterface $payment_method)
  {
    // TODO: Implement deletePaymentMethod() method.
  }
}
