'use strict';

const Relation = {
	PROPOSED: 0,
	ACCEPTED: 1,
	REJECTED: 2
}

var Similar = function (baseUrl) {
	this._baseUrl = baseUrl;

	this._enabled = false;
	this._similarProposed = [];
	this._similarRejected = [];
	this._similarName = undefined;
};

Similar.prototype = {
	isEnabled: function () {
		return this._enabled;
	},
	findProposal: function (clusterId, clusterName) {
		if (this._similarName !== clusterName) {
			this.resetProposals();
		}
		var self = this;
		var deferred = $.Deferred();
		$.get(this._baseUrl+'/relation/'+clusterId).done(function (response) {
			self._enabled = response.enabled;
			if (!self._enabled) {
				self.resetSuggestions();
			} else {
				self.concatNewProposals(response.proposed);
				self._similarName = clusterName;
			}
			deferred.resolve();
		}).fail(function () {
			deferred.reject();
		});
		return deferred.promise();
	},
	acceptProposed: function (proposed) {
		return this.updateRelation(proposed.origId, proposed.id, Relation.ACCEPTED);
	},
	rejectProposed: function (proposed) {
		this._similarRejected.push(proposed);
		return this.updateRelation(proposed.origId, proposed.id, Relation.REJECTED);
	},
	hasProposal: function () {
		return (this._similarProposed.length > 0);
	},
	getProposal: function () {
		return this._similarProposed.shift();
	},
	updateRelation: function (clusterId, toClusterId, newState) {
		var self = this;
		var deferred = $.Deferred();
		var data = {
			toPersonId: toClusterId,
			state: newState
		};
		$.ajax({
			url: this._baseUrl + '/relation/' + clusterId,
			method: 'PUT',
			contentType: 'application/json',
			data: JSON.stringify(data)
		}).done(function (data) {
			deferred.resolve();
		}).fail(function () {
			deferred.reject();
		});
		return deferred.promise();
	},
	concatNewProposals: function (proposals) {
		var self = this;
		proposals.forEach(function (proposed) {
			if ((self._similarProposed.find(function (oldProposed) { return proposed.id === oldProposed.id;}) === undefined) &&
			    (self._similarRejected.find(function (rejProposed) { return proposed.id === rejProposed.id;}) === undefined)) {
				self._similarProposed.push(proposed);
			}
		});
	},
	resetProposals: function () {
		this._similarProposed = [];
		this._similarRejected = [];
		this._similarName = undefined;
	},
};