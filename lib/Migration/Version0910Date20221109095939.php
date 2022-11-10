<?php
namespace OCA\FaceRecognition\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\BigIntMigration;
use OCP\Migration\IOutput;

class Version0910Date20221109095939 extends BigIntMigration {

	/**
	 * @return array Returns an array with the following structure
	 * ['table1' => ['column1', 'column2'], ...]
	 * @since 13.0.0
	 */
	protected function getColumnsByTable() {
		return [
			'facerecog_images' => ['id', 'file'],
			'facerecog_faces' => ['image'],
		];
	}

}
