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
use Paysafe\CustomerVault\Address;
use Paysafe\CustomerVault\Card;
use Paysafe\Environment;
use Paysafe\CustomerVault\Profile;

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

    // Setup the API client.
    $client = new PaysafeApiClient('devcentre322', 'B-qa2-0-53625f86-302c021476f52bdc9deab7aea876bb28762e62f92fc6712d0214736abf501e9675e55940e83ef77f5c304edc7968', Environment::TEST, 89987201);

    // Get the remote id, which is the id from paysafe.
    $remote_id = $payment_method->getRemoteId();

    // Load in current user.
    $uid = \Drupal::currentUser()->id();

    // Get the user data service to store stuff in the user data table.
    $userData = \Drupal::service('user.data');

    // Get the billing address.
    $billing_address = $payment_method->getBillingProfile()->address->first();

    // If there is no remote id, we need to check if there is a profile
    // already created and if not then create one, if there is one
    // then we can use the value in the user field.
    if (!$remote_id) {

      // Get the profile id from the user data table.
      $profile_id = $userData->get('mj_commerce_paysafe', $uid, 'profile_id');

      // Get the address id from the user data table.
      $address_id = $userData->get('mj_commerce_paysafe', $uid, 'address_id');

      // If there is no profile id we have to create one.
      if (!$profile_id) {

        // Need to setup the merchant id, which is a unique value for this customer.
        // We are going to use mj-<drupal_uid>.
        $merchant_id = 'mj-site-' . \Drupal::currentUser()->id();

        // Setup the profile.
        $profile = $client->customerVaultService()->createProfile(
          new Profile(
            [
              "merchantCustomerId" => $merchant_id,
              "locale" => "en_US",
              "firstName" => $billing_address->getGivenName(),
              "lastName" => $billing_address->getFamilyName(),
              "email" => \Drupal::currentUser()->getEmail(),
            ]
          )
        );

        // Get the profile id of this profile from the API call.
        $profile_id = $profile->id;

        // Set the profile id in the data user table.
        $userData->set('mj_commerce_paysafe', $uid, 'profile_id', $profile_id);
      }

      // Setup the recipient_name which is first_name last_name.
      $recipient_name = $billing_address->getGivenName() . ' ' . $billing_address->getFamilyName();

      // If there is no address id, then we need to create an address.
      // If there is an address id, then we need to check that they are
      // are the same as entered in the form.
      if (!$address_id) {

        // Setup the address as provided from the form.
        $address = new Address(
          [
            "profileID" => $profile_id,
            "nickName" => $billing_address->getGivenName() . '-' . $billing_address->getFamilyName(),
            "street" => $billing_address->getAddressLine1(),
            "street2" => $billing_address->getAddressLine2(),
            "city" => $billing_address->getLocality(),
            "country" => $billing_address->getCountryCode(),
            "state" => $billing_address->getAdministrativeArea(),
            "zip" => $billing_address->getPostalCode(),
            "recipientName" => $billing_address->getGivenName() . ' ' . $billing_address->getFamilyName()
          ]
        );

        // Perform the address API call.
        $address = $client->customerVaultService()->createAddress($address);

        // Set the address id in the data user table.
        $userData->set('mj_commerce_paysafe', $uid, 'address_id', $address->id);
      }
      else {

        // Get the address from the API.
        $address = $client->customerVaultService()->getAddress(
          new Address(
            [
              'id' => $address_id,
              'profileID' => $profile_id,
            ]
          )
        );

        // Fields to check for the address.
        $fields_to_check = [
          'street' => 'address_line1',
          'city' => 'locality',
          'country' => 'country_code',
          'state' => 'administrative_area',
          'zip' => 'postal_code',
        ];

        // Flag to check if we update address.
        $update_address = FALSE;

        // Step through each field and see if we have to update the
        // address.
        foreach ($fields_to_check as $key => $field_to_check) {

          // If the keys are not the same, we need to update the address.
          if ($address->$key !== $billing_address->$field_to_check) {

            // Set the flag to update the address.
            $update_address = TRUE;

            // Break out of the loop, since we found a change.
            break;
          }
        }

        // If there is an update to an address, then update it.
        if ($update_address) {

          // Update all the address fields.
          $address->nickName = $billing_address->getGivenName() . '-' . $billing_address->getFamilyName();
          $address->street = $billing_address->getAddressLine1();
          $address->street2 = $billing_address->getAddressLine2();
          $address->city = $billing_address->getLocality();
          $address->country = $billing_address->getCountryCode();
          $address->state = $billing_address->getAdministrativeArea();
          $address->zip = $billing_address->getPostalCode();
          $address->recipientName = $billing_address->getGivenName() . ' ' . $billing_address->getFamilyName();

          // Complete the update to the API.
          $response = $client->customerVaultService()->updateAddress($address);
        }
      }

      // Setup the new card.
      $card = new Card(array(
        "profileID" => $profile_id,
        "holderName" => $billing_address->getGivenName() . ' ' . $billing_address->getFamilyName(),
        "cardNum" => $payment_details['number'],
        "cardExpiry" => [
          'month' => (int)$payment_details['expiration']['month'],
          'year' => (int)$payment_details['expiration']['year'],
        ],
        "billingAddressId" => $address_id,
      ));

      // Perform the card API call.
      $card = $client->customerVaultService()->createCard($card);
    }

    // Store the details of the card.
    $payment_method->card_type = $payment_details['type'];
    $payment_method->card_exp_month = $payment_details['expiration']['month'];
    $payment_method->card_exp_year = $payment_details['expiration']['year'];
    $payment_method->remote_id = $card->id;
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

    // Load in current user.
    $uid = \Drupal::currentUser()->id();

    // Get the user data service to store stuff in the user data table.
    $userData = \Drupal::service('user.data');

    // Get the profile id from the user data table.
    $profile_id = $userData->get('mj_commerce_paysafe', $uid, 'profile_id');

    // Get the address id from the user data table.
    $address_id = $userData->get('mj_commerce_paysafe', $uid, 'address_id');

    // If the API is offline, set a message and throw an exception.
    if (!$isOnline) {
      $message = $this->t('We apologize but our payment processing is not available at this time, please try again or contact andrea@montgomeryjames.ca for assistance');
      $this->messenger()->addError($message);
      throw new PaymentGatewayException('Count not capture payment. ');
    }

    // Get the info about the payment method.
    $payment_method = $payment->getPaymentMethod();

    // Get the remote id from the payment method.
    $card_id = $payment_method->getRemoteId();

    // Make the call to the API for the card.
    $card = $client->customerVaultService()->getCard(
      new Card(
        [
          'id' => $card_id,
          'profileID' => $profile_id,
        ]
      )
    );

    // Get the address from the API.
    $address = $client->customerVaultService()->getAddress(
      new Address(
        [
          'id' => $address_id,
          'profileID' => $profile_id,
        ]
      )
    );

    // Set the marchant id, which is mj-<order_number>.
    $merchant_id = 'mj-site-' . $payment->getOrderId();

    // Create a new authorization.
    $auth = new Authorization(
      [
        'settleWithAuth' => true,
        'merchantRefNum' => $merchant_id,
        'amount' => $payment->getAmount()->getNumber(),
        'card' => array(
          'paymentToken' => $card->paymentToken
        ),
        'billingDetails' => [
          'street' => $address->street,
          'city' => $address->city,
          'state' => $address->state,
          'country' => $address->country,
          'zip' => $address->zip,
        ]
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
          $payment->setRemoteId($response->id);
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
