<?php
	script('facerecognition', 'admin');
	style('facerecognition', 'facerecognition');
?>

<form id="facerecognition">
	<div class="section">
		<h2>
			<?php p($l->t('Face Recognition'));?>
			<a target="_blank" rel="noreferrer noopener" class="icon-info" title="<?php p($l->t('Open Documentation'));?>" href="https://github.com/matiasdelellis/facerecognition/wiki"></a>
		</h2>
		<h3>
			<?php p($l->t('Temporary files'));?>
		</h3>
		<p class="settings-hint"><?php p($l->t('During analysis, temporary files are used to ensure homogeneity between all images.'));?></p>
		<p class="settings-hint"><?php p($l->t('Small images allow a quick analysis, but you can lose the smallest faces of your photos. Large images can improve the results, but the analysis will be slower.'));?>
			<a target="_blank" rel="noreferrer noopener" class="icon-info" title="<?php p($l->t('Open Documentation'));?>" href="https://github.com/matiasdelellis/facerecognition/wiki/Settings#temporary-files"></a>
		</p>
		<p class="settings-ranged">
			<label for="image-area-range"><?php p($l->t('Smaller images'));?></label>
			<span><input type="range" id="image-area-range" min="307200" max="8294400" value="-1" step="1200" class="ui-slider"></span>
			<label for="image-area-range"><?php p($l->t('Larger images'));?></label>
			<span id="image-area-value"class="span-highlighted">...</span>
			<a id="restore-image-area" class="icon-align icon-history" style="display: none;" title="<?php p($l->t('Restore'));?>" href="#"></a>
			<a id="save-image-area" class="icon-align icon-edit" style="display: none;" title="<?php p($l->t('Save'));?>" href="#"></a>
		</p>
		<br>
		<h3>
			<?php p($l->t('Clustering threshold'));?>
		</h3>
		<p class="settings-hint"><?php p($l->t('Persons are determined as groups of similar faces and to obtain them, all the faces found must be compared. When they are compared, a threshold is used to determine if they should be grouped.'));?></p>
		<p class="settings-hint"><?php p($l->t('A small threshold will only group very similar faces, but initially you will have many groups to name. A larger threshold is more flexible to group the faces and obtaining fewer groups, but being able to confuse similar persons.'));?>
			<a target="_blank" rel="noreferrer noopener" class="icon-info" title="<?php p($l->t('Open Documentation'));?>" href="https://github.com/matiasdelellis/facerecognition/wiki/Sensitivity"></a>
		</p>
		<p class="settings-ranged">
			<label for="sensitivity-range"><?php p($l->t('Small threshold'));?></label>
			<span><input type="range" id="sensitivity-range" min="0.2" max="0.6" value="0.4" step="0.01" class="ui-slider"></span>
			<label for="sensitivity-range"><?php p($l->t('Higher threshold'));?></label>
			<span id="sensitivity-value"class="span-highlighted">...</span>
			<a id="restore-sensitivity" class="icon-align icon-history" style="display: none;" title="<?php p($l->t('Restore'));?>" href="#"></a>
			<a id="save-sensitivity" class="icon-align icon-edit" style="display: none;" title="<?php p($l->t('Save'));?>" href="#"></a>
		</p>
		<br>
		<h3>
			<?php p($l->t('Minimum confidence'));?>
		</h3>
		<p class="settings-hint"><?php p($l->t('The minimum confidence determines how reliable must be a face detection to try to group it. Blurred or misaligned faces would have a confidence closer to 0.0, and the best images close to 1.0.'));?>
			<a target="_blank" rel="noreferrer noopener" class="icon-info" title="<?php p($l->t('Open Documentation'));?>" href="https://github.com/matiasdelellis/facerecognition/wiki/Confidence"></a>
		</p>
		<p class="settings-ranged">
			<label for="min-confidence-range"><?php p($l->t('Lower minimum confidence'));?></label>
			<span><input type="range" id="min-confidence-range" min="0.0" max="1.1" value="0.99" step="0.01" class="ui-slider"></span>
			<label for="min-confidence-range"><?php p($l->t('Higher minimum confidence'));?></label>
			<span id="min-confidence-value"class="span-highlighted">...</span>
			<a id="restore-min-confidence" class="icon-align icon-history" style="display: none;" title="<?php p($l->t('Restore'));?>" href="#"></a>
			<a id="save-min-confidence" class="icon-align icon-edit" style="display: none;" title="<?php p($l->t('Save'));?>" href="#"></a>
		</p>
		<br>
		<h3>
			<?php p($l->t('Configuration information'));?>
			<span class="status success<?php if(!($_['model-version'] > 0 && $_['meet-dependencies'])):?> error<?php endif;?>"></span>
		</h3>
		<p><?php p($l->t('System memory:'));?> <em><?php p($_['system-memory']);?></em></p>
		<p><?php p($l->t('Memory assigned to PHP:'));?> <em><?php p($_['php-memory']);?></em></p>
		<p><?php p($l->t('Maximum memory for facial recognition:'));?> <em><?php p($_['available-memory']);?></em></p>
		<br>
		<p><?php p($l->t('Pdlib version:'));?> <em><?php p($_['pdlib-version']);?></em></p>
		<br>
		<p><?php p($l->t('Current model:'));?> <em><?php p($_['model-version']);?></em></p>
		<p><span><?php p($_['resume']); ?></span></p>
		<br>
		<h3>
			<?php p($l->t('Current status'));?>
		</h3>
		<div>
			<p id="progress-text"><?php p($l->t('Stopped'));?></p>
			<progress id="progress-bar" value="0" max="100"></progress>
		</div>
	</div>
</form>
