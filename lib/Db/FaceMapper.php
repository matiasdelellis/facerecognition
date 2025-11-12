<?php

/**
 * @copyright Copyright (c) 2017-2020, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018-2019, Branko Kokanovic <branko@kokanovic.org>
 *
 * @author Matias De lellis <mati86dl@gmail.com>
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;

use OCA\FaceRecognition\Traits\LoggerTrait;
use Psr\Log\LoggerInterface;

use OCA\FaceRecognition\Db\FaceMapperTraits\AllFacesTrait;
use OCA\FaceRecognition\Db\FaceMapperTraits\CountFacesTrait;
use OCA\FaceRecognition\Db\FaceMapperTraits\DescriptorsTrait;
use OCA\FaceRecognition\Db\FaceMapperTraits\FindFacesTrait;
use OCA\FaceRecognition\Db\FaceMapperTraits\GroupingTrait;
use OCA\FaceRecognition\Db\FaceMapperTraits\ModificationTrait;

class FaceMapper extends QBMapper
{
	use LoggerTrait;
    use AllFacesTrait;
    use CountFacesTrait;
    use DescriptorsTrait;
    use FindFacesTrait;
    use GroupingTrait;
    use ModificationTrait;

    public function __construct(IDBConnection $db, LoggerInterface $logger)
    {
        parent::__construct($db, 'facerecog_faces', '\OCA\FaceRecognition\Db\Face');

		$this->setLogger($logger);
    }
    
}
