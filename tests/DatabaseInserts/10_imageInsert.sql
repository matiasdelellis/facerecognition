INSERT INTO *PREFIX*facerecog_images (id, nc_file_id, model, is_processed, error, last_processed_time, processing_duration) VALUES
  (1, 101, 1, 1, NULL, '2025-08-26 10:05:00', 120),
  (3, 103, 1, 1, NULL, '2025-08-26 10:15:00', 150),
  (5, 105, 1, 0, NULL, NULL, 0),
  (7, 107, 1, 0, NULL, NULL, 0),
  (10, 201, 1, 1, NULL, '2025-08-28 12:00:00', 100),

  (2, 102, 2, 1, 'error', '2025-08-25 09:10:00', 110),
  (4, 104, 2, 0, NULL, NULL, 0),
  (6, 106, 2, 1, NULL, '2025-08-26 10:35:00', 200),
  (8, 108, 2, 1, 'error', '2025-08-26 10:45:00', 210),
  (9, 109, 2, 0, NULL, NULL, 0);