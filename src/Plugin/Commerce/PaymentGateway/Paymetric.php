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
      // print_r($values['credit_card_types']); // When discover not selected : Array ( [amex] => amex [mastercard] => mastercard [visa] => visa [discover] => 0 )
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
   *
   */
  public function onReturn(OrderInterface $order, Request $request) {
    // TODO: Change the autogenerated stub.
    parent::onReturn($order, $request);
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
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    $stop = TRUE;
  }

  /**
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function authorizePaymentMethod($payment, array $payment_details) {
    $stop = TRUE;

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getEntity()->getOrder();

    /** @var \Drupal\profile\Entity\ProfileInterface $billing_profile */
    $billing_profile = $order->getBillingProfile();

    /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
    $address = $billing_profile->get('address')->first();

    $data = $this->executeTransaction([
      'trxtype' => 'A',
      'amt' => 0,
      'verbosity' => 'HIGH',
      'acct' => $payment_details['number'],
      'expdate' => $this->getExpirationDate($payment_details['expiration']),
      'cvv2' => $payment_details['security_code'],
    // - NO EMAIL ADDRESS for Guest checkout but not a problem as it is stored in Commerce 2.
      'billtoemail' => $billing_profile->getOwner()->getEmail(),
      'billtofirstname' => $address->getGivenName(),
      'billtolastname' => $address->getFamilyName(),
      'billtostreet' => $address->getAddressLine1(),
      'billtostreet2' => $address->getAddressLine2(),
      'billtocity' => $address->getLocality(),
      'billtostate' => $address->getAdministrativeArea(),
      'billtozip' => $address->getPostalCode(),
      'billtocountry' => $address->getCountryCode(),
    ]);
  }

  /**
   *
   */
  public function getExpirationDate($expiration) {
    return $expiration['month'] . '/' . str_split($expiration['year'], '2')[1];
  }

  /**
   * This is going to create the payment in the API.
   *
   * @see PaymetricOffsiteCheckoutForm::submitConfigurationForm();:w
   */
  public function createPayment() {
    $stop = TRUE;
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
  protected function executeTransaction(array $parameters) {

    // Echo 'Execute Transaction v1: <br>';
    // print_r($parameters);
    // echo '<br> ACCNT: '.$parameters[acct];.
    $ini_array = [];
    $ini_array['XiPay-QA']['paymetric.xipay.url'] = $this->getXIURL();
    $ini_array['XiPay-QA']['paymetric.xipay.user'] = $this->getUser();
    $ini_array['XiPay-QA']['paymetric.xipay.password'] = $this->getPassword();
    $ini_array['MerchantID']['paymetric.xipay.merchantid'] = $this->getXIMerchantID();
    $ini_array['Xiintercept-QA']['paymetric.xiintercept.GUID'] = $this->getXIGUID();
    $ini_array['Xiintercept-QA']['paymetric.xiintercept.PSK'] = $this->getXIPSK();
    $ini_array['Xiintercept-QA']['paymetric.xiintercept.url'] = $this->getXIIURL();

    $nvp = [];
    // Add the default name-value pairs to the array.
    // Evaluate if person is logged in or using guest checkout.
    $customer_email = $parameters['billtoemail'];

    $nvp += [
      'x_card_code' => $parameters['cvv2'],
      'x_card_datasource' => "E",
      'x_exp_date' => $parameters['expdate'],
      'x_address' => $parameters['billtostreet'],
      'x_address2' => $parameters['billtostreet2'],
      'x_city' => $parameters['billtocity'],
      'x_country' => $parameters['billtocountry'],
      'x_first_name' => $parameters['billtofirstname'],
      'x_last_name' => $parameters['billtolastname'],
      'x_state' => $parameters['billtostate'],
      'x_zip' => $parameters['billtozip'],
    // ???
      'x_card_type' => "mastercard",
      'x_total' => $parameters['amt'],
      'x_card_num' => $parameters['acct'],
    // ???
      'x_card_present' => 0,
    // ???
      'x_currency_code' => "USD",
      'x_email_customer' => $customer_email,
    ];

    $response = $this->authorize($ini_array, $nvp);

    echo $response;
    sleep(180);

    // Return $response;.
  }

  /**
   *
   */
  public function authorize($ini_array, $nvp) {
    // Echo 'Authorize Function called!<br>';
    // print_r($ini_array);
    // print_r($nvp);
    if ($ini_array == FALSE) {
      $FileError = "Paymetric ini file not found. Please check the Paymetric directory.";
      throw new PaymentGatewayException($FileError);
    }

    $XiPay = new XiPaySoapClient($ini_array['XiPay-QA']['paymetric.xipay.url'],
      $ini_array['XiPay-QA']['paymetric.xipay.user'],
      $ini_array['XiPay-QA']['paymetric.xipay.password']);

    $xipayTransaction = new PaymetricTransaction();
    // Test value.
    $grandTotal = 0;
    // $grandTotal= $nvp['x_total'];.
    $xipayTransaction->CardCVV2 = $nvp['x_card_code'];
    $xipayTransaction->CardDataSource = $nvp['x_card_datasource'];
    // format: month/year.
    $xipayTransaction->CardExpirationDate = $nvp['x_exp_date'];
    $xipayTransaction->CardHolderAddress1 = $nvp['x_address'];
    $xipayTransaction->CardHolderAddress2 = $nvp['x_address2'];
    $xipayTransaction->CardHolderCity = $nvp['x_city'];
    $xipayTransaction->CardHolderCountry = $nvp['x_country'];
    $xipayTransaction->CardHolderName1 = $nvp['x_first_name'];
    $xipayTransaction->CardHolderName2 = $nvp['x_last_name'];
    $xipayTransaction->CardHolderName = $nvp['x_first_name'] . " " . $nvp['x_last_name'];
    $xipayTransaction->CardHolderState = $nvp['x_state'];
    $xipayTransaction->CardHolderZip = $nvp['x_zip'];
    $xipayTransaction->CardType = ucfirst($nvp['x_card_type']);

    $xipayTransaction->Amount = $grandTotal;
    $xipayTransaction->CardNumber = $nvp['x_card_num'];
    $xipayTransaction->CardPresent = $nvp['x_card_present'];
    $xipayTransaction->CurrencyKey = $nvp['x_currency_code'];
    $xipayTransaction->MerchantID = $ini_array['MerchantID']['paymetric.xipay.merchantid'];

    // Print XT Request
    // $XTReq = print_r($xipayTransaction);
    // $XTReq = print_r($ini_array);
    // echo $XTReq;
    // stdClass Object ( [CardCVV2] => 000 [CardDataSource] => E [CardExpirationDate] => 20/24-05 [CardHolderAddress1] => 101 Cherry Court [CardHolderAddress2] => Unit 1436 [CardHolderCity] => Waleska [CardHolderCountry] => US [CardHolderName1] => Robert [CardHolderName2] => Simmons [CardHolderName] => Robert Simmons [CardHolderState] => GA [CardHolderZip] => 30183 [CardType] => Master Card [Amount] => 0 [CardNumber] => 5424000000000015 [CardPresent] => 0 [CurrencyKey] => USD [MerchantID] => 334273210883 )
    $authResponse = $XiPay->Authorize($xipayTransaction);
//    echo "XTRes <pre>"; print_r($authResponse); echo "</pre>";

    $transID = 0;
    $authorized = '';

    if ($authResponse->Status == STATUS_OK) {
      $authorized = $authResponse->Transaction;
      echo "Transaction Authorized with STATUS_OK: <pre>"; print_r($authorized); echo "</pre>";
      echo "Status Code: " . $authorized->StatusCode . "<br>";

      if ($authorized->StatusCode != 100) {
        $ErrorMsg = "Error Message: " . $authResponse->Message;
        $ErrorCode = " and Error Code: " . $authorized->StatusCode;
        // watchdog('commerce_paymetric', 'Paymetric authorize request @MSG: @CODE', array('@MSG' => $ErrorMsg, '@CODE' => $ErrorCode), WATCHDOG_DEBUG);.
      }
    }
    return $authorized;
  }

}
