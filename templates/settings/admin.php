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
		<div>
			<h3>
				<strong><?php p($l->t('Sensitivity'));?></strong>
			</h3>
			<p class="settings-hint"><?php p($l->t('The sensitivity determines how different the faces can be to continue to be considered as the same person.'));?>
				<a target="_blank" rel="noreferrer noopener" class="icon-info" title="<?php p($l->t('Open Documentation'));?>" href="https://github.com/matiasdelellis/facerecognition/wiki/Sensitivity"></a>
			</p>
			<p>
				<span><?php p($l->t('More sensitivity, more groups'));?></span>
				<span><input type="range" id="sensitivity-range" min="0.4" max="0.6" value="0.5" step="0.01" class="ui-slider"></span>
				<span><?php p($l->t('Less sensitivity, less groups'));?></span>
				<span id="sensitivity-value"class="span-highlighted">...</span>
				<a id="restore-sensitivity" class="icon-align icon-history" style="display: none;" title="<?php p($l->t('Restore'));?>" href="#"></a>
				<a id="save-sensitivity" class="icon-align icon-edit" style="display: none;" title="<?php p($l->t('Save'));?>" href="#"></a>
			</p>
		</div>
		<h3><strong><?php p($l->t('Configuration information'));?> <span class="status success<?php if(!($_['pdlib-loaded'] && $_['model-present'])):?> error<?php endif;?>"></span></strong></h3>
		<p><strong>Pdlib Version: </strong><?php p($_['pdlib-version']);?></p>
		<p><strong>Models Version: </strong><?php p($_['model-version']);?></p>
		<p><span><?php p($_['resume']); ?></span></p>
		<h3><strong><?php p($l->t('Current status'));?></strong></h3>
		<div>
			<p id="progress-text"><?php p($l->t('Stopped'));?></p>
			<progress id="progress-bar" value="0" max="100"></progress>
		</div>
	</div>
</form>