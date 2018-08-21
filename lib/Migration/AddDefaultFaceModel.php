<?php
/**
 * @copyright Copyright (c) 2018, Branko Kokanovic <branko@kokanovic.org>
 *
 * @author Branko Kokanovic <branko@kokanovic.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\FaceRecognition\Migration;

use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class AddDefaultFaceModel implements IRepairStep {
	/** Defines ID for default face model */
	const DEFAULT_FACE_MODEL_ID = 1;

	/** Defines name for default face model */
	const DEFAULT_FACE_MODEL_NAME = 'Default';

	/** Defines description for default face model */
	const DEFAULT_FACE_MODEL_DESC = 'Main model, using dlib defaults: mmod_human_face_detector.dat, shape_predictor_5_face_landmarks.dat and dlib_face_recognition_resnet_model_v1.dat';

	/** @var IDBConnection */
	private $connection;

	/**
	 * AddDefaultFaceModel constructor.
	 *
	 * @param IDBConnection $connection
	 */
	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	/**
	 * @inheritdoc
	 */
	public function getName() {
		return 'Adds default face model (if it does not exist)';
	}

	/**
	 * @inheritdoc
     * 
     * Upserts first row in Face Model, so that after installation there is always at least one row.
     * If row with ID 1 already exists, it does not touch it.
	 * 
     * @todo See if we really can just accept anything with ID 1 and don't care, because we never
     * afterwards check if ID=1 has changes name/description?
	 */
	public function run(IOutput $output) {
		$qb = $this->connection->getQueryBuilder();
		$query = $qb->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from('face_recognition_face_models')
			->where($qb->expr()->eq('id', $qb->createParameter('id')))
			->setParameter('id', self::DEFAULT_FACE_MODEL_ID);
		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		if ((int)$data[0] <= 0) {
			$query = $this->connection->getQueryBuilder();
			$query->insert('face_recognition_face_models')
				->values([
					'id' => $query->createNamedParameter(self::DEFAULT_FACE_MODEL_ID),
					'name' => $query->createNamedParameter(self::DEFAULT_FACE_MODEL_NAME),
					'description' => $query->createNamedParameter(self::DEFAULT_FACE_MODEL_DESC)
				])
				->execute();
			$output->info("Inserted missing default face model.");
		} else {
			$output->info("Default face model already existed, no need to add it again.");
		}
	}
}