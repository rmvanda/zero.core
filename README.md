# zero.core
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
`core/skeleton/` has a working example of a whole site laid out this way.

The only required paths are ZERO_ROOT, VIEW_PATH, and MODULE_PATH.
ZERO_ROOT is the folder that you put the core/ into.
ROOT_PATH for autoloading external classes not in the above folder.
VIEW_PATH is the path of your global views, such as your head.php, header.php, sideNav.php, and footer.php
MODULE_PATH is the base path of where your code lives.

Inside of MODULE_PATH, you keep your modules. More on that, later.

Once you have those constants defined, include `Application.php`, instantiate it, and call its
four methods in order. This site's `app/frontend/www/index.php` is the reference:

```php
require ZERO_ROOT."core/Application.php";

$app = new Zero\Core\Application();

$app->registerAutoloaders();
$app->parseRequest();
$app->defineConstants();

$app->run(
    \Zero\Core\Request::$module,
    \Zero\Core\Request::$endpoint,
    \Zero\Core\Request::$args
);
```

The constructor takes no arguments — it starts output buffering and nothing else. The methods
are called externally so it stays obvious what the class does. `registerAutoloaders()` optionally
takes a string, callable, or array of extra autoloaders; `defineConstants()` optionally takes an
array of constants to define alongside the `.ini` files.

`Application` does four things:

1. **Registers autoloaders.** You can pass your own function, or an array of functions to be
   added to the autoload stack. By default, it adds:
    - its own PSR autoloader, which looks up classes according to ROOT_PATH and namespacing,
    - [your passed-on-runtime autoloaders then go here]
    - your vendor/autoloader
    - an autoloader that throws in the towel and gives a 404 message.

   Plugins are loaded at the end of this step, once the autoload stack is ready.

2. **Parses the request.** Really all this does is instantiate the Request class which gives you
   static access to information about the request. For example:

   `$protocol://$sub.$domain.$tld/$module/$endpoint/$arg1/$arg2/$arg3`

3. **Defines constants.** This is where some of the "magic" happens, because in addition to
   loading `.ini` files from a default location, it also looks into MODULE_PATH for configuration
   files that may correspond with the module that's being called. Thus, if your application class
   needs some constants defined, they can be defined before your class is even loaded, simply by
   putting a `.ini` file into your module's folder.

4. **Runs.** Now it puts all the above into motion to create a Response that suits the Request
   given.

For example:

domain.com/class/method/arg1/arg2/argX/

`Application` will try to load `Class` and execute `method`, splatting the URL segments into it as
separate arguments — `$module->method("arg1", "arg2", "argX")`. So an endpoint declares named
parameters matching the URL segments, not a single `$args` array. Extra segments are ignored;
missing required ones raise `ArgumentCountError`, which `run()` catches and returns as a 400.

Now, for security reasons, it will only load "Class" if it finds it in the MODULE_PATH.
If the Class it finds also extends `\Zero\Core\Module` — then it will be armed with
Reasonable Defaults ™ - such as where to load the header & footer from
and what to do if the `method` called does not exist (throw 404 page, automatically).

# Overview

## The front controller

- **Application.php**
    The main front controller. It registers autoloaders, instantiates the Request object,
    defines constants, loads the requested module and executes the requested endpoint, and
    controls output buffering. It also runs the attribute gates — `checkAttributes()` is public
    and static so `Module::__call()` and `AdminResponse` can run them for their own levels too.

- **Request.php**
    Holds information about the request: module, endpoint, args, protocol, subdomain, whether
    the caller wants JSON, whether it came from AJAX. All static. Parsing only — path and
    response state belong to Response.

- **Response.php**
    Determines what type of response to give and how to give it.
    It automagically wraps an html head, header, and footer, automatically loading extra css or
    scripts as the module requires. If it cannot do something automagically, it tries to default
    to something reasonable like displaying an index page, but if it cannot do that, then it
    displays a 404. Furthermore, based on the context of the Request, it may respond with JSON
    instead of HTML — or in the case of AJAX, it leaves off the header and the footer.
    As long as your module extends this class and calls `parent::__construct()` — the magic will happen.

- **Module.php**
    Just an alias for Response so that code you write can be a "Module" and not a "Response".
    Semantics! Think Modular! *Be* Modular. It also resolves submodules in `__call()`.

- **AdminResponse.php**
    Base class for admin controllers. A Module subclass with its own asset handling — admin
    assets are inlined, never web-accessible. See `ADMIN_ARCHITECTURE.md` and `ADMIN_ASSETS.md`.

- **HTTPError.php**
    Exception class for HTTP errors. `throw new HTTPError(404)` bubbles up to
    `Application::run()`'s try/catch and renders an error page using the module's
    existing Response instance — preserving framePath and all resolved paths.
    Optionally takes a second parameter (detail string) for diagnostic messages.

## Data

- **Database.php**
    PDO connection management. Its `TrackedStatement` subclass times and records every query,
    so the DevToolbar can show you what a page actually ran.

- **Model.php**
    A thin typed row gateway over a real table. Deliberately not an ORM and deliberately not
    `Zero\Entity` — for models that want a real schema with real constraints.

- **User.php**
    A site user: the row gateway for `users`, the profile store, and the session facade in one
    class. `establish()` is the single writer of the login session and `logout()` the single
    teardown. See `modules/Auth/CLAUDE.md` for the session contract.

- **GroupPermission.php**
    Reusable group-based permission handler for per-row sharing — owner check plus group
    membership, with read/update/delete granularity. Used by Notes, Notebook, Kanban and Lists.
    Distinct from the `allow.*` capability keys that `#[RequirePermission]` gates on.
    See `GROUP_PERMISSIONS.md`.

## Auth and security

- **Crypto.php**
    Authenticated at-rest encryption (AES-256-GCM). Table-agnostic: any column can store
    `Crypto::encrypt($plain)` and recover it.

- **TokenBroker.php**
    Provider-token access for consumers such as the Dashboard. Returns an always-valid access
    token, refreshing transparently when the stored one has expired. The only component aware
    of expiry and refresh.

- **TrustedProxy.php**
    Which peers are entitled to tell us who the real client is. `X-Forwarded-For` and
    `CF-Connecting-IP` are just request headers — anyone can send them.

- **Cidr.php**
    IP address and CIDR range matching.

- **attribute/**
    The declarative gates: `RequireLogin`, `RequirePermission`, `AllowWithToken`,
    `AllowedMethods`, `RequiredParams`, `Sanitize`, `Validate`, `RestrictIP`, `AuditLog`,
    and the shelved `RequireAuthLevel`. See `docs/ATTRIBUTES.md`.

## Everything else

- **Console.php**
    Threshold-based logging. `Console::warn()`, `Console::debug()` and friends via
    `__callStatic`; messages are also retrievable in-process for the DevToolbar.

- **PluginLoader.php**
    Discovers and manages plugins. Scans `plugin/` for subdirectories containing a matching
    `.php` file, and fires hooks such as `afterRun`. See `docs/PLUGINS.md`.

- **ExternalRequest.php**
    Small curl wrapper for calling third-party APIs. Used by Dictionary and Thingiverse.

- **Error.php**
    Deprecated. Use `HTTPError`. See `DEPRECATING_CHANGES.md`.

- **SQLAdapter.php**
    Dead code — it extends an `Adapter` class that does not exist in this repo, and nothing
    references it. It would fatal if it were ever autoloaded.

## Directories

| Path | What it is |
|---|---|
| `attribute/` | The declarative gate attributes |
| `tests/` | PHPUnit suite for core |
| `skeleton/` | A minimal working site layout, for standing up a new install |
| `bin/` | Maintenance scripts (`refresh-cloudflare-ips.php`) |
| `archive/` | Superseded design notes. History, not documentation. |

# TODO

- Learn MarkDown and make this file prettier
- Better access control
- Subdomain support
- Delete `SQLAdapter.php` or restore the `Adapter` base class it needs
- Migrate the last `new Error()` call sites off the deprecated class
