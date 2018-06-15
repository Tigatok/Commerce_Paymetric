<?php

namespace Drupal\commerce_paymetric\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a checkout form for our offsite payment.
 */
class PaymetricOffsiteCheckoutForm extends PaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $this->entity;

    $form['#attached']['library'][] = 'commerce_payment/payment_method_form';
    $form['#tree'] = TRUE;
    $form['payment_details'] = [
      '#parents' => array_merge($form['#parents'], ['payment_details']),
      '#type' => 'container',
      '#payment_method_type' => $payment_method->bundle(),
    ];
    $form['payment_details'] = $this->buildCreditCardForm($form['payment_details'], $form_state);
    $order = $payment_method->getOrder();
    $current_checkout_step = $order->get('checkout_step')->getValue()[0]['value'];
    /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
    $checkout_flow = $order->get('checkout_flow')->entity;
    $next_step = $checkout_flow->getPlugin()->getNextStepId($current_checkout_step);

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#title' => t('Complete purchase'),
    ];

    return $form;
  }

  /**
   * Builds the credit card form.
   *
   * @param array $element
   *   The target element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the complete form.
   *
   * @return array
   *   The built credit card form.
   */
  protected function buildCreditCardForm(array $element, FormStateInterface $form_state) {
    // Build a month select list that shows months with a leading zero.
    $months = [];
    for ($i = 1; $i < 13; $i++) {
      $month = str_pad($i, 2, '0', STR_PAD_LEFT);
      $months[$month] = $month;
    }
    // Build a year select list that uses a 4 digit key with a 2 digit value.
    $current_year_4 = date('Y');
    $current_year_2 = date('y');
    $years = [];
    for ($i = 0; $i < 10; $i++) {
      $years[$current_year_4 + $i] = $current_year_2 + $i;
    }

    $element['#attributes']['class'][] = 'credit-card-form';
    // Placeholder for the detected card type. Set by validateCreditCardForm().
    $element['type'] = [
      '#type' => 'hidden',
      '#value' => '',
    ];
    $element['number'] = [
      '#type' => 'textfield',
      '#title' => t('Card number'),
      '#attributes' => ['autocomplete' => 'off'],
      '#required' => TRUE,
      '#maxlength' => 19,
      '#size' => 20,
    ];
    $element['expiration'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['credit-card-form__expiration'],
      ],
    ];
    $element['expiration']['month'] = [
      '#type' => 'select',
      '#title' => t('Month'),
      '#options' => $months,
      '#default_value' => date('m'),
      '#required' => TRUE,
    ];
    $element['expiration']['divider'] = [
      '#type' => 'item',
      '#title' => '',
      '#markup' => '<span class="credit-card-form__divider">/</span>',
    ];
    $element['expiration']['year'] = [
      '#type' => 'select',
      '#title' => t('Year'),
      '#options' => $years,
      '#default_value' => $current_year_4,
      '#required' => TRUE,
    ];
    $element['security_code'] = [
      '#type' => 'textfield',
      '#title' => t('CVV'),
      '#attributes' => ['autocomplete' => 'off'],
      '#required' => TRUE,
      '#maxlength' => 4,
      '#size' => 4,
    ];

    return $element;
  }

  /**
   * Do card authorization here?
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   *
   * @return bool
   *   If the authorization succeeded or not.
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\dblog\Logger\DbLog $dblog */
    $dblog = \Drupal::service('logger.dblog');
    try {
      $payment_details = $form_state->getUserInput()['payment_process']['offsite_payment']['payment_details'];
      /** @var \Drupal\commerce_paymetric\Plugin\Commerce\PaymentGateway\Paymetric $plugin */
      $plugin = $this->plugin;
      $authorization = $plugin->authorizePaymentMethod($this, $payment_details);

      if ($authorization->ResponseCode != '104' || $authorization->ResponseCode != '105') {
        /** @var \Drupal\commerce_log\LogStorageInterface $commerce_log */
        $commerce_log = \Drupal::entityTypeManager()->getStorage('commerce_log');
        $form_state->setError($form['payment_details']['number'], 'There was an error processing your credit card.');

        // Saves to the dblog.
        $dblog->error($authorization->Message);
        // Saves to the order.
        $commerce_log->generate($this->getEntity()->getOrder(), 'paymetric_payment_error', [
          'data' => $authorization->Message,
        ])->save();
        return FALSE;
      }
    }
    catch (\Exception $e) {
      $dblog->error('There was an issue processing: ' . $e->getMessage());
      $form_state->setError($form['payment_details']['number'], 'There was an error processing your credit card. Please try again later.');
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Call the plugins implementation of the create payment here.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Invoke the payment gateway createPayment.
    $this->plugin->createPayment();
  }

}
