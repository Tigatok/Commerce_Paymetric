<?php

//
//Transaction response used for Authorize, Manual Authorize, Capture and Void.
//
include_once('./sites/all/modules/commerce_paymetric/includes/lib/paymetric/BaseResponse.php');
class TransactionResponse extends BaseResponse
{
    public $Transaction;
}

?>