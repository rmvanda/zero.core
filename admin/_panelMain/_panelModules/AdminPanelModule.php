<?php

    class AdminPanelModule
    {

        public $specs = array(
            'icon' => '',
            "uri" => '',
            "otherThing" => ''
        );

        public function __toString()
        {
            return json_encode($this -> specs, JSON_PRETTY_PRINT);
        }

    }
