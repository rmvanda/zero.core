<?php

/**
 *
 */
namespace Zero {

    abstract class AdminPanelModule
    {

        public $title;
        public $uri;
        public $isActive;

        public $alert;
        public $menu;

        protected static $topcache;

    }

    class AdminPanel
    {

        public $moduleMenu = array(
            '<ul class="sidebar-menu">',
            '<li><a href="/"><i class="fa fa-dashboard"></i><span>Dashboard</span></a></li>'
        );

        private static $instance, $cache;

        private $return, $i;

        protected $topCache;

        public function __construct()
        {

            // define("ADMIN_PANEL_VIEW_PATH", )
            define("ADMIN_PANEL_PATH", ADMIN_PATH . "_panelMain/");
            define("ADMIN_PANEL_VIEW_PATH", ADMIN_PANEL_PATH . "view/");
            define("TOP_CACHE", ADMIN_PANEL_PATH . "topCache.json");

            // die("<br><br>".ADMIN_PANEL_PATH);

            $this -> loadCache();
        }

        private static function instantiate()
        {
            return self::$instance = new self;
        }

        public static function topCache($output = null)
        {
            if (!self::$instance) {
                self::instantiate();
            }
            return self::$instance -> loadCache();
        }

        private function loadCache()
        {
            return $this -> moduleList = $this -> topCache = json_decode(file_get_contents(TOP_CACHE));
            //, JSON_FORCE_OBJECT);
            if (!$this -> moduleList) {
                Error::JSON();
            }
        }

        public function makeModuleMenu()
        {
            foreach ($this -> moduleList as $moduleTitle => $module) {

                $this -> addTopMenuItem($module, $moduleTitle);
            }
            $this -> moduleMenu[] = "</ul><!-- done --> ";
            return implode("\n", $this -> moduleMenu);
        }

        public function addTopMenuItem($module, $moduleTitle = null)
        {

            $this -> moduleMenu[] = '<li id="' . $moduleTitle . '" class="treeview">';
            $this -> moduleMenu[] = '<a href="' . $module -> href . '">';
            $this -> moduleMenu[] = '<i class="fa ' . $module -> icon . '"></i>';
            $this -> moduleMenu[] = '<span>' . ($moduleTitle ? : $module -> title ? : "Unknown Module Name") . '</span>';
            $this -> moduleMenu[] = '<i class="fa pull-right fa-angle-down"></i></a>';

            $this -> addSubMenu($module -> menu);

            $this -> moduleMenu[] = "</li>";
        }

        private function isGhostMenu($href)
        {
            if (is_object($href)) {
                return json_decode(json_encode($href), JSON_FORCE_OBJECT);
            } elseif (is_array($href)) {
                return $href;
            } else {
                return false;
            }
        }

        public function addSubMenu($menuItems)
        {
            $this -> moduleMenu[] = '<ul class="treeview-menu">';

            foreach ($menuItems as $title => $href) {

                if ($href = $this -> isGhostMenu($href)) {
                    $this -> addGhostMenu($title, $href);
                } else {
                    $this -> moduleMenu[] = '<li><a href="' . $href . '" style="margin-left:10px;"><i class="fa fa-angle-double-right"></i>' . $title . '</a>  </li>';
                }
            }
            $this -> moduleMenu[] = '</ul>';
        }

        public function addGhostMenu($title, $href)
        {

            $this -> moduleMenu[] = '<li class="ghostMenuContainer"><a href="#" style="margin-left:10px;"><i class="fa fa-angle-double-right"></i>' . $title . '</a> <ul class="ghostMenu">';

            foreach ($href as $title => $href) {
                if (is_array($href)) {
                    $this -> addGhostMenu($title, $href);
                } else {
                    $this -> moduleMenu[] = '<li><a href="' . $href . '" style="margin-left:10px;"><i class="fa fa-angle-double-right"></i>' . $title . '</a>  </li>';
                }
            }
            $this -> moduleMenu[] = '</ul></li>';
        }

        public function addSubMenuItem($title, $href)
        {
            $this -> moduleMenu[] = '<ul class="treeview-menu">';
            if (is_array($href)) {
                foreach ($href as $key => $value) {
                    $this -> addGhostMenuItem($key, $value);
                }
            }
        }

        public function addMenuItem($module)
        {
            foreach ($module->menu as $title => $href) {
                //$this->moduleMenu[] =
                $this -> moduleMenu[] = $title . " - " . $href . "<br>";
            }
        }

        /**
         public function makeAdminHeader()
         {
         }

         public function makeAdminSideBar()
         {
         $this -> loadCache();

         } */

        public static function Modules()
        {

            self::$instance = new self;

            define("ADMIN_PANEL_MODULE_PATH", ROOT_PATH . "admin/_panelModules/");
            $topCache = json_decode(file_get_contents(ADMIN_PANEL_MODULE_PATH . "topCache.json"), JSON_FORCE_OBJECT);
            unset($topCache[0]);

            self::$instance -> return = '';

            self::$instance -> return[] = '<ul class="sidebar-menu">';

            foreach ($topCache as $moduleName => $module) {

                //  echo "<h1>" . $moduleName . "</h1>";
                self::$instance -> i = 1;
                self::$instance -> buildModuleMenu($moduleName, $module['views']);
            }

            self::$instance -> return[] = '</ul>';
            //print_x($module);
            //self::$instance -> $return[] = '<li class="treeview"><a><i class="'
            // . $module['icon'] . '"></i><span>' . $moduleName . '</span><i
            // class="fa fa-angle-down pull-right"></i></a>';
            //self::$instance -> $return .= '<ul class="treeview-menu">';
            // print_x($module);
            // self::$instance -> buildSubMenu($moduleName, $module['views']);

            echo implode("\n", self::$instance -> return);

        }

        private function saveCache()
        {
            include ADMIN_PANEL_VIEW_PATH . "_global/header.php";

        }

        private function updateCache()
        {

        }

        private function addToCache()
        {

        }

        private function buildModuleMenu($title, $view)
        {
            echo "<h1>" . $title . "</h1>";
            print_x($view);
            echo "<hr>";
            self::$instance -> return[] = '<li class="treeview">
                                    <a href="' . ($view['href'] ? : "#") . '"> 
                                        <i class="fa ' . $view['icon'] . '"></i> 
                                        <span>' . $title . '</span> 
                                        <i class="fa pull-right fa-angle-down"></i> 
                                    </a>';

            foreach ($view as $key => $uri) {
                if (is_array($uri)) {
                    self::$instance -> buildSubMenu($key, $uri);
                } else {
                    $uri = str_replace('.php', '', $uri);
                    self::$instance -> return[] = '<li><a href="' . $uri . '/" style="margin-left: ' . (self::$instance -> i * 10) . 'px;">
                                                <i class="fa fa-angle-double-right"></i> 
                                                ' . $uri . '</a>  </li>';

                }

            }

        }

        private function buildSubMenu($title, $view)
        {
            self::$instance -> return[] = '<li class="treeview">
                                    <a href="' . ('' ? : "#") . '"> 
                                        <i class="fa ' . $view['icon'] . '"></i> 
                                        <span>' . $title . '</span> 
                                        <i class="fa pull-right fa-angle-down"></i> 
                                    </a><ul class="treeview-menu"v>';
            foreach ($view as $title => $uri) {
                if (is_array($uri)) {
                    self::$instance -> buildSubMenu($title, $uri);
                } else {
                    $uri = str_replace('.php', '', $uri);
                    self::$instance -> return[] = '<li><a href="' . $uri . '/" style="margin-left: ' . (self::$instance -> i * 10) . 'px;">
                                                <i class="fa fa-angle-double-right"></i> 
                                                ' . $uri . '</a>  </li>';
                }
            }
            self::$instance -> return[] = '</li>';
        }

        public static function generate()
        {
            if (!$instance) {
                self::instantiate();
            }
            return self::$instance;
        }

        public function header()
        {
            include ADMIN_PANEL_VIEW_PATH . "_global/head.php";
            include ADMIN_PANEL_VIEW_PATH . "_global/header.php";
            return $this -> footer();
        }

        public function footer()
        {
            include ADMIN_PANEL_VIEW_PATH . "_global/footer.php";
            return $this;
        }

        /* echo $title;

         if (self::$instance -> i == 1) {
         self::$instance -> return[] = '<li class="treeview active">
         <a href="' . ($view['href'] ? : "#") . '">
         <i class="fa ' . $view['icon'] . '"></i>
         <span>' . $title . '</span>
         <i class="fa pull-right fa-angle-down"></i>
         </a>';

         }

         self::$instance -> i++;
         if (true) {

         self::$instance -> return[] = '<li class="treeview active">
         <a href="' . ('' ? : "#") . '">
         <i class="fa fa-double-right"></i>
         <span>' . $title . '</span>
         <i class="fa pull-right fa-angle-down"></i>
         </a>';

         foreach ($view as $key => $value) {
         if (is_array($value)) {
         self::$instance -> buildSubMenu($key, $value);
         } else {
         self::$instance -> return[] = ' <li><a href="' . $value . '"
         style="margin-left: ' . (10 * self::$instance -> i) . 'px;"><i class="fa
         fa-angle-double-right"></i> Icons</a></li>';
         }
         }

         }

         self::$instance -> i--;

         }

         //   return;
         /*
         echo "<h1>$title</h1>";
         print_x($view);

         if (is_array($view)) {
         self::$instance -> buildSubMenu($view, $view);
         }

         return;

         foreach ($view as $key => $value) {

         if (is_array($view)) {

         self::$instance -> return[] = '<li class="treeview active">
         <a href="' . ($view['href'] ? : $title ? : "#") . '">
         <i class="fa ' . $view['icon'] . '"></i>
         <span>' . $title . '</span>
         <i class="fa pull-right fa-angle-down"></i>
         </a>';

         } else {

         }

         // self::$instance -> return .= '<ul class="treeview-menu">';

         }
         /*
         foreach ($view as $title => $uri) {
         if (is_array($uri)) {
         self::$instance -> buildSubMenu($uri);
         } else {

         self::$instance -> return[] = '';

         }
         } */

    }

}
