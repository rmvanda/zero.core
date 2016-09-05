# zero.core

The front controller for the Zero Framework

# Configuration Options:

- Minimal 
    Minimal will use MUFASA to load your class and call the function, nothing else.

- Simple Frame 
    Simple Frame will load up some other useful stuff and will also automagically insert the header and footer

- Zero



#TODO: 

- Move away from the MUFASA - and onto something a bit more sensible - 
- Move back to static Request::$variables 
- Better Access Control 
- Model vs DAO distinction - 

    public function __construct()
    private function modprobe(array $modprobe)
    public function parseRequest()
    public function run($aspect, $endpoint, $args)
    private function friendlyURLConverter($url)
    public function load($filename, $path = null)
    public function suload($filename)
    public function registerAutoloaders($autoloader = null)
         spl_autoload_register(function($class)
    public function getClientSession(){ 
    public function fetchUtilities($utilities = null)
    public function defineConstants(array $key = null)
    public function finalizeRoute()
    public function errorHandler($class)
            xdebug_print_function_stack();
