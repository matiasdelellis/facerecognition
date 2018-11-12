<?php
//	script('facerecognition', 'admin');
?>

<form id="facerecognition">
	<div class="section">
		<h2>
			<?php p($l->t('Face recognition'));?>
			<a target="_blank" rel="noreferrer noopener" class="icon-info" title="<?php p($l->t('Open Documentation'));?>" href="https://github.com/matiasdelellis/facerecognition/wiki"></a>
		</h2>
		<h3>Configuration information</h3>
		<p><strong>Requirements: </strong><?php p($_['msg']); ?><span class="status success<?php if(!$_['requirements']):?> error<?php endif;?>"></span></p>
		<h3>Models</h3>
		<p><strong>Recognition Model: </strong><?php p($_['recognition-model']); ?></p>
		<p><strong>Landmarking Model: </strong><?php p($_['landmarking-model']); ?></p>
		<p><strong>Detection Model: </strong><?php p($_['detection-model']); ?></p>
		<h3>Current status</h3>
		<div>
			<p id="progress-text">Stopped</p>
			<progress id="progress-bar" value="0" max="100"></progress>
		</div>
	</div>
</form>