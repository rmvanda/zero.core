$(document).ready(function() {
	$("button,a").click(function(e) {
		e.preventDefault();
		e.stopPropagation();
		if ($(this).attr("href")) {
			console.log("navigating to" + $(this).attr("href"));
			navigation.navigate($(this).attr("href"));
			return;
		} else {
			if ( typeof $(this).attr("id") != 'undefined') {
				action = $(this).attr("id");
			} else {
				action = $(this).text().toLowerCase().split(" ");
				action = action[0].trim();
			}
			console.log(action);
			actionIndex[action]();
		}
	});

	$("li").click(function() {
		$(this).find("a").click();
	});

});
