var actionIndex = {

	__noSuchMethod__ : function(id, args) {
		console.log("No Such Method: " + id + "  " + args);
	},
	login : function() {
		alert("login action - ! ");
	},
	toggleAside : function() {
		asideMenu.toggle();
	},
	showMenu : function() {
		asideMenu.toggle();
	},
	showAuth : function() {
		authForm.toggle();
	},
	showHUD : function() {
		systemHUD.toggle();
	}
};
