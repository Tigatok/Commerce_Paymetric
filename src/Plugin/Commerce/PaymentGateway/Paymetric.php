<?php

namespace Drupal\commerce_paymetric\Plugin\Commerce\PaymentGateway;

// Required for base class.
use CommerceGuys\AuthNet\DataTypes\TransactionRequest;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
// Required for base class.
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
// Required for base class.
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
// Required for base class.
use Drupal\Component\Datetime\TimeInterface;
// Required for base class.
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\ClientInterface;

use Paymetric\PaymetricTransaction;
use Paymetric\XiPaySoapClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Paymetric payment gateway.
 * We make it an offsite payment gateway base because
 * we don't want to be responsible for keeping the card.
 *
 * @CommercePaymentGateway(
 *   id = "paymetric",
 *   label = "Paymetric",
 *   display_label = "Credit Card",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_paymetric\PluginForm\PaymetricOffsiteCheckoutForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "discover", "mastercard", "visa",
 *   },
 * )
 */
class Paymetric extends OffsitePaymentGatewayBase {

  /**
   * Paymetric test API URL.
   */
  const PAYMETRIC_API_TEST_URL = 'https://test.authorize.net/gateway/transact.dll';

  /**
   * Paymetric production API URL.
   */
  const PAYMETRIC_API_URL = 'https://secure2.authorize.net/gateway/transact.dll';

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The rounder.
   *
   * @var \Drupal\commerce_price\RounderInterface
   */
  protected $rounder;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, ClientInterface $client, RounderInterface $rounder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->httpClient = $client;
    $this->rounder = $rounder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('http_client'),
      $container->get('commerce_price.rounder')
    );
  }

  /**
   * Returns the Api URL.
   */
  protected function getApiUrl() {
    return $this->getMode() == 'test' ? self::PAYMETRIC_API_TEST_URL : self::PAYMETRIC_API_URL;
  }

  /**
   * Returns the XI URL.
   */
  protected function getXIURL() {
    return $this->configuration['xipay_url'] ?: '';
  }

  /**
   * Returns the user.
   */
  protected function getUser() {
    return $this->configuration['user'] ?: '';
  }

  /**
   * Returns the password.
   */
  protected function getPassword() {
    return $this->configuration['password'] ?: '';
  }

  /**
   * Returns the XI Intercept GUID.
   */
  protected function getXIGUID() {
    return $this->configuration['xiintercept_GUID'] ?: '';
  }

  /**
   * Returns the XI Intercept PSK.
   */
  protected function getXIPSK() {
    return $this->configuration['xiintercept_PSK'] ?: '';
  }

  /**
   * Returns the XI Intercept URL.
   */
  protected function getXIIURL() {
    return $this->configuration['xiintercept_url'] ?: '';
  }

  /**
   * Returns the XIPay Merchant ID.
   */
  protected function getXIMerchantID() {
    return $this->configuration['xipay_merchantid'] ?: '';
  }

  /**
   * Returns the Credit Card Types.
   */
  protected function getCardTypes() {
    return $this->configuration['credit_card_types'] ?: '';
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'xipay_url' => '',
      'user' => '',
      'password' => '',
      'xiintercept_GUID' => '',
      'xiintercept_PSK' => '',
      'xiintercept_url' => '',
      'xipay_merchantid' => '',
      'credit_card_types' => '',
      'transaction_type' => TransactionRequest::AUTH_CAPTURE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['xipay_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('XiPay URL'),
      '#description' => t('Set the URl for authorization.'),
      '#default_value' => $this->configuration['xipay_url'],
      '#required' => TRUE,
    ];
    $form['user'] = [
      '#type' => 'textfield',
      '#title' => $this->t('XiPay User ID'),
      '#description' => t('Set USER for authorization.'),
      '#default_value' => $this->configuration['user'],
      '#required' => TRUE,
    ];
    $form['password'] = [
      // '#type' => 'password',.
      '#type' => 'textfield',
      '#title' => $this->t('XiPay Password'),
      '#description' => $this->t('Set Password for authorization.'),
      '#default_value' => $this->configuration['password'],
      // '#required' => empty($this->configuration['password']),.
      '#required' => TRUE,
    ];
    $form['xiintercept_GUID'] = [
      '#type' => 'textfield',
      '#title' => $this->t('XiIntercept GUID'),
      '#description' => t('Set the GUID for tokenization.'),
      '#default_value' => $this->configuration['xiintercept_GUID'],
      '#required' => TRUE,
    ];
    $form['xiintercept_PSK'] = [
      '#type' => 'textfield',
      '#title' => $this->t('XiIntercept PSK'),
      '#description' => t('Set PSK for authorization.'),
      '#default_value' => $this->configuration['xiintercept_PSK'],
      '#required' => TRUE,
    ];
    $form['xiintercept_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('XiIntercept URL'),
      '#description' => t('Set the URl for tokenization.'),
      '#default_value' => $this->configuration['xiintercept_url'],
      '#required' => TRUE,
    ];
    $form['xipay_merchantid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('XiPay Merchant ID'),
      '#description' => t('Set Merchant ID for tokenization.'),
      '#default_value' => $this->configuration['xipay_merchantid'],
      '#required' => TRUE,
    ];

    $form['credit_card_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Limit accepted credit cards to the following types'),
      '#description' => t('If you want to limit acceptable card types, you should only select those supported by your merchant account.') . '<br />' . t('If none are checked, any credit card type will be accepted.'),
      '#options' => ['amex' => $this->t('American Express'), 'discover' => $this->t('Discover'), 'mastercard' => $this->t('Master Card'), 'visa' => $this->t('Visa'), 'dinersclub' => $this->t('Diners Club'), 'jcb' => $this->t('JCB'), 'maestro' => $this->t('Maestro'), 'unionpay' => $this->t('Union Pay')],
      '#default_value' => $this->configuration['credit_card_types'],
      // '#default_value' => array('amex', 'discover', 'mastercard', 'visa'),.
      '#required' => TRUE,
    ];

    $form['transaction_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Default credit card transaction type'),
      '#description' => t('The default will be used to process transactions during checkout.'),
      '#default_value' => $this->configuration['transaction_type'],
      '#options' => [
        TransactionRequest::AUTH_ONLY => $this->t('Authorization only (requires manual or automated capture after checkout)'),
        TransactionRequest::AUTH_CAPTURE => $this->t('Authorization and capture'),
        // @todo AUTH_ONLY but causes capture at placed transition.
      ],
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

      $this->configuration['xipay_url'] = $values['xipay_url'];
      $this->configuration['user'] = $values['user'];
      if (!empty($values['password'])) {
        $this->configuration['password'] = $values['password'];
      }
      $this->configuration['xiintercept_GUID'] = $values['xiintercept_GUID'];
      $this->configuration['xiintercept_PSK'] = $values['xiintercept_PSK'];
      $this->configuration['xiintercept_url'] = $values['xiintercept_url'];
      $this->configuration['xipay_merchantid'] = $values['xipay_merchantid'];
      $card_types = [];
      foreach ($values['credit_card_types'] as $key => $val) {
        // Echo $key.' :: '.$val .' :: Value type: '.gettype($val).'<br>';
        if ($val !== 0) {
          // $card_types[] = $key;.
          array_push($card_types, $key);
        }
      }
      // print_r($card_types);
      $this->configuration['credit_card_types'] = $card_types;
    }
  }

  /**
   * On return save payment method.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => 'completed',
      'amount' => new Price($_GET['amount'], $_GET['currency']),
      'payment_gateway' => $this->entityId,
      'order_id' => $order->id(),
      'test' => $this->getMode() == 'test',
      'remote_id' => $_GET['transactionId'],
      'remote_state' => $_GET['message'],
      'authorized' => $this->time->getRequestTime(),
    ]);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    drupal_set_message($this->t('You have canceled checkout at @gateway but may resume the checkout process here when you are ready.', [
      '@gateway' => $this->getDisplayLabel(),
    ]));
  }

  /**
   * This is going to create the payment in the API.
   *
   * @see PaymetricOffsiteCheckoutForm::submitConfigurationForm();:w
   */
  public function createPaymentMethod($payment, $payment_details) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getEntity()->getOrder();

    /** @var \Drupal\profile\Entity\ProfileInterface $billing_profile */
    $billing_profile = $order->getBillingProfile();

    $data = $this->executeTransaction('17', [
      'amt' => $order->getTotalPrice()->getNumber(),
      'acct' => $payment_details['number'],
      'expdate' => $this->getExpirationDate($payment_details['expiration']),
      'cvv2' => $payment_details['security_code'],
      'transaction_id' => $payment_details['transaction_id'],
    ]);
    return $data;
  }

  /**
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function authorizePaymentMethod($payment, array $payment_details) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getEntity()->getOrder();

    /** @var \Drupal\profile\Entity\ProfileInterface $billing_profile */
    $billing_profile = $order->getBillingProfile();

    $data = $this->executeTransaction('1', [
      'amt' => 0,
      'acct' => $payment_details['number'],
      'expdate' => $this->getExpirationDate($payment_details['expiration']),
      'cvv2' => $payment_details['security_code'],
    ]);
    return $data;
  }

  /**
   *
   */
  public function getExpirationDate($expiration) {
    return $expiration['month'] . '/' . str_split($expiration['year'], '2')[1];
  }

  /**
   * Post a transaction to the Paymetric server and return the response.
   *
   * @param array $parameters
   *   The parameters to send (will have base parameters added).
   *
   * @return array
   *   The response body data in array format.
   */
  protected function executeTransaction($transaction_type, array $parameters) {

    $ini_array = [];
    $ini_array['XiPay-QA']['paymetric.xipay.url'] = $this->getXIURL();
    $ini_array['XiPay-QA']['paymetric.xipay.user'] = $this->getUser();
    $ini_array['XiPay-QA']['paymetric.xipay.password'] = $this->getPassword();
    $ini_array['MerchantID']['paymetric.xipay.merchantid'] = $this->getXIMerchantID();
    $ini_array['Xiintercept-QA']['paymetric.xiintercept.GUID'] = $this->getXIGUID();
    $ini_array['Xiintercept-QA']['paymetric.xiintercept.PSK'] = $this->getXIPSK();
    $ini_array['Xiintercept-QA']['paymetric.xiintercept.url'] = $this->getXIIURL();

    // 1 == Authorization.
    if ($transaction_type == 1) {
      $data = [
        'x_card_code' => $parameters['cvv2'],
        'x_exp_date' => $parameters['expdate'],
        'x_total' => $parameters['amt'],
        'x_card_num' => $parameters['acct'],
        'x_currency_code' => "USD",
      ];
      $response = $this->authorize($ini_array, $data);
    }
    // 17 == Capture.
    elseif ($transaction_type == 17) {
      $data = [
        'x_card_code' => $parameters['cvv2'],
        'x_exp_date' => $parameters['expdate'],
        'x_total' => $parameters['amt'],
        'x_card_num' => $parameters['acct'],
        'x_currency_code' => "USD",
        'x_transaction_id' => $parameters['transaction_id'],
        'x_batch_id' => '1',
      ];
      $response = $this->capture($ini_array, $data);
    }
    // 10 == Void.
    elseif ($transaction_type == 10) {

    }

    return $response;
  }

  /**
   * Authorizes a card for payment.
   */
  public function authorize($ini_array, $data) {
    if ($ini_array == FALSE) {
      $file_error = "Paymetric ini file not found. Please check the Paymetric directory.";
      throw new PaymentGatewayException($file_error);
    }

    $XiPay = new XiPaySoapClient($ini_array['XiPay-QA']['paymetric.xipay.url'],
      $ini_array['XiPay-QA']['paymetric.xipay.user'],
      $ini_array['XiPay-QA']['paymetric.xipay.password']);

    $xipayTransaction = new PaymetricTransaction();
    // Must send 0 for authorization.
    $xipayTransaction->Amount = '0.00';
    $xipayTransaction->CardCVV2 = $data['x_card_code'];
    $xipayTransaction->CardExpirationDate = $data['x_exp_date'];
    $xipayTransaction->CardNumber = $data['x_card_num'];
    $xipayTransaction->CurrencyKey = $data['x_currency_code'];
    $xipayTransaction->MerchantID = $ini_array['MerchantID']['paymetric.xipay.merchantid'];

    // Authorize the request.
    $authResponse = $XiPay->Authorize($xipayTransaction);

    /** @var \Drupal\Core\Messenger\Messenger $messenger */
    $messenger = \Drupal::service('messenger');

    // Status code success == 0.
    if ($authResponse->Status == STATUS_OK) {
      $authorized = $authResponse->Transaction;
      if ($authResponse->Transaction->ResponseCode == 104) {
        $messenger->addMessage('The card was authorized successfully.');
      }
    }
    else {
      $messenger->addError('There was an issue processing your card.');
      return $authResponse;
    }
    return $authorized;
  }

  /**
   * Authorizes a card for payment.
   */
  public function capture($ini_array, $data) {
    if ($ini_array == FALSE) {
      $file_error = "Paymetric ini file not found. Please check the Paymetric directory.";
      throw new PaymentGatewayException($file_error);
    }

    $XiPay = new XiPaySoapClient($ini_array['XiPay-QA']['paymetric.xipay.url'],
      $ini_array['XiPay-QA']['paymetric.xipay.user'],
      $ini_array['XiPay-QA']['paymetric.xipay.password']);

    $xipayTransaction = new PaymetricTransaction();
    // Must send 0 for authorization.
    $xipayTransaction->CardCVV2 = $data['x_card_code'];
    $xipayTransaction->CardExpirationDate = $data['x_exp_date'];
    $xipayTransaction->CardNumber = $data['x_card_num'];
    $xipayTransaction->CurrencyKey = $data['x_currency_code'];
    $xipayTransaction->MerchantID = $ini_array['MerchantID']['paymetric.xipay.merchantid'];
    $xipayTransaction->BatchID = '1';
    $xipayTransaction->SettlementAmount = $data['x_total'];
    $xipayTransaction->TransactionID = $data['x_transaction_id'];

    // Authorize the request.
    $authResponse = $XiPay->Capture($xipayTransaction);

    /** @var \Drupal\Core\Messenger\Messenger $messenger */
    $messenger = \Drupal::service('messenger');

    // Status code success == 0.
    if ($authResponse->Status == STATUS_OK) {
      $authorized = $authResponse->Transaction;
      if ($authResponse->Transaction->StatusCode == 200) {
        $messenger->addMessage('The transaction was captured successfully.');
      }
    }
    else {
      $messenger->addError('There was an issue processing your card.');
      return $authResponse;
    }
    return $authorized;
  }

}
