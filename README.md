# zero.core v4.0 (alpha)
The core components of the Zero Framework

The heart of this is to get people coding modularly and writing object oriented. 
If you work with this framework, you will be able to see the benefits of this style of coding. 
Also, at the heart of this framework is a burning hatred for other frameworks that do way more than be just a frame. 

Zero Routing 
Zero Configuration 
Zero Bloat

# How to use it. 

Shit you can't use this without zero.app. Fuck. 
include Application.php and instantiate it. It'll load your vendor/autoloader.php and whatever other autoloader you feed it and 
try to load a Class and call it's method, passing in arguments

For example: 

domain.com/class/method/arg1/arg2/argX/

`Application` will try to load `Class` and execute `method`, passing in `array("arg1","arg2","arg3")` as the argument 

`Application` has some methods you can use to guide it. juse see the documentation. 


# What's changed since 'v3' 
- Organizational changes (namespaces, fewer core/ subfolders, better separation)
- No more MUFASA
- More defaults in Response 
- +Output Buffering 
- Better JSON/response-type handling 
- Access control stuff is moving into it's own thing 


# Overview

## Core Components: 

- Application.php 
    This is the main front controller. It does five things: 
    1). Registers Autoloaders 
    2). Instantiates the Request object 
    3). Defines constants 
    4). Includes global functions and the like #TODO KILL
    5). Loads the Requested Module(aspect) and executes the requested method(endpoint) #TODO Not an aspect, anymore. => $module
    6). Controls output buffering This allows the framework to do neat things like cache itself. 

- Request.php 
    This is the object that holds information about the Request. See src for details. 

- Response.php 
    This object determines what type of response to give and how to give it. 
    It automagically wraps an html head, header, and footer, automatically loading extra css or scripts as the module requires.
    If it cannot do something automagically, it tries to default to something reasonable like displaying an index page, 
    but if it cannot do that, then it displays a 404. 
    Furthermore, based on the context of the Request, it may respond with JSON instead of HTML - or in the case of AJAX, 
    it leaves off the header and the footer. 
    As long as your module extends this class and calls parent::__construct() - the magic will happen.

- Error.php 
    This displays error pages. new Error(404) will generate a 404 page and stop execution. 
    Optionally takes a second parameter, which is a message to display. 

That's it. This is the core of the zero framework. Application.min.php is an attempt to minimize the above into a single class file, but is currently incomplete. 

The other modules are optional: 

- Client.php 
    Controls the session info and provides some other useful methods, like getGeoLocation and getGravatarURL

- Model.php
    Kicks off a database connection. 

- Module.php 
    Just an alias for Response so that code you write can be a "Module" and not a "Response" Semantics! Think Modular! *Be* Modular.

- Restricted.php && Whitelist.php
    Contains a bunch of old ACL that are currently deprecated. ACL stuff is likely to go into a separate thing. 

- tools/ 
    Contains debugging tools that are not really hooked into the framework, yet. While I wanted to keep Zero lightweight and independent on stuff like that, good debugging hooks will make this more lovable.

# In Depth: 

For a truly in-depth look, look at the code. Otherwise, the methods you really want to use are : 

- ::Application::
    By Default, it does everything it needs to do in it's construct() class, and you can pass in options. 
    if you pass 'autorun'=>false then you can control the flow of how the application builds itself and do something
    that I clearly haven't thought of, but by all means, do whatever. 

    'autorun' => false makes all of the following options useless. 

    Other options Application will take are: 
    1. 'autoloaders' // an array or a string specifying additional autoloaders to load. 
    ....(by default, automatically loads vendor/autoloader.php)
    2. 'constants'   // an array of global constant key => values to define, if you did it outside of .ini for some reason
    3. 'extensions'  // a string or an array of .php files to include
    
    You would pass these like so: 

        new Application(array("optionname"=>"optionvalue"));

    If you choose to disable autorun, you will need to call the following functions 
    (or not. Maybe you don't need extensions or extra autoloaders or contants or whatever.)

        $app = new Application(array("autorun"=>false)) //or just `false` is fine, too.
        $app->registerAutoloaders($option);// where $option is the related option, above
        $app->defineConstants($option)     // again, where $option is the array described above
        $app->fetchExtensions($option)     //-again, where $option is the array described above
        $app->parseRequest(); // Don't think I'd call this optional, but do whatever. 
        $app->run($class,$method,(array)$arguments) // only one that really matters. This is why you're here. 

    Then, execution is essentially handed off to Response.php


#TODO: 

- Learn MarkDown and make this file prettier
- Fix up Client. 
- ZeroZero(min) vs ZeroFW 
- Better Access Control  - fix up Whitelist & Restricted 
- OAuth Adapters


# Runtime Options:

- Minimal 
    Minimal will use MUFASA to load your class and call the function, nothing else.

- Simple Frame 
    Simple Frame will load up some other useful stuff and will also automagically insert the header and footer
    Of course it isn't perfectly "Simple" it is genuinely easy to get going with. 

- Zero.JS 
    Acts as a client-side zero framework for rapid prototyping - but also enforces good practices for production


