function swapTarget(content) {
	$("#target").removeAttr("class").addClass(content).html(cache[content]);
}
