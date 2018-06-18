<?php

//
//echo 'Transaction response used for Authorize, Manual Authorize, Capture and Void. ';
//
//include_once('./sites/all/modules/commerce_paymetric/includes/lib/paymetric/BaseResponse.php');
//include_once('BaseResponse.php');

namespace Drupal\commerce_paymetric\lib;
use Drupal\commerce_paymetric\lib\BaseResponse;

class TransactionResponse extends BaseResponse
{
    public $Transaction;
}

?>