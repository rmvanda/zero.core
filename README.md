# zero.core v1.0 (alpha)
The core components of the Zero Framework

This bit is basically just a front controller that provides reasonable defaults for your web application. 

The heart of this is to get people coding modularly and writing object oriented. 
If you work with this framework, you will be able to see the benefits of this style of coding. 
Also, at the heart of this framework is a burning hatred for other frameworks that are more "kitchen-sink" than "frame"

Zero Routing 
Zero Configuration 
Zero Bloat

# How to use it. 


First, you need to define some constants to let the Core Frame know where it is and what the things you want are. 
In Zero.Skeleton, there's a great example of how this can be done automatically, without much though. 

The only required paths are ZERO_ROOT, VIEW_PATH, and MODULE_PATH. 
ZERO_ROOT is the folder that you put the core/ into.
ROOT_PATH for autoloading external classes not in the above folder. 
VIEW_PATH is the path of your global views, such as your head.php, header.php, sideNav.php, and footer.php
MODULE_PATH is the base path of where your code lives. 

Inside of MODULE_PATH, you keep your modules. More on that, later. 

Once you have those constants defined, 
Include Application.php and instantiate it. 

In previous iterations, that was it, but in order to be transparent about what this class does, it's methods are now called externally. 
Again, the index.php in Zero.Skeleton has a great example of this. 

Frame does 4 things for you: 
1). Registers autoloaders. You can pass your own function, or an array of functions to be added to the autoload stack. 
By default, it adds :
    -it's own, PSR autoloader, that looks up classes according to ROOT_PATH and namespacing, 
    -[Your passed-on-runtime autoloaders then go here ]
    -your vendor/autoloader
    -An autoloader that throws in the towel and gives a 404 message. 

2). Parses request. 
    - Really all this does is instantiate the Request class which gives you static access to information about the request. 
    for example: 
    $protocol://$sub.$domain.$tld/$aspect/$endpoint/$arg1/$arg2/$arg3

3). Define Constants. 
    - This is where some of the "magic" happens, because in addition to loading .ini files from a default location, it also looks into MODULE_PATH for a configuration files that may correspond with the module that's being called. Thus, if your application class needs some constants defined, they can be defined before your class is even loaded, simply by putting a .ini file into your module's folder
    - or, if you prefer, you can have the constants defined by passing them in as an argument to this method at runtime. Although I personally thing that goes against what we're trying to do, here. 

4). Run. 
    Now, it puts all the above into motion to create a Response that suits the Request given. 

For example: 

domain.com/class/method/arg1/arg2/argX/

`Application` will try to load `Class` and execute `method`, passing in `array("arg1","arg2","arg3")` as the argument 

Now, for security reasons, it will only load "Class" if it finds it in the MODULE_PATH. 
If the Class it finds also Extends \Zero\Core\Module - then it will be armed with Reasonable Defaults ™ - such as where to load the header & footer from
and what to do if the `method` called does not exist (throw 404 page, automatically). 



# What's changed since 'vX' 
- Organizational changes (namespaces, fewer core/ subfolders, better separation)
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
    4). Loads the Requested Module(aspect) and executes the requested method(endpoint) #TODO Not an aspect, anymore. => $module
    5). Controls output buffering This will eventually allow the framework to do neat things like cache itself. 

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

