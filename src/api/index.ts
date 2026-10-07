import axios from 'axios';

const API_BASE_URL = process.env.REACT_APP_API_URL || 'http://localhost:3001';

export const api = {
  // ...existing methods...

  getPhotoPersons: async (photoId: string): Promise<any[]> => {
    const response = await axios.get(`${API_BASE_URL}/photos/${photoId}/persons`);
    return response.data;
  },

  addPersonToPhoto: async (photoId: string, personId: string): Promise<void> => {
    await axios.post(`${API_BASE_URL}/photos/${photoId}/persons`, { personId });
  },

  removePersonFromPhoto: async (photoId: string, personId: string): Promise<void> => {
    await axios.delete(`${API_BASE_URL}/photos/${photoId}/persons/${personId}`);
  },
};
