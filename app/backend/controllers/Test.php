<?php
/*
 use PayPal\Auth\OAuthTokenCredential;
 use PayPal\Rest\ApiContext;
 use PayPal\Api\Payer;
 use PayPal\Api\Amount;
 use PayPal\Api\Transaction;
 use PayPal\Api\RedirectUrls;
 use PayPal\Api\Payment;
 use PayPal\Api\PaymentExecution;
 */
class Test extends Response
{
    public $extension;

    function session()
    {
        print_x($_SESSION);
        print_x($_COOKIE);
        print_x($_SERVER);
        if ($_SERVER['REMOTE_ADDR'] == "50.199.113.222")
            print_x($_SERVER);
    }

    public function __call($func, $args)
    {
        //  print_x($func()); Dangerous -!
    }

}
