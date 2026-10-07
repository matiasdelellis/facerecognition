-- Migration: Create photo_persons junction table for manual tagging
CREATE TABLE IF NOT EXISTS photo_persons (
  id SERIAL PRIMARY KEY,
  photo_id UUID NOT NULL REFERENCES photos(id) ON DELETE CASCADE,
  person_id UUID NOT NULL REFERENCES persons(id) ON DELETE CASCADE,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
  UNIQUE(photo_id, person_id)
);

-- Index for faster lookups
CREATE INDEX IF NOT EXISTS idx_photo_persons_photo_id ON photo_persons(photo_id);
CREATE INDEX IF NOT EXISTS idx_photo_persons_person_id ON photo_persons(person_id);
