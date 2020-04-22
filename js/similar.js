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
	hasProposal: function () {
		return (this._similarProposed.length > 0);
	},
	getProposal: function () {
		return this._similarProposed.shift();
	},
	updateProposal: function (proposal, newState) {
		var self = this;
		var deferred = $.Deferred();
		var data = {
			toPersonId: proposal.id,
			state: newState
		};
		$.ajax({
			url: this._baseUrl + '/relation/' + proposal.origId,
			method: 'PUT',
			contentType: 'application/json',
			data: JSON.stringify(data)
		}).done(function (data) {
			if (newState !== Relation.ACCEPTED)
				self._similarRejected.push(proposal);
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