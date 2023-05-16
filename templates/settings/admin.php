<?php
	script('facerecognition', 'facerecognition-admin');
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
			<span><input type="range" id="image-area-range" min="307200" max="<?php p($_['max-image-range']);?>" value="-1" step="1200" class="ui-slider" <?php if(!($_['is-configured'])):?>disabled<?php endif;?>></span>
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
			<span><input type="range" id="sensitivity-range" min="0.2" max="0.6" value="0.4" step="0.01" class="ui-slider" <?php if(!($_['is-configured'])):?>disabled<?php endif;?>></span>
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
			<span><input type="range" id="min-confidence-range" min="0.0" max="1.1" value="0.99" step="0.01" class="ui-slider" <?php if(!($_['is-configured'])):?>disabled<?php endif;?>></span>
			<label for="min-confidence-range"><?php p($l->t('Higher minimum confidence'));?></label>
			<span id="min-confidence-value"class="span-highlighted">...</span>
			<a id="restore-min-confidence" class="icon-align icon-history" style="display: none;" title="<?php p($l->t('Restore'));?>" href="#"></a>
			<a id="save-min-confidence" class="icon-align icon-edit" style="display: none;" title="<?php p($l->t('Save'));?>" href="#"></a>
		</p>
		<br>
		<h3>
			<?php p($l->t('Minimum of faces in cluster'));?>
		</h3>
		<p class="settings-hint"><?php p($l->t('The minimum number of faces that a cluster must have to display it to the user.'));?></p>
		<p class="settings-hint"><?php p($l->t('These faces clusters will not be shown as a suggestion, but can always be renamed eventually in the side panel.'));?></p>
		<p class="settings-ranged">
			<label for="min-no-faces"><?php p($l->t('Less faces'));?></label>
			<span><input type="range" id="min-no-faces-range" min="1" max="20" value="5" step="1" class="ui-slider" <?php if(!($_['is-configured'])):?>disabled<?php endif;?>></span>
			<label for="min-no-face"><?php p($l->t('More faces'));?></label>
			<span id="min-no-faces-value"class="span-highlighted">...</span>
			<a id="restore-min-no-faces" class="icon-align icon-history" style="display: none;" title="<?php p($l->t('Restore'));?>" href="#"></a>
			<a id="save-min-no-faces" class="icon-align icon-edit" style="display: none;" title="<?php p($l->t('Save'));?>" href="#"></a>
		</p>
		<br>
		<h3>
			<?php p($l->t('Configuration information'));?>
			<span class="status success<?php if(!($_['is-configured'])):?> error<?php endif;?>"></span>
		</h3>
		<p><?php p($l->t('Current model:'));?> <em><?php p($_['model-version']);?></em></p>
		<p><?php p($l->t('Maximum memory assigned for image processing:'));?> <em><?php p($_['assigned-memory']);?></em></p>
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
