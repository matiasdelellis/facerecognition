<?php
namespace OCA\FaceRecognition\Db;

use OCP\IDBConnection;
use OCP\AppFramework\Db\Mapper;
use OCP\AppFramework\Db\DoesNotExistException;

class FaceMapper extends Mapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'face_recognition', '\OCA\FaceRecognition\Db\Face');
	}

	public function find($id, $userId) {
		$sql = 'SELECT * FROM *PREFIX*face_recognition WHERE id = ? AND uid = ?';
		return $this->findEntity($sql, [$id, $userId]);
	}

	public function findAll($userId) {
		$sql = 'SELECT * FROM *PREFIX*face_recognition WHERE uid = ? and encoding IS NOT NULL';
		return $this->findEntities($sql, [$userId]);
	}

	public function findAllNew($userId) {
		$sql = 'SELECT * FROM *PREFIX*face_recognition WHERE uid = ? AND distance = -1 AND encoding IS NULL';
		return $this->findEntities($sql, [$userId]);
	}

	public function findAllKnown($userId) {
		$sql = 'SELECT * FROM *PREFIX*face_recognition WHERE uid = ? AND distance = 0 AND encoding IS NOT NULL';
		return $this->findEntities($sql, [$userId]);
	}

	public function findAllUnknown($userId) {
		$sql = 'SELECT * FROM *PREFIX*face_recognition WHERE uid = ? AND distance = 1 AND encoding IS NOT NULL';
		return $this->findEntities($sql, [$userId]);
	}

	public function findAllEmpty($userId) {
		$sql = 'SELECT * FROM *PREFIX*face_recognition WHERE uid = ? AND distance = 0 AND encoding IS NULL';
		return $this->findEntities($sql, [$userId]);
	}

	public function findAllNamed($userId, $query, $limit = 0) {
		$sql = 'SELECT * FROM *PREFIX*face_recognition WHERE uid = ? AND encoding IS NOT NULL AND LOWER(name) LIKE LOWER(?)';
		$params = ('%' . $query . '%');

		if ($limit > 0) {
			$sql = $sql.' LIMIT ?';
			return $this->findEntities($sql, [$userId, $params, $limit]);
		}
		else {
			return $this->findEntities($sql, [$userId, $params]);
		}
	}

	public function findRandom($userId) {
		$sql = 'SELECT * FROM *PREFIX*face_recognition WHERE uid = ? AND distance = 1 AND encoding IS NOT NULL ORDER BY RAND() LIMIT 8';
		return $this->findEntities($sql, [$userId]);
	}

	public function getGroups($userId) {
		$sql = 'SELECT DISTINCT name FROM *PREFIX*face_recognition WHERE uid = ? AND encoding IS NOT NULL';
		return $this->findEntities($sql, [$userId]);
	}

	public function findNewFile($userId, $fileId) {
		$sql = 'SELECT * FROM *PREFIX*face_recognition WHERE uid = ? AND file = ? AND distance = -1 AND encoding IS NULL';
		return $this->findEntities($sql, [$userId, $fileId]);
	}

	public function findFile($userId, $fileId) {
		$sql = 'SELECT * FROM *PREFIX*face_recognition WHERE uid = ? AND file = ?';
		return $this->findEntities($sql, [$userId, $fileId]);
	}

	public function fileExists($fileId) {
		$sql = 'SELECT * FROM *PREFIX*face_recognition WHERE file = ?';
		try {
			$faces = $this->findEntities($sql, [$fileId]);
			return ($faces != NULL);
		} catch (Exception $e) {
			return false;
		}
		return true;
	}

}