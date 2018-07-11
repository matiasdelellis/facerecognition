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
     * @throws Distance\NonNumericException if vectors are not numeric
     * @throws Distance\ImcompatibleItemsException if vectors are of dissimilar size
     * @return double The Euclidean distance between vector1 and vector2
     * @see _compatibleData()
     *
     * @assert (array(1,2,3), array(1,2,3,4)) throws Distance\IncompatibleItemsException
     * @assert (array(2,'a',6,7), array(4,5,1,9)) throws Distance\NonNumericException
     * @assert (array(1,2), array(3,4)) == sqrt(8)
     * @assert (array(2,4,6,7), array(4,5,1,9)) == sqrt(4+1+25+4)
     *
     */

    public function distance($vector1, $vector2)
    {
        $n = count($vector1);
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $sum += ($vector1[$i] - $vector2[$i]) * ($vector1[$i] - $vector2[$i]);
        }
        return sqrt($sum);
    }
}
