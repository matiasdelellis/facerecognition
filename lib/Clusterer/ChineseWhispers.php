<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023, Matias De lellis
 *
 * @author Matias De lellis <mati86dl@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\FaceRecognition\Clusterer;


/**
 * This class implements the graph clustering algorithm described in the
 * paper: Chinese Whispers - an Efficient Graph Clustering Algorithm and its
 * Application to Natural Language Processing Problems by Chris Biemann.
 *
 * In particular, it tries to be a shameless copy of the original dlib
 * implementation.
 *  - https://github.com/davisking/dlib/blob/master/dlib/clustering/chinese_whispers.h
 */
class ChineseWhispers {

	/**
	 * Cluster the dataset by assigning a label to each sample.from the edges
	 */
	static public function predict(array &$edges, array &$labels, int $num_iterations = 100)
	{
		// To improve the stability of the clusters, we must
		// iterate the neighbors in a pseudo-random way.
		mt_srand(2023);

		$labels = [];
		if (count($edges) == 0)
			return 0;

		$neighbors = [];
		self::find_neighbor_ranges($edges, $neighbors);

		// Initialize the labels, each node gets a different label.
		for ($i = 0; $i < count($neighbors); ++$i)
			$labels[$i] = $i;

		for ($iter = 0; $iter < count($neighbors)*$num_iterations; ++$iter)
		{
			// Pick a random node.
			$idx = mt_rand()%count($neighbors);

			// Count how many times each label happens amongst our neighbors.
			$labels_to_counts = [];
			$end = $neighbors[$idx][1];

			for ($i = $neighbors[$idx][0]; $i != $end; ++$i)
			{
				$iLabelFirst = $edges[$i][1];
				$iLabel = $labels[$iLabelFirst];
				if (isset($labels_to_counts[$iLabel]))
					$labels_to_counts[$iLabel]++;
				else
					$labels_to_counts[$iLabel] = 1;
			}

			// find the most common label
			// std::map<unsigned long, double>::iterator i;
			$best_score = PHP_INT_MIN;
			$best_label = $labels[$idx];
			foreach ($labels_to_counts as $key => $value)
			{
				if ($value > $best_score)
				{
					$best_score = $value;
					$best_label = $key;
				}
			}

			$labels[$idx] = $best_label;
		}

		// Remap the labels into a contiguous range.  First we find the
		// mapping.
		$label_remap = [];
		for ($i = 0; $i < count($labels); ++$i)
		{
			$next_id = count($label_remap);
			if (!isset($label_remap[$labels[$i]]))
				$label_remap[$labels[$i]] = $next_id;
		}
		// now apply the mapping to all the labels.
		for ($i = 0; $i < count($labels); ++$i)
		{
			$labels[$i] = $label_remap[$labels[$i]];
		}

		return count($label_remap);
	}

	static function find_neighbor_ranges (&$edges, &$neighbors) {
		// setup neighbors so that [neighbors[i].first, neighbors[i].second) is the range
		// within edges that contains all node i's edges.
		$num_nodes = self::max_index_plus_one($edges);
		for ($i = 0; $i < $num_nodes; ++$i) $neighbors[$i] = [0, 0];
		$cur_node = 0;
		$start_idx = 0;
		for ($i = 0; $i < count($edges); ++$i)
		{
			if ($edges[$i][0] != $cur_node)
			{
				$neighbors[$cur_node] = [$start_idx, $i];
				$start_idx = $i;
				$cur_node = $edges[$i][0];
			}
		}
		if (count($neighbors) !== 0)
			$neighbors[$cur_node] = [$start_idx, count($edges)];
	}

	static function max_index_plus_one ($pairs): int {
		if (count($pairs) === 0)
		{
			return 0;
		}
		else {
			$max_idx = 0;
			for ($i = 0; $i < count($pairs); ++$i)
			{
				if ($pairs[$i][0] > $max_idx)
					$max_idx = $pairs[$i][0];
				if ($pairs[$i][1] > $max_idx)
					$max_idx = $pairs[$i][1];
			}
			return $max_idx + 1;
		}
	}

	static function convert_unordered_to_ordered (&$edges, &$out_edges)
	{
		$out_edges = [];
		for ($i = 0; $i < count($edges); ++$i)
		{
			$out_edges[] = [$edges[$i][0], $edges[$i][1]];
			if ($edges[$i][0] != $edges[$i][1])
				$out_edges[] = [$edges[$i][1], $edges[$i][0]];
		}
	}
}
