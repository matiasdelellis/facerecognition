<?php

/**
 * @copyright Copyright (c) 2018-2021, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018-2019, Branko Kokanovic <branko@kokanovic.org>
 *
 * @author Branko Kokanovic <branko@kokanovic.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * under the terms of the GNU Affero General Public License as
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

namespace OCA\FaceRecognition\Db;


use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;

use OCA\FaceRecognition\Db\ClusterMapperTraits\ClusterCountTrait;
use OCA\FaceRecognition\Db\ClusterMapperTraits\ClusterCRUDTrait;
use OCA\FaceRecognition\Db\ClusterMapperTraits\ClusterFaceTrait;
use OCA\FaceRecognition\Db\ClusterMapperTraits\ClusterFinderTrait;
use OCA\FaceRecognition\Db\ClusterMapperTraits\ClusterPersonTrait;
use OCA\FaceRecognition\Traits\LoggerTrait;
use Psr\Log\LoggerInterface;

class ClusterMapper extends QBMapper
{
	use LoggerTrait;
    use ClusterCountTrait;
    use ClusterCRUDTrait;
    use ClusterFaceTrait;
    use ClusterFinderTrait; 
    use ClusterPersonTrait;

    public function __construct(IDBConnection $db, LoggerInterface $logger)
    {
        parent::__construct($db, 'facerecog_clusters', '\OCA\FaceRecognition\Db\Person');
		$this->setLogger($logger);
    }



}
