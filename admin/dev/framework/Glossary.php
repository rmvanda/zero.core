<?php

/**
 * Map:
 * ./
 ├── admin 						  // This is where srv-side admin things go. The plan is to encapsulate it with all of the ADMIN_LTE assets so that nothing is left client-accessible. (Lest admin endpoints be revealed) 
 ├── app 						  // All of the app-specific things go here. 
 │   ├── _autoloader.php v1.0.2   // @autoloader via exec("find [...]") - pretty need, but still needs PenTesting
 │   ├── _configs 				  // This folder is automatically parsed out in Application.php - each line in each file gets run through*/ define($key,$value) /**
 │   │   ├── config.ini     
 │   │   ├── constants.ini
 │   │   ├── database.ini
 │   │   └── paths.ini
 │   ├── controllers 			  // Controllers go here - will eventually follow the division - 
 │   │   ├── Auth.php
 │   │   ├── Error.php
 │   │   ├── Index.php
 │   │   ├── Process.php
 │   │   └── Test.php
 │   ├── models 				  // Self explanatory - 
 │   │   └── IndexModel.php
 │   ├── traits
 │   │   └── GeneralFunctions.php // for Page
 │   └── views 					  // Your template stuff goes here
 │       ├── about 				  // i.e. domain.com/abount/contact
 │       │   └── contact.php
 │       ├── docs
 │       │   ├── copyright.php
 │       │   ├── privacy-policy.php
 │       │   └── terms-of-use.php
 │       ├── _global
 │       │   ├── footer.php
 │       │   ├── header.php
 │       │   └── head.php
 │       ├── index 				  // i.e. domain.com/ 
 │       │   └── index.php 		  // 'index' is implied
 │       ├── Index.php 			  // To be deleted. 
 │       └── test
 │           └── index.php
 ├── dev
 │   ├── bin
 │   │   ├── bun
 │   │   ├── install
 │   │   └── update
 │   └── framework
 │       ├── Changelog.php
 │       ├── Glossary.php
 │       └── Roadmap.php
 ├── srv
 │   ├── base
 │   │   ├── Application.php
 │   │   ├── App.php
 │   │   ├── Client.php
 │   │   ├── Model.php
 │   │   ├── old
 │   │   │   ├── Controller.php
 │   │   │   └── View.php
 │   │   ├── Page.php
 │   │   ├── Request.php
 │   │   └── Response.php
 │   ├── bin
 │   │   └── tpl
 │   │       └── _configs
 │   │           ├── config.ini
 │   │           ├── constants.ini
 │   │           ├── database.ini
 │   │           └── paths.ini
 │   ├── libs
 │   │   ├── ae.php
 │   │   ├── apis
 │   │   │   └── adapters
 │   │   │       └── Facebook.php
 │   │   ├── Basecamp.php
 │   │   ├── Captcha.php
 │   │   ├── data
 │   │   │   ├── Signatures
 │   │   │   │   └── captureForm.php
 │   │   │   └── SMS_Gateways
 │   │   │       ├── gateways.json
 │   │   │       ├── providers.json
 │   │   │       └── SMS_Gateways_master.json
 │   │   ├── MantisAdapter.php
 │   │   ├── Mantis.php
 │   │   ├── relativeTime.php
 │   │   ├── SCAN.php
 │   │   ├── Signature.php
 │   │   ├── SMS.php
 │   │   ├── Utilities.php
 │   │   ├── Whitepages.php
 │   │   └── xdebug_reff.php
 │   └── plugins
 │       └── Vcard.php
 ├── usr
 └── www
 ├── assets
 │   ├── css
 │   │   ├── global.css
 │   │   └── reset.css
 │   ├── js
 │   │   ├── global.js
 │   │   └── libs
 │   │       ├── jquery-latest.js
 │   │       └── signature_pad
 │   │           ├── bower.json
 │   │           ├── Gruntfile.js
 │   │           ├── package.json
 │   │           ├── README.md
 │   │           ├── signature_pad.js
 │   │           ├── signature_pad.min.js
 │   │           └── src
 │   │               └── signature_pad.js
 │   └── something
 └── index.php

 *
 */

/**
 * Plugins:
 *
 *
 *
 */
