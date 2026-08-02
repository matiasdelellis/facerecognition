<?php
/**
 * @copyright Copyright (c) 2017-2020, Matias De lellis <mati86dl@gmail.com>
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
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Controller;

use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Service\SettingsService;

class ProcessController extends Controller {

	/** @var ImageMapper */
	private $imageMapper;

	/** @var SettingsService */
	private $settingsService;

	/**  @var string */
	private $userId;

	public function __construct($AppName,
	                            IRequest        $request,
	                            ImageMapper     $imageMapper,
	                            SettingsService $settingsService,
	                            $UserId)
	{
		parent::__construct($AppName, $request);

		$this->imageMapper     = $imageMapper;
		$this->settingsService = $settingsService;
		$this->userId          = $UserId;
	}

	/**
	 * Just print a global status of the analysis.
	 *
	 * @return JSONResponse
	 */
	public function index(): JSONResponse {

		$model = $this->settingsService->getCurrentFaceModel();

		$totalImages = $this->imageMapper->countImages($model);
		// An image is only done when it was refined, unless the refinement is
		// disabled: with the two passes, being processed alone is not finished.
		$refinementEnabled = $this->settingsService->getRefinementEnabled();
		$doneImages = $refinementEnabled
			? $this->imageMapper->countRefinedImages($model)
			: $this->imageMapper->countProcessedImages($model);
		// What is left is refinement, which costs much more than the fast pass,
		// so the estimate is made with the time the refined images took.
		$avgProcessingTime = $this->imageMapper->avgProcessingDuration($model, $refinementEnabled);

		// The analysis has started once an image was worked on, in either pass.
		$status = ($this->imageMapper->countProcessedImages($model) > 0);

		$estimatedTime = ($totalImages - $doneImages) * $avgProcessingTime/1000;

		$estimatedFinalize = $estimatedTime;

		// The key keeps its name for the frontend, but it counts the images
		// that have nothing pending, which with the two passes means refined.
		$params = array(
			'status' => $status,
			'estimatedFinalize' => $estimatedFinalize,
			'totalImages' => $totalImages,
			'processedImages' => $doneImages
		);

		return new JSONResponse($params);
	}

}
