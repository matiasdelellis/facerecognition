<?php

namespace OCA\FaceRecognition\Helper;

class Euclidean
{
    /**
     * Euclidean distance metric between two vectors
     *
     * The euclidean distance between two vectors (vector1, vector2) is defined as
     * D = SQRT(SUM((vector1(i) - vector2(i))^2))  (i = 0..k)
     *
     * Refs:
     * - http://mathworld.wolfram.com/EuclideanMetric.html
     * - http://en.wikipedia.org/wiki/Euclidean_distance
     *
     * @param array $vector1 first vector
     * @param array $vector2 second vector
     *
     * @return float The Euclidean distance between vector1 and vector2
     *
     */

    public static function distance(array $vector1, array $vector2): float
    {
        $n = count($vector1);
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $sum += ($vector1[$i] - $vector2[$i]) * ($vector1[$i] - $vector2[$i]);
        }
        return sqrt($sum);
    }
}
