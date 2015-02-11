<body>
	<script>
	/*
		window.fbAsyncInit = function() {
			FB.init({
				appId : '',
				xfbml : true,
				version : 'v2.0'
			});
		}; ( function(d, s, id) {
				var js, fjs = d.getElementsByTagName(s)[0];
				if (d.getElementById(id)) {
					return;
				}
				js = d.createElement(s);
				js.id = id;
				js.src = "//connect.facebook.net/en_US/sdk.js";
				fjs.parentNode.insertBefore(js, fjs);
			}(document, 'script', 'facebook-jssdk'));
			*/
	</script>
	<header>
			<nav>
			<ul>

			</ul>
		</nav>
		<button id="hamburger"></button>
	</header>
	<section id="target" class="<?="/" . Request::$endpoint . "/" . Request::$aspect."/" ?>">