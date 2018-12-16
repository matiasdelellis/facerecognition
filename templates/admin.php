<?php
	script('facerecognition', 'admin');
?>

<form id="facerecognition">
	<div class="section">
		<h2>
			<?php p($l->t('Face Recognition'));?>
			<a target="_blank" rel="noreferrer noopener" class="icon-info" title="<?php p($l->t('Open Documentation'));?>" href="https://github.com/matiasdelellis/facerecognition/wiki"></a>
		</h2>
		<h3><?php p($l->t('Configuration information'));?> <span class="status success<?php if(!($_['pdlib-loaded'] && $_['model-present'])):?> error<?php endif;?>"></span></h3>
		<p><strong>Pdlib Version: </strong><?php p($_['pdlib-version']);?></p>
		<p><strong>Models Version: </strong><?php p($_['model-version']);?></p>
		<p><span><?php p($_['resume']); ?></span></p>
		<h3><?php p($l->t('Current status'));?></h3>
		<div>
			<p id="progress-text"><?php p($l->t('Stopped'));?></p>
			<progress id="progress-bar" value="0" max="100"></progress>
		</div>
	</div>
</form>