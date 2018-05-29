<?php
namespace Drupal\commerce_paymetric\Plugin\Commerce\PaymentGateway;

// Supporting Libraries
use Drupal\commerce_paymetric\lib\XiPaySoapClient;
use Drupal\commerce_paymetric\lib\PaymetricTransaction;

use Drupal\commerce_payment\CreditCard; // Required for base class
use Drupal\commerce_payment\Entity\PaymentInterface; // Required for base class
use Drupal\commerce_payment\Entity\PaymentMethodInterface; // Required for base class
use Drupal\commerce_payment\Exception\HardDeclineException; // Required for base class
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager; // Required for base class
use Drupal\commerce_payment\PaymentTypeManager; // Required for base class
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase; // Required for base class
use Drupal\commerce_price\Price; // Required for base class
use Drupal\commerce_price\RounderInterface;
use Drupal\Component\Datetime\TimeInterface; // Required for base class
use Drupal\Core\Entity\EntityTypeManagerInterface; // Required for base class
use Drupal\Core\Form\FormStateInterface; // Required for base class
use GuzzleHttp\ClientInterface;

// Commerce Guys Libraries for Authorize.net
use CommerceGuys\AuthNet\Configuration;
use CommerceGuys\AuthNet\CreateCustomerPaymentProfileRequest;
use CommerceGuys\AuthNet\CreateCustomerProfileRequest;
use CommerceGuys\AuthNet\CreateTransactionRequest;
use CommerceGuys\AuthNet\DataTypes\BillTo;
use CommerceGuys\AuthNet\DataTypes\CreditCard as CreditCardDataType;
use CommerceGuys\AuthNet\DataTypes\MerchantAuthentication;
use CommerceGuys\AuthNet\DataTypes\Order as OrderDataType;
use CommerceGuys\AuthNet\DataTypes\OpaqueData;
use CommerceGuys\AuthNet\DataTypes\PaymentProfile;
use CommerceGuys\AuthNet\DataTypes\Profile;
use CommerceGuys\AuthNet\DataTypes\TransactionRequest;
use CommerceGuys\AuthNet\DeleteCustomerPaymentProfileRequest;
use CommerceGuys\AuthNet\Request\XmlRequest;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;


/**
ALL SUPPORTING LIBRARIES LOADED
--------------------------------------
XiPaySoapClient.php
    NTLMSoapClient.php is a 3rd party workaround for NTLM authentication. 
    
NTMLSoapClient.php
    NTMLSoapClient LOADED! 
    
XiPaySoapOpTemplate.php
    static class XiPaySoapOpTemplate to encapsulate XML payload structures 
    
TransactionResponse.php
    Transaction response used for Authorize, Manual Authorize, Capture and Void. 
    
BaseResponse.php
    Base response object with status code and message 
    
PaymetricTransaction.php 
    XiPay transaction class 
    
BaseClass.php
    Base class for all XiPay classes 
    
BaseCore.php
    Base class for all XiPay classes 
*/

/**
 * Provides the Paymetric payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "paymetric",
 *   label = "Paymetric",
 *   display_label = "Credit Card",
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "discover", "mastercard", "visa",
 *   },
 * )
 */
class Paymetric extends OnsitePaymentGatewayBase implements PaymetricInterface {

  /**
   * Paymetric test API URL.
   */
  const PAYMETRIC_API_TEST_URL = 'https://test.authorize.net/gateway/transact.dll';

  /**
   * Paymetric production API URL.
   */
  const PAYMETRIC_API_URL = 'https://secure2.authorize.net/gateway/transact.dll';

  /**
   * The Authorize.net API configuration.
   *
   * @var \CommerceGuys\AuthNet\Configuration
   */
  //protected $authnetConfiguration;
  
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
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, ClientInterface $client, RounderInterface $rounder, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->httpClient = $client;
    $this->rounder = $rounder;
    $this->logger = $logger;
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
      $container->get('commerce_price.rounder'),
      $container->get('commerce_authnet.logger')
    );
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
      //'#type' => 'password',
      '#type' => 'textfield',
      '#title' => $this->t('XiPay Password'),
      '#description' => $this->t('Set Password for authorization.'),
      '#default_value' => $this->configuration['password'],
      //'#required' => empty($this->configuration['password']),
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
      '#options'=> array('amex' => $this->t('American Express'), 'discover' => $this->t('Discover'), 'mastercard' => $this->t('Master Card'), 'visa' => $this->t('Visa'), 'dinersclub' => $this->t('Diners Club'), 'jcb' => $this->t('JCB'), 'maestro' => $this->t('Maestro'), 'unionpay' => $this->t('Union Pay')),
      '#default_value' => $this->configuration['credit_card_types'], 
      //'#default_value' => array('amex', 'discover', 'mastercard', 'visa'),
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
      //print_r($values['credit_card_types']); // When discover not selected : Array ( [amex] => amex [mastercard] => mastercard [visa] => visa [discover] => 0 )
      
      $card_types = array();
      foreach ($values['credit_card_types'] as $key => $val) {
          //echo $key.' :: '.$val .' :: Value type: '.gettype($val).'<br>';
          if ($val !== 0){
              //$card_types[] = $key;
              array_push($card_types, $key);
          }
      }
      //print_r($card_types);
      $this->configuration['credit_card_types'] = $card_types;
    }
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
   * Format the expiration date for Paymetric from the provided payment details.
   *
   * @param array $payment_details
   *   The payment details array.
   *
   * @return string
   *   The expiration date string.
   */
  protected function getExpirationDate(array $payment_details) {
    // expiration date required format: 2023-12
    return $payment_details['expiration']['year'].'-'.$payment_details['expiration']['month'];
  }

  /**
   * Merge default Paymetric parameters in with the provided ones.
   *
   * @param array $parameters
   *   The parameters for the transaction.
   *
   * @return array
   *   The new parameters.
   */
  protected function getParameters(array $parameters = []) {
    $defaultParameters = [
      'tender' => 'C',
      'xipay_url' => $this->getXIURL(),
      'user' => $this->getUser(),
      'pwd' => $this->getPassword(),
      'xiintercept_GUID' => $this->getXIGUID(),
      'xiintercept_PSK' => $this->getXIPSK(),
      'xiintercept_url' => $this->getXIIURL(),
      'xipay_merchantid' => $this->getXIMerchantID(),
      'credit_card_types' => $this->getCardTypes()
    ];

    return $parameters + $defaultParameters;
  }


   
    
   /** 
   The createPayment method is called when the 'Pay and complete purchase' button has been clicked on the final page of the checkout process (i.e. the 'Review' page). If $capture is TRUE, a sale transaction should be run; if FALSE, an authorize only transaction should be run.
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);
      
    $order = $payment->getOrder();
    $owner = $payment_method->getOwner();

    // Add a built in test for testing decline exceptions.
    /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $billing_address */
    /*if ($billing_address = $payment_method->getBillingProfile()) {
      $billing_address = $payment_method->getBillingProfile()->get('address')->first();
      if ($billing_address->getPostalCode() == '007') {
        throw new HardDeclineException('The payment was declined');
      }
    }*/

    
      
      
    // COMMERCE GUYS: 
    // Perform the create payment request here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    // Remember to take into account $capture when performing the request.
/*
               ::'.:                       
            ''M;                       
            """:;                       
                M;.     .               
       ...... ..M:'    MM;.             
     ;MMMMMMMMMM:     :MMMM.           
     MMMMMMMMMMMMM..  ;MMMM;.           
     'MMMMMMMMMMMMMM;.MMM'MM;           
      '"MMMMMMMMMMMMMMMMM 'MM;     ,. . 
, ,:    ':MMMMMMMMMMMMMMM  'MM.   :;'"" 
"'.:     :M:":MMMMMMMMMMM   'M; ,;:;;"" 
'"'M;..;.;M'  ""MMMMMMMM:    'M.MMMM:"" 
'"'  ""'""" ;.MMMMMMMM""      '"""'     
         ,;MMMMMMM:""                   
         'MMMMMMM;.;.                   
            """'""""":M..               
                      ,MM               
                     ;MM:               
                   ,;'MM'               
                   ":"M:               
                   ::::.  
*/
    
    $amount = $payment->getAmount();
    $payment_method_token = $payment_method->getRemoteId();
    // The remote ID returned by the request.
    $remote_id = '123456'; // TEST VALUE
    //Example after call is made
    //$authResponse = $XiPay->Authorize($xipayTransaction);
    //$authorized = $authResponse->Transaction;
    //$remote_id = $authorized->TransactionID; //??
    $next_state = $capture ? 'completed' : 'authorization';

    $payment->setState($next_state);
    $payment->setRemoteId($remote_id);
    $payment->save();
  }
    
    
    

  /**
  /*
    Previously authorized transactions are captured and moved to the current batch for settlement in this method.
  */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    // COMMERCE GUYS: 
    // Perform the capture request here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
/*
               ::'.:                       
            ''M;                       
            """:;                       
                M;.     .               
       ...... ..M:'    MM;.             
     ;MMMMMMMMMM:     :MMMM.           
     MMMMMMMMMMMMM..  ;MMMM;.           
     'MMMMMMMMMMMMMM;.MMM'MM;           
      '"MMMMMMMMMMMMMMMMM 'MM;     ,. . 
, ,:    ':MMMMMMMMMMMMMMM  'MM.   :;'"" 
"'.:     :M:":MMMMMMMMMMM   'M; ,;:;;"" 
'"'M;..;.;M'  ""MMMMMMMM:    'M.MMMM:"" 
'"'  ""'""" ;.MMMMMMMM""      '"""'     
         ,;MMMMMMM:""                   
         'MMMMMMM;.;.                   
            """'""""":M..               
                      ,MM               
                     ;MM:               
                   ,;'MM'               
                   ":"M:               
                   ::::.  
*/
    
      
      
    
    $remote_id = $payment->getRemoteId();
    $number = $amount->getNumber();

    $payment->setState('completed');
    $payment->setAmount($amount);
    $payment->save();
  }

  /**
  The voidPayment method could also be called delete payment. It is called when the 'Delete' operations button is clicked on a specific payment on the payments page. It will void a transaction that was previously authorized but has not been settled.
  */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, ['authorization']);
    
    // COMMERCE GUYS:
    // Perform the void request here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
/*
               ::'.:                       
            ''M;                       
            """:;                       
                M;.     .               
       ...... ..M:'    MM;.             
     ;MMMMMMMMMM:     :MMMM.           
     MMMMMMMMMMMMM..  ;MMMM;.           
     'MMMMMMMMMMMMMM;.MMM'MM;           
      '"MMMMMMMMMMMMMMMMM 'MM;     ,. . 
, ,:    ':MMMMMMMMMMMMMMM  'MM.   :;'"" 
"'.:     :M:":MMMMMMMMMMM   'M; ,;:;;"" 
'"'M;..;.;M'  ""MMMMMMMM:    'M.MMMM:"" 
'"'  ""'""" ;.MMMMMMMM""      '"""'     
         ,;MMMMMMM:""                   
         'MMMMMMM;.;.                   
            """'""""":M..               
                      ,MM               
                     ;MM:               
                   ,;'MM'               
                   ":"M:               
                   ::::.  
*/
      
      
      
    $remote_id = $payment->getRemoteId();

    $payment->setState('authorization_voided');
    $payment->save();
  }

  /**
  The refundPayment method is called from the 'Payments' tab of an order when the 'Refund' operations button is click on a payment. This method serves to refund all or part of a sale.
  */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    // COMMERCE GUYS: 
    // Perform the refund request here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
/*
               ::'.:                       
            ''M;                       
            """:;                       
                M;.     .               
       ...... ..M:'    MM;.             
     ;MMMMMMMMMM:     :MMMM.           
     MMMMMMMMMMMMM..  ;MMMM;.           
     'MMMMMMMMMMMMMM;.MMM'MM;           
      '"MMMMMMMMMMMMMMMMM 'MM;     ,. . 
, ,:    ':MMMMMMMMMMMMMMM  'MM.   :;'"" 
"'.:     :M:":MMMMMMMMMMM   'M; ,;:;;"" 
'"'M;..;.;M'  ""MMMMMMMM:    'M.MMMM:"" 
'"'  ""'""" ;.MMMMMMMM""      '"""'     
         ,;MMMMMMM:""                   
         'MMMMMMM;.;.                   
            """'""""":M..               
                      ,MM               
                     ;MM:               
                   ,;'MM'               
                   ":"M:               
                   ::::.  
*/
      
    
    $remote_id = $payment->getRemoteId();
    $number = $amount->getNumber();

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    //$MID = $this->getXIMerchantID();
    //echo "Create Payment Method called. Merchant ID: ".$MID;
    //print_r($payment_details);
    //Array ( [type] => mastercard [number] => 5424000000000015 [expiration] => Array ( [month] => 05 [divider] => [year] => 2021 ) [security_code] => 000 )
    
      
      
    
      
    /*$required_keys = [
      // The expected keys are payment gateway specific and usually match
      // the PaymentMethodAddForm form elements. They are expected to be valid.
      'type', 'number', 'expiration',
    ];
    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
      }
    }*/

    // COMMERCE GUYS: 
    // If the remote API needs a remote customer to be created.
    $owner = $payment_method->getOwner();
    if ($owner && $owner->isAuthenticated()) {
      $customer_id = $this->getRemoteCustomerId($owner);
      // If $customer_id is empty, create the customer remotely and then do
      // $this->setRemoteCustomerId($owner, $customer_id);
      // $owner->save();
/*
               ::'.:                       
            ''M;                       
            """:;                       
                M;.     .               
       ...... ..M:'    MM;.             
     ;MMMMMMMMMM:     :MMMM.           
     MMMMMMMMMMMMM..  ;MMMM;.           
     'MMMMMMMMMMMMMM;.MMM'MM;           
      '"MMMMMMMMMMMMMMMMM 'MM;     ,. . 
, ,:    ':MMMMMMMMMMMMMMM  'MM.   :;'"" 
"'.:     :M:":MMMMMMMMMMM   'M; ,;:;;"" 
'"'M;..;.;M'  ""MMMMMMMM:    'M.MMMM:"" 
'"'  ""'""" ;.MMMMMMMM""      '"""'     
         ,;MMMMMMM:""                   
         'MMMMMMM;.;.                   
            """'""""":M..               
                      ,MM               
                     ;MM:               
                   ,;'MM'               
                   ":"M:               
                   ::::.  
*/
         
    }

    // COMMERCE GUYS: 
    // Perform the create request here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    // You might need to do different API requests based on whether the
    // payment method is reusable: $payment_method->isReusable().
    // Non-reusable payment methods usually have an expiration timestamp.
    try {
     
      
/*
               ::'.:                       
            ''M;                       
            """:;                       
                M;.     .               
       ...... ..M:'    MM;.             
     ;MMMMMMMMMM:     :MMMM.           
     MMMMMMMMMMMMM..  ;MMMM;.           
     'MMMMMMMMMMMMMM;.MMM'MM;           
      '"MMMMMMMMMMMMMMMMM 'MM;     ,. . 
, ,:    ':MMMMMMMMMMMMMMM  'MM.   :;'"" 
"'.:     :M:":MMMMMMMMMMM   'M; ,;:;;"" 
'"'M;..;.;M'  ""MMMMMMMM:    'M.MMMM:"" 
'"'  ""'""" ;.MMMMMMMM""      '"""'     
         ,;MMMMMMM:""                   
         'MMMMMMM;.;.                   
            """'""""":M..               
                      ,MM               
                     ;MM:               
                   ,;'MM'               
                   ":"M:               
                   ::::.  
*/
     
      /* TESTED SOAP REQUEST AND IT WORKS!
      $address = $payment_method->getBillingProfile()->get('address')->first();
        
      $data = $this->executeTransaction([
        'trxtype' => 'A',
        'amt' => 0,
        'verbosity' => 'HIGH',
        'acct' => $payment_details['number'],
        'expdate' => $this->getExpirationDate($payment_details),    
        'cvv2' => $payment_details['security_code'],
        'billtoemail' => $payment_method->getOwner()->getEmail(),//- NO EMAIL ADDRESS for Guest checkout but not a problem as it is stored in Commerce 2
        'billtofirstname' => $address->getGivenName(),
        'billtolastname' => $address->getFamilyName(),
        'billtostreet' => $address->getAddressLine1(), 
        'billtostreet2' => $address->getAddressLine2(), 
        'billtocity' => $address->getLocality(),
        'billtostate' => $address->getAdministrativeArea(),
        'billtozip' => $address->getPostalCode(),
        'billtocountry' => $address->getCountryCode(),
      ]);*/
        
        
      /*if ($data['result'] !== '0') {
        throw new HardDeclineException("Unable to verify the credit card: " . $data['respmsg'], $data['result']);
      }*/
      

        $payment_method->card_type = $payment_details['type'];
        // Only the last 4 numbers are safe to store.
        $payment_method->card_number = substr($payment_details['number'], -4);
        $payment_method->card_exp_month = $payment_details['expiration']['month'];
        $payment_method->card_exp_year = $payment_details['expiration']['year'];
        $expires = CreditCard::calculateExpirationTimestamp($payment_details['expiration']['month'], $payment_details['expiration']['year']);
    // The remote ID returned by the request.
        $remote_id = '789'; // TEST remote id

        $payment_method
            ->setRemoteId($remote_id)
            ->setExpiresTime($expires)
            ->save();
    }
    catch (RequestException $e) {
      throw new HardDeclineException("Unable to store the credit card");
    }
    
  }

  /**
  The deletePaymentMethod deletes a stored payment method from an existing customer's record. It is called from the 'Payment methods' tab of a user's account. It should delete a saved payment method both on the Commerce site and in the gateway customer records.
  */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // COMMERCE GUYS: 
    // Delete the remote record here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
/*
               ::'.:                       
            ''M;                       
            """:;                       
                M;.     .               
       ...... ..M:'    MM;.             
     ;MMMMMMMMMM:     :MMMM.           
     MMMMMMMMMMMMM..  ;MMMM;.           
     'MMMMMMMMMMMMMM;.MMM'MM;           
      '"MMMMMMMMMMMMMMMMM 'MM;     ,. . 
, ,:    ':MMMMMMMMMMMMMMM  'MM.   :;'"" 
"'.:     :M:":MMMMMMMMMMM   'M; ,;:;;"" 
'"'M;..;.;M'  ""MMMMMMMM:    'M.MMMM:"" 
'"'  ""'""" ;.MMMMMMMM""      '"""'     
         ,;MMMMMMM:""                   
         'MMMMMMM;.;.                   
            """'""""":M..               
                      ,MM               
                     ;MM:               
                   ,;'MM'               
                   ":"M:               
                   ::::.  
*/
    
    // Delete the local entity.
    $payment_method->delete();
  }
    
    
    
    
    
    
    
/**** TEST FUNCTION ****/
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
      
    //echo 'Execute Transaction v1: <br>';
    //print_r($parameters);
    //echo '<br> ACCNT: '.$parameters[acct];
      
    $ini_array=array();
    $ini_array['XiPay-QA']['paymetric.xipay.url'] = $this->getXIURL();
    $ini_array['XiPay-QA']['paymetric.xipay.user'] = $this->getUser();
    $ini_array['XiPay-QA']['paymetric.xipay.password'] = $this->getPassword();
    $ini_array['MerchantID']['paymetric.xipay.merchantid'] = $this->getXIMerchantID();
    $ini_array['Xiintercept-QA']['paymetric.xiintercept.GUID'] = $this->getXIGUID();
    $ini_array['Xiintercept-QA']['paymetric.xiintercept.PSK'] = $this->getXIPSK();
    $ini_array['Xiintercept-QA']['paymetric.xiintercept.url'] = $this->getXIIURL();
          
      $nvp = array();
      // Add the default name-value pairs to the array.
      // Evaluate if person is logged in or using guest checkout
      $customer_email = $parameters['billtoemail'];
      
      
      $nvp += array(
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
        'x_card_type' => "mastercard", //???
        'x_total' => $parameters['amt'],
        'x_card_num' => $parameters['acct'],
        'x_card_present' => 0, //???
        'x_currency_code' => "USD", //???
        'x_email_customer' => $customer_email
      );
      
      
      $response = $this->authorize($ini_array, $nvp);
      
      
      
      echo $response;
      sleep(180);
      
      //return $response;
      
      
  }
/**** /TEST FUNCTION ****/
    
    
    
    
    
/**** REFACTORED FUNCTIONS FROM OLD MODULE ****/    
    /** For authorization **/
    function authorize($ini_array, $nvp)
    {
        //echo 'Authorize Function called!<br>';
        //print_r($ini_array);
        //print_r($nvp);
        
        
        if ($ini_array == false){
            $FileError = "Paymetric ini file not found. Please check the Paymetric directory.";
            throw new PaymentGatewayException($FileError);
        }

        $XiPay = new XiPaySoapClient( $ini_array['XiPay-QA']['paymetric.xipay.url'],
                                  $ini_array['XiPay-QA']['paymetric.xipay.user'],
                                  $ini_array['XiPay-QA']['paymetric.xipay.password']);

        
        $xipayTransaction = new PaymetricTransaction();
        $grandTotal= 1; // Test value
        //$grandTotal= $nvp['x_total'];

        $xipayTransaction->CardCVV2 = $nvp['x_card_code'];
        $xipayTransaction->CardDataSource = $nvp['x_card_datasource'];
        $xipayTransaction->CardExpirationDate = substr($nvp['x_exp_date'],0,2).'/'.substr($nvp['x_exp_date'],2); // format: month/year
        $xipayTransaction->CardHolderAddress1 = $nvp['x_address'];
        $xipayTransaction->CardHolderAddress2 = $nvp['x_address2'];
        $xipayTransaction->CardHolderCity = $nvp['x_city'];
        $xipayTransaction->CardHolderCountry = $nvp['x_country'];
        $xipayTransaction->CardHolderName1 =  $nvp['x_first_name'];
        $xipayTransaction->CardHolderName2 = $nvp['x_last_name'];
        $xipayTransaction->CardHolderName =  $nvp['x_first_name']." ".$nvp['x_last_name'];
        $xipayTransaction->CardHolderState = $nvp['x_state'];
        $xipayTransaction->CardHolderZip = $nvp['x_zip'];
        $xipayTransaction->CardType = ucfirst($nvp['x_card_type']);

        $xipayTransaction->Amount = $grandTotal;
        $xipayTransaction->CardNumber = $nvp['x_card_num'];
        $xipayTransaction->CardPresent = $nvp['x_card_present'];
        $xipayTransaction->CurrencyKey = $nvp['x_currency_code'];
        $xipayTransaction->MerchantID = $ini_array['MerchantID']['paymetric.xipay.merchantid'];
      
        
        // Print XT Request
        //$XTReq = print_r($xipayTransaction); 
        //$XTReq = print_r($ini_array);
        //echo $XTReq;
        //stdClass Object ( [CardCVV2] => 000 [CardDataSource] => E [CardExpirationDate] => 20/24-05 [CardHolderAddress1] => 101 Cherry Court [CardHolderAddress2] => Unit 1436 [CardHolderCity] => Waleska [CardHolderCountry] => US [CardHolderName1] => Robert [CardHolderName2] => Simmons [CardHolderName] => Robert Simmons [CardHolderState] => GA [CardHolderZip] => 30183 [CardType] => Master Card [Amount] => 0 [CardNumber] => 5424000000000015 [CardPresent] => 0 [CurrencyKey] => USD [MerchantID] => 334273210883 )
        
        
        
        $authResponse = $XiPay->Authorize($xipayTransaction);
        echo "XTRes <pre>"; print_r($authResponse); echo "</pre>";

        $transID = 0;
        $authorized = '';
        
        if($authResponse->Status == STATUS_OK)	{
            $authorized = $authResponse->Transaction;
            echo "Transaction Authorized with STATUS_OK: <pre>"; print_r($authorized); echo "</pre>";
            echo "Status Code: ".$authorized->StatusCode."<br>";

            if($authorized->StatusCode != 100){
                $ErrorMsg = "Error Message: " . $authResponse->Message;
                $ErrorCode = " and Error Code: " . $authorized->StatusCode;
                watchdog('commerce_paymetric', 'Paymetric authorize request @MSG: @CODE', array('@MSG' => $ErrorMsg, '@CODE' => $ErrorCode), WATCHDOG_DEBUG);
            }
            //else {
                //$transID = $authorized->TransactionID;
                //return $transID;
            //}
        }
        
        //return $authorized;
        
    }

    /**
     * Submits an AIM API request to Authorize.Net.
     *
     * @paramx $payment_method
     *   The payment method instance array associated with this API request.
     */
    function commerce_paymetric_aim_request($payment_method, $nvp = array()) {
      // Get the API endpoint URL for the method's transaction mode.
      //$url = commerce_paymetric_aim_server_url($payment_method['settings']['txn_mode']);

      $ini_array=array();
      $ini_array['XiPay-QA']['paymetric.xipay.url'] = $payment_method['settings']['xipay_url'];
      $ini_array['XiPay-QA']['paymetric.xipay.user'] = $payment_method['settings']['xipay_user'];
      $ini_array['XiPay-QA']['paymetric.xipay.password'] = $payment_method['settings']['xipay_password'];
      $ini_array['MerchantID']['paymetric.xipay.merchantid'] = $payment_method['settings']['xipay_merchantid'];
      $ini_array['Xiintercept-QA']['paymetric.xiintercept.GUID'] = $payment_method['settings']['xiintercept_GUID'];
      $ini_array['Xiintercept-QA']['paymetric.xiintercept.PSK'] = $payment_method['settings']['xiintercept_PSK'];
      $ini_array['Xiintercept-QA']['paymetric.xiintercept.url'] = $payment_method['settings']['xiintercept_url'];

      //echo "<pre>"; print_r($ini_array); echo "</pre>";
      //echo "<pre>"; print_r($payment_method); echo "</pre>"; //exit;
      //echo "<pre>"; print_r($nvp); echo "</pre>"; exit;

       // Add the default name-value pairs to the array.
      $nvp += array(
        'x_email_customer' => $nvp['x_email'],
      );

      $paymentType=$nvp['x_type'];

      $response = authorize($ini_array, $nvp);
        
      return $response;
    }

    /**
     * Returns the URL to the Authorize.Net AIM server determined by transaction mode.
     * Not currenty used...commented out of commerce_paymetric_aim_request()
     *
     * @paramx $txn_mode
     *   The transaction mode that relates to the live or test server.
     *
     * @returnx
     *   The URL to use to submit requests to the Authorize.Net AIM server.
     */
    function commerce_paymetric_aim_server_url($txn_mode) {
      switch ($txn_mode) {
        case PAYMETRIC_TXN_MODE_LIVE:
        case PAYMETRIC_TXN_MODE_LIVE_TEST:
          return variable_get('commerce_paymetric_aim_server_url_live', 'https://secure2.authorize.net/gateway/transact.dll');
        case PAYMETRIC_TXN_MODE_DEVELOPER:
          return variable_get('commerce_paymetric_aim_server_url_dev', 'https://test.authorize.net/gateway/transact.dll');
      }
    }
    
    /**
     * Submits a CIM XML API request to Authorize.Net.
     *
     * @paramx $payment_method
     *   The payment method instance array associated with this API request.
     * @paramx $request_type
     *   The name of the request type to submit.
     * @paramx $api_request_data
     *   An associative array of data to be turned into a CIM XML API request.
     */
    function commerce_paymetric_cim_request($payment_method, $request_type, $api_request_data) {
      // Get the API endpoint URL for the method's transaction mode.
      $url = commerce_paymetric_cim_server_url($payment_method['settings']['txn_mode']);

      // Add default data to the API request data array.
      if (!isset($api_request_data['merchantAuthentication'])) {
        $api_request_data = array(
          'merchantAuthentication' => array(
            'name' => $payment_method['settings']['login'],
            'transactionKey' => $payment_method['settings']['tran_key'],
          ),
        ) + $api_request_data;
      }

      // Determine if it is necessary to add a validation mode to the API request.
      $validation_mode = '';

      switch ($request_type) {
        case 'createCustomerProfileRequest':
          if (empty($api_request_data['profile']['paymentProfiles'])) {
            $validation_mode = 'none';
          }
          else {
            $validation_mode = $payment_method['settings']['txn_mode'] == PAYMETRIC_TXN_MODE_LIVE ? 'liveMode' : 'testMode';
          }
          break;

        case 'createCustomerPaymentProfileRequest':
        case 'updateCustomerPaymentProfileRequest':
        case 'validateCustomerPaymentProfileRequest':
          $validation_mode = $payment_method['settings']['txn_mode'] == PAYMETRIC_TXN_MODE_LIVE ? 'liveMode' : 'testMode';
          break;

        default:
          break;
      }

      // Add the validation mode now if one was found.
      if (!empty($validation_mode)) {
        $api_request_data['validationMode'] = $validation_mode;
      }

      // Build and populate the API request SimpleXML element.
      $api_request_element = new SimpleXMLElement('<' . $request_type . '/>');
      $api_request_element->addAttribute('xmlns', 'AnetApi/xml/v1/schema/AnetApiSchema.xsd');
      commerce_simplexml_add_children($api_request_element, $api_request_data);

      // Allow modules an opportunity to alter the request before it is sent.
      drupal_alter('commerce_paymetric_cim_request', $api_request_element, $payment_method, $request_type);

      // Generate an XML string.
      $xml = $api_request_element->asXML();

      // Log the request if specified.
      if ($payment_method['settings']['log']['request'] == 'request') {
        // Mask the credit card number and CVV.
        $log_element = clone($api_request_element);
        $log_element->merchantAuthentication->name = str_repeat('X', strlen((string) $log_element->merchantAuthentication->name));
        $log_element->merchantAuthentication->transactionKey = str_repeat('X', strlen((string) $log_element->merchantAuthentication->transactionKey));

        if (!empty($log_element->profile->paymentProfiles->payment->creditCard->cardNumber)) {
          $card_number = (string) $log_element->profile->paymentProfiles->payment->creditCard->cardNumber;
          $log_element->profile->paymentProfiles->payment->creditCard->cardNumber = str_repeat('X', strlen($card_number) - 4) . substr($card_number, -4);
        }

        if (!empty($log_element->paymentProfile->payment->creditCard->cardNumber)) {
          $card_number = (string) $log_element->paymentProfile->payment->creditCard->cardNumber;
          $log_element->paymentProfile->payment->creditCard->cardNumber = str_repeat('X', strlen($card_number) - 4) . substr($card_number, -4);
        }

        if (!empty($log_element->profile->paymentProfiles->payment->creditCard->cardCode)) {
          $log_element->profile->paymentProfiles->payment->creditCard->cardCode = str_repeat('X', strlen((string) $log_element->profile->paymentProfiles->payment->creditCard->cardCode));
        }

        if (!empty($log_element->paymentProfile->payment->creditCard->cardCode)) {
          $log_element->paymentProfile->payment->creditCard->cardCode = str_repeat('X', strlen((string) $log_element->paymentProfile->payment->creditCard->cardCode));
        }

        watchdog('commerce_paymetric', 'Authorize.Net CIM @type to @url: @xml', array('@type' => $request_type, '@url' => $url, '@xml' => $log_element->asXML()), WATCHDOG_DEBUG);
      }

      // Build the array of header information for the request.
      $header = array();
      $header[] = 'Content-type: text/xml; charset=utf-8';

      // Setup the cURL request.
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_VERBOSE, 0);
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
      curl_setopt($ch, CURLOPT_NOPROGRESS, 1);
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
      curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
      $result = curl_exec($ch);

      // Log any errors to the watchdog.
      if ($error = curl_error($ch)) {
        watchdog('commerce_paymetric', 'cURL error: @error', array('@error' => $error), WATCHDOG_ERROR);
        return FALSE;
      }
      curl_close($ch);

      // If we received data back from the server...
      if (!empty($result)) {
        // Remove non-absolute XML namespaces to prevent SimpleXML warnings.
        $result = str_replace(' xmlns="AnetApi/xml/v1/schema/AnetApiSchema.xsd"', '', $result);

        // Extract the result into an XML response object.
        $response = new SimpleXMLElement($result);

        // Log the API response if specified.
        if ($payment_method['settings']['log']['response'] == 'response') {
          watchdog('commerce_paymetric', 'API response received:<pre>@xml</pre>', array('@xml' => $response->asXML()));
        }

        return $response;
      }
      else {
        return FALSE;
      }
    }    
    
    
    
    
    
    

    
    
    
    
    
    

/**** Drupal 7 Commerce Module Legacy Functions ****/
    /**
     * Returns the URL to the Authorize.Net CIM server determined by transaction mode.
     *
     * @paramx $txn_mode
     *   The transaction mode that relates to the live or test server.
     *
     * @returnx
     *   The URL to use to submit requests to the Authorize.Net CIM server.
     */
    function commerce_paymetric_cim_server_url($txn_mode) {
      switch ($txn_mode) {
        case PAYMETRIC_TXN_MODE_LIVE:
        case PAYMETRIC_TXN_MODE_LIVE_TEST:
          return variable_get('commerce_paymetric_cim_server_url_live', 'https://api2.authorize.net/xml/v1/request.api');
        case PAYMETRIC_TXN_MODE_DEVELOPER:
          return variable_get('commerce_paymetric_cim_server_url_dev', 'https://apitest.authorize.net/xml/v1/request.api');
      }
    }
    
     /**
     * Returns the transaction type string for Authorize.Net that corresponds to the
     *   Drupal Commerce constant.
     *
     * @param $txn_type
     *   A Drupal Commerce transaction type constant.
     */
    function commerce_paymetric_txn_type($txn_type) {
      switch ($txn_type) {
        case COMMERCE_CREDIT_AUTH_ONLY:
          return 'AUTH_ONLY';
        case COMMERCE_CREDIT_PRIOR_AUTH_CAPTURE:
          return 'PRIOR_AUTH_CAPTURE';
        case COMMERCE_CREDIT_AUTH_CAPTURE:
          return 'AUTH_CAPTURE';
        case COMMERCE_CREDIT_CAPTURE_ONLY:
          return 'CAPTURE_ONLY';
        case COMMERCE_CREDIT_REFERENCE_SET:
        case COMMERCE_CREDIT_REFERENCE_TXN:
        case COMMERCE_CREDIT_REFERENCE_REMOVE:
        case COMMERCE_CREDIT_REFERENCE_CREDIT:
          return NULL;
        case COMMERCE_CREDIT_CREDIT:
          return 'CREDIT';
        case COMMERCE_CREDIT_VOID:
          return 'VOID';
      }
    }

    /**
     * Returns the CIM transaction request type that correponds to a the Drupal
     * Commerce constant.
     *
     * @param $txn_type
     *   A Drupal Commerce transaction type constant.
     */
    function commerce_paymetric_cim_transaction_element_name($txn_type) {
      switch ($txn_type) {
        case COMMERCE_CREDIT_AUTH_ONLY:
          return 'profileTransAuthOnly';
        case COMMERCE_CREDIT_AUTH_CAPTURE:
          return 'profileTransAuthCapture';
        case COMMERCE_CREDIT_CAPTURE_ONLY:
          return 'profileTransCaptureOnly';
        case COMMERCE_CREDIT_PRIOR_AUTH_CAPTURE:
          return 'profileTransPriorAuthCapture';
        case COMMERCE_CREDIT_CREDIT:
          return 'profileTransRefund';
        case COMMERCE_CREDIT_VOID:
          return 'profileTransVoid';
        default:
          return '';
      }
    }
    
    /**
     * Returns the description of an Authorize.Net transaction type.
     *
     * @param $txn_type
     *   An Authorize.Net transaction type string.
     */
    public function commerce_paymetric_reverse_txn_type($txn_type) {
      switch (strtoupper($txn_type)) {
        case 'AUTH_ONLY':
          return t('Authorization only');
        case 'PRIOR_AUTH_CAPTURE':
          return t('Prior authorization capture');
        case 'AUTH_CAPTURE':
          return t('Authorization and capture');
        case 'CAPTURE_ONLY':
          return t('Capture only');
        case 'CREDIT':
          return t('Credit');
        case 'VOID':
          return t('Void');
      }
    }

    /**
     * Returns the message text for an AVS response code.
     */
    public function commerce_paymetric_avs_response($code) {
      switch ($code) {
        case 'A':
          return t('Address (Street) matches, ZIP does not');
        case 'B':
          return t('Address information not provided for AVS check');
        case 'E':
          return t('AVS error');
        case 'G':
          return t('Non-U.S. Card Issuing Bank');
        case 'N':
          return t('No Match on Address (Street) or ZIP');
        case 'P':
          return t('AVS not applicable for this transaction');
        case 'R':
          return t('Retry – System unavailable or timed out');
        case 'S':
          return t('Service not supported by issuer');
        case 'U':
          return t('Address information is unavailable');
        case 'W':
          return t('Nine digit ZIP matches, Address (Street) does not');
        case 'X':
          return t('Address (Street) and nine digit ZIP match');
        case 'Y':
          return t('Address (Street) and five digit ZIP match');
        case 'Z':
          return t('Five digit ZIP matches, Address (Street) does not');
      }

      return '-';
    }

    /**
     * Returns the message text for a CVV match.
     */
    public function commerce_paymetric_cvv_response($code) {
      switch ($code) {
        case 'M':
          return t('Match');
        case 'N':
          return t('No Match');
        case 'P':
          return t('Not Processed');
        case 'S':
          return t('Should have been present');
        case 'U':
          return t('Issuer unable to process request');
      }

      return '-';
    }
    
    
    
    
/**** Functions implemented by the AuthorizeNet Payment Gateway module for Commerce 2 ****/
   /**
   * Maps the Authorize.Net credit card type to a Commerce credit card type.
   *
   * @param string $card_type
   *   The Authorize.Net credit card type.
   *
   * @return string
   *   The Commerce credit card type.
   */
  protected function mapCreditCardType($card_type) {
    $map = [
      'American Express' => 'amex',
      'Diners Club' => 'dinersclub',
      'Discover' => 'discover',
      'JCB' => 'jcb',
      'MasterCard' => 'mastercard',
      'Visa' => 'visa',
    ];
    if (!isset($map[$card_type])) {
      throw new HardDeclineException(sprintf('Unsupported credit card type "%s".', $card_type));
    }

    return $map[$card_type];
  }

  /**
   * Writes an API response to the log for debugging.
   *
   * @param \CommerceGuys\AuthNet\Response\ResponseInterface $response
   *   The API response object.
   */
  protected function logResponse(ResponseInterface $response) {
    $message = $this->describeResponse($response);
    $level = $response->getResultCode() === 'Error' ? 'error' : 'info';
    $this->logger->log($level, $message);
  }

  /**
   * Formats an API response as a string.
   *
   * @param \CommerceGuys\AuthNet\Response\ResponseInterface $response
   *   The API response object.
   *
   * @return string
   *   The message.
   */
  protected function describeResponse(ResponseInterface $response) {
    $messages = [];
    foreach ($response->getMessages() as $message) {
      $messages[] = $message->getCode() . ': ' . $message->getText();
    }

    return $this->t('Received response with code %code from Authorize.net: @messages', [
      '%code' => $response->getResultCode(),
      '@messages' => implode("\n", $messages),
    ]);
  }

  /**
   * Gets the line items from order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \CommerceGuys\AuthNet\DataTypes\LineItem[]
   *   An array of line items.
   */
  protected function getLineItems(OrderInterface $order) {
    $line_items = [];
    foreach ($order->getItems() as $order_item) {
      $name = $order_item->label();
      $name = (strlen($name) > 31) ? substr($name, 0, 28) . '...' : $name;

      $line_items[] = new LineItem([
        'itemId' => $order_item->id(),
        'name' => $name,
        'quantity' => $order_item->getQuantity(),
        'unitPrice' => $order_item->getUnitPrice()->getNumber(),
      ]);
    }

    return $line_items;
  }


}