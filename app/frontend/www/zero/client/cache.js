if ( typeof localStorage == 'undefined') {
	cache = {
		getItem : function(prop) {
			return this[prop];
		},
		setItem : function(key, val) {
			this[key] = val;
		}
	};
} else {
	cache = localStorage;
}