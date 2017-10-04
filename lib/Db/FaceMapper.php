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
		$sql = 'SELECT * FROM *PREFIX*face_recognition WHERE uid = ?';
		return $this->findEntities($sql, [$userId]);
	}

	public function findNew($userId) {
		$sql = 'SELECT * FROM *PREFIX*face_recognition WHERE uid = ? AND distance = -1';
		return $this->findEntities($sql, [$userId]);
	}

	public function findFaces($userId, $query) {
		$sql = 'SELECT * FROM *PREFIX*face_recognition WHERE uid = ? AND LOWER(name) LIKE LOWER(?)';
		$params = ('%' . $query . '%');
		return $this->findEntities($sql, [$userId, $params]);
	}

	public function findNewFile($userId, $fileId) {
		$sql = 'SELECT * FROM *PREFIX*face_recognition WHERE uid = ? AND file = ? AND distance = -1';
		return $this->findEntities($sql, [$userId, $fileId]);
	}

	public function findFile($fileId) {
		$sql = 'SELECT * FROM *PREFIX*face_recognition WHERE file = ?';
		return $this->findEntities($sql, [$fileId]);
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