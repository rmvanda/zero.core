    <body class="skin-blue">
        <header class="header">
            <nav class="navbar navbar-static-top" role="navigation">
                <a href="#" class="navbar-btn sidebar-toggle" data-toggle="offcanvas" role="button">
                    <span class="sr-only">Toggle navigation</span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                </a>
                <div class="navbar-right">
                    <ul class="nav navbar-nav">
                        <li class="dropdown console-menu">
                            <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                                <i class="fa fa-cogs"></i>
                                <span class="label label-success"><?php //TODO
                                    // Inbox::getNewMessages($_SESSION['uid'])
 ?></span>
                            </a>
                            <ul class="dropdown-menu">
                                <li class="header"><?php //TODO
 ?></li>
                                <li>
                                    <!-- inner menu: contains the actual data -->
                                   
                                    <ul class="menu">
                                        
                                       <?php die("motherfucker")?>
                                        
                                    </ul>
                                </li>
                                <li class="footer"><a href="#">See All Messages</a></li>
                            </ul>
                        </li><li class="dropdown messages-menu">
                            <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                                <i class="fa fa-cogs"></i>
                                <span class="label label-success"><?php //TODO
                                    // Inbox::getNewMessages($_SESSION['uid'])
 ?></span>
                            </a>
                            <ul class="dropdown-menu">
                                <li class="header"><?php //TODO ?></li>
                                <li>
                                    <ul class="menu">
                                        
                                        
                                    </ul>
                                </li>
                                <li class="footer"><a href="#">See All Messages</a></li>
                            </ul>
                        </li><li class="dropdown messages-menu">
                            <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                                <i class="fa fa-envelope"></i>
                                <span class="label label-success"><?php //TODO
                                    // Inbox::getNewMessages($_SESSION['uid'])
 ?></span>
                            </a>
                            <ul class="dropdown-menu">
                                <li class="header"><?php //TODO
                                // Inbox::getNewMessages($_SESSION['uid'])
 ?></li>
                                <li>
                                    <!-- inner menu: contains the actual data -->
                                   
                                    <ul class="menu">
                                        
                                       <?php?>
                                        
                                    </ul>
                                </li>
                                <li class="footer"><a href="#">See All Messages</a></li>
                            </ul>
                        </li>
                        <!-- Notifications: style can be found in dropdown.less -->
                        <li class="dropdown notifications-menu">
                            <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                                <i class="fa fa-warning"></i>
                                <span class="label label-warning"><?php //Server::getNotificationCount() ?></span>
                            </a>
                            <ul class="dropdown-menu">
                                <li class="header"><?php //Server::getNotificationCount() ?></li>
                                <li>
                                    <!-- inner menu: contains the actual data -->
                                    <ul class="menu">
    <?php?>
                                    </ul>
                                </li>
                                <li class="footer"><a href="#">View all</a></li>
                            </ul>
                        </li>
                        <!-- Tasks: style can be found in dropdown.less -->
                        <li class="dropdown tasks-menu">
                            <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                                <i class="fa fa-tasks"></i>
                                <span class="label label-danger"><?php //Task::count() ?></span>
                            </a>
                            <ul class="dropdown-menu">
                                <li class="header">You have <?php //Task::count() ?> tasks</li>
                                <li>
                                    <!-- inner menu: contains the actual data -->
                                    <ul class="menu">
                                    <?php?>
                                    </ul>
                                </li>
                                <li class="footer">
                                    <a href="#">View all tasks</a>
                                </li>
                            </ul>
                        </li>
                        <!-- User Account: style can be found in dropdown.less -->
                        <li class="dropdown user user-menu">
                            <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                                <i class="glyphicon glyphicon-user"></i>
                                <span><?=$_SESSION['username'] ?><i class="caret"></i></span>
                            </a>
                            <ul class="dropdown-menu">
                                <!-- User image -->
                                <li class="user-header bg-light-blue">
                                    <img src="<?=$_SESSION['user_pic_URI'] ?>" class="img-circle" alt="User Image" />
                                    <p>
                                        <?=$_SESSION['username'] . "-" . $_SESSION['user_role'] ?>
                                        <small></small>
                                    </p>
                                </li>
                                <!-- Menu Body -->
                                <li class="user-body">
                                    <div class="col-xs-4 text-center">
                                        <a href="#">button</a>
                                    </div>
                                    <div class="col-xs-4 text-center">
                                        <a href="#">button</a>
                                    </div>
                                    <div class="col-xs-4 text-center">
                                        <a href="#">button</a>
                                    </div>
                                </li>
                                <!-- Menu Footer-->
                                <li class="user-footer">
                                    <div class="pull-left">
                                        <a href="#" class="btn btn-default btn-flat">Profile &amp; Settings</a>
                                    </div>
                                    <div class="pull-right">
                                        <a href="/auth/logout" class="btn btn-default btn-flat">Sign Out</a>
                                    </div>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </nav>
        </header>
        <div class="wrapper row-offcanvas row-offcanvas-left">
            <!-- Left side column. contains the logo and sidebar -->
            <aside class="left-side sidebar-offcanvas">                
                <!-- sidebar: style can be found in sidebar.less -->
                <section class="sidebar">
                    <!-- Sidebar user panel -->
                    <div class="user-panel">
                        <div class="pull-left image">
                            <img src="/framework/zero-lte/img/avatar3.png" class="img-circle" alt="User Image" />
                        </div>
                        <div class="pull-left info">
                            <p>Hello, <?=$_SESSION['username'] ?></p>

                            <a href="#"><i class="fa fa-circle text-success"></i> Online</a>
                        </div>
                    </div>
                    <!-- search form -->
                    <form action="#" method="get" class="sidebar-form">
                        <div class="input-group">
                            <input type="text" name="q" class="form-control" placeholder="Search..."/>
                            <span class="input-group-btn">
                                <button type='submit' name='seach' id='search-btn' class="btn btn-flat"><i class="fa fa-search"></i></button>
                            </span>
                        </div>
                    </form>
                        <?= self::$instance->makeModuleMenu()// AdminPanel::makeModuleMenu() ?>       
                </section>
                <!-- /.sidebar -->
            </aside>

     