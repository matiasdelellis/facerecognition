import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import PhotoPersonsTab from '../components/PhotoPersonsTab';
import { api } from '../api';

const PhotoDetailPage: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const [photo, setPhoto] = useState<any>(null);
  const [people, setPeople] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    loadData();
  }, [id]);

  const loadData = async () => {
    try {
      setLoading(true);
      const [photoData, peopleData] = await Promise.all([
        api.getPhoto(id!),
        api.getAllPeople(),
      ]);
      setPhoto(photoData);
      setPeople(peopleData);
    } catch (err) {
      console.error('Failed to load photo data', err);
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-xl text-gray-500">Loading...</div>
      </div>
    );
  }

  if (!photo) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-xl text-gray-500">Photo not found</div>
      </div>
    );
  }

  return (
    <div className="max-w-6xl mx-auto px-4 py-8">
      <h1 className="text-3xl font-bold mb-6">Photo Details</h1>
      
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <div>
          <img
            src={photo.url}
            alt={photo.filename}
            className="w-full rounded-lg shadow-lg"
          />
          <p className="mt-4 text-gray-600 text-center">{photo.filename}</p>
        </div>

        <div>
          <PhotoPersonsTab
            photo={photo}
            people={people}
            onPeopleChange={loadData}
          />
        </div>
      </div>
    </div>
  );
};

export default PhotoDetailPage;
