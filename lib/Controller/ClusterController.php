<?php
/**
 * @copyright Copyright (c) 2018-2024 Matias De lellis <mati86dl@gmail.com>
 *
 * @author Matias De lellis <mati86dl@gmail.com>
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

namespace OCA\FaceRecognition\Controller;

use OCP\IRequest;
use OCP\Files\File;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Controller;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Db\Cluster;
use OCA\FaceRecognition\Db\ClusterMapper;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\ClusterLinkService;
use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Service\UrlService;


class ClusterController extends Controller {

	/** @var ClusterLinkService */
	private $clusterLinkService;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var ImageMapper */
	private $imageMapper;

	/** @var ClusterMapper */
	private $clusterMapper;

	/** @var PersonMapper */
	private $personMapper;

	/** @var SettingsService */
	private $settingsService;

	/** @var UrlService */
	private $urlService;

	/** @var string */
	private $userId;

	public function __construct($AppName,
	                            IRequest           $request,
	                            FaceMapper         $faceMapper,
	                            ImageMapper        $imageMapper,
	                            ClusterMapper      $clusterMapper,
	                            PersonMapper       $personmapper,
	                            ClusterLinkService $clusterLinkService,
	                            SettingsService    $settingsService,
	                            UrlService         $urlService,
	                            $UserId)
	{
		parent::__construct($AppName, $request);

		$this->faceMapper         = $faceMapper;
		$this->imageMapper        = $imageMapper;
		$this->clusterMapper      = $clusterMapper;
		$this->personMapper       = $personmapper;
		$this->clusterLinkService = $clusterLinkService;
		$this->settingsService    = $settingsService;
		$this->urlService         = $urlService;
		$this->userId             = $UserId;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function find(int $id): DataResponse {
		$cluster = $this->clusterMapper->find($this->userId, $id);

		$resp = [];
		$faces = [];
		$clusterFaces = $this->faceMapper->findFromCluster($this->userId, $cluster->getId(), $this->settingsService->getCurrentFaceModel());
		foreach ($clusterFaces as $clusterFace) {
			$image = $this->imageMapper->find($this->userId, $clusterFace->getImage());

			$file =  $this->urlService->getFileNode($image->getFile());
			if ($file === null) continue;

			$face = [];
			$face['thumbUrl'] = $this->urlService->getThumbUrl($clusterFace->getId(), 50);
			$face['fileUrl'] = $this->urlService->getRedirectToFileUrl($file);
			$faces[] = $face;
		}
		$resp['name'] = $this->nameOf($cluster);
		$resp['id'] = $cluster->getId();
		$resp['faces'] = $faces;

		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function findByName(string $personName): DataResponse {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();
		$resp['clusters'] = array();

		if (!$userEnabled)
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$person = $this->personMapper->findByName($this->userId, $personName);
		if (is_null($person)) {
			return new DataResponse($resp);
		}

		$clusters = $this->clusterMapper->findByPerson($this->userId, $modelId, $person->getId());
		foreach ($clusters as $cluster) {
			$clusterFaces = $this->faceMapper->findFromCluster($this->userId, $cluster->getId(), $modelId);

			$faces = [];
			foreach ($clusterFaces as $clusterFace) {
				$image = $this->imageMapper->find($this->userId, $clusterFace->getImage());

				$file = $this->urlService->getFileNode($image->getFile());
				if ($file === null) continue;

				$face = [];
				$face['thumbUrl'] = $this->urlService->getThumbUrl($clusterFace->getId(), 50);
				$face['fileUrl'] = $this->urlService->getRedirectToFileUrl($file);
				$faces[] = $face;
			}

			$entry = [];
			$entry['name'] = $person->getName();
			$entry['count'] = count($clusterFaces);
			$entry['id'] = $cluster->getId();
			$entry['faces'] = $faces;
			$resp['clusters'][] = $entry;
		}

		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function findUnassigned(): DataResponse {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();
		$resp['enabled'] = $userEnabled;
		$resp['clusters'] = array();

		if (!$userEnabled)
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();
		$minClusterSize = $this->settingsService->getMinimumFacesInCluster();

		$clusters = $this->clusterMapper->findUnassigned($this->userId, $modelId);
		foreach ($clusters as $cluster) {
			$clusterSize = $this->clusterMapper->countClusterFaces($cluster->getId());
			if ($clusterSize < $minClusterSize)
				continue;

			$clusterFaces = $this->faceMapper->findFromCluster($this->userId, $cluster->getId(), $modelId, 40);
			$faces = [];
			foreach ($clusterFaces as $clusterFace) {
				$image = $this->imageMapper->find($this->userId, $clusterFace->getImage());

				$file = $this->urlService->getFileNode($image->getFile());
				if ($file === null) continue;

				$face = [];
				$face['thumbUrl'] = $this->urlService->getThumbUrl($clusterFace->getId(), 50);
				$face['fileUrl'] = $this->urlService->getRedirectToFileUrl($file);

				$faces[] = $face;
			}

			$entry = [];
			$entry['count'] = $clusterSize;
			$entry['id'] = $cluster->getId();
			$entry['faces'] = $faces;
			$resp['clusters'][] = $entry;
		}

		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	Public function findIgnored(): DataResponse {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();
		$resp['enabled'] = $userEnabled;
		$resp['clusters'] = array();

		if (!$userEnabled)
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();
		$minClusterSize = $this->settingsService->getMinimumFacesInCluster();

		$clusters = $this->clusterMapper->findIgnored($this->userId, $modelId);
		foreach ($clusters as $cluster) {
			$clusterSize = $this->clusterMapper->countClusterFaces($cluster->getId());
			if ($clusterSize < $minClusterSize)
				continue;

			$clusterFaces = $this->faceMapper->findFromCluster($this->userId, $cluster->getId(), $modelId, 40);
			$faces = [];
			foreach ($clusterFaces as $clusterFace) {
				$image = $this->imageMapper->find($this->userId, $clusterFace->getImage());

				$file = $this->urlService->getFileNode($image->getFile());
				if ($file === null) continue;

				$face = [];
				$face['thumbUrl'] = $this->urlService->getThumbUrl($clusterFace->getId(), 50);
				$face['fileUrl'] = $this->urlService->getRedirectToFileUrl($file);

				$faces[] = $face;
			}

			$entry = [];
			$entry['count'] = $clusterSize;
			$entry['id'] = $cluster->getId();
			$entry['faces'] = $faces;
			$resp['clusters'][] = $entry;
		}

		return new DataResponse($resp);
	}

	/**
	 * Other clusters that could be the same person as this one.
	 *
	 * The clustering never joins them by itself: a person at another age, from
	 * another angle or without the beard is farther than the sensitivity, and
	 * joining on so little evidence is how two people end up together. So they
	 * are proposed here, and naming one of them is what actually links them.
	 *
	 * @NoAdminRequired
	 *
	 * @param int $id of the cluster
	 *
	 * @return DataResponse
	 */
	public function findSimilar(int $id): DataResponse {
		// Throws if the cluster is not from this user.
		$this->clusterMapper->find($this->userId, $id);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$clusters = [];
		foreach ($this->clusterLinkService->findCandidates($this->userId, $id) as $candidate) {
			$faces = $this->faceMapper->findFromCluster($this->userId, $candidate['id'], $modelId, 1);
			if (empty($faces)) {
				continue;
			}

			$candidate['thumbUrl'] = $this->urlService->getThumbUrl(current($faces)->getId(), 128);
			$clusters[] = $candidate;
		}

		return new DataResponse(['clusters' => $clusters]);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $id
	 * @param bool $visible
	 *
	 * @return DataResponse
	 */
	public function setVisibility (int $id, bool $visible): DataResponse {
		$resp = array();
		$this->clusterMapper->setVisibility($id, $visible);
		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $id if of cluster
	 * @param int $face id of face.
	 * @param string|null $name optional name to rename it.
	 *
	 * @return DataResponse
	 */
	public function detachFace (int $id, int $face, $name = null): DataResponse {
		$cluster = $this->clusterMapper->find($this->userId, $id);

		$personId = null;
		if (!is_null($name) && $name !== '') {
			$personId = $this->personMapper->findOrCreateByName($this->userId, $name)->getId();
		}

		$detached = $this->clusterMapper->detachFace($cluster->getId(), $face, $personId);

		return new DataResponse($this->describe($detached));
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $id of cluster
	 * @param string $name to rename them.
	 * @param int|null $face_id optional face id if you just want to name that face
	 *
	 * @return DataResponse new person with that update.
	 */
	public function updateName($id, $name, $face_id = null): DataResponse {
		$cluster = $this->clusterMapper->find($this->userId, $id);

		// Naming a cluster is saying which person it is one of the looks of.
		$personId = null;
		if (!is_null($name) && $name !== '') {
			$personId = $this->personMapper->findOrCreateByName($this->userId, $name)->getId();
		}

		if (is_null($face_id)) {
			$this->clusterMapper->setPerson($cluster->getId(), $personId);
			$cluster = $this->clusterMapper->find($this->userId, $id);
		} else {
			$cluster = $this->clusterMapper->detachFace($cluster->getId(), $face_id, $personId);
		}

		// A person nobody points at any more is not a person.
		$this->personMapper->deleteOrphaned($this->userId);

		return new DataResponse($this->describe($cluster));
	}

	/**
	 * Name of the person the cluster belongs to, if any.
	 */
	private function nameOf(Cluster $cluster): ?string {
		if (is_null($cluster->getPerson())) {
			return null;
		}

		try {
			return $this->personMapper->find($this->userId, $cluster->getPerson())->getName();
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * The cluster as the clients of this API expect it, which is with the name
	 * of its person and not with the id of it.
	 */
	private function describe(Cluster $cluster): array {
		return [
			'id' => $cluster->getId(),
			'name' => $this->nameOf($cluster),
			'is_visible' => $cluster->getIsVisible(),
		];
	}

}
