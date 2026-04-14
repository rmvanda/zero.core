<body>

<!-- Header -->
<shadow-header class="loading">
    <button id="theMenuButton" class="menu-btn" slot="left" aria-label="Menu">
        <span class="material-symbols-outlined">menu</span>
    </button>
    <h1 slot="right"><a href="/"><?= SITE_NAME ?></a></h1>
</shadow-header>

<!-- Side Navigation -->
<shadow-nav id="main-nav" class="hidden">
    <li slot="subitem">
        <a href="/">
            <span class="material-symbols-outlined">home</span>
            Home
        </a>
    </li>
    <!-- Add your nav items here -->
</shadow-nav>

<main id="main">

