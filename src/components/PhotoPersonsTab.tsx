import React, { useState, useEffect } from 'react';
import { Photo, Person } from '../types';
import { api } from '../api';
import { PlusIcon, XMarkIcon } from '@heroicons/react/24/outline';

interface PhotoPersonsTabProps {
  photo: Photo;
  people: Person[];
  onPeopleChange?: () => void;
}

const PhotoPersonsTab: React.FC<PhotoPersonsTabProps> = ({ photo, people, onPeopleChange }) => {
  const [photoPersons, setPhotoPersons] = useState<Person[]>([]);
  const [showAddDialog, setShowAddDialog] = useState(false);
  const [selectedPersonId, setSelectedPersonId] = useState<string>('');
  const [searchQuery, setSearchQuery] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    loadPhotoPersons();
  }, [photo.id]);

  const loadPhotoPersons = async () => {
    try {
      setLoading(true);
      const persons = await api.getPhotoPersons(photo.id);
      setPhotoPersons(persons);
    } catch (err) {
      setError('Failed to load persons');
    } finally {
      setLoading(false);
    }
  };

  const handleRemovePerson = async (personId: string) => {
    try {
      await api.removePersonFromPhoto(photo.id, personId);
      setPhotoPersons(prev => prev.filter(p => p.id !== personId));
      onPeopleChange?.();
    } catch (err) {
      setError('Failed to remove person from photo');
    }
  };

  const handleAddPerson = async () => {
    if (!selectedPersonId) return;
    try {
      setLoading(true);
      await api.addPersonToPhoto(photo.id, selectedPersonId);
      const person = people.find(p => p.id === selectedPersonId);
      if (person) {
        setPhotoPersons(prev => [...prev, person]);
      }
      setShowAddDialog(false);
      setSelectedPersonId('');
      setSearchQuery('');
      onPeopleChange?.();
    } catch (err) {
      setError('Failed to add person to photo');
    } finally {
      setLoading(false);
    }
  };

  const filteredPeople = people.filter(p =>
    p.name.toLowerCase().includes(searchQuery.toLowerCase())
  );

  return (
    <div className="space-y-4">
      {error && (
        <div className="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
          {error}
        </div>
      )}

      <div className="flex items-center justify-between">
        <h3 className="text-lg font-semibold">Persons in this photo</h3>
        <button
          onClick={() => setShowAddDialog(true)}
          className="flex items-center gap-2 px-3 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors"
          disabled={loading}
        >
          <PlusIcon className="w-4 h-4" />
          Add Person
        </button>
      </div>

      {loading && !photoPersons.length ? (
        <p className="text-gray-500">Loading...</p>
      ) : photoPersons.length === 0 ? (
        <p className="text-gray-500">No persons tagged in this photo yet.</p>
      ) : (
        <div className="space-y-2">
          {photoPersons.map(person => (
            <div
              key={person.id}
              className="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
            >
              <div className="flex items-center gap-3">
                {person.faceImage ? (
                  <img
                    src={person.faceImage}
                    alt={person.name}
                    className="w-8 h-8 rounded-full object-cover"
                  />
                ) : (
                  <div className="w-8 h-8 rounded-full bg-blue-500 flex items-center justify-center text-white text-sm font-bold">
                    {person.name.charAt(0)}
                  </div>
                )}
                <span className="font-medium">{person.name}</span>
              </div>
              <button
                onClick={() => handleRemovePerson(person.id)}
                className="p-1 text-gray-400 hover:text-red-500 transition-colors"
                title="Remove from photo"
              >
                <XMarkIcon className="w-5 h-5" />
              </button>
            </div>
          ))}
        </div>
      )}

      {showAddDialog && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg p-6 w-full max-w-md mx-4">
            <h2 className="text-xl font-bold mb-4">Add Person to Photo</h2>

            <input
              type="text"
              placeholder="Search people..."
              value={searchQuery}
              onChange={e => setSearchQuery(e.target.value)}
              className="w-full px-4 py-2 border rounded-lg mb-4 focus:outline-none focus:ring-2 focus:ring-blue-500"
            />

            <div className="max-h-60 overflow-y-auto space-y-2 mb-4">
              {filteredPeople.length === 0 ? (
                <p className="text-gray-500 text-center py-4">No people found</p>
              ) : (
                filteredPeople.map(person => (
                  <button
                    key={person.id}
                    onClick={() => setSelectedPersonId(person.id)}
                    className={`w-full flex items-center gap-3 p-3 rounded-lg transition-colors ${
                      selectedPersonId === person.id
                        ? 'bg-blue-100 border-2 border-blue-500'
                        : 'bg-gray-50 hover:bg-gray-100'
                    }`}
                  >
                    {person.faceImage ? (
                      <img
                        src={person.faceImage}
                        alt={person.name}
                        className="w-8 h-8 rounded-full object-cover"
                      />
                    ) : (
                      <div className="w-8 h-8 rounded-full bg-blue-500 flex items-center justify-center text-white text-sm font-bold">
                        {person.name.charAt(0)}
                      </div>
                    )}
                    <span className="font-medium">{person.name}</span>
                    {photoPersons.some(p => p.id === person.id) && (
                      <span className="text-xs text-gray-500 ml-auto">(already tagged)</span>
                    )}
                  </button>
                ))
              )}
            </div>

            <div className="flex gap-3 justify-end">
              <button
                onClick={() => {
                  setShowAddDialog(false);
                  setSelectedPersonId('');
                  setSearchQuery('');
                }}
                className="px-4 py-2 text-gray-600 hover:text-gray-800 transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleAddPerson}
                disabled={!selectedPersonId || loading}
                className="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
              >
                Add
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default PhotoPersonsTab;
