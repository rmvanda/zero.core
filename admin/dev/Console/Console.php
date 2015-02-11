<?php

/**
 * @depends on class Table
 *
 */
class Console// extends Page
{
    public static $instance;
    public $output, $table, $display;

    public function __call($func, $args)
    {
        $this -> output[$func][] = $args;
        return $this;
    }

    public function getAutoloadList()
    {
        echo "successish";
        foreach ($this->output['autoloading'] as $autoloaded) {
            echo $autoloaded[0];
        }
    }

    public function display()
    {
        if (defined("DEV")) {
            self::$instance -> displayBar();
            //load("ConsoleBar.php");
        }
    }

    public function displayBar()
    {
        require __DIR__ . "/ConsoleBar.php";
    }

    public static function log($type = null)
    {
        if (!self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    public static function output()
    {
        print_x(self::$instance);
        // echo '<div id="connnsole">';

        // foreach (self::$instance -> output as $title => $output) {
        // echo "$title : ";
        // echo "<tr>";
        // foreach ($output as $key => $value) {
        // echo "<th>$key</th>";
        // }
        // echo "<tr>";
        // foreach ($output as $key => $value) {
        // echo "";
        // }
        // foreach ($output as $t => $v) {
        // echo "$t: $v";
        // }
        // }
        // echo '</pre></div>';
    }

    public static function table($magic)
    {
        if (!self::$instance) {
            self::$instance = new self;
            self::$instance -> output = new Table();
        }
        if ($magic) {
            echo self::$instance -> output -> auto($magic) -> display();
        } else {
            return self::$instance -> output;
        }
    }

    public function __deconstruct()
    {

    }

    public function sdisplay()
    {
        print_x($_SERVER);
        print_x($_SESSION);
        print_x($_REQUEST);
    }

}
