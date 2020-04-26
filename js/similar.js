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
	this._similarAccepted = [];
	this._similarRejected = [];
	this._similarIgnored = [];
	this._similarApplied = [];
	this._similarName = undefined;
};

Similar.prototype = {
	isEnabled: function () {
		return this._enabled;
	},
	findProposal: function (clusterId, clusterName) {
		if (this._similarName !== clusterName) {
			this.resetProposals(clusterId, clusterName);
		}
		var self = this;
		var deferred = $.Deferred();
		$.get(this._baseUrl+'/relation/'+clusterId).done(function (response) {
			self._enabled = response.enabled;
			if (!self._enabled) {
				self.resetSuggestions(clusterId, clusterName);
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
	getAcceptedProposal: function () {
		return this._similarAccepted;
	},
	answerProposal: function (proposal, state) {
		var self = this;
		// First of all, remove from queue all relations with that ID
		var newProposed = [];
		self._similarProposed.forEach(function (oldProposal) {
			if (proposal.id !== oldProposal.id) {
				newProposed.push(oldProposal);
			} else {
				if (state === Relation.ACCEPTED) {
					oldProposal.state = Relation.ACCEPTED;
					self._similarAccepted.push(oldProposal);
					self._similarApplied.push(oldProposal);
				} else if (state === Relation.REJECTED) {
					oldProposal.state = Relation.REJECTED;
					self._similarRejected.push(oldProposal);
					self._similarApplied.push(oldProposal);
				} else {
					oldProposal.state = Relation.PROPOSED;
					self._similarIgnored.push(oldProposal);
				}
			}
		});
		self._similarProposed = newProposed;

		// Add the old proposal to its actual state.
		proposal.state = state;
		if (state === Relation.ACCEPTED) {
			this._similarAccepted.push(proposal);
			this._similarApplied.push(proposal);
		} else if (state === Relation.REJECTED) {
			this._similarRejected.push(proposal);
			this._similarApplied.push(proposal);
		} else {
			this._similarIgnored.push(proposal);
		}
	},
	applyProposals: function () {
		var self = this;
		var deferred = $.Deferred();
		var data = {
			personsRelations: self._similarApplied,
			personName: self._similarName
		};
		$.ajax({
			url: this._baseUrl + '/relations',
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
			// An person ca be propoced several times, since they are related to direfents persons.
			if (self._similarAccepted.find(function (oldProposed) { return proposed.id === oldProposed.id;}) !== undefined) {
				proposed.state = Relation.ACCEPTED;
				self._similarAccepted.push(proposed);
				self._similarApplied.push(proposed);
			} else if (self._similarRejected.find(function (oldProposed) { return proposed.id === oldProposed.id;}) !== undefined) {
				proposed.state = Relation.REJECTED;
				self._similarRejected.push(proposed);
				self._similarApplied.push(proposed);
			} else if (self._similarIgnored.find(function (oldProposed) { return proposed.id === oldProposed.id;}) !== undefined) {
				proposed.state = Relation.REJECTED;
				self._similarIgnored.push(proposed);
			} else {
				proposed.state = Relation.PROPOSED;
				self._similarProposed.push(proposed);
			}
		});
	},
	resetProposals: function (clusterId, clusterName) {
		this._similarAccepted = [];
		this._similarProposed = [];
		this._similarRejected = [];
		this._similarIgnored = [];
		this._similarApplied = [];
		this._similarName = undefined;

		// Add a fake proposal to self-accept when referring to the initial person
		var fakeProposal = {
			origId: clusterId,
			id: clusterId,
			name: clusterName,
			state: Relation.ACCEPTED
		};
		this._similarAccepted.push(fakeProposal);
	}
};