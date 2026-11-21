<?php

namespace Zero\Module;

use \Zero\Core\Console;
use \Zero\Entity\Entity;
use \Zero\Lib\CloudFlare\CloudFlare;

use \Zero\Core\Attribute\RequireAuthLevel; 


#[RequireAuthLevel(1)]
class Test extends \Zero\Core\Module {

    
    public function __construct(){
        //if(!defined("DEVMODE") || DEVMODE == false) {
        //    Console::warn("Not in dev mode, so access to Test module denied");
        //    new \Zero\Core\Error(404); 
        //} else {
            parent::__construct(); 
        //} 
        /*
        echo "<br>"; 
        echo "<br>"; 
        echo "<br>"; 
        echo "<br>"; 
        echo "<br>"; 
        */
    }
   
    public function git(){
        var_dump(exec("git status", $output)); 
        var_dump($output); 
    }

    public function ajax(){
        echo \Zero\Core\isAjax(); 
    }

    public function headers(){
        echo json_encode($_SERVER);  
    }
    
    public function accepts(){
        echo "<h1>".\Zero\Core\Request::$accepts."!</h1>"; 
    }

    public function console(){
    
        Console::debug(array("One" => "two",
                            "three" => "four", 
                            "hippopotamonagus" => array(
                                    "sub array! woot" => "atoot toot"
                                )
                        )
        ); 
        
        Console::debug("What's up? I'm alive");
        Console::error("A test error from /test/console"); 
        echo "<pre>";
        echo LOG_FILE."</pre>"; 
        echo "<pre>".ZERO_ROOT; 
        
    }

    /*
    public function error($code){
        new \Zero\Core\Error($code); 
    }
    */

    public function paths(){
        echo ZERO_ROOT; 
    }

    public function server(){
        var_dump($_SERVER); 
    }

    public function session(){
        var_dump($_SESSION);
    }

    public function stuff($arg){
        trigger_error("OH NO ERRORS!"); 
        throw new \Exception("An error occurred. We don't know what it was"); 
    }
    
    public function info(){
        phpinfo(); 
    }

    
    public function constants($category = null){
        $constants = get_defined_constants(1);
        if($category && in_array($category, array_keys($constants))){
            var_dump($constants[$category]);
        } else {
            var_dump($constants);
        }
    }

    public function post(){
    
        if(!$_REQUEST){
            echo "no more! ";     
        } else {
            $_REQUEST['a'] = "Changed";
            echo $m=\Zero\Core\Request::$method."\n"; 
            $rm = '$'."_$m"; 
            echo ${$rm}['a'];
        
        }
        
    }

    /**
     * Replicates the problem described in core/CORE_REQUEST.md.
     *
     * GET  /test/test-json  → renders the fetch UI (view/test-json.php).
     * POST /test/test-json  → reports whether $_POST is empty and, if it is,
     *                         attempts what convertJSONtoPOST() would do:
     *                         read php://input and json_decode() it.
     *
     * With convertJSONtoPOST() disabled in core/Request.php, a JSON body
     * (Content-Type: application/json) never auto-populates $_POST — PHP only
     * does that for form-encoded/multipart bodies — so $_POST comes back empty
     * while the raw JSON is still sitting in php://input. That gap is the exact
     * reason convertJSONtoPOST() was written.
     */
    public function testJson(){

        // GET: show the fetch UI.
        if(\Zero\Core\Request::$method !== 'POST'){
            $this->build($this->viewPath . 'test-json.php');
            return;
        }

        // POST: diagnostic. Do NOT set headers here — the constructor has
        // already emitted output, so we just echo the JSON payload.
        $result = array(
            'method'         => \Zero\Core\Request::$method,
            'content_type'   => $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '(none)',
            'post_is_empty'  => empty($_POST),
            'post_contents'  => $_POST,
        );

        // Mirror the guard in convertJSONtoPOST(): only act when $_POST is empty.
        if(empty($_POST)){
            $raw = file_get_contents("php://input");
            $result['php_input_has_data'] = !empty($raw);
            $result['php_input_raw']      = $raw;

            // Same decode convertJSONtoPOST() performs.
            $decoded = json_decode($raw, true);
            $result['json_decode_ok']      = (json_last_error() === JSON_ERROR_NONE);
            $result['json_last_error']     = json_last_error();
            $result['json_last_error_msg'] = json_last_error_msg();
            $result['decoded']             = $decoded;
        }

        echo json_encode($result, JSON_PRETTY_PRINT);
    }

    public function index(){

        $class = new \ReflectionClass("\Zero\Module\Test");
        $methods = $class->getMethods(\ReflectionMethod::IS_PUBLIC);
        echo "<h1>Index of Test module: </h1>"; 
        foreach($methods as $method){
            echo "<a href='/test/$method->name'>$method->name </a></br>";    
        }
    }

    public function request(){
        echo "<h1>Request Properties:</h1>";  
        echo "<br><br>"; 
        $class = new \ReflectionClass('\Zero\Core\Request');
        $staticProperties = $class->getStaticProperties();
        
        foreach ($staticProperties as $propertyName => $value) { 
            $print = is_array($value)?("(Array) ".implode(", ",$value)):$value; 
            echo "$propertyName : ".($print?:"false")." <br>"; 
        }
    }
    
    #[RequireAuthLevel(1)]
    public function auth(){
        echo "<h1>Success!</h1>"; 
    }

    /**
     * Display all localStorage keys and values
     */
    public function localStorage() {
        $this->respond($this->viewPath . 'localStorage.php');
    }

    /**
     * Test CloudFlare IP blocking
     * GET: shows form, POST: submits block request
     */
    public function cloudflare() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $ip = trim($_POST['ip'] ?? '');
            $reason = trim($_POST['reason'] ?? '') ?: 'Manual block via Test module';

            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                echo json_encode(['success' => false, 'error' => 'Invalid IP address']);
                return;
            }

            $result = CloudFlare::block($ip, $reason);
            echo json_encode(['success' => $result, 'ip' => $ip, 'reason' => $reason]);
            return;
        }

        $this->view('cloudflare');
    }

}
