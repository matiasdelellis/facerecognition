var Similar = function (baseUrl) {
	this._baseUrl = baseUrl;
	this._enabled = false;
	this._similarClusters = [];
	this._similarRejected = [];
	this._similarName = undefined;
};

Similar.prototype = {
	isEnabled: function () {
		return this._enabled;
	},
	loadSimilar: function (clusterId, clusterName) {
		if (this._similarName != clusterName) {
			this.resetSuggestions();
		}
		var self = this;
		var deferred = $.Deferred();
		$.get(this._baseUrl+'/clusters/similar/'+clusterId).done(function (response) {
			self._enabled = response.enabled;
			if (!self._enabled) {
				self.resetSuggestions();
			} else {
				self.concatNewClusters(response.suggestions);
				self._similarName = clusterName;
			}
			deferred.resolve();
		}).fail(function () {
			deferred.reject();
		});
		return deferred.promise();
	},
	hasSuggestion: function () {
		return (this._similarClusters.length > 0);
	},
	getSuggestion: function () {
		return this._similarClusters.shift();
	},
	rejectSuggestion: function (suggestion) {
		return this._similarRejected.push(suggestion);
	},
	concatNewClusters: function (newClusters) {
		var self = this;
		newClusters.forEach(function (newCluster) {
			if ((self._similarClusters.find(function (oldCluster) { return newCluster.id === oldCluster.id;}) === undefined) &&
			    (self._similarRejected.find(function (rejCluster) { return newCluster.id === rejCluster.id;}) === undefined)) {
				self._similarClusters.push(newCluster);
			}
		});
	},
	resetSuggestions: function () {
		this._similarClusters = [];
		this._similarRejected = [];
		this._similarName = undefined;
	},
};