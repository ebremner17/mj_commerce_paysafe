<?php

namespace Drupal\mj_commerce_paysafe\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Paysafe\CardPayments\Authorization;
use Paysafe\PaysafeException;
use Symfony\Component\HttpFoundation\Request;
use Paysafe\PaysafeApiClient;
use Paysafe\Environment;

/**
 * Provides the Onsite payment gateway for Paysafe.
 *
 * @CommercePaymentGateway(
 *   id = "paysafe_onsite_payment",
 *   label = "PaySafe",
 *   display_label = "Paysafe",
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "mastercard", "visa",
 *   },
 *   requires_billing_information = TRUE,
 * )
 */
class PaySafeOnSitePayment extends OnsitePaymentGatewayBase {

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

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {

    // Store the details of the card.
    $payment_method->card_type = $payment_details['type'];
    $payment_method->card_number = $payment_details['number'];
    $payment_method->card_exp_month = $payment_details['expiration']['month'];
    $payment_method->card_exp_year = $payment_details['expiration']['year'];
    $payment_method->security_code = $payment_details['security_code'];
    $expires = CreditCard::calculateExpirationTimestamp($payment_details['expiration']['month'], $payment_details['expiration']['year']);
    $payment_method->setExpiresTime($expires);
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {

    // Setup the client using the Paysafe SDK.
    $client = new PaysafeApiClient('devcentre322', 'B-qa2-0-53625f86-302c021476f52bdc9deab7aea876bb28762e62f92fc6712d0214736abf501e9675e55940e83ef77f5c304edc7968', Environment::TEST, 89987201);

    // Check that the API is online.
    $isOnline = $client->cardPaymentService()->monitor();

    // If the API is offline, set a message and throw an exception.
    if (!$isOnline) {
      $message = $this->t('We apologize but our payment processing is not available at this time, please try again or contact andrea@montgomeryjames.ca for assistance');
      $this->messenger()->addError($message);
      throw new PaymentGatewayException('Count not capture payment. ');
    }

    $payment_method = $payment->getPaymentMethod();

    // Get the card number.
    $card_number = $payment_method->card_number->getValue()[0]['value'];

    // Get the card expiry information.
    $card_expire = [
      'month' => $payment_method->card_exp_month->getValue()[0]['value'],
      'year' => $payment_method->card_exp_year->getValue()[0]['value'],
    ];

    // Create a new authorization.
    $auth = new Authorization(
      [
        'settleWithAuth' => true,
        'merchantRefNum' => $order_id,
        'amount' => $payment->getAmount()->getNumber(),
        'card' => [
          'cardNum' => $payment_method->card_number->getValue()[0]['value'],
          'cvv' => 123,
          'cardExpiry' => $card_expire,
        ],
        'billingDetails' => [
          "street" => $billing_address->getAddressLine1(),
          "city" => $billing_address->getLocality(),
          "state" => $billing_address->getAdministrativeArea(),
          "country" => $billing_address->getCountryCode(),
          "zip" => $billing_address->getPostalCode(),
        ],
      ]
    );

    // Attempt to authorize a payment.
    try {
      $response = $client->cardPaymentService()->authorize($auth);
    }

    // Catch any exemption that was returned from Paysafe, more than likely API
    // was not available or the order was already sent for processing.
    catch (PaysafeException $e) {
      $message = $this->t('There was an error trying to process your payment, please try again or contact andrea@montgomeryjames.ca for assistance');
      $this->messenger()->addError($message);
      throw new PaymentGatewayException('Count not capture payment. ');
    }

    // Ensure that we have a response and then process it.
    if (!empty($response) && is_object($response)) {

      // Process the response.
      switch ($response->__get('status')) {

        // The order was completed without any errors.
        case 'COMPLETED':
          $next_state = $capture ? 'completed' : 'authorization';
          $payment->setState($next_state);
          $payment->setRemoteId($response->transaction->id);
          $payment->setExpiresTime(strtotime('+5 days'));
          $payment->save();
          break;

        // The payment was declined.
        case 'FAILED':
          $message = $this->t('We apologize but the transaction was declined, please try again or contact andrea@montgomeryjames.ca for assistance');
          $this->messenger()->addError($message);
          throw new DeclineException('Payment was declined');
          break;

        // The purchase was held.
        case 'HELD':
          $message = $this->t('Your purchase was held for risk review.  If it is released, we will let you know.');
          $this->messenger()->addError($message);
          throw new PaymentGatewayException('Payment held.');
          break;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method)
  {
    // TODO: Implement deletePaymentMethod() method.
  }
}
