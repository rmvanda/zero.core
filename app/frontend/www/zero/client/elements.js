var systemHUD = {
	state : "0",
	nextState : function() {
		return (this.state == "0") ? "-225px" : "0";
	},
	toggle : function() {
		$("#systemHUD").slideToggle();
	}
};

var asideMenu = {
	state : "-125px",
	nextState : function() {
		return this.state = (this.state == "0") ? "-125px" : "0";
	},
	toggle : function() {
		//$("aside").css("display", "block");
		$("aside").animate({
			"left" : asideMenu.nextState()
		}, 100);
	}
};

var authForm = {

	state : {
		a : {
			"top" : "-800px",
			"opacity" : 0
		},
		b : {
			"top" : "0",
			"opacity" : 1
		},
		next : function() {
			return this.current = (this.current == "a") ? "b" : "a";
		},
		current : "a"
	},

	toggle : function() {
		$("#authForm").animate(this.state[this.state.next()]);
	}
};

var form = {};

function Form(obj) {
	this.id = obj.id;
	this.send = function() {
		alert("hey-oh");
	};
	this.pause = function() {

		console.log("paused");
	};
	alert("constructed...");

	this.deconstruct = function() {
		console.log("fin");
	};
}

sideMenu = {

	state : {
		open : {},
		closed : {},
		current : {},
		next : {}
	},
	toggleState : function() {

		$("#asideMenu").hide();

	}
};
