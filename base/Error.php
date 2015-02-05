<?php
/*
 * This class handles and generates error pages.
 *
 * There is currently no real support for custom error pages, but that may be
 * handled
 * in the future...
 *
 */

//namespace Zero\Core;
class Error//extends Response
{
    private static $message = array(
        400 => "BAD REQUEST",
        402 => "PAYMENT REQUIRED",
        401 => "NOT AUTHORIZED",
        403 => "FORBIDDEN",
        404 => "NOT FOUND",
        405 => "METHOD NOT ALLOWED",
        406 => "NOT ACCEPTABLE",
        407 => "PROXY AUTHENTICATION REQUIRED",
        408 => "REQUEST TIMEOUT",
        409 => "CONFLICT",
        410 => "GONE",
        411 => "LENGTH REQUIRED",
        412 => "PRECONDITION FAILED",
        413 => "REQUEST ENTITY TOO LARGE",
        414 => "REQUEST-URI TOO LONG",
        415 => "UNSUPPORTED MEDIA TYPE",
        416 => "UNSUPPORTED MEDIA TYPE",
        417 => "EXPECTATION FAILED",
        418 => "",
        420 => "ENHANCE YOUR CALM"
    );

    public static function __callStatic($func, $args)
    {
        define("VIEW_PATH", ROOT_PATH . "skeleton/frontend/views/");
        $code = trim($func, "_");//, "", $func);

        header("HTTP/1.1 $code " . self::$message[$code]);

        if (file_exists($errorPage = VIEW_PATH . "_global/_error/_$code.php")) {
            $message = $args[0]; 
            include $errorPage;
        } else {
            self::generateErrorPage($code, self::$message[$code]);
            exit();
        }
    }

    public static function JSON()
    {

        switch(json_last_error()) {
            case '' :
                break;
            default :
                die("Something went wrong with the json parsing...");
                break;
        }
    }

    private static function generateErrorPage($code, $message)
    {
        echo "
            <!DOCTYPE html>
            <head>
                <title>Error $code | $message</title>
            </head>";
        include VIEW_PATH . "_global/header.php";
        echo "
                <h1>$message</h1>
                <h2>$code</h2>
                <br>
                <hr>";
        include VIEW_PATH . "_global/footer.php";

    }

}
