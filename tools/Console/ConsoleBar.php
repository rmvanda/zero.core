<style>
	.console {
		display: block;
		height: 44px;
		padding: 22px;
		position: fixed;
		bottom: 0;
		width: 100%;
		background-color: rgba(0,0,0,0.8);
		color: white;
		z-index:1;
		box-sizing:content-box;
		color:black; 
	}
	console button {
		height: 100%;
		width: 50px;
	}
	arrow {
		display: block !important;
		color: blue;
		height: 40px;
		width: 40px;
		border-left: 4px solid rgba(55,55,55,0.2);
		border-bottom:4px solid rgba(55,55,55,0.2);
		position: fixed;
		bottom: 11px;
		right: 22px;
		border-top: 4px solid rgba(255,255,255,0.4);
		border-right: 4px solid rgba(255,255,255,0.4);
		-webkit-transform: rotate(135deg);
		-moz-transform: rotate(135deg);
		-o-transform: rotate(135deg);
		-ms-transform: rotate(135deg);
		transform: rotate(135deg);
		display: none;
		z-index:2;
	}
	console button display {
		display: none;
		position: fixed;
		width: 280px;
		height: 200px;
		overflow-y: scroll;
		bottom: 88px;
		background-color: rgba(88,88,88,0.8);
		color: white;
		border: 1px solid green;
		font-size: 8px;
		text-align: left;
	}

</style>

<arrow action="toggle"></arrow>
<console class="console">
	<button id="autoloader">
		autoloader
		<display>
			<?=$this -> getAutoloadList(); ?>
		</display>
	</button>
	<button id="server">
		server
		<display>
			<?=print_x($_SESSION);
            print_x($_SERVER);
        ?>
		</display>
	</button>

	<button>
		request
		<display>
			<?php print_x($_REQUEST); ?>
		</display>
	</button>
</console>

<script>
	var konsole = {
		hide : function() {
			$("console").hide();
			$("arrow").show();
		},
		show : function() {
			$("console").show();
			$("arrow").hide();
		},
		toggle : function() {
			$("console").slideToggle();

		}
	};
	$("arrow").click(function() {
		console.log("ello");
		konsole.toggle();

	});
	$("console > *").on("click", function() {

		action = $(this).attr("action");

		console.log(action);
		if ( typeof action != 'undefined') {
			konsole[action]();
		}
		display = $(this).find("display");
		if (display.length > 0) {
			console.log("here");
			display.fadeToggle();
		}
	});

</script>

