<?php

namespace Zero\Module;

use Zero\Core\Exception\HTTPError;

/**
 * Module — a Zero Framework module.
 *
 * Routes at /module/. Endpoints map from the URL: /module/ping/hello calls
 * ping('hello').
 *
 * MKMOD:SKELETON-ONLY-START
 * This is the skeleton: a live, working example of every directory a Zero
 * module CAN contain. It routes at https://example.com/module/ so that every
 * example in here is verified by actually working. See README for a map of
 * the directories.
 *
 * `mkmod` copies this file and rewrites the anchored line `class Module ...`.
 * Keep that line on one line, starting at column 0.
 * MKMOD:SKELETON-ONLY-END
 */
class Module extends \Zero\Core\Module {
    // Don't forget to construct the parent... if you want to, anyway.
    // public function __construct(){parent::__construct();}

    /**
     * https://example.com/module/
     * Renders view/index.php inside the frame from frame/head.php.
     * Auto-loads assets/css/module.css + assets/css/index.css + assets/js/module.js.
     */
    public function index() {
        $this->pageTitle = 'Module Skeleton';
        $this->respond($this->viewPath . 'index.php');
    }

    /**
     * https://example.com/module/ping        -> $echo is null
     * https://example.com/module/ping/hello  -> $echo is "hello"
     *
     * Declare named parameters matching URL segments. Never use $args as a
     * catch-all. Too few arguments raises ArgumentCountError -> HTTP 400.
     *
     * respond() returns JSON when the Accept header asks for it, else HTML.
     * The JSON payload is wrapped: {"data": {...}, "status": ..., "code": ...}
     */
    public function ping($echo = null) {
        $this->data = ['pong' => true, 'echo' => $echo];
        $this->echo = $echo;
        $this->respond($this->viewPath . 'index.php');
    }

    /**
     * https://example.com/module/not-found
     * Demonstrates view/error404.php. HTTPError bubbles to Application::run(),
     * which calls renderError() on this live module, preserving the frame.
     */
    public function notFound() {
        throw new HTTPError(404, 'Thrown on purpose to demo view/error404.php');
    }
}
