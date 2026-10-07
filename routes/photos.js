const express = require('express');
const router = express.Router();
const db = require('../db');

// Get all persons tagged in a photo
router.get('/:id/persons', async (req, res) => {
  try {
    const { id } = req.params;
    const persons = await db.query(
      `SELECT p.* FROM persons p
       JOIN photo_persons pp ON p.id = pp.person_id
       WHERE pp.photo_id = $1`,
      [id]
    );
    res.json(persons);
  } catch (err) {
    console.error('Error fetching photo persons:', err);
    res.status(500).json({ error: 'Failed to fetch persons' });
  }
});

// Add a person to a photo
router.post('/:id/persons', async (req, res) => {
  try {
    const { id } = req.params;
    const { personId } = req.body;

    if (!personId) {
      return res.status(400).json({ error: 'personId is required' });
    }

    // Check if already tagged
    const existing = await db.query(
      'SELECT 1 FROM photo_persons WHERE photo_id = $1 AND person_id = $2',
      [id, personId]
    );

    if (existing.rows.length > 0) {
      return res.status(409).json({ error: 'Person already tagged in this photo' });
    }

    await db.query(
      'INSERT INTO photo_persons (photo_id, person_id) VALUES ($1, $2)',
      [id, personId]
    );

    res.status(201).json({ message: 'Person added to photo successfully' });
  } catch (err) {
    console.error('Error adding person to photo:', err);
    res.status(500).json({ error: 'Failed to add person to photo' });
  }
});

// Remove a person from a photo
router.delete('/:id/persons/:personId', async (req, res) => {
  try {
    const { id, personId } = req.params;

    const result = await db.query(
      'DELETE FROM photo_persons WHERE photo_id = $1 AND person_id = $2',
      [id, personId]
    );

    if (result.rowCount === 0) {
      return res.status(404).json({ error: 'Person not found in this photo' });
    }

    res.json({ message: 'Person removed from photo successfully' });
  } catch (err) {
    console.error('Error removing person from photo:', err);
    res.status(500).json({ error: 'Failed to remove person from photo' });
  }
});

module.exports = router;
