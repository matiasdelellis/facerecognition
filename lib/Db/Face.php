<?php
namespace OCA\FaceRecognition\Db;

use JsonSerializable;

use OCP\AppFramework\Db\Entity;

class Face extends Entity implements JsonSerializable {

	protected $uid;
	protected $file;
	protected $name;
	protected $distance;
	protected $top;
	protected $right;
	protected $bottom;
	protected $left;
	protected $encoding;

	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'uid' => $this->uid,
			'file' => $this->file,
			'name' => $this->name,
			'distance' => $this->distance,
			'top' => $this->top,
			'right' => $this->right,
			'bottom' => $this->bottom,
			'left' => $this->left,
			'enconding' => $this->encoding
		];
	}

}