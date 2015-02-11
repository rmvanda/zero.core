<?php

class ModuleManager extends Response
{
    public $topcache;
    public function __construct()
    {

    }

    public static function makeInstallJson($object)
    {

    }

    public function hello()
    {
        echo "Suggest!";
    }

    public function newModule()
    {
        print_x(AdminPanel::topCache());
        Console::output();
    }

    public function edit($module)
    {

    }

    public function view()
    {
        foreach (AdminPanel::topCache() as $module => $properties) {
            echo "<h1>$module</h1>";
            print_x($properties);
        }
    }

    public function review($module)
    {
    }

    public function remove($module)
    {
    }

}
