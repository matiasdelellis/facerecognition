<?php
declare(strict_types=1);

namespace OCA\FaceRecognition\Model\Exceptions;

/**
 * The model computes descriptors that cannot be compared with the ones of the
 * faces already analyzed.
 */
class IncompatibleModelException extends \Exception
{
}
